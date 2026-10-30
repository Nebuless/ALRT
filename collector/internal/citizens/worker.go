package citizens

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"net"
	"net/http"
	"net/url"
	"strings"
	"time"

	"github.com/Nebuless/ALRT/collector/internal/uploader"
)

type challenge struct {
	Nonce     string `json:"nonce"`
	ExpiresAt string `json:"expires_at"`
}
type commandResults struct {
	Results []Result `json:"results"`
}

// Worker owns one destination, one in-flight request and at most 64 pending
// results. Run and Step must not be called concurrently.
type Worker struct {
	boot, machine string
	destination   uploader.Destination
	primary       bool
	local, hub    *http.Client
	pending       []Result
	seen          map[string]bool
}

func New(socket, boot, machine string, d uploader.Destination, primary bool, client *http.Client) *Worker {
	local := &http.Transport{DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
		return (&net.Dialer{}).DialContext(ctx, "unix", socket)
	}}
	if client == nil {
		transport := http.DefaultTransport.(*http.Transport).Clone()
		transport.Proxy = nil
		if d.ProxyURL != "" {
			proxy, _ := url.Parse(d.ProxyURL)
			transport.Proxy = http.ProxyURL(proxy)
		}
		client = &http.Client{Transport: transport}
	}
	hub := *client
	hub.Timeout = 5 * time.Second
	hub.CheckRedirect = func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }
	return &Worker{boot: boot, machine: machine, destination: d, primary: primary, local: &http.Client{Transport: local, Timeout: 5 * time.Second, CheckRedirect: hub.CheckRedirect}, hub: &hub, pending: []Result{}, seen: map[string]bool{}}
}

func request(ctx context.Context, client *http.Client, method, endpoint, token string, body, out any) error {
	ctx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()
	var payload []byte
	if body != nil {
		var e error
		payload, e = json.Marshal(body)
		if e != nil || len(payload) > maxBytes {
			return errInvalid
		}
	}
	req, e := http.NewRequestWithContext(ctx, method, endpoint, bytes.NewReader(payload))
	if e != nil {
		return errors.New("citizens_unavailable")
	}
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	if token != "" {
		req.Header.Set("Authorization", "Bearer "+token)
	}
	r, e := client.Do(req)
	if e != nil {
		return errors.New("citizens_unavailable")
	}
	defer func() { _ = r.Body.Close() }()
	_, isChallenge := out.(*challenge)
	if r.StatusCode != http.StatusOK && (!isChallenge || r.StatusCode != http.StatusCreated) {
		return errors.New("citizens_rejected")
	}
	b, e := io.ReadAll(io.LimitReader(r.Body, maxBytes+1))
	if e != nil {
		return errInvalid
	}
	return decode(b, out)
}

func (w *Worker) Run(ctx context.Context) {
	defer w.local.CloseIdleConnections()
	defer w.hub.CloseIdleConnections()
	ticker := time.NewTicker(2 * time.Second)
	defer ticker.Stop()
	for {
		if ctx.Err() != nil {
			return
		}
		_ = w.Step(ctx)
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
		}
	}
}

func (w *Worker) Step(ctx context.Context) error {
	var s Snapshot
	if e := request(ctx, w.local, http.MethodGet, "http://unix/snapshot", "", nil, &s); e != nil {
		return e
	}
	if !s.validate(w.boot, time.Now()) {
		return errInvalid
	}
	base := strings.TrimRight(w.destination.URL, "/") + "/api/v1/collector/" + url.PathEscape(w.machine) + "/citizens"
	var c challenge
	if e := request(ctx, w.hub, http.MethodPost, base+"/challenge", w.destination.Token, struct{}{}, &c); e != nil {
		return e
	}
	expires, e := time.Parse(time.RFC3339Nano, c.ExpiresAt)
	if e != nil || len(c.ExpiresAt) > 40 || !nonce.MatchString(c.Nonce) || !expires.After(time.Now()) || expires.After(time.Now().Add(10*time.Second)) || !s.validate(w.boot, time.Now()) {
		return errInvalid
	}
	var ack acknowledgement
	if e = request(ctx, w.hub, http.MethodPost, base, w.destination.Token, Exchange{Nonce: c.Nonce, Snapshot: s, Results: w.pending}, &ack); e != nil {
		return e
	}
	if !ack.Accepted {
		return errInvalid
	}
	ids := map[string]bool{}
	for _, cmd := range ack.Commands {
		if !cmd.valid() || ids[cmd.ID] {
			return errInvalid
		}
		ids[cmd.ID] = true
	}
	w.pending = []Result{}
	if !w.primary {
		return nil
	}
	commands := []Command{}
	for _, cmd := range ack.Commands {
		if w.seen[cmd.ID] {
			continue
		}
		// Bridge receipts are capped at 10000; stop issuing new actions at the
		// same ceiling instead of evicting unknown outcomes and retrying them.
		if len(w.seen) >= 10000 {
			return errors.New("citizens_action_capacity")
		}
		w.seen[cmd.ID] = true
		status := "identity_changed"
		expires, _ := time.Parse(time.RFC3339Nano, cmd.ExpiresAt)
		now := time.Now()
		if !expires.After(now) || expires.After(now.Add(5*time.Second)) {
			status = "expired"
		} else if cmd.BootID == s.BootID && cmd.BridgeID == s.BridgeID {
			for _, row := range s.Citizens {
				if row.ID == cmd.CitizenID && row.Process != nil && row.Process.PID == cmd.PID && row.Process.StartTicks == cmd.StartTicks && row.CanTerminate && fresh(row.ObservedAt, now, 10*time.Second) {
					status = ""
					break
				}
			}
		}
		if status != "" {
			w.pending = append(w.pending, Result{ID: cmd.ID, Status: status})
		} else {
			commands = append(commands, cmd)
		}
	}
	if len(commands) == 0 {
		return nil
	}
	// Fence uncertain local delivery in memory before POST. Only the bridge
	// owns durable attempts; transport never automatically repeats unknown.
	start := len(w.pending)
	for _, cmd := range commands {
		w.pending = append(w.pending, Result{ID: cmd.ID, Status: "unknown"})
	}
	var results commandResults
	if e = request(ctx, w.local, http.MethodPost, "http://unix/commands", "", struct {
		Commands []Command `json:"commands"`
	}{commands}, &results); e != nil {
		return e
	}
	if len(results.Results) != len(commands) {
		return errInvalid
	}
	for i, r := range results.Results {
		if !r.valid() || r.ID != commands[i].ID {
			return errInvalid
		}
	}
	copy(w.pending[start:], results.Results)
	return nil
}
