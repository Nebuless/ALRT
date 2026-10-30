package daemon

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/Nebuless/ALRT/collector/internal/journal"
	"github.com/Nebuless/ALRT/collector/internal/uploader"
)

func TestProtocolMismatchHasPrivateObservableDaemonStatus(t *testing.T) {
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if strings.HasSuffix(r.URL.Path, "challenge") {
			if err := json.NewEncoder(w).Encode(map[string]string{"nonce": strings.Repeat("n", 64), "expires_at": time.Now().Add(time.Minute).UTC().Format(time.RFC3339Nano)}); err != nil {
				t.Errorf("challenge response: %v", err)
			}
			return
		}
		w.WriteHeader(422)
		if _, err := io.WriteString(w, `{"error":"protocol_version_unsupported"}`); err != nil {
			t.Errorf("protocol rejection response: %v", err)
		}
	}))
	defer server.Close()
	c := validConfig(t)
	hub := c.Destinations[0].ID
	j, e := journal.Open(journal.Config{Directory: t.TempDir(), Destinations: []string{hub}})
	if e != nil {
		t.Fatal(e)
	}
	defer func() {
		if err := j.Close(); err != nil {
			t.Errorf("journal close: %v", err)
		}
	}()
	d := c.Destinations[0]
	d.URL = server.URL
	d.Token = "SECRET_CREDENTIAL"
	worker := uploader.New(d, j, server.Client())
	b, e := os.ReadFile("../../../contracts/fixtures/telemetry/valid-sample.json")
	if e != nil {
		t.Fatal(e)
	}
	j.Append(b)
	worker.Publish(b)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go worker.Run(ctx)
	reader, writer := io.Pipe()
	defer func() { requireIO(t, reader.Close()) }()
	defer func() { requireIO(t, writer.Close()) }()
	go reportStatus(ctx, writer, c, []*uploader.Worker{worker}, j)
	decoder := json.NewDecoder(reader)
	found := false
	for i := 0; i < 4; i++ {
		var event map[string]string
		if e = decoder.Decode(&event); e != nil {
			t.Fatal(e)
		}
		encoded, _ := json.Marshal(event)
		if strings.Contains(string(encoded), "SECRET") || strings.Contains(string(encoded), server.URL) {
			t.Fatal("private status data leaked")
		}
		if event["status"] == "protocol_version_unsupported" {
			found = true
			if event["hub_id"] != hub {
				t.Fatal("missing provisioned hub identity")
			}
			break
		}
	}
	if !found {
		t.Fatal("protocol mismatch not surfaced")
	}
	if j.Cursor(hub) != 0 {
		t.Fatal("unsupported protocol destroyed journal progress")
	}
}
