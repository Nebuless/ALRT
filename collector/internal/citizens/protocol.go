// Package citizens transports private metadata independently of telemetry replay.
package citizens

import (
	"bytes"
	"encoding/json"
	"errors"
	"io"
	"math"
	"regexp"
	"strings"
	"time"
)

const maxBytes = 256 << 10
const maxInteger = 1<<53 - 1

var uuid = regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`)
var opaque = regexp.MustCompile(`^[0-9a-f]{64}$`)
var nonce = regexp.MustCompile(`^[A-Za-z0-9]{64}$`)
var errInvalid = errors.New("citizens_invalid_response")

type Process struct {
	BootID     string `json:"boot_id"`
	PID        int64  `json:"pid"`
	StartTicks uint64 `json:"start_ticks"`
}
type Citizen struct {
	ID           string   `json:"id"`
	ParentID     *string  `json:"parent_id"`
	Tool         string   `json:"tool"`
	State        string   `json:"state"`
	ObservedAt   string   `json:"observed_at"`
	RuntimeID    string   `json:"runtime_id"`
	Attribution  string   `json:"attribution"`
	Process      *Process `json:"process"`
	CPUPercent   *float64 `json:"cpu_percent"`
	RSSBytes     *int64   `json:"rss_bytes"`
	CanTerminate bool     `json:"can_terminate"`
}
type Snapshot struct {
	BootID    string    `json:"boot_id"`
	BridgeID  string    `json:"bridge_id"`
	Sequence  uint64    `json:"sequence"`
	SampledAt string    `json:"sampled_at"`
	Coverage  string    `json:"coverage"`
	Citizens  []Citizen `json:"citizens"`
}
type Result struct {
	ID     string `json:"id"`
	Status string `json:"status"`
}
type Command struct {
	ID         string `json:"id"`
	CitizenID  string `json:"citizen_id"`
	BootID     string `json:"boot_id"`
	BridgeID   string `json:"bridge_id"`
	PID        int64  `json:"pid"`
	StartTicks uint64 `json:"start_ticks"`
	ExpiresAt  string `json:"expires_at"`
}
type Exchange struct {
	Nonce string `json:"nonce"`
	Snapshot
	Results []Result `json:"results"`
}
type acknowledgement struct {
	Accepted bool      `json:"accepted"`
	Commands []Command `json:"commands"`
}

// Inspect tokens before typed decoding: duplicate fields and null required
// scalars must not silently become valid zero values at the privacy boundary.
func value(d *json.Decoder, depth int) (any, error) {
	if depth > 8 {
		return nil, errInvalid
	}
	t, err := d.Token()
	if err != nil {
		return nil, errInvalid
	}
	switch t {
	case json.Delim('{'):
		m := map[string]any{}
		for d.More() {
			key, e := d.Token()
			if e != nil {
				return nil, errInvalid
			}
			k, ok := key.(string)
			if !ok {
				return nil, errInvalid
			}
			if _, ok = m[k]; ok {
				return nil, errInvalid
			}
			v, e := value(d, depth+1)
			if e != nil {
				return nil, e
			}
			m[k] = v
		}
		_, err = d.Token()
		return m, err
	case json.Delim('['):
		a := []any{}
		for d.More() {
			v, e := value(d, depth+1)
			if e != nil {
				return nil, e
			}
			a = append(a, v)
		}
		_, err = d.Token()
		return a, err
	default:
		return t, nil
	}
}

// A '?' field is required but nullable; '*' is optional and nullable.
func fields(v any, required ...string) bool {
	m, ok := v.(map[string]any)
	if !ok {
		return false
	}
	allowed := map[string]bool{}
	for _, field := range required {
		k := strings.TrimLeft(field, "?*")
		allowed[k] = true
		v, exists := m[k]
		if (!exists && field[0] != '*') || (v == nil && field[0] != '?' && field[0] != '*') {
			return false
		}
	}
	for k := range m {
		if !allowed[k] {
			return false
		}
	}
	return true
}

func decode(b []byte, out any) error {
	if len(b) > maxBytes {
		return errInvalid
	}
	d := json.NewDecoder(bytes.NewReader(b))
	d.UseNumber()
	v, e := value(d, 0)
	if e != nil || d.Decode(new(any)) != io.EOF {
		return errInvalid
	}
	valid := false
	switch out.(type) {
	case *Snapshot:
		valid = fields(v, "boot_id", "bridge_id", "sequence", "sampled_at", "coverage", "citizens")
		if valid {
			rows, ok := v.(map[string]any)["citizens"].([]any)
			valid = ok && len(rows) <= 128
			for _, r := range rows {
				if !fields(r, "id", "*parent_id", "tool", "state", "observed_at", "runtime_id", "attribution", "?process", "?cpu_percent", "?rss_bytes", "can_terminate") {
					valid = false
					break
				}
				m := r.(map[string]any)
				if m["process"] != nil && !fields(m["process"], "boot_id", "pid", "start_ticks") {
					valid = false
				}
			}
		}
	case *acknowledgement:
		valid = fields(v, "accepted", "commands")
		if valid {
			a, ok := v.(map[string]any)["commands"].([]any)
			valid = ok && len(a) <= 64
			for _, c := range a {
				if !fields(c, "id", "citizen_id", "boot_id", "bridge_id", "pid", "start_ticks", "expires_at") {
					valid = false
				}
			}
		}
	case *challenge:
		valid = fields(v, "nonce", "expires_at")
	case *commandResults:
		valid = fields(v, "results")
		if valid {
			a, ok := v.(map[string]any)["results"].([]any)
			valid = ok && len(a) <= 64
			for _, r := range a {
				if !fields(r, "id", "status") {
					valid = false
				}
			}
		}
	}
	if !valid {
		return errInvalid
	}
	d = json.NewDecoder(bytes.NewReader(b))
	d.DisallowUnknownFields()
	if d.Decode(out) != nil {
		return errInvalid
	}
	return nil
}

func oneOf(s string, choices ...string) bool {
	for _, c := range choices {
		if s == c {
			return true
		}
	}
	return false
}
func fresh(s string, now time.Time, age time.Duration) bool {
	t, e := time.Parse(time.RFC3339Nano, s)
	return len(s) <= 40 && e == nil && !t.After(now) && now.Sub(t) <= age
}
func (s Snapshot) validate(boot string, now time.Time) bool {
	if s.BootID != boot || !uuid.MatchString(s.BootID) || !uuid.MatchString(s.BridgeID) || s.Sequence == 0 || s.Sequence > maxInteger || !fresh(s.SampledAt, now, 10*time.Second) || !oneOf(s.Coverage, "complete", "partial", "unavailable") || s.Citizens == nil || len(s.Citizens) > 128 || (s.Coverage == "unavailable" && len(s.Citizens) > 0) {
		return false
	}
	sampled, _ := time.Parse(time.RFC3339Nano, s.SampledAt)
	ids := map[string]bool{}
	for _, r := range s.Citizens {
		if !opaque.MatchString(r.ID) || ids[r.ID] || (r.ParentID != nil && (!opaque.MatchString(*r.ParentID) || *r.ParentID == r.ID)) || !opaque.MatchString(r.RuntimeID) || !oneOf(r.Tool, "pi", "omo") || !oneOf(r.State, "working", "waiting", "idle", "stopped", "failed", "unknown") || !oneOf(r.Attribution, "dedicated", "shared", "unavailable") || !fresh(r.ObservedAt, sampled, 4*time.Hour) {
			return false
		}
		ids[r.ID] = true
		if r.Process != nil && (r.Process.BootID != boot || r.Process.PID <= 1 || r.Process.PID > math.MaxInt32 || r.Process.StartTicks == 0 || r.Process.StartTicks > maxInteger) {
			return false
		}
		if r.CPUPercent != nil && (math.IsNaN(*r.CPUPercent) || math.IsInf(*r.CPUPercent, 0) || *r.CPUPercent < 0) {
			return false
		}
		if r.RSSBytes != nil && (*r.RSSBytes < 0 || *r.RSSBytes > maxInteger) {
			return false
		}
		if r.CanTerminate && (r.Attribution != "dedicated" || r.Process == nil || oneOf(r.State, "stopped", "failed", "unknown") || !fresh(r.ObservedAt, now, 10*time.Second)) {
			return false
		}
	}
	return true
}

func (c Command) valid() bool {
	_, e := time.Parse(time.RFC3339Nano, c.ExpiresAt)
	return uuid.MatchString(c.ID) && uuid.MatchString(c.BootID) && uuid.MatchString(c.BridgeID) && opaque.MatchString(c.CitizenID) && c.PID > 1 && c.PID <= math.MaxInt32 && c.StartTicks > 0 && c.StartTicks <= maxInteger && len(c.ExpiresAt) <= 40 && e == nil
}
func (r Result) valid() bool {
	return uuid.MatchString(r.ID) && oneOf(r.Status, "terminated", "already_exited", "identity_changed", "permission_denied", "unsupported", "expired", "unknown")
}
