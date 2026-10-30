package citizens

import (
	"context"
	"encoding/json"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/Nebuless/ALRT/collector/internal/uploader"
)

const boot = "11111111-1111-4111-8111-111111111111"
const bridge = "22222222-2222-4222-8222-222222222222"
const action = "33333333-3333-4333-8333-333333333333"

func snapshot() Snapshot {
	now := time.Now().UTC().Format(time.RFC3339Nano)
	return Snapshot{BootID: boot, BridgeID: bridge, Sequence: 1, SampledAt: now, Coverage: "complete", Citizens: []Citizen{{ID: strings.Repeat("a", 64), Tool: "pi", State: "working", ObservedAt: now, RuntimeID: strings.Repeat("b", 64), Attribution: "dedicated", Process: &Process{BootID: boot, PID: 123, StartTicks: 456}, CanTerminate: true}}}
}

func localServer(t *testing.T, handler http.HandlerFunc) string {
	t.Helper()
	path := filepath.Join(t.TempDir(), "bridge.sock")
	l, err := net.Listen("unix", path)
	if err != nil {
		t.Fatal(err)
	}
	s := httptest.NewUnstartedServer(handler)
	_ = s.Listener.Close()
	s.Listener = l
	s.Start()
	t.Cleanup(s.Close)
	return path
}

func reply(w http.ResponseWriter, value any) { _ = json.NewEncoder(w).Encode(value) }

func TestExchangeRetainsResultsUntilAcceptedAndDoesNotRepeatUnknown(t *testing.T) {
	s := snapshot()
	localCalls := 0
	socket := localServer(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/snapshot" {
			reply(w, s)
			return
		}
		if r.URL.Path != "/commands" {
			t.Error(r.URL.Path)
		}
		localCalls++
		var body struct {
			Commands []Command `json:"commands"`
		}
		if json.NewDecoder(r.Body).Decode(&body) != nil || len(body.Commands) != 1 {
			t.Error("invalid command batch")
		}
		reply(w, map[string]any{"results": []Result{{ID: action, Status: "unknown"}}})
	})
	steps := 0
	var sent []Exchange
	hub := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer token" {
			t.Error("bearer missing")
		}
		if strings.HasSuffix(r.URL.Path, "/challenge") {
			w.WriteHeader(http.StatusCreated)
			reply(w, map[string]string{"nonce": strings.Repeat("Z9", 32), "expires_at": time.Now().Add(9 * time.Second).UTC().Format(time.RFC3339Nano)})
			return
		}
		var body Exchange
		if json.NewDecoder(r.Body).Decode(&body) != nil {
			t.Error("invalid exchange")
		}
		sent = append(sent, body)
		steps++
		if steps == 2 {
			w.WriteHeader(503)
			return
		}
		reply(w, map[string]any{"accepted": true, "commands": []Command{{ID: action, CitizenID: s.Citizens[0].ID, BootID: boot, BridgeID: bridge, PID: 123, StartTicks: 456, ExpiresAt: time.Now().Add(4 * time.Second).UTC().Format(time.RFC3339Nano)}}})
	}))
	defer hub.Close()
	w := New(socket, boot, "machine", uploader.Destination{URL: hub.URL, Token: "token"}, true, hub.Client())
	for i := 0; i < 4; i++ {
		err := w.Step(context.Background())
		if (i == 1) != (err != nil) {
			t.Fatalf("step %d: %v", i, err)
		}
	}
	if localCalls != 1 || len(sent[1].Results) != 1 || len(sent[2].Results) != 1 || len(sent[3].Results) != 0 {
		t.Fatalf("result retention/repeat: calls=%d exchanges=%+v", localCalls, sent)
	}
	if sent[0].SampledAt != s.SampledAt || sent[0].Citizens[0].ObservedAt != s.Citizens[0].ObservedAt {
		t.Fatal("source age changed")
	}
}

func TestSnapshotPrivacyValidationBeforeHubRequest(t *testing.T) {
	for _, kind := range []string{"secret", "row_secret", "boot", "stale", "coverage", "id", "cpu", "rss", "too_large", "trailing"} {
		t.Run(kind, func(t *testing.T) {
			s := snapshot()
			b, _ := json.Marshal(s)
			switch kind {
			case "secret":
				b = append(b[:len(b)-1], []byte(`,"prompt":"CANARY_SECRET"}`)...)
			case "row_secret":
				b = []byte(strings.Replace(string(b), `"tool":"pi"`, `"tool":"pi","cwd":"CANARY_SECRET"`, 1))
			case "boot":
				s.BootID = bridge
				b, _ = json.Marshal(s)
			case "stale":
				s.SampledAt = time.Now().Add(-11 * time.Second).UTC().Format(time.RFC3339Nano)
				b, _ = json.Marshal(s)
			case "coverage":
				s.Coverage = "CANARY_SECRET"
				b, _ = json.Marshal(s)
			case "id":
				s.Citizens[0].ID = "CANARY_SECRET"
				b, _ = json.Marshal(s)
			case "cpu":
				f := -1.0
				s.Citizens[0].CPUPercent = &f
				b, _ = json.Marshal(s)
			case "rss":
				n := int64(-1)
				s.Citizens[0].RSSBytes = &n
				b, _ = json.Marshal(s)
			case "too_large":
				b = []byte(strings.Repeat(" ", 256<<10+1))
			case "trailing":
				b = append(b, []byte(` {}`)...)
			}
			socket := localServer(t, func(w http.ResponseWriter, r *http.Request) { _, _ = w.Write(b) })
			hub := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { t.Error("invalid snapshot reached hub") }))
			defer hub.Close()
			w := New(socket, boot, "machine", uploader.Destination{URL: hub.URL}, true, hub.Client())
			if err := w.Step(context.Background()); err == nil || strings.Contains(err.Error(), "CANARY") {
				t.Fatalf("unsafe validation: %v", err)
			}
		})
	}
}

func TestCommandsFailClosed(t *testing.T) {
	for _, kind := range []string{"standby", "boot", "bridge", "expired", "long_expiry", "pid", "secret", "too_many", "missing", "duplicate"} {
		t.Run(kind, func(t *testing.T) {
			s := snapshot()
			socket := localServer(t, func(w http.ResponseWriter, r *http.Request) {
				if r.URL.Path != "/snapshot" {
					t.Error("unsafe command reached bridge")
				}
				reply(w, s)
			})
			cmd := Command{ID: action, CitizenID: s.Citizens[0].ID, BootID: boot, BridgeID: bridge, PID: 123, StartTicks: 456, ExpiresAt: time.Now().Add(4 * time.Second).UTC().Format(time.RFC3339Nano)}
			switch kind {
			case "boot":
				cmd.BootID = bridge
			case "bridge":
				cmd.BridgeID = boot
			case "expired":
				cmd.ExpiresAt = time.Now().Add(-time.Second).UTC().Format(time.RFC3339Nano)
			case "long_expiry":
				cmd.ExpiresAt = time.Now().Add(6 * time.Second).UTC().Format(time.RFC3339Nano)
			case "pid":
				cmd.PID++
			}
			hub := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				if strings.HasSuffix(r.URL.Path, "/challenge") {
					reply(w, challenge{strings.Repeat("a", 64), time.Now().Add(9 * time.Second).UTC().Format(time.RFC3339Nano)})
					return
				}
				commands := []Command{cmd}
				if kind == "too_many" {
					commands = make([]Command, 65)
				}
				b, _ := json.Marshal(acknowledgement{Accepted: true, Commands: commands})
				if kind == "secret" {
					b = []byte(strings.Replace(string(b), `"pid":123`, `"pid":123,"prompt":"CANARY_SECRET"`, 1))
				}
				if kind == "missing" {
					b = []byte(strings.Replace(string(b), `"pid":123,`, "", 1))
				}
				if kind == "duplicate" {
					b = []byte(strings.Replace(string(b), `"pid":123`, `"pid":123,"pid":124`, 1))
				}
				_, _ = w.Write(b)
			}))
			defer hub.Close()
			w := New(socket, boot, "machine", uploader.Destination{URL: hub.URL}, kind != "standby", hub.Client())
			err := w.Step(context.Background())
			if oneOf(kind, "secret", "too_many", "missing", "duplicate") {
				if err == nil {
					t.Fatal("invalid command accepted")
				}
				return
			}
			if err != nil {
				t.Fatal(err)
			}
			if kind == "standby" {
				if len(w.pending) != 0 || len(w.seen) != 0 {
					t.Fatal("standby consumed actions")
				}
				return
			}
			want := "identity_changed"
			if kind == "expired" || kind == "long_expiry" {
				want = "expired"
			}
			if len(w.pending) != 1 || w.pending[0].Status != want {
				t.Fatalf("outcome %+v", w.pending)
			}
		})
	}
}

func TestHubRejectionsRedirectsAndResponseLimits(t *testing.T) {
	for _, kind := range []string{"401", "503", "redirect", "oversize", "secret", "nonce", "expired", "unaccepted"} {
		t.Run(kind, func(t *testing.T) {
			s := snapshot()
			socket := localServer(t, func(w http.ResponseWriter, r *http.Request) { reply(w, s) })
			hub := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				switch kind {
				case "401":
					w.WriteHeader(401)
					return
				case "503":
					w.WriteHeader(503)
					return
				case "redirect":
					http.Redirect(w, r, "/CANARY_SECRET", http.StatusTemporaryRedirect)
					if r.URL.Path == "/CANARY_SECRET" {
						t.Error("redirect followed")
					}
					return
				case "oversize":
					_, _ = io.WriteString(w, strings.Repeat("x", maxBytes+1))
					return
				case "secret":
					_, _ = io.WriteString(w, `{"nonce":"x","expires_at":"x","token":"CANARY_SECRET"}`)
					return
				}
				if strings.HasSuffix(r.URL.Path, "/challenge") {
					c := challenge{strings.Repeat("a", 64), time.Now().Add(9 * time.Second).UTC().Format(time.RFC3339Nano)}
					if kind == "nonce" {
						c.Nonce = "CANARY_SECRET"
					}
					if kind == "expired" {
						c.ExpiresAt = time.Now().Add(-time.Second).UTC().Format(time.RFC3339Nano)
					}
					reply(w, c)
					return
				}
				reply(w, acknowledgement{Commands: []Command{}})
			}))
			defer hub.Close()
			w := New(socket, boot, "machine", uploader.Destination{URL: hub.URL}, true, hub.Client())
			if err := w.Step(context.Background()); err == nil || strings.Contains(err.Error(), "CANARY") {
				t.Fatalf("rejection %v", err)
			}
		})
	}
}

func TestLocalFailureAndUncertainDeliveryNeverRepeat(t *testing.T) {
	s := snapshot()
	calls := 0
	socket := localServer(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/snapshot" {
			reply(w, s)
			return
		}
		calls++
		w.WriteHeader(503)
	})
	hub := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if strings.HasSuffix(r.URL.Path, "/challenge") {
			reply(w, challenge{strings.Repeat("a", 64), time.Now().Add(9 * time.Second).UTC().Format(time.RFC3339Nano)})
			return
		}
		reply(w, acknowledgement{Accepted: true, Commands: []Command{{ID: action, CitizenID: s.Citizens[0].ID, BootID: boot, BridgeID: bridge, PID: 123, StartTicks: 456, ExpiresAt: time.Now().Add(4 * time.Second).UTC().Format(time.RFC3339Nano)}}})
	}))
	defer hub.Close()
	w := New(socket, boot, "machine", uploader.Destination{URL: hub.URL}, true, hub.Client())
	if w.Step(context.Background()) == nil || len(w.pending) != 1 || w.pending[0].Status != "unknown" {
		t.Fatal("uncertain local request not fenced")
	}
	if err := w.Step(context.Background()); err != nil {
		t.Fatal(err)
	}
	if calls != 1 {
		t.Fatal("uncertain command repeated")
	}
	missing := New(filepath.Join(t.TempDir(), "absent.sock"), boot, "machine", uploader.Destination{URL: hub.URL}, true, hub.Client())
	if missing.Step(context.Background()) == nil {
		t.Fatal("missing bridge accepted")
	}
}

func await(t *testing.T, signal <-chan struct{}) {
	t.Helper()
	select {
	case <-signal:
	case <-time.After(3 * time.Second):
		t.Fatal("event deadline")
	}
}

func TestWorkersIndependentAndCancellationBounded(t *testing.T) {
	s := snapshot()
	socket := localServer(t, func(w http.ResponseWriter, r *http.Request) { reply(w, s) })
	entered := make(chan struct{})
	release := make(chan struct{})
	done := make(chan struct{})
	slow := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		close(entered)
		select {
		case <-r.Context().Done():
		case <-release:
		}
	}))
	defer slow.Close()
	defer close(release)
	fast := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if strings.HasSuffix(r.URL.Path, "/challenge") {
			reply(w, challenge{strings.Repeat("a", 64), time.Now().Add(9 * time.Second).UTC().Format(time.RFC3339Nano)})
			return
		}
		reply(w, acknowledgement{Accepted: true, Commands: []Command{}})
	}))
	defer fast.Close()
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	w := New(socket, boot, "machine", uploader.Destination{URL: slow.URL}, false, slow.Client())
	go func() { defer close(done); w.Run(ctx) }()
	await(t, entered)
	f := New(socket, boot, "machine", uploader.Destination{URL: fast.URL}, false, fast.Client())
	if err := f.Step(ctx); err != nil {
		t.Fatal(err)
	}
	cancel()
	await(t, done)
}

func TestLocalTimeoutAndCancellation(t *testing.T) {
	for _, kind := range []string{"timeout", "cancel"} {
		t.Run(kind, func(t *testing.T) {
			entered := make(chan struct{})
			release := make(chan struct{})
			defer close(release)
			socket := localServer(t, func(w http.ResponseWriter, r *http.Request) {
				close(entered)
				select {
				case <-release:
				case <-r.Context().Done():
				}
			})
			w := New(socket, boot, "machine", uploader.Destination{URL: "https://unused.invalid"}, true, nil)
			ctx, cancel := context.WithCancel(context.Background())
			defer cancel()
			if kind == "timeout" {
				ctx, cancel = context.WithTimeout(ctx, 100*time.Millisecond)
				defer cancel()
			}
			done := make(chan struct{})
			go func() {
				defer close(done)
				if err := w.Step(ctx); err == nil {
					t.Error("blocked local request accepted")
				}
			}()
			await(t, entered)
			if kind == "cancel" {
				cancel()
			}
			await(t, done)
		})
	}
}

func TestConfiguredProxyUsedWithoutEnvironmentFallback(t *testing.T) {
	s := snapshot()
	socket := localServer(t, func(w http.ResponseWriter, r *http.Request) { reply(w, s) })
	var connects atomic.Int32
	proxy := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != "CONNECT" {
			t.Error("proxy request not CONNECT")
		}
		connects.Add(1)
		w.WriteHeader(502)
	}))
	defer proxy.Close()
	t.Setenv("HTTPS_PROXY", proxy.URL)
	hub := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if strings.HasSuffix(r.URL.Path, "/challenge") {
			reply(w, challenge{strings.Repeat("a", 64), time.Now().Add(9 * time.Second).UTC().Format(time.RFC3339Nano)})
			return
		}
		reply(w, acknowledgement{Accepted: true, Commands: []Command{}})
	}))
	defer hub.Close()
	// Production constructor uses configured proxy, even for loopback targets.
	w := New(socket, boot, "machine", uploader.Destination{URL: hub.URL, ProxyURL: proxy.URL}, false, nil)
	if w.Step(context.Background()) == nil || connects.Load() != 1 {
		t.Fatal("configured proxy bypassed")
	}
	// A trusted TLS client with production's no-proxy transport proves ambient
	// HTTPS_PROXY cannot divert an unproxied destination.
	w = New(socket, boot, "machine", uploader.Destination{URL: hub.URL}, false, nil)
	w.hub.Transport.(*http.Transport).TLSClientConfig = hub.Client().Transport.(*http.Transport).TLSClientConfig.Clone()
	if err := w.Step(context.Background()); err != nil || connects.Load() != 1 {
		t.Fatalf("ambient proxy used: %v", err)
	}
}
