package uploader

import (
	"context"
	"encoding/json"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/Nebuless/ALRT/collector/internal/journal"
	"github.com/Nebuless/ALRT/collector/internal/protocol"
)

func closeTestJournal(t *testing.T, j *journal.Journal) {
	t.Helper()
	if err := j.Close(); err != nil {
		t.Errorf("journal close: %v", err)
	}
}
func respondJSON(t *testing.T, w http.ResponseWriter, v any) {
	t.Helper()
	// Serialize separately so invalid test responses fail the proof. A client may
	// intentionally disconnect during timeout/cancellation after server acceptance.
	b, err := json.Marshal(v)
	if err != nil {
		t.Errorf("response JSON: %v", err)
		return
	}
	_, _ = w.Write(b)
}
func decodeRequest(t *testing.T, w http.ResponseWriter, r *http.Request, v any) bool {
	t.Helper()
	if err := json.NewDecoder(r.Body).Decode(v); err != nil {
		t.Errorf("request JSON: %v", err)
		w.WriteHeader(http.StatusBadRequest)
		return false
	}
	return true
}
func fixture(t *testing.T, n uint64) []byte {
	t.Helper()
	b, e := os.ReadFile("../../../contracts/fixtures/telemetry/valid-sample.json")
	if e != nil {
		t.Fatal(e)
	}
	r, e := protocol.Decode(b)
	if e != nil {
		t.Fatal(e)
	}
	r.Sequence = n
	b, _ = json.Marshal(r)
	return b
}

func awaitTestEvent(t *testing.T, event <-chan struct{}, name string) {
	t.Helper()
	select {
	case <-event:
	case <-time.After(5 * time.Second):
		t.Fatalf("timed out waiting for %s", name)
	}
}

type roundTripFunc func(*http.Request) (*http.Response, error)

func (f roundTripFunc) RoundTrip(r *http.Request) (*http.Response, error) { return f(r) }

type responseCloseEvent struct {
	io.ReadCloser
	onClose func()
}

func (b responseCloseEvent) Close() error {
	err := b.ReadCloser.Close()
	b.onClose()
	return err
}

func TestFreshContactNeverSkipsBacklogAndAckLossRetriesIdentity(t *testing.T) {
	var mu sync.Mutex
	var events []string
	var attempts [][]byte
	lose := true
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var body struct {
			Reports []json.RawMessage `json:"reports"`
			Report  json.RawMessage   `json:"report"`
		}
		if !decodeRequest(t, w, r, &body) {
			return
		}
		mu.Lock()
		defer mu.Unlock()
		switch {
		case len(body.Reports) > 0:
			events = append(events, "replay")
			b, _ := json.Marshal(body.Reports)
			attempts = append(attempts, b)
			if lose {
				lose = false
				w.WriteHeader(503)
				return
			}
			var accepted []protocol.Ack
			for _, b := range body.Reports {
				p, _ := protocol.Decode(b)
				accepted = append(accepted, protocol.Ack{StreamID: p.StreamID, Sequence: p.Sequence})
			}
			respondJSON(t, w, map[string]any{"accepted": accepted})
		case len(body.Report) > 0:
			events = append(events, "contact")
			p, _ := protocol.Decode(body.Report)
			respondJSON(t, w, map[string]any{"accepted": []protocol.Ack{{StreamID: p.StreamID, Sequence: p.Sequence}}, "contact_at": time.Now().UTC().Format(time.RFC3339Nano)})
		default:
			respondJSON(t, w, map[string]string{"nonce": string(make([]byte, 64)), "expires_at": time.Now().Add(30 * time.Second).UTC().Format(time.RFC3339Nano)})
		}
	}))
	defer server.Close()
	j, e := journal.Open(journal.Config{Directory: t.TempDir(), Destinations: []string{"hub"}})
	if e != nil {
		t.Fatal(e)
	}
	defer closeTestJournal(t, j)
	for n := uint64(1); n <= 4; n++ {
		j.Append(fixture(t, n))
	}
	time.Sleep(30 * time.Millisecond)
	worker := New(Destination{ID: "hub", URL: server.URL, Token: "credential"}, j, server.Client())
	worker.Publish(fixture(t, 100))
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go worker.Run(ctx)
	go func() { time.Sleep(300 * time.Millisecond); worker.Publish(fixture(t, 101)) }()
	end := time.Now().Add(3 * time.Second)
	for time.Now().Before(end) && j.Cursor("hub") < 4 {
		time.Sleep(5 * time.Millisecond)
	}
	mu.Lock()
	defer mu.Unlock()
	if len(events) < 3 || events[0] != "contact" || len(attempts) < 2 || string(attempts[0]) != string(attempts[1]) || j.Cursor("hub") != 4 {
		t.Fatalf("ordering/retry/cursor %v %d", events, j.Cursor("hub"))
	}
}

func TestEmptyWrongAndPartialACKOnlyAdvanceSentPrefix(t *testing.T) {
	for _, kind := range []string{"empty", "wrong", "partial"} {
		t.Run(kind, func(t *testing.T) {
			server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				var body struct {
					Reports []json.RawMessage `json:"reports"`
					Report  json.RawMessage   `json:"report"`
				}
				if !decodeRequest(t, w, r, &body) {
					return
				}
				if len(body.Reports) > 0 {
					var accepted []protocol.Ack
					if kind == "partial" {
						p, _ := protocol.Decode(body.Reports[0])
						accepted = []protocol.Ack{{StreamID: p.StreamID, Sequence: p.Sequence}}
					}
					if kind == "wrong" {
						accepted = []protocol.Ack{{StreamID: "other", Sequence: 999}}
					}
					respondJSON(t, w, map[string]any{"accepted": accepted})
					return
				}
				if len(body.Report) > 0 {
					p, _ := protocol.Decode(body.Report)
					respondJSON(t, w, map[string]any{"accepted": []protocol.Ack{{StreamID: p.StreamID, Sequence: p.Sequence}}})
					return
				}
				respondJSON(t, w, map[string]string{"nonce": strings.Repeat("n", 64), "expires_at": time.Now().Add(time.Minute).UTC().Format(time.RFC3339Nano)})
			}))
			defer server.Close()
			cfg := journal.Config{Directory: t.TempDir(), Destinations: []string{"hub"}}
			j, e := journal.Open(cfg)
			if e != nil {
				t.Fatal(e)
			}
			defer closeTestJournal(t, j)
			for n := uint64(1); n <= 3; n++ {
				if !j.Append(fixture(t, n)) {
					t.Fatal("sampling queue unexpectedly full")
				}
			}
			// Close drains the asynchronous append queue and synchronizes the real
			// journal; reopening makes all three records available before contact.
			closeTestJournal(t, j)
			j, e = journal.Open(cfg)
			if e != nil {
				t.Fatal(e)
			}
			defer closeTestJournal(t, j)
			contactCtx, cancelContact := context.WithCancel(context.Background())
			defer cancelContact()
			replayCtx, cancelReplay := context.WithCancel(context.Background())
			defer cancelReplay()
			client := *server.Client()
			transport := client.Transport
			responseRead := make(chan struct{})
			client.Transport = roundTripFunc(func(r *http.Request) (*http.Response, error) {
				response, err := transport.RoundTrip(r)
				if err == nil && strings.HasSuffix(r.URL.Path, "/reports") {
					response.Body = responseCloseEvent{response.Body, func() {
						// post closes the fully read/decoded response before replay
						// processes its ACK. Cancel only the next iteration, so the
						// partial case cannot ACK another record on a later retry.
						close(responseRead)
						cancelReplay()
					}}
				}
				return response, err
			})
			w := New(Destination{ID: "hub", URL: server.URL}, j, &client)
			w.OnContact = func(protocol.Report) { cancelContact() }
			contactDone := make(chan struct{})
			go func() { defer close(contactDone); w.contacts(contactCtx) }()
			w.Publish(fixture(t, 100))
			awaitTestEvent(t, contactDone, "accepted fresh contact")
			replayDone := make(chan struct{})
			go func() { defer close(replayDone); w.replay(replayCtx) }()
			awaitTestEvent(t, responseRead, "replay ACK response")
			awaitTestEvent(t, replayDone, "replay ACK processing")
			// A successful Commit only queues work. Drain it after replay exits
			// before checking the persisted cursor, including the zero cases.
			closeTestJournal(t, j)
			want := uint64(0)
			if kind == "partial" {
				want = 1
			}
			if j.Cursor("hub") != want {
				t.Fatalf("cursor %d want %d", j.Cursor("hub"), want)
			}
		})
	}
}

func TestFourHoursThreeIndependentHubsFreshSamplesAndRestart(t *testing.T) {
	const count = 1440
	dir := t.TempDir()
	cfg := journal.Config{Directory: dir, BudgetBytes: 16 << 20, SegmentBytes: 256 << 10, Destinations: []string{"primary", "standby", "beta-restricted"}}
	j, e := journal.Open(cfg)
	if e != nil {
		t.Fatal(e)
	}
	// Four hours at a ten-second cadence. Wait for finite queues instead of
	// sleeping through simulated time; this is the same on-disk append boundary.
	base := time.Now().Add(-4 * time.Hour)
	sample := func(n uint64) []byte {
		r, _ := protocol.Decode(fixture(t, n))
		r.SampledAt = base.Add(time.Duration(n) * 10 * time.Second).UTC().Format(time.RFC3339Nano)
		if n == 600 {
			v := 99.0
			r.Processes.TopCPU.Items[0].CPUPercent = &v
			r.Processes.TopCPU.Items[0].Name = "culprit"
		}
		b, _ := json.Marshal(r)
		return b
	}
	for n := uint64(1); n <= count; n++ {
		if !j.Append(sample(n)) {
			t.Fatal("sampling queue unexpectedly full")
		}
		if n%16 == 0 {
			closeTestJournal(t, j)
			j, e = journal.Open(cfg)
			if e != nil {
				t.Fatal(e)
			}
		}
	}
	closeTestJournal(t, j)
	j, e = journal.Open(cfg)
	if e != nil {
		t.Fatal(e)
	}
	var mu sync.Mutex
	contacts := map[string]uint64{}
	histories := map[string]map[uint64]bool{}
	first := map[string]string{}
	culprit := false
	release := make(chan struct{})
	var releaseOnce sync.Once
	unblock := func() { releaseOnce.Do(func() { close(release) }) }
	blocked := make(chan struct{}, 1)
	servers := map[string]*httptest.Server{}
	for _, id := range cfg.Destinations {
		id := id
		histories[id] = map[uint64]bool{}
		servers[id] = httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			if id == "primary" {
				select {
				case blocked <- struct{}{}:
				default:
				}
				select {
				case <-release:
				case <-r.Context().Done():
					return
				}
			}
			var body struct {
				Reports []json.RawMessage `json:"reports"`
				Report  json.RawMessage   `json:"report"`
			}
			raw, readErr := io.ReadAll(io.LimitReader(r.Body, protocol.MaxBatchBytes+1))
			if readErr != nil {
				if r.Context().Err() == nil {
					t.Errorf("request read: %v", readErr)
				}
				return
			}
			if len(raw) > protocol.MaxBatchBytes {
				t.Error("oversize request")
			}
			if err := json.Unmarshal(raw, &body); err != nil {
				t.Errorf("request JSON: %v", err)
				w.WriteHeader(400)
				return
			}
			if len(body.Report) == 0 && len(body.Reports) == 0 {
				respondJSON(t, w, map[string]string{"nonce": strings.Repeat("n", 64), "expires_at": time.Now().Add(time.Minute).UTC().Format(time.RFC3339Nano)})
				return
			}
			mu.Lock()
			defer mu.Unlock()
			var accepted []protocol.Ack
			if len(body.Report) > 0 {
				p, _ := protocol.Decode(body.Report)
				contacts[id] = p.Sequence
				if first[id] == "" {
					first[id] = "contact"
				}
				accepted = append(accepted, protocol.Ack{StreamID: p.StreamID, Sequence: p.Sequence})
			} else {
				if first[id] == "" {
					first[id] = "replay"
				}
				if len(body.Reports) > 128 {
					t.Error("record limit")
				}
				for _, b := range body.Reports {
					p, _ := protocol.Decode(b)
					histories[id][p.Sequence] = true
					if p.Sequence == 600 && p.Processes.TopCPU.Items[0].Name == "culprit" {
						culprit = true
					}
					accepted = append(accepted, protocol.Ack{StreamID: p.StreamID, Sequence: p.Sequence})
				}
			}
			respondJSON(t, w, map[string]any{"accepted": accepted})
		}))
		defer servers[id].Close()
	}
	defer unblock()
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	var tasks sync.WaitGroup
	workers := map[string]*Worker{}
	for id, s := range servers {
		w := New(Destination{ID: id, URL: s.URL}, j, s.Client())
		workers[id] = w
		w.Publish(sample(count + 1))
		tasks.Add(1)
		go func() { defer tasks.Done(); w.Run(ctx) }()
	}
	<-blocked
	wait := func(f func() bool) {
		t.Helper()
		end := time.Now().Add(15 * time.Second)
		for time.Now().Before(end) {
			if f() {
				return
			}
			time.Sleep(10 * time.Millisecond)
		}
		for id, w := range workers {
			t.Logf("hub %s status %s cursor %d", id, w.Status(), j.Cursor(id))
		}
		t.Fatal("independent hubs timed out")
	}
	wait(func() bool { return j.Cursor("standby") >= count && j.Cursor("beta-restricted") >= count })
	mu.Lock()
	if contacts["primary"] != 0 || !culprit {
		t.Error("blocked hub/culprit proof")
	}
	mu.Unlock()
	if j.Cursor("primary") != 0 {
		t.Error("blocked hub advanced")
	}
	for n := uint64(count + 2); n <= count+5; n++ {
		b := sample(n)
		j.Append(b)
		for _, w := range workers {
			w.Publish(b)
		}
		time.Sleep(20 * time.Millisecond)
	}
	wait(func() bool {
		mu.Lock()
		defer mu.Unlock()
		return contacts["standby"] == count+5 && contacts["beta-restricted"] == count+5
	})
	unblock()
	wait(func() bool { return j.Cursor("primary") >= count })
	cancel()
	tasks.Wait()
	if e = j.Close(); e != nil {
		t.Fatal(e)
	}
	j, e = journal.Open(cfg)
	if e != nil {
		t.Fatal(e)
	}
	defer closeTestJournal(t, j)
	mu.Lock()
	defer mu.Unlock()
	for _, id := range cfg.Destinations {
		if first[id] != "contact" || len(histories[id]) < count || j.Cursor(id) < count {
			t.Fatalf("lost replay/progress %s %s %d %d", id, first[id], len(histories[id]), j.Cursor(id))
		}
	}
}

func TestEstablishedHubReconnectStartsWithFreshContact(t *testing.T) {
	var mu sync.Mutex
	online := true
	phase := 0
	var events []string
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		defer mu.Unlock()
		if !online {
			w.WriteHeader(503)
			return
		}
		var body struct {
			Reports []json.RawMessage `json:"reports"`
			Report  json.RawMessage   `json:"report"`
		}
		if !decodeRequest(t, w, r, &body) {
			return
		}
		if len(body.Reports) == 0 && len(body.Report) == 0 {
			respondJSON(t, w, map[string]string{"nonce": strings.Repeat("n", 64), "expires_at": time.Now().Add(time.Minute).UTC().Format(time.RFC3339Nano)})
			return
		}
		var accepted []protocol.Ack
		if len(body.Report) > 0 {
			p, _ := protocol.Decode(body.Report)
			if phase == 2 {
				events = append(events, "contact")
			}
			accepted = append(accepted, protocol.Ack{StreamID: p.StreamID, Sequence: p.Sequence})
		} else {
			if phase == 2 {
				events = append(events, "replay")
			}
			for _, b := range body.Reports {
				p, _ := protocol.Decode(b)
				accepted = append(accepted, protocol.Ack{StreamID: p.StreamID, Sequence: p.Sequence})
			}
		}
		respondJSON(t, w, map[string]any{"accepted": accepted})
	}))
	defer server.Close()
	j, e := journal.Open(journal.Config{Directory: t.TempDir(), Destinations: []string{"hub"}})
	if e != nil {
		t.Fatal(e)
	}
	defer closeTestJournal(t, j)
	j.Append(fixture(t, 1))
	w := New(Destination{ID: "hub", URL: server.URL}, j, server.Client())
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go w.Run(ctx)
	w.Publish(fixture(t, 10))
	wait := func(f func() bool) {
		t.Helper()
		end := time.Now().Add(3 * time.Second)
		for time.Now().Before(end) {
			if f() {
				return
			}
			time.Sleep(5 * time.Millisecond)
		}
		t.Fatal("phase timed out")
	}
	wait(func() bool { return j.Cursor("hub") == 1 })
	mu.Lock()
	online = false
	phase = 1
	mu.Unlock()
	j.Append(fixture(t, 2))
	w.Publish(fixture(t, 11))
	wait(func() bool { return w.Status() == "delivery_rejected" })
	time.Sleep(150 * time.Millisecond)
	mu.Lock()
	online = true
	phase = 2
	mu.Unlock()
	time.Sleep(1200 * time.Millisecond)
	mu.Lock()
	early := len(events)
	mu.Unlock()
	if early != 0 {
		t.Fatal("replay reconnected before new current contact")
	}
	w.Publish(fixture(t, 12))
	wait(func() bool { return j.Cursor("hub") == 2 })
	mu.Lock()
	defer mu.Unlock()
	if len(events) < 2 || events[0] != "contact" {
		t.Fatalf("reconnection order %v", events)
	}
}

func TestEvictedBacklogRemainsExplicitGapInCurrentEnvelope(t *testing.T) {
	const hub = "33333333-3333-4333-8333-333333333333"
	cfg := journal.Config{Directory: t.TempDir(), BudgetBytes: 160 << 10, SegmentBytes: 8 << 10, Destinations: []string{hub}}
	j, e := journal.Open(cfg)
	if e != nil {
		t.Fatal(e)
	}
	for n := uint64(1); n <= 40; n++ {
		if !j.Append(fixture(t, n)) {
			t.Fatal("queue full")
		}
		if n%16 == 0 {
			// Append is asynchronous with a 32-slot queue. Drain a bounded
			// batch before submitting more, independent of writer/CPU speed.
			closeTestJournal(t, j)
			j, e = journal.Open(cfg)
			if e != nil {
				t.Fatal(e)
			}
		}
	}
	closeTestJournal(t, j)
	j, e = journal.Open(cfg)
	if e != nil {
		t.Fatal(e)
	}
	defer closeTestJournal(t, j)
	if d := j.Delivery(); len(d.LostRanges) == 0 || d.LostRanges[0].Reason != "eviction" {
		t.Fatalf("fixture did not persist eviction: %#v", d)
	}
	seen := make(chan protocol.Delivery, 1)
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var body struct {
			Report   json.RawMessage   `json:"report"`
			Reports  []json.RawMessage `json:"reports"`
			Delivery protocol.Delivery `json:"delivery"`
		}
		if !decodeRequest(t, w, r, &body) {
			return
		}
		if len(body.Report) > 0 {
			p, _ := protocol.Decode(body.Report)
			select {
			case seen <- body.Delivery:
			default:
			}
			respondJSON(t, w, map[string]any{"accepted": []protocol.Ack{{StreamID: p.StreamID, Sequence: p.Sequence}}})
			return
		}
		if len(body.Reports) > 0 {
			var accepted []protocol.Ack
			for _, b := range body.Reports {
				p, _ := protocol.Decode(b)
				if p.Sequence == 1 {
					t.Error("evicted record invented")
				}
				accepted = append(accepted, protocol.Ack{StreamID: p.StreamID, Sequence: p.Sequence})
			}
			respondJSON(t, w, map[string]any{"accepted": accepted})
			return
		}
		respondJSON(t, w, map[string]string{"nonce": strings.Repeat("n", 64), "expires_at": time.Now().Add(time.Minute).UTC().Format(time.RFC3339Nano)})
	}))
	defer server.Close()
	worker := New(Destination{ID: hub, URL: server.URL}, j, server.Client())
	worker.Publish(fixture(t, 100))
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go worker.Run(ctx)
	select {
	case d := <-seen:
		if len(d.LostRanges) == 0 || d.LostRanges[0].FirstSequence != 1 || d.LostRanges[0].Reason != "eviction" || d.LostRanges[0].HubID != hub {
			t.Fatalf("gap not disclosed %#v", d)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("current envelope missing")
	}
}

func TestConfiguredStandardCONNECTProxyCarriesVerifiedHTTPS(t *testing.T) {
	target := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if strings.HasSuffix(r.URL.Path, "challenge") {
			respondJSON(t, w, map[string]string{"nonce": strings.Repeat("n", 64), "expires_at": time.Now().Add(time.Minute).UTC().Format(time.RFC3339Nano)})
			return
		}
		var b struct {
			Report json.RawMessage `json:"report"`
		}
		if !decodeRequest(t, w, r, &b) {
			return
		}
		p, _ := protocol.Decode(b.Report)
		respondJSON(t, w, map[string]any{"accepted": []protocol.Ack{{StreamID: p.StreamID, Sequence: p.Sequence}}})
	}))
	defer target.Close()
	var connects atomic.Int32
	proxy := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodConnect || r.Header.Get("Authorization") != "" {
			t.Error("CONNECT method or token privacy")
			w.WriteHeader(400)
			return
		}
		upstream, e := net.Dial("tcp", r.Host)
		if e != nil {
			w.WriteHeader(502)
			return
		}
		downstream, buffer, e := w.(http.Hijacker).Hijack()
		if e != nil {
			_ = upstream.Close() // No tunnel was established; release the unused socket.
			return
		}
		connects.Add(1)
		if _, err := buffer.WriteString("HTTP/1.1 200 Connection Established\r\n\r\n"); err != nil {
			t.Errorf("CONNECT response: %v", err)
			_ = upstream.Close()
			_ = downstream.Close()
			return
		}
		if err := buffer.Flush(); err != nil {
			t.Errorf("CONNECT flush: %v", err)
			_ = upstream.Close()
			_ = downstream.Close()
			return
		}
		// Tunnel shutdown races with worker cancellation. Either copy returning
		// closes both sockets; errors here are deliberate disconnection cleanup.
		go func() {
			defer func() { _ = upstream.Close(); _ = downstream.Close() }()
			_, _ = io.Copy(upstream, downstream)
		}()
		_, _ = io.Copy(downstream, upstream)
	}))
	defer proxy.Close()
	j, e := journal.Open(journal.Config{Directory: t.TempDir(), Destinations: []string{"hub"}})
	if e != nil {
		t.Fatal(e)
	}
	defer closeTestJournal(t, j)
	worker := New(Destination{ID: "hub", URL: target.URL, Token: "PRIVATE_TOKEN", ProxyURL: proxy.URL}, j, nil)
	// Trust only this test server's CA while retaining the production transport's
	// proxy, hostname verification and certificate-verification behavior.
	transport := worker.client.Transport.(*http.Transport)
	transport.TLSClientConfig = target.Client().Transport.(*http.Transport).TLSClientConfig.Clone()
	if transport.TLSClientConfig.InsecureSkipVerify {
		t.Fatal("TLS verification disabled")
	}
	defer transport.CloseIdleConnections()
	worker.Publish(fixture(t, 1))
	ctx, cancel := context.WithTimeout(context.Background(), time.Second)
	defer cancel()
	worker.Run(ctx)
	if connects.Load() == 0 || worker.Status() != "delivering" {
		t.Fatalf("proxy delivery %d %s", connects.Load(), worker.Status())
	}
}
