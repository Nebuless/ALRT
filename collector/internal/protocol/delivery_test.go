package protocol

import (
	"encoding/json"
	"os"
	"strings"
	"testing"
)

func TestPrepareBoundsWorstCategoriesBeforePublication(t *testing.T) {
	b, e := os.ReadFile("../../../contracts/fixtures/telemetry/valid-sample.json")
	if e != nil {
		t.Fatal(e)
	}
	r, e := Decode(b)
	if e != nil {
		t.Fatal(e)
	}
	p := r.Processes.TopCPU.Items[0]
	p.Name = strings.Repeat("\u2028", 64)
	for _, category := range []*Category{&r.Processes.TopCPU, &r.Processes.TopRSS, &r.Processes.LongestRunning} {
		category.Items = make([]Process, 10)
		for i := range category.Items {
			category.Items[i] = p
		}
		category.TotalObserved = 4096
	}
	r.Processes.Orphans.Items = make([]Process, 4096)
	r.Processes.Zombies.Items = make([]Process, 4096)
	for i := 0; i < 4096; i++ {
		r.Processes.Orphans.Items[i] = p
		r.Processes.Zombies.Items[i] = p
	}
	r.Processes.Orphans.TotalObserved = 4096
	r.Processes.Zombies.TotalObserved = 4096
	b, e = Prepare(r)
	if e != nil {
		t.Fatal(e)
	}
	prepared, _ := Decode(b)
	delivery := Delivery{Version: 1, Recording: "journal_sync_failed", PossibleLossSeconds: 30, UnknownCoverage: true, LostRanges: []LostRange{}, Acknowledgements: []Acknowledgement{}}
	for i := 0; i < 32; i++ {
		delivery.LostRanges = append(delivery.LostRanges, LostRange{HubID: "33333333-3333-4333-8333-333333333333", StreamID: strings.Repeat("s", 64), FirstSequence: 9007199254740990, LastSequence: 9007199254740991, From: "2026-10-15T01:00:00.999999999+00:00", To: "2026-10-15T01:00:30.999999999+00:00", Reason: "write_failed"})
	}
	for i := 0; i < 8; i++ {
		delivery.Acknowledgements = append(delivery.Acknowledgements, Acknowledgement{HubID: "33333333-3333-4333-8333-333333333333", StreamID: strings.Repeat("s", 64), Sequence: 9007199254740991, AcknowledgedAt: "2026-10-15T01:00:30.999999999+00:00"})
	}
	request, _ := json.Marshal(map[string]any{"nonce": strings.Repeat("n", 64), "report": json.RawMessage(b), "delivery": delivery})
	replay, _ := json.Marshal(struct {
		Reports []json.RawMessage `json:"reports"`
	}{[]json.RawMessage{b}})
	t.Logf("immutable report %d bytes; current envelope with maximal metadata %d bytes; replay envelope %d bytes", len(b), len(request), len(replay))
	if len(replay) > MaxBatchBytes {
		t.Fatalf("replay envelope too large: %d", len(replay))
	}
	if len(request) > 128<<10 || prepared.Processes.Orphans.Completeness != "partial" || prepared.Processes.Orphans.Reason == nil || *prepared.Processes.Orphans.Reason != "category_limit" || len(prepared.Processes.TopCPU.Items) == 0 {
		t.Fatalf("bad bound/completeness: %d", len(request))
	}
}
