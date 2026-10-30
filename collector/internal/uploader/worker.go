// Package uploader keeps current contact and historical replay independently
// bounded for each destination. Journal IO cannot hold up the contact worker.
package uploader

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"time"

	"github.com/Nebuless/ALRT/collector/internal/journal"
	"github.com/Nebuless/ALRT/collector/internal/protocol"
)

type Destination struct {
	ID       string `json:"id"`
	URL      string `json:"url"`
	Token    string `json:"token"`
	ProxyURL string `json:"proxy_url,omitempty"`
}
type Worker struct {
	// OnContact is configured before Run and must remain nonblocking.
	OnContact   func(protocol.Report)
	destination Destination
	journal     *journal.Journal
	client      *http.Client
	current     chan []byte
	ready       chan struct{}
	mu          sync.Mutex
	status      string
	generation  uint64
	required    uint64
}

func New(d Destination, j *journal.Journal, client *http.Client) *Worker {
	if client == nil {
		transport := http.DefaultTransport.(*http.Transport).Clone()
		transport.Proxy = nil
		if d.ProxyURL != "" {
			proxy, _ := url.Parse(d.ProxyURL)
			transport.Proxy = http.ProxyURL(proxy)
		}
		client = &http.Client{Transport: transport, Timeout: 5 * time.Second}
	}
	copyClient := *client
	copyClient.Timeout = 5 * time.Second
	copyClient.CheckRedirect = func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }
	return &Worker{destination: d, journal: j, client: &copyClient, current: make(chan []byte, 1), ready: make(chan struct{}, 1), required: 1, status: "awaiting_contact"}
}
func (w *Worker) Publish(data []byte) {
	b := append([]byte(nil), data...)
	select {
	case w.current <- b:
	default:
		select {
		case <-w.current:
		default:
		}
		select {
		case w.current <- b:
		default:
		}
	}
}
func (w *Worker) Status() string { w.mu.Lock(); defer w.mu.Unlock(); return w.status }
func (w *Worker) setStatus(code string) {
	w.mu.Lock()
	if w.status == "protocol_version_unsupported" && code == "delivering" {
		w.mu.Unlock()
		return
	}
	w.status = code
	w.mu.Unlock()
}
func (w *Worker) Run(ctx context.Context) {
	replayDone := make(chan struct{})
	go func() { defer close(replayDone); w.replay(ctx) }()
	w.contacts(ctx)
	// A filesystem read can remain blocked in the kernel. Each destination owns
	// at most one replay operation; cancellation never waits on that operation.
	select {
	case <-replayDone:
	case <-ctx.Done():
	}
}
func (w *Worker) post(ctx context.Context, path string, body any, result any) error {
	b, e := json.Marshal(body)
	if e != nil || len(b) > protocol.MaxBatchBytes {
		return errors.New("batch_too_large")
	}
	req, e := http.NewRequestWithContext(ctx, http.MethodPost, strings.TrimRight(w.destination.URL, "/")+path, bytes.NewReader(b))
	if e != nil {
		return errors.New("delivery_unavailable")
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+w.destination.Token)
	response, e := w.client.Do(req)
	if e != nil {
		return errors.New("delivery_unavailable")
	}
	defer func() { _ = response.Body.Close() }() // A read-only response close cannot invalidate the parsed committed ACK.
	b, e = io.ReadAll(io.LimitReader(response.Body, 32<<10+1))
	if e != nil || len(b) > 32<<10 {
		return errors.New("invalid_ack")
	}
	if response.StatusCode != 200 && response.StatusCode != 201 {
		var failure struct {
			Error string `json:"error"`
		}
		if json.Unmarshal(b, &failure) != nil {
			return errors.New("delivery_rejected")
		}
		if failure.Error == "protocol_version_unsupported" {
			return errors.New("protocol_version_unsupported")
		}
		return errors.New("delivery_rejected")
	}
	if json.Unmarshal(b, result) != nil {
		return errors.New("invalid_ack")
	}
	return nil
}
func prefix(sent []journal.Record, accepted []protocol.Ack) int {
	if len(accepted) > len(sent) {
		return 0
	}
	for i, a := range accepted {
		if a.StreamID != sent[i].StreamID || a.Sequence != sent[i].Sequence {
			return i
		}
	}
	return len(accepted)
}
func (w *Worker) contacts(ctx context.Context) {
	for {
		select {
		case <-ctx.Done():
			return
		case b := <-w.current:
			r, e := protocol.Decode(b)
			if e != nil {
				w.setStatus("invalid_report")
				continue
			}
			base := "/api/v1/collector/" + url.PathEscape(r.MachineID)
			var challenge struct {
				Nonce     string `json:"nonce"`
				ExpiresAt string `json:"expires_at"`
			}
			started := time.Now()
			e = w.post(ctx, base+"/challenge", struct{}{}, &challenge)
			if e == nil && len(challenge.Nonce) != 64 {
				e = errors.New("invalid_ack")
			}
			if e == nil {
				expires, err := time.Parse(time.RFC3339Nano, challenge.ExpiresAt)
				if err != nil || !expires.After(time.Now()) {
					e = errors.New("invalid_ack")
				}
			}
			if e == nil {
				var ack struct {
					Accepted  []protocol.Ack `json:"accepted"`
					ContactAt string         `json:"contact_at"`
				}
				e = w.post(ctx, base+"/contact", struct {
					Nonce    string            `json:"nonce"`
					Report   json.RawMessage   `json:"report"`
					Delivery protocol.Delivery `json:"delivery"`
				}{challenge.Nonce, b, w.journal.Delivery()}, &ack)
				if e == nil {
					if len(ack.Accepted) != 1 || ack.Accepted[0].StreamID != r.StreamID || ack.Accepted[0].Sequence != r.Sequence || time.Since(started) > 10*time.Second {
						e = errors.New("invalid_ack")
					} else {
						w.note(ack.Accepted[0])
						if w.OnContact != nil {
							w.OnContact(r)
						}
						w.mu.Lock()
						w.generation++
						w.mu.Unlock()
						select {
						case w.ready <- struct{}{}:
						default:
						}
					}
				}
			}
			if e != nil {
				w.offline()
				w.setStatus(e.Error())
			} else {
				w.setStatus("delivering")
			}
		}
	}
}
func (w *Worker) note(a protocol.Ack) {
	w.journal.NoteACK(protocol.Acknowledgement{HubID: w.destination.ID, StreamID: a.StreamID, Sequence: a.Sequence, AcknowledgedAt: time.Now().UTC().Format(time.RFC3339Nano)})
}
func pause(ctx context.Context, d time.Duration) bool {
	timer := time.NewTimer(d)
	defer timer.Stop()
	select {
	case <-ctx.Done():
		return false
	case <-timer.C:
		return true
	}
}
func (w *Worker) replay(ctx context.Context) {

	backoff := time.Second
	for {
		if !w.awaitContact(ctx) {
			return
		}
		records, e := w.journal.Batch(w.destination.ID)
		if e != nil {
			w.setStatus(e.Error())
		}
		if e == nil && len(records) > 0 {
			var report protocol.Report
			report, e = protocol.Decode(records[0].Data)
			if e != nil {
				w.setStatus("invalid_report")
				if !pause(ctx, backoff) {
					return
				}
				continue
			}
			payload := make([]json.RawMessage, len(records))
			for i, r := range records {
				payload[i] = r.Data
			}
			var ack struct {
				Accepted []protocol.Ack `json:"accepted"`
			}
			e = w.post(ctx, "/api/v1/collector/"+url.PathEscape(report.MachineID)+"/reports", struct {
				Reports []json.RawMessage `json:"reports"`
			}{payload}, &ack)
			if e == nil {
				n := prefix(records, ack.Accepted)
				if n > 0 {
					w.note(ack.Accepted[n-1])
					if !w.journal.Commit(w.destination.ID, records[:n]) {
						e = errors.New("journal_busy")
					}
				} else {
					e = errors.New("invalid_ack")
				}
			}
		}
		if e != nil {
			w.offline()
			w.setStatus(e.Error())
			if !pause(ctx, backoff) {
				return
			}
			backoff *= 2
			if backoff > 30*time.Second {
				backoff = 30 * time.Second
			}
		} else {
			backoff = time.Second
			if !pause(ctx, 100*time.Millisecond) {
				return
			}
		}
	}
}

func (w *Worker) offline() { w.mu.Lock(); w.required = w.generation + 1; w.mu.Unlock() }
func (w *Worker) awaitContact(ctx context.Context) bool {
	for {
		w.mu.Lock()
		ready := w.generation >= w.required
		w.mu.Unlock()
		if ready {
			return true
		}
		select {
		case <-ctx.Done():
			return false
		case <-w.ready:
		}
	}
}
