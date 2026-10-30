package sampler

import (
	"encoding/json"
	"strings"
	"testing"
	"time"
)

func TestHostMetricsAndOpaqueLabels(t *testing.T) {
	root := fixture(t)
	put(t, root, "pressure/cpu", "some avg10=1.25 avg60=2.00 avg300=3.00 total=100\n")
	put(t, root, "net/dev", "private_interface: 100 0 0 0 0 0 0 0 200 0 0 0 0 0 0 0\n")
	s, err := New(Config{ProcRoot: root, MachineID: "primary-hub", Disks: map[string]string{"data": "/SECRET_CANARY_PATH"}, Interfaces: map[string]string{"uplink": "private_interface"}})
	if err != nil {
		t.Fatal(err)
	}
	now := time.Now()
	first, err := s.Sample(now)
	if err != nil {
		t.Fatal(err)
	}
	if first.Scope != "sandbox" || first.Metrics.Memory.UsedBytes.Value == nil || *first.Metrics.Memory.UsedBytes.Value != 524288 {
		t.Fatal("scope or memory wrong")
	}
	if first.Metrics.Pressure.CPU.Some.Avg10 == nil || *first.Metrics.Pressure.CPU.Some.Avg10 != 1.25 || first.Metrics.Pressure.CPU.Full.Avg10 != nil {
		t.Fatal("PSI missing or fabricated")
	}
	if first.Metrics.Network[0].ReceiveBytesPerSecond.Value != nil || first.Metrics.Disks[0].TotalBytes.Value != nil {
		t.Fatal("missing metrics must be null")
	}
	put(t, root, "stat", "cpu 150 0 100 850 0 0 0 0\n")
	put(t, root, "net/dev", "private_interface: 300 0 0 0 0 0 0 0 600 0 0 0 0 0 0 0\n")
	second, _ := s.Sample(now.Add(2 * time.Second))
	if second.Metrics.CPUPercent.Value == nil || *second.Metrics.CPUPercent.Value != 50 || *second.Metrics.Network[0].ReceiveBytesPerSecond.Value != 100 || *second.Metrics.Network[0].TransmitBytesPerSecond.Value != 200 {
		t.Fatal("host counter deltas wrong")
	}
	encoded, _ := json.Marshal(second)
	if strings.Contains(string(encoded), "SECRET_CANARY") || strings.Contains(string(encoded), "private_interface") {
		t.Fatal("configured source identifiers leaked")
	}
	put(t, root, "net/dev", "private_interface: 1 0 0 0 0 0 0 0 2 0 0 0 0 0 0 0\n")
	third, _ := s.Sample(now.Add(3 * time.Second))
	if third.Metrics.Network[0].ReceiveBytesPerSecond.Value != nil || third.Metrics.Network[0].ReceiveBytesPerSecond.Status != "counter_reset" {
		t.Fatal("counter reset must be unknown")
	}
}

func TestBoundedCategoriesAndSafeErrors(t *testing.T) {
	root := fixture(t)
	for i := 1; i <= 15; i++ {
		proc(t, root, i, 0, uint64(i), 1, "Z", "worker")
	}
	s, err := New(Config{ProcRoot: root, MachineID: "test", MaxCategoryItems: 2})
	if err != nil {
		t.Fatal(err)
	}
	r, _ := s.Sample(time.Now())
	if len(r.Processes.Zombies.Items) != 2 || r.Processes.Zombies.Completeness != "partial" || r.Processes.Zombies.TotalObserved != 15 || len(r.Processes.TopRSS.Items) != 10 {
		t.Fatal("category bounds/coverage wrong")
	}
	bad, _ := New(Config{ProcRoot: "/SECRET_CANARY_PATH", MachineID: "test"})
	_, err = bad.Sample(time.Now())
	if err == nil || strings.Contains(err.Error(), "SECRET_CANARY") {
		t.Fatal("raw error leaked")
	}
}

func TestUnavailableParentAndVanishedProcess(t *testing.T) {
	root := fixture(t)
	proc(t, root, 20, 10, 20, 1, "Z", "zombie")
	put(t, root, "99/disappeared", "race fixture")
	s := newFixtureSampler(t, root, 100)
	r, err := s.Sample(time.Now())
	if err != nil {
		t.Fatal(err)
	}
	if r.Coverage.Processes.Vanished != 1 || r.Coverage.Processes.Status != "partial" {
		t.Fatal("vanished stat must reduce coverage")
	}
	z := r.Processes.Zombies.Items[0]
	if z.Parent.Identity != nil || z.Parent.Status != "unknown" || z.OrphanStatus != "uncertain" {
		t.Fatal("missing zombie parent must retain uncertainty")
	}
}

func TestRestartCreatesStreamAndRetainsBootIdentity(t *testing.T) {
	root := fixture(t)
	first, err := New(Config{ProcRoot: root, MachineID: "22222222-2222-4222-8222-222222222222"})
	if err != nil {
		t.Fatal(err)
	}
	second, err := New(Config{ProcRoot: root, MachineID: "22222222-2222-4222-8222-222222222222"})
	if err != nil {
		t.Fatal(err)
	}
	a, _ := first.Sample(time.Now())
	b, _ := second.Sample(time.Now())
	if a.StreamID == b.StreamID || a.BootID != b.BootID || a.Sequence != 1 || b.Sequence != 1 {
		t.Fatal("restart must reset the stream but retain the boot identity")
	}
}
