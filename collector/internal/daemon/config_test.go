package daemon

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/Nebuless/ALRT/collector/internal/uploader"
)

func requireIO(t *testing.T, err error) {
	t.Helper()
	if err != nil {
		t.Fatal(err)
	}
}
func validConfig(t *testing.T) Config {
	t.Helper()
	d := t.TempDir()
	return Config{MachineID: "22222222-2222-4222-8222-222222222222", Scope: "sandbox", TelemetryDirectory: filepath.Join(d, "telemetry"), ActionDirectory: filepath.Join(d, "actions"), Destinations: []uploader.Destination{{ID: "33333333-3333-4333-8333-333333333333", URL: "https://hub.example.test", Token: strings.Repeat("x", 64), ProxyURL: "http://127.0.0.1:1080"}}}
}
func TestPrivateProvisionedConfigAndHTTPSProxy(t *testing.T) {
	c := validConfig(t)
	path := filepath.Join(t.TempDir(), "config.json")
	b, _ := json.Marshal(c)
	requireIO(t, os.WriteFile(path, b, 0600))
	loaded, e := Load(path)
	if e != nil || loaded.IntervalSeconds != 10 || loaded.Destinations[0].ProxyURL != c.Destinations[0].ProxyURL {
		t.Fatalf("load %v", e)
	}
	requireIO(t, os.Chmod(path, 0644))
	if _, e = Load(path); e == nil || strings.Contains(e.Error(), "hub.example") {
		t.Fatal("public config accepted/leaked")
	}
}
func TestConfigRejectsPlainHTTPAndActionRingOverlap(t *testing.T) {
	for _, kind := range []string{"http", "nested", "symlink", "scope", "extra", "overflow_interval"} {
		t.Run(kind, func(t *testing.T) {
			c := validConfig(t)
			switch kind {
			case "overflow_interval":
				c.IntervalSeconds = 18446744074
			case "http":
				c.Destinations[0].URL = "http://hub.example.test"
			case "nested":
				c.ActionDirectory = filepath.Join(c.TelemetryDirectory, "actions")
			case "symlink":
				requireIO(t, os.MkdirAll(c.TelemetryDirectory, 0700))
				alias := filepath.Join(filepath.Dir(c.TelemetryDirectory), "alias")
				requireIO(t, os.Symlink(c.TelemetryDirectory, alias))
				c.ActionDirectory = filepath.Join(alias, "actions")
			case "scope":
				c.Scope = ""
			}
			path := filepath.Join(t.TempDir(), "config.json")
			b, _ := json.Marshal(c)
			if kind == "extra" {
				b = append(b[:len(b)-1], []byte(`,"private_extra":"secret"}`)...)
			}
			requireIO(t, os.WriteFile(path, b, 0600))
			if _, e := Load(path); e == nil {
				t.Fatal("invalid config accepted")
			}
		})
	}
}

func TestCitizensSocketOptionalAbsoluteConfig(t *testing.T) {
	for _, path := range []string{"", "/run/user/1000/citizens.sock", "relative.sock", "/run/bad\x00socket"} {
		c := validConfig(t)
		c.CitizensSocket = path
		err := c.Validate()
		want := path == "" || path == "/run/user/1000/citizens.sock"
		if (err == nil) != want {
			t.Fatalf("socket validity %q: %v", path, err)
		}
	}
}
