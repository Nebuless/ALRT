package daemon

import (
	"context"
	"github.com/Nebuless/ALRT/collector/internal/journal"
	"github.com/Nebuless/ALRT/collector/internal/protocol"
	"io"
	"testing"
	"time"
)

func TestRunStopsDurablyAndRestartChangesStreamOnly(t *testing.T) {
	c := validConfig(t)
	c.Destinations[0].URL = "https://127.0.0.1:1"
	c.Destinations[0].ProxyURL = ""
	var stream, boot string
	for attempt := 0; attempt < 2; attempt++ {
		ctx, cancel := context.WithTimeout(context.Background(), 80*time.Millisecond)
		e := Run(ctx, c, io.Discard)
		cancel()
		if e != nil {
			t.Fatal(e)
		}
		j, e := journal.Open(journal.Config{Directory: c.TelemetryDirectory, Destinations: []string{c.Destinations[0].ID}})
		if e != nil {
			t.Fatal(e)
		}
		records, e := j.Batch(c.Destinations[0].ID)
		if e != nil || len(records) != attempt+1 {
			if closeErr := j.Close(); closeErr != nil {
				t.Errorf("journal close: %v", closeErr)
			}
			t.Fatalf("run did not persist sample: %d %v", len(records), e)
		}
		r, e := protocol.Decode(records[len(records)-1].Data)
		if e != nil {
			t.Fatal(e)
		}
		if r.MachineID != c.MachineID || r.Scope != "sandbox" || r.Sequence != 1 {
			t.Fatal("run identity/scope")
		}
		if attempt > 0 && (r.StreamID == stream || r.BootID != boot) {
			t.Fatal("restart changed boot or reused stream")
		}
		stream = r.StreamID
		boot = r.BootID
		if e = j.Close(); e != nil {
			t.Fatal(e)
		}
	}
}
