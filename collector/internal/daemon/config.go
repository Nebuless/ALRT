package daemon

import (
	"bytes"
	"encoding/json"
	"errors"
	"io"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strings"

	"github.com/Nebuless/ALRT/collector/internal/uploader"
)

type Config struct {
	PrimaryHubID       string                 `json:"primary_hub_id,omitempty"`
	MachineID          string                 `json:"machine_id"`
	Scope              string                 `json:"scope"`
	TelemetryDirectory string                 `json:"telemetry_directory"`
	ActionDirectory    string                 `json:"action_directory"`
	CitizensSocket     string                 `json:"citizens_socket,omitempty"`
	IntervalSeconds    int                    `json:"interval_seconds"`
	BudgetBytes        int64                  `json:"budget_bytes"`
	SegmentBytes       int64                  `json:"segment_bytes"`
	Disks              map[string]string      `json:"disks"`
	Interfaces         map[string]string      `json:"interfaces"`
	Destinations       []uploader.Destination `json:"destinations"`
}

var uuid = regexp.MustCompile(`^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$`)

func Load(path string) (Config, error) {
	var c Config
	f, e := os.Open(path)
	if e != nil {
		return c, errors.New("config_unavailable")
	}
	defer func() { _ = f.Close() }() // Read-only configuration; close cannot change the validated bytes.
	info, e := f.Stat()
	if e != nil || !info.Mode().IsRegular() || info.Mode().Perm()&0077 != 0 || info.Size() > 64<<10 {
		return c, errors.New("config_not_private")
	}
	decoder := json.NewDecoder(io.LimitReader(f, 64<<10+1))
	decoder.DisallowUnknownFields()
	if decoder.Decode(&c) != nil || decoder.Decode(new(any)) != io.EOF {
		return Config{}, errors.New("invalid_config")
	}
	if e = c.Validate(); e != nil {
		return Config{}, e
	}
	return c, nil
}
func (c *Config) Validate() error {
	bad := errors.New("invalid_config")
	if c.CitizensSocket != "" && (!filepath.IsAbs(c.CitizensSocket) || strings.ContainsRune(c.CitizensSocket, 0)) {
		return bad
	}
	if !uuid.MatchString(c.MachineID) || (c.Scope != "host" && c.Scope != "sandbox") || !filepath.IsAbs(c.TelemetryDirectory) || !filepath.IsAbs(c.ActionDirectory) {
		return bad
	}
	telemetry, e := resolvedPath(c.TelemetryDirectory)
	if e != nil {
		return bad
	}
	actions, e := resolvedPath(c.ActionDirectory)
	if e != nil {
		return bad
	}
	if telemetry == actions || strings.HasPrefix(actions, telemetry+string(os.PathSeparator)) || strings.HasPrefix(telemetry, actions+string(os.PathSeparator)) {
		return bad
	}
	if c.IntervalSeconds == 0 {
		c.IntervalSeconds = 10
	}
	if c.IntervalSeconds < 1 || c.IntervalSeconds > 60 {
		return bad
	}
	if len(c.Destinations) < 1 || len(c.Destinations) > 8 {
		return bad
	}
	ids := map[string]bool{}
	for _, d := range c.Destinations {
		u, e := url.Parse(d.URL)
		if e != nil || u.Scheme != "https" || u.Host == "" || u.User != nil || u.RawQuery != "" || u.Fragment != "" || (u.Path != "" && u.Path != "/") || !uuid.MatchString(d.ID) || ids[d.ID] || len(d.Token) != 64 || bytes.ContainsAny([]byte(d.Token), "\r\n\t ") {
			return bad
		}
		ids[d.ID] = true
		if d.ProxyURL != "" {
			p, e := url.Parse(d.ProxyURL)
			if e != nil || (p.Scheme != "http" && p.Scheme != "https") || p.Host == "" || p.RawQuery != "" || p.Fragment != "" || (p.Path != "" && p.Path != "/") {
				return bad
			}
		}
	}
	if c.PrimaryHubID != "" && (!uuid.MatchString(c.PrimaryHubID) || !ids[c.PrimaryHubID]) {
		return bad
	}
	return nil
}

// Resolve every existing ancestor to keep action state outside symlinked rings.
func resolvedPath(path string) (string, error) {
	path = filepath.Clean(path)
	if real, e := filepath.EvalSymlinks(path); e == nil {
		return real, nil
	}
	parent := filepath.Dir(path)
	if parent == path {
		return "", errors.New("invalid_path")
	}
	real, e := resolvedPath(parent)
	if e != nil {
		return "", e
	}
	return filepath.Join(real, filepath.Base(path)), nil
}
