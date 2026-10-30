package actions

import (
	"bytes"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"sync"
	"time"
)

type Permit struct {
	Version         int    `json:"version"`
	ActionID        string `json:"action_id"`
	MachineID       string `json:"machine_id"`
	BootID          string `json:"boot_id"`
	Nonce           string `json:"nonce"`
	Issuer          string `json:"issuer"`
	KeyID           string `json:"key_id"`
	IssuedAt        int64  `json:"issued_at"`
	ExpiresAt       int64  `json:"expires_at"`
	ActionExpiresAt int64  `json:"action_expires_at"`
}
type Envelope struct {
	Payload   string `json:"payload"`
	Signature string `json:"signature"`
}
type Policy struct {
	MachineID      string            `json:"machine_id"`
	MachineName    string            `json:"machine_name"`
	BootID         string            `json:"-"`
	Issuer         string            `json:"issuer"`
	KeyID          string            `json:"key_id"`
	PublicKey      ed25519.PublicKey `json:"public_key"`
	CredentialHash string            `json:"credential_hash"`
	CollectorUID   int               `json:"collector_uid"`
}
type Clock interface {
	Now() (time.Time, time.Duration, error)
}
type Helper struct {
	Policy Policy
	Ledger *Ledger
	clock  Clock
}
type Pending struct {
	Nonce   string `json:"nonce"`
	started time.Duration
	owner   *Helper
	mu      sync.Mutex
	used    bool
}

func credentialHash(s string) string {
	sum := sha256.Sum256([]byte(s))
	return hex.EncodeToString(sum[:])
}
func (h *Helper) Begin(credential string) (*Pending, error) {
	if credential == "" || subtle.ConstantTimeCompare([]byte(credentialHash(credential)), []byte(h.Policy.CredentialHash)) != 1 {
		return nil, errors.New("credential_refused")
	}
	if h.Ledger == nil || h.Ledger.Check() != nil {
		return nil, errState
	}
	c := h.clock
	if c == nil {
		c = bootClock{}
	}
	_, elapsed, e := c.Now()
	if e != nil {
		return nil, errors.New("clock_unavailable")
	}
	var n [32]byte
	if _, e = rand.Read(n[:]); e != nil {
		return nil, errors.New("nonce_unavailable")
	}
	return &Pending{Nonce: hex.EncodeToString(n[:]), started: elapsed, owner: h}, nil
}
func (h *Helper) validTime(p *Pending, v Permit) bool {
	c := h.clock
	if c == nil {
		c = bootClock{}
	}
	now, elapsed, e := c.Now()
	return e == nil && elapsed >= p.started && elapsed-p.started < 10*time.Second && elapsed-p.started < time.Duration(v.ExpiresAt-v.IssuedAt)*time.Second && v.IssuedAt <= now.Unix() && v.ExpiresAt > now.Unix() && v.ExpiresAt > v.IssuedAt && v.ExpiresAt-v.IssuedAt <= 10 && v.ExpiresAt <= v.ActionExpiresAt && v.ActionExpiresAt > now.Unix()
}
func (h *Helper) Execute(p *Pending, envelope Envelope, invoke func() error) error {
	if p == nil || p.owner != h {
		return errors.New("context_refused")
	}
	p.mu.Lock()
	defer p.mu.Unlock()
	if p.used {
		return errors.New("context_refused")
	}
	p.used = true
	b, e := base64.StdEncoding.DecodeString(envelope.Payload)
	if e != nil || len(b) > 4096 {
		return errors.New("permit_refused")
	}
	sig, e := base64.StdEncoding.DecodeString(envelope.Signature)
	if e != nil || len(h.Policy.PublicKey) != ed25519.PublicKeySize || !ed25519.Verify(h.Policy.PublicKey, b, sig) {
		return errors.New("permit_refused")
	}
	var v Permit
	decoder := json.NewDecoder(bytes.NewReader(b))
	decoder.DisallowUnknownFields()
	if decoder.Decode(&v) != nil || decoder.Decode(new(any)) != io.EOF || v.Version != 1 || v.ActionID == "" || v.MachineID != h.Policy.MachineID || v.BootID != h.Policy.BootID || v.Issuer != h.Policy.Issuer || v.KeyID != h.Policy.KeyID || v.Nonce != p.Nonce || !h.validTime(p, v) {
		return errors.New("permit_refused")
	}
	if e = h.Ledger.consume(v.ActionID); e != nil {
		return e
	}
	// Lock waits and slow sync can use the entire request budget. Once consumed,
	// expiry loses the operation safely; it never makes the ID reusable.
	if !h.validTime(p, v) {
		return errors.New("consumed_unknown")
	}
	if e = invoke(); e != nil {
		return errors.New("consumed_unknown")
	}
	return nil
}
