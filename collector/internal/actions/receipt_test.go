package actions

import (
	"os"
	"path/filepath"
	"testing"
)

func TestReceiptDurabilityBeforeACK(t *testing.T) {
	for boundary := 1; boundary <= 8; boundary++ {
		t.Run(string(rune('A'+boundary)), func(t *testing.T) {
			dir := t.TempDir()
			fs := &failingFiles{stop: boundary}
			r := &Receipts{Directory: dir, ops: fs}
			a := Action{ID: "22222222-2222-4222-8222-222222222222", MachineID: "machine", BootID: "boot", ExpiresAt: "2033-05-18T09:33:20Z"}
			e := r.Save(a, "received")
			acked := e == nil
			if fs.failed && acked {
				t.Fatal("ACK before durability")
			}
			r = &Receipts{Directory: dir}
			state, e := r.Read(a.ID)
			if e == nil && state.Action.ID != a.ID {
				t.Fatal("wrong receipt")
			}
			if !fs.failed && e != nil {
				t.Fatal(e)
			}
		})
	}
}
func TestReceiptRotationIsIndependentOfConsumption(t *testing.T) {
	h, key, _, path := enrolled(t)
	p, _ := h.Begin("local-only")
	count := 0
	_ = h.Execute(p, signed(t, h, key, p, nil), func() error { count++; return nil })
	telemetry := t.TempDir()
	_ = os.WriteFile(filepath.Join(telemetry, "segment"), nil, 0600)
	_ = os.RemoveAll(telemetry)
	r := &Receipts{Directory: t.TempDir()}
	_ = r.Save(Action{ID: "22222222-2222-4222-8222-222222222222"}, "received")
	h.Ledger = &Ledger{Path: path}
	p, _ = h.Begin("local-only")
	_ = h.Execute(p, signed(t, h, key, p, nil), func() error { count++; return nil })
	if count != 1 {
		t.Fatal("rotation replayed")
	}
}
