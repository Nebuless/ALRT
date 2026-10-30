package actions

import (
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"sync"
	"testing"
	"time"
)

func enrolled(t *testing.T) (*Helper, ed25519.PrivateKey, *fakeClock, string) {
	t.Helper()
	public, private, _ := ed25519.GenerateKey(rand.Reader)
	dir := t.TempDir()
	path := filepath.Join(dir, "consumed.json")
	if e := os.WriteFile(path, []byte(`{"version":1,"consumed":{}}`), 0600); e != nil {
		t.Fatal(e)
	}
	if e := os.WriteFile(filepath.Join(dir, "lock"), nil, 0600); e != nil {
		t.Fatal(e)
	}
	clock := &fakeClock{wall: time.Unix(2000000000, 0), elapsed: time.Second}
	h := &Helper{Policy: Policy{MachineID: "machine", BootID: "boot", Issuer: "primary", KeyID: "key", PublicKey: public, CredentialHash: credentialHash("local-only")}, Ledger: &Ledger{Path: path, ops: osFiles{}}, clock: clock}
	return h, private, clock, path
}

type fakeClock struct {
	wall    time.Time
	elapsed time.Duration
}

func (c *fakeClock) Now() (time.Time, time.Duration, error) { return c.wall, c.elapsed, nil }
func signed(t *testing.T, h *Helper, key ed25519.PrivateKey, p *Pending, mutate func(*Permit)) Envelope {
	t.Helper()
	permit := Permit{Version: 1, ActionID: "action", MachineID: "machine", BootID: "boot", Nonce: p.Nonce, Issuer: "primary", KeyID: "key", IssuedAt: 2000000000, ExpiresAt: 2000000010, ActionExpiresAt: 2000021600}
	if mutate != nil {
		mutate(&permit)
	}
	b, _ := json.Marshal(permit)
	return Envelope{Payload: base64.StdEncoding.EncodeToString(b), Signature: base64.StdEncoding.EncodeToString(ed25519.Sign(key, b))}
}
func TestAuthorityAndTrustedContext(t *testing.T) {
	cases := []string{"valid", "wrong_key", "machine", "boot", "issuer", "key_id", "nonce", "expired", "long_budget", "suspended", "restart", "credential"}
	for _, name := range cases {
		t.Run(name, func(t *testing.T) {
			h, key, c, _ := enrolled(t)
			p, e := h.Begin("local-only")
			if e != nil {
				t.Fatal(e)
			}
			envelope := signed(t, h, key, p, func(v *Permit) {
				switch name {
				case "machine":
					v.MachineID = "other"
				case "boot":
					v.BootID = "other"
				case "issuer":
					v.Issuer = "standby"
				case "key_id":
					v.KeyID = "other"
				case "nonce":
					v.Nonce = "other"
				case "expired":
					v.ExpiresAt = v.IssuedAt
				case "long_budget":
					v.ExpiresAt += 1
				}
			})
			switch name {
			case "wrong_key":
				_, key, _ = ed25519.GenerateKey(rand.Reader)
				envelope = signed(t, h, key, p, nil)
			case "suspended":
				c.elapsed += 11 * time.Second
			case "restart":
				p = &Pending{Nonce: p.Nonce}
			case "credential":
				_, e = h.Begin("wrong")
				if e == nil {
					t.Fatal("credential accepted")
				}
				return
			}
			count := 0
			e = h.Execute(p, envelope, func() error { count++; return nil })
			if name == "valid" {
				if e != nil || count != 1 {
					t.Fatalf("valid: %v %d", e, count)
				}
			} else if e == nil || count != 0 {
				t.Fatalf("accepted %s: %v %d", name, e, count)
			}
		})
	}
}
func TestConcurrentConsumptionAndRestartNeverRetry(t *testing.T) {
	h, key, _, path := enrolled(t)
	var wg sync.WaitGroup
	var mu sync.Mutex
	count := 0
	for i := 0; i < 12; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			p, e := h.Begin("local-only")
			if e != nil {
				t.Error(e)
				return
			}
			env := signed(t, h, key, p, nil)
			_ = h.Execute(p, env, func() error { mu.Lock(); count++; mu.Unlock(); return nil })
		}()
	}
	wg.Wait()
	if count != 1 {
		t.Fatalf("invocations %d", count)
	}
	h.Ledger = &Ledger{Path: path, ops: osFiles{}}
	p, _ := h.Begin("local-only")
	if h.Execute(p, signed(t, h, key, p, nil), func() error { count++; return nil }) == nil || count != 1 {
		t.Fatal("replayed after restart")
	}
}
func TestMissingCorruptLedgerFailsClosed(t *testing.T) {
	for _, kind := range []string{"missing", "corrupt", "truncated", "missing_lock"} {
		t.Run(kind, func(t *testing.T) {
			h, key, _, path := enrolled(t)
			switch kind {
			case "missing":
				_ = os.Remove(path)
			case "corrupt":
				_ = os.WriteFile(path, []byte(`{"version":1,"consumed":null}`), 0600)
			case "truncated":
				_ = os.WriteFile(path, []byte(`{"version":1,`), 0600)
			case "missing_lock":
				_ = os.Remove(filepath.Join(filepath.Dir(path), "lock"))
			}
			p, e := h.Begin("local-only")
			if e == nil {
				e = h.Execute(p, signed(t, h, key, p, nil), func() error { t.Fatal("invoked"); return nil })
			}
			if e == nil {
				t.Fatal("lost state accepted")
			}
		})
	}
}

// Inject disk/full, sync and crash failures at each durability operation. Each
// case reopens the same real files and retries, measuring the fixed adapter.
func TestEveryDurabilityBoundaryNeverDuplicates(t *testing.T) {
	for boundary := 1; boundary <= 8; boundary++ {
		t.Run(string(rune('A'+boundary)), func(t *testing.T) {
			h, key, _, path := enrolled(t)
			fs := &failingFiles{stop: boundary}
			h.Ledger.ops = fs
			p, _ := h.Begin("local-only")
			count := 0
			first := h.Execute(p, signed(t, h, key, p, nil), func() error { count++; return errors.New("crashed_after_invocation") })
			if fs.failed && count != 0 {
				t.Fatal("invoked after failed durability")
			}
			h.Ledger = &Ledger{Path: path, ops: osFiles{}}
			p, e := h.Begin("local-only")
			if e == nil {
				_ = h.Execute(p, signed(t, h, key, p, nil), func() error { count++; return nil })
			}
			if count > 1 {
				t.Fatalf("duplicate across boundary %d: %v", boundary, first)
			}
		})
	}
}

func TestShortActionBudgetUsesTrustedElapsedDespiteWallClockSkew(t *testing.T) {
	h, key, c, _ := enrolled(t)
	c.wall = time.Unix(1999999998, 0)
	p, e := h.Begin("local-only")
	if e != nil {
		t.Fatal(e)
	}
	envelope := signed(t, h, key, p, func(v *Permit) { v.ExpiresAt = 2000000003; v.ActionExpiresAt = 2000000003 })
	c.elapsed += 4 * time.Second
	c.wall = time.Unix(2000000002, 0)
	count := 0
	e = h.Execute(p, envelope, func() error { count++; return nil })
	if e == nil || count != 0 {
		t.Fatal("short signed request budget accepted after expiry under clock skew")
	}
}
