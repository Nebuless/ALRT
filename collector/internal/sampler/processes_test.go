package sampler

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func fixture(t *testing.T) string {
	t.Helper()
	root := t.TempDir()
	for name, body := range map[string]string{"sys/kernel/random/boot_id": "11111111-1111-4111-8111-111111111111", "uptime": "1000.00 10.00", "stat": "cpu 100 0 100 800 0 0 0 0 0 0\n", "meminfo": "MemTotal: 1024 kB\nMemAvailable: 512 kB\n", "loadavg": "0.10 0.20 0.30 1/2 3"} {
		put(t, root, name, body)
	}
	return root
}
func put(t *testing.T, root, name, body string) {
	t.Helper()
	p := filepath.Join(root, name)
	if err := os.MkdirAll(filepath.Dir(p), 0700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(p, []byte(body), 0600); err != nil {
		t.Fatal(err)
	}
}
func proc(t *testing.T, root string, pid, ppid int, start, cpu uint64, state, name string) {
	t.Helper()
	fields := make([]string, 22)
	for i := range fields {
		fields[i] = "0"
	}
	fields[0] = state
	fields[1] = fmt.Sprint(ppid)
	fields[11] = fmt.Sprint(cpu)
	fields[12] = "0"
	fields[13] = "99999999"
	fields[14] = "99999999"
	fields[19] = fmt.Sprint(start)
	fields[21] = "3"
	put(t, root, fmt.Sprintf("%d/stat", pid), fmt.Sprintf("%d (%s) %s", pid, name, strings.Join(fields, " ")))
	for _, file := range []string{"cmdline", "environ", "cwd"} {
		put(t, root, fmt.Sprintf("%d/%s", pid, file), "SECRET_CANARY_"+file)
	}
}
func newFixtureSampler(t *testing.T, root string, limit int) *Sampler {
	t.Helper()
	s, err := New(Config{ProcRoot: root, MachineID: "test-host", StreamID: "test-stream", Scope: "sandbox", MaxProcesses: limit})
	if err != nil {
		t.Fatal(err)
	}
	return s
}
func TestFirstCPUPrivacyAndIdentity(t *testing.T) {
	root := fixture(t)
	proc(t, root, 1, 0, 1, 1, "S", "init")
	proc(t, root, 2, 1, 100, 10, "R", strings.Repeat("a", 1000))
	s := newFixtureSampler(t, root, 100)
	now := time.Unix(1700000000, 0)
	first, err := s.Sample(now)
	if err != nil {
		t.Fatal(err)
	}
	if first.Processes.TopRSS.Items[0].CPUPercent != nil {
		t.Fatal("first CPU must be unknown")
	}
	if first.Metrics.Pressure.CPU.Some.Avg10 != nil {
		t.Fatal("unsupported PSI must be null")
	}
	raw, _ := json.Marshal(first)
	if strings.Contains(string(raw), "SECRET_CANARY") || strings.Contains(string(raw), strings.Repeat("a", 65)) {
		t.Fatal("private or unbounded data escaped")
	}
	proc(t, root, 2, 1, 100, 410, "R", "worker")
	stat, _ := os.ReadFile(filepath.Join(root, "2/stat"))
	put(t, root, "2/stat", strings.ReplaceAll(string(stat), "99999999", "100999999"))
	second, err := s.Sample(now.Add(time.Second))
	if err != nil {
		t.Fatal(err)
	}
	if got := second.Processes.TopCPU.Items[0].CPUPercent; got == nil || *got != 400 {
		t.Fatalf("multicore own CPU expected 400, got %v", got)
	}
	proc(t, root, 2, 1, 200, 1, "R", "replacement")
	third, _ := s.Sample(now.Add(2 * time.Second))
	for _, p := range third.Processes.TopRSS.Items {
		if p.Identity.PID == 2 && p.CPUPercent != nil {
			t.Fatal("PID reuse merged counters")
		}
	}
	proc(t, root, 2, 1, 200, 0, "R", "replacement")
	fourth, _ := s.Sample(now.Add(3 * time.Second))
	for _, p := range fourth.Processes.TopRSS.Items {
		if p.Identity.PID == 2 && p.CPUPercent != nil {
			t.Fatal("counter decrease must be unknown")
		}
	}
}
func TestOrphansRequireObservedExitAndParentIdentity(t *testing.T) {
	root := fixture(t)
	proc(t, root, 1, 0, 1, 1, "S", "init")
	proc(t, root, 5, 1, 5, 1, "S", "subreaper")
	proc(t, root, 10, 1, 10, 1, "S", "parent")
	proc(t, root, 20, 10, 20, 1, "S", "child")
	proc(t, root, 21, 1, 21, 1, "Z", "zombie")
	s := newFixtureSampler(t, root, 100)
	now := time.Unix(1700000000, 0)
	r, _ := s.Sample(now)
	if len(r.Processes.Orphans.Items) != 0 {
		t.Fatal("first adoption is not proven orphan")
	}
	if r.Processes.Zombies.Items[0].Parent.Identity == nil {
		t.Fatal("expected known zombie parent")
	}
	proc(t, root, 20, 5, 20, 2, "S", "child")
	r, _ = s.Sample(now.Add(time.Second))
	if len(r.Processes.Orphans.Items) != 0 {
		t.Fatal("living old parent does not prove orphan")
	}
	// Return to old parent before observing its exit and adoption by a subreaper.
	proc(t, root, 20, 10, 20, 3, "S", "child")
	if _, err := s.Sample(now.Add(2 * time.Second)); err != nil {
		t.Fatal(err)
	}
	if err := os.RemoveAll(filepath.Join(root, "10")); err != nil {
		t.Fatal(err)
	}
	proc(t, root, 20, 5, 20, 4, "S", "child")
	r, _ = s.Sample(now.Add(3 * time.Second))
	if len(r.Processes.Orphans.Items) != 1 || r.Processes.Orphans.Items[0].OrphanStatus != "proven" {
		t.Fatal("observed adoption missing")
	}
	r, _ = s.Sample(now.Add(4 * time.Second))
	if len(r.Processes.Orphans.Items) != 1 {
		t.Fatal("proven orphan evidence must persist")
	}
	// A parent PID reused by a new identity is evidence the old parent exited.
	proc(t, root, 30, 5, 30, 1, "S", "parent2")
	proc(t, root, 40, 30, 40, 1, "S", "child2")
	if _, err := s.Sample(now.Add(5 * time.Second)); err != nil {
		t.Fatal(err)
	}
	proc(t, root, 30, 5, 300, 1, "S", "reused")
	proc(t, root, 40, 5, 40, 2, "Z", "child2")
	r, _ = s.Sample(now.Add(6 * time.Second))
	if len(r.Processes.Orphans.Items) != 2 {
		t.Fatal("parent reuse identity lost")
	}
}
func TestLongestAndCoverage(t *testing.T) {
	root := fixture(t)
	for i := 1; i <= 15; i++ {
		proc(t, root, i, 0, uint64(100-i), 1, "S", "worker")
	}
	put(t, root, "99/stat", "malformed")
	s := newFixtureSampler(t, root, 100)
	r, _ := s.Sample(time.Now())
	if len(r.Processes.LongestRunning.Items) != 10 || r.Processes.LongestRunning.Items[0].Identity.PID != 15 {
		t.Fatal("longest must use boot start order")
	}
	if r.Coverage.Processes.Status != "partial" {
		t.Fatal("malformed process must reduce coverage")
	}
	restarted := newFixtureSampler(t, root, 100)
	r2, _ := restarted.Sample(time.Now())
	if r2.Processes.LongestRunning.Items[0].Identity != r.Processes.LongestRunning.Items[0].Identity {
		t.Fatal("restart changed longest ordering")
	}
	limited := newFixtureSampler(t, root, 2)
	r3, _ := limited.Sample(time.Now())
	if r3.Coverage.Processes.Status != "partial" || r3.Processes.LongestRunning.Completeness != "partial" {
		t.Fatal("scan limit must be explicit")
	}
}
