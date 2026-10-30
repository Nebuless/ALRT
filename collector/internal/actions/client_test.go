package actions

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

type fakeLocal struct {
	h              *Helper
	key            []byte
	calls          int
	permitRequests int
}

func (f *fakeLocal) Probe(context.Context) error { return f.h.Ledger.Check() }
func (f *fakeLocal) Attempt(ctx context.Context, request func(string) (Envelope, error)) (string, error) {
	p, e := f.h.Begin("local-only")
	if e != nil {
		return "unknown", e
	}
	envelope, e := request(p.Nonce)
	if e != nil {
		return "unknown", e
	}
	e = f.h.Execute(p, envelope, func() error { f.calls++; return nil })
	if e != nil {
		return "unknown", e
	}
	return "attempted", nil
}
func TestClientReceiptACKPermitAndLostResult(t *testing.T) {
	h, key, _, _ := enrolled(t)
	local := &fakeLocal{h: h, key: key}
	action := Action{ID: "22222222-2222-4222-8222-222222222222", MachineID: "machine", BootID: "boot", State: "awaiting_primary", ExpiresAt: time.Now().Add(time.Hour).UTC().Format(time.RFC3339)}
	r := &Receipts{Directory: t.TempDir()}
	acks := 0
	results := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, req *http.Request) {
		if req.Header.Get("Authorization") != "Bearer test-token" {
			t.Error("missing auth")
		}
		switch req.URL.Path {
		case "/api/v1/collector/machine/actions/pickup":
			_ = json.NewEncoder(w).Encode(map[string]any{"action": action})
		case "/api/v1/collector/machine/actions/" + action.ID + "/ack":
			if _, e := r.Read(action.ID); e != nil {
				t.Error("ACK before receipt")
			}
			acks++
			_ = json.NewEncoder(w).Encode(map[string]any{"action": action})
		case "/api/v1/collector/machine/actions/" + action.ID + "/permit":
			var body struct {
				Nonce string `json:"nonce"`
			}
			_ = json.NewDecoder(req.Body).Decode(&body)
			local.permitRequests++
			p := &Pending{Nonce: body.Nonce}
			_ = json.NewEncoder(w).Encode(signed(t, h, key, p, func(v *Permit) { v.ActionID = action.ID }))
		case "/api/v1/collector/machine/actions/" + action.ID + "/result":
			results++
			w.WriteHeader(503)
		default:
			t.Error("unexpected path")
			w.WriteHeader(404)
		}
	}))
	defer server.Close()
	c := &Client{MachineID: "machine", URL: server.URL, Token: "test-token", HTTP: server.Client(), Receipts: r, Helper: local}
	_ = c.Step(context.Background(), "boot")
	_ = c.Step(context.Background(), "boot")
	if local.calls != 1 || local.permitRequests != 1 || acks != 1 || results != 2 {
		t.Fatalf("counts invoked=%d permits=%d ACKs=%d results=%d", local.calls, local.permitRequests, acks, results)
	}
}
