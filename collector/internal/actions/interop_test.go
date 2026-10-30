package actions

import (
	"bytes"
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"os/exec"
	"path/filepath"
	"testing"
)

func TestPHPWirePermitVerifiedByGo(t *testing.T) {
	h, _, _, _ := enrolled(t)
	p, e := h.Begin("local-only")
	if e != nil {
		t.Fatal(e)
	}
	permit := Permit{Version: 1, ActionID: "php-action", MachineID: "machine", BootID: "boot", Nonce: p.Nonce, Issuer: "primary", KeyID: "key", IssuedAt: 2000000000, ExpiresAt: 2000000010, ActionExpiresAt: 2000021600}
	b, _ := json.Marshal(permit)
	root := filepath.Join("..", "..", "..")
	cmd := exec.Command(filepath.Join(root, "bin", "php"), filepath.Join(root, "contracts", "fixtures", "actions", "sign-permit.php"))
	cmd.Stdin = bytes.NewReader(b)
	out, e := cmd.Output()
	if e != nil {
		t.Fatalf("PHP OpenSSL fixture: %v", e)
	}
	var wire struct {
		Envelope  Envelope `json:"envelope"`
		PublicKey string   `json:"public_key"`
	}
	if json.Unmarshal(out, &wire) != nil {
		t.Fatal("invalid wire")
	}
	public, e := base64.StdEncoding.DecodeString(wire.PublicKey)
	if e != nil || len(public) != ed25519.PublicKeySize {
		t.Fatal("invalid PHP key")
	}
	h.Policy.PublicKey = public
	count := 0
	if e = h.Execute(p, wire.Envelope, func() error { count++; return nil }); e != nil || count != 1 {
		t.Fatalf("PHP wire rejected: %v", e)
	}
}
