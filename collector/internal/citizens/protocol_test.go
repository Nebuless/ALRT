package citizens

import (
	"encoding/json"
	"math"
	"strings"
	"testing"
	"time"
)

func TestStrictSnapshotShapeAndBounds(t *testing.T) {
	for _, kind := range []string{"case_alias", "null_bool", "null_sequence", "process_extra", "rows", "duplicate_id", "future", "observation_future", "process_boot", "shared_action", "rss_bound", "sequence_bound", "cpu_infinite", "cpu_nan", "tool", "state", "runtime", "parent", "pid", "ticks", "unavailable_rows", "depth"} {
		t.Run(kind, func(t *testing.T) {
			s := snapshot()
			b, _ := json.Marshal(s)
			switch kind {
			case "case_alias":
				b = []byte(strings.Replace(string(b), `"boot_id":`, `"BOOT_ID":`, 1))
			case "null_bool":
				b = []byte(strings.Replace(string(b), `"can_terminate":true`, `"can_terminate":null`, 1))
			case "null_sequence":
				b = []byte(strings.Replace(string(b), `"sequence":1`, `"sequence":null`, 1))
			case "process_extra":
				b = []byte(strings.Replace(string(b), `"pid":123`, `"pid":123,"environment":"CANARY_SECRET"`, 1))
			case "rows":
				s.Citizens = make([]Citizen, 129)
			case "duplicate_id":
				s.Citizens = append(s.Citizens, s.Citizens[0])
			case "future":
				s.SampledAt = time.Now().Add(time.Minute).UTC().Format(time.RFC3339Nano)
			case "observation_future":
				s.Citizens[0].ObservedAt = time.Now().Add(time.Minute).UTC().Format(time.RFC3339Nano)
			case "process_boot":
				s.Citizens[0].Process.BootID = bridge
			case "shared_action":
				s.Citizens[0].Attribution = "shared"
			case "rss_bound":
				v := int64(maxInteger + 1)
				s.Citizens[0].RSSBytes = &v
			case "sequence_bound":
				s.Sequence = maxInteger + 1
			case "cpu_infinite":
				v := math.Inf(1)
				s.Citizens[0].CPUPercent = &v
			case "cpu_nan":
				v := math.NaN()
				s.Citizens[0].CPUPercent = &v
			case "tool":
				s.Citizens[0].Tool = "CANARY_SECRET"
			case "state":
				s.Citizens[0].State = "ready"
			case "runtime":
				s.Citizens[0].RuntimeID = "CANARY_SECRET"
			case "parent":
				v := "CANARY_SECRET"
				s.Citizens[0].ParentID = &v
			case "pid":
				s.Citizens[0].Process.PID = 1
			case "ticks":
				s.Citizens[0].Process.StartTicks = 0
			case "unavailable_rows":
				s.Coverage = "unavailable"
			case "depth":
				b = []byte(strings.Repeat("[", 100) + "0" + strings.Repeat("]", 100))
			}
			if oneOf(kind, "cpu_infinite", "cpu_nan") {
				if s.validate(boot, time.Now()) {
					t.Fatal("nonfinite metric accepted")
				}
				return
			}
			if !oneOf(kind, "case_alias", "null_bool", "null_sequence", "process_extra", "depth") {
				b, _ = json.Marshal(s)
			}
			var out Snapshot
			if decode(b, &out) == nil && out.validate(boot, time.Now()) {
				t.Fatal("invalid snapshot accepted")
			}
		})
	}
}

func TestStrictResultIdentityAndStatus(t *testing.T) {
	for _, b := range []string{
		`{"results":[{"id":"` + action + `","status":"unknown","prompt":"CANARY_SECRET"}]}`,
		`{"results":[{"id":"` + action + `","status":null}]}`,
		`{"results":null}`,
		`{"results":[{"id":"` + action + `","status":"CANARY_SECRET"}]}`,
	} {
		var out commandResults
		if decode([]byte(b), &out) == nil && len(out.Results) == 1 && out.Results[0].valid() {
			t.Fatal("unsafe results accepted")
		}
	}
}

func TestPartialStaleRowsPreserveObservationAgeWithoutActions(t *testing.T) {
	s := snapshot()
	s.Coverage = "partial"
	s.Citizens[0].ObservedAt = time.Now().Add(-time.Hour).UTC().Format(time.RFC3339Nano)
	s.Citizens[0].State = "unknown"
	s.Citizens[0].CanTerminate = false
	b, err := json.Marshal(s)
	if err != nil {
		t.Fatal(err)
	}
	var decoded Snapshot
	if err = decode(b, &decoded); err != nil || !decoded.validate(boot, time.Now()) {
		t.Fatalf("stale row rejected: %v", err)
	}
	if decoded.Citizens[0].ObservedAt != s.Citizens[0].ObservedAt {
		t.Fatal("source age changed")
	}
	decoded.Citizens[0].CanTerminate = true
	if decoded.validate(boot, time.Now()) {
		t.Fatal("stale row enabled actions")
	}
}
