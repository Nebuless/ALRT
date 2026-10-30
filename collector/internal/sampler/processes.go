package sampler

import (
	"errors"
	"io"
	"math"
	"os"
	"sort"
	"strconv"
	"strings"
	"unicode"
	"unicode/utf8"

	"github.com/Nebuless/ALRT/collector/internal/protocol"
)

type processSample struct {
	process protocol.Process
	ppid    int
	cpu     uint64
}

func parseProcess(b []byte, boot string, pid int, pageSize uint64) (processSample, error) {
	bad := errors.New("process_unavailable")
	// comm may contain spaces, parentheses, or newlines. The final ')' delimits
	// it; field offsets below are relative to state (the third proc stat field).
	raw := string(b)
	open := strings.IndexByte(raw, '(')
	close := strings.LastIndexByte(raw, ')')
	if open < 1 || close < open || close+2 >= len(raw) {
		return processSample{}, bad
	}
	parsedPID, e := strconv.Atoi(strings.TrimSpace(raw[:open]))
	if e != nil || parsedPID != pid {
		return processSample{}, bad
	}
	f := strings.Fields(raw[close+1:])
	if len(f) < 22 || len(f[0]) != 1 || !strings.Contains("RSDZTtXxKWPI", f[0]) {
		return processSample{}, bad
	}
	ppid, e := strconv.Atoi(f[1])
	if e != nil || ppid < 0 {
		return processSample{}, bad
	}
	user, e1 := strconv.ParseUint(f[11], 10, 64)
	system, e2 := strconv.ParseUint(f[12], 10, 64)
	start, e3 := strconv.ParseUint(f[19], 10, 64)
	rss, e4 := strconv.ParseInt(f[21], 10, 64)
	if e1 != nil || e2 != nil || e3 != nil || e4 != nil || math.MaxUint64-user < system {
		return processSample{}, bad
	}
	name := strings.ToValidUTF8(raw[open+1:close], "")
	name = strings.Map(func(r rune) rune {
		if unicode.IsControl(r) {
			return -1
		}
		return r
	}, name)
	if len(name) > 64 {
		name = name[:64]
		for !utf8.ValidString(name) {
			name = name[:len(name)-1]
		}
	}
	p := protocol.Process{Identity: protocol.Identity{BootID: boot, PID: pid, StartTicks: start}, Name: name, State: f[0], Parent: protocol.Parent{Status: "unknown"}, OrphanStatus: "none"}
	if rss >= 0 && uint64(rss) <= math.MaxUint64/pageSize {
		p.RSSBytes = ptr(uint64(rss) * pageSize)
	}
	return processSample{process: p, ppid: ppid, cpu: user + system}, nil
}

func (s *Sampler) sampleProcesses(elapsed *float64) (protocol.Processes, protocol.ProcessCoverage) {
	coverage := protocol.ProcessCoverage{Status: "complete"}
	current := make(map[int]processSample)
	f, err := os.Open(s.config.ProcRoot)
	if err != nil {
		coverage.Status = "unavailable"
		coverage.Reason = ptr(failure(err))
		s.processes = current
		return s.categories(current, coverage, elapsed), coverage
	}
	entries, err := f.ReadDir(s.config.MaxDirectoryEntries + 1)
	// The read result determines coverage; closing this read-only directory does
	// not change the entries already observed.
	_ = f.Close()
	// io.EOF is normal when fewer than the configured maximum entries exist.
	if err != nil && !errors.Is(err, io.EOF) && len(entries) == 0 {
		coverage.Status = "unavailable"
		coverage.Reason = ptr("enumeration_failed")
		s.processes = current
		return s.categories(current, coverage, elapsed), coverage
	}
	if err != nil && !errors.Is(err, io.EOF) {
		coverage.Status = "partial"
		coverage.Reason = ptr("enumeration_failed")
	}
	if len(entries) > s.config.MaxDirectoryEntries {
		coverage.EnumerationLimited = true
		entries = entries[:s.config.MaxDirectoryEntries]
	}
	pids := make([]int, 0, len(entries))
	for _, entry := range entries {
		pid, e := strconv.Atoi(entry.Name())
		if e == nil && pid > 0 && strconv.Itoa(pid) == entry.Name() {
			pids = append(pids, pid)
		}
	}
	sort.Ints(pids)
	coverage.Discovered = len(pids)
	if len(pids) > s.config.MaxProcesses {
		coverage.ScanLimited = true
		pids = pids[:s.config.MaxProcesses]
	}
	var uptime *float64
	if b, e := s.read("uptime", 1024); e == nil {
		fields := strings.Fields(string(b))
		if len(fields) > 0 {
			if v, ok := finite(fields[0]); ok {
				uptime = ptr(v)
			}
		}
	}
	for _, pid := range pids {
		b, e := s.read(s.processPath(pid), 16384)
		if e != nil {
			switch {
			case os.IsPermission(e):
				coverage.PermissionDenied++
			case os.IsNotExist(e):
				coverage.Vanished++
			default:
				coverage.Malformed++
			}
			continue
		}
		p, e := parseProcess(b, s.boot, pid, uint64(os.Getpagesize()))
		if e != nil {
			coverage.Malformed++
			continue
		}
		if uptime != nil {
			age := *uptime - float64(p.process.Identity.StartTicks)/float64(s.ticks)
			if age >= 0 {
				p.process.AgeSeconds = ptr(age)
			}
		}
		if prev, ok := s.processes[pid]; ok && prev.process.Identity == p.process.Identity && elapsed != nil && p.cpu >= prev.cpu {
			p.process.CPUPercent = ptr(float64(p.cpu-prev.cpu) / float64(s.ticks) / *elapsed * 100)
		}
		current[pid] = p
	}
	coverage.Sampled = len(current)
	if coverage.ScanLimited || coverage.EnumerationLimited || coverage.PermissionDenied > 0 || coverage.Vanished > 0 || coverage.Malformed > 0 {
		coverage.Status = "partial"
		coverage.Reason = ptr("process_scan_incomplete")
	}
	for pid, p := range current {
		if parent, ok := current[p.ppid]; ok && parent.process.Identity != p.process.Identity {
			p.process.Parent = protocol.Parent{Identity: ptr(parent.process.Identity), Status: "known"}
		}
		if p.ppid == 1 || (p.ppid != 0 && p.process.Parent.Identity == nil) {
			p.process.OrphanStatus = "uncertain"
		}
		if previous, ok := s.processes[pid]; ok && previous.process.Identity == p.process.Identity {
			if previous.process.OrphanStatus == "proven" {
				p.process.OrphanStatus = "proven"
				p.process.FormerParent = previous.process.FormerParent
			}
			old := previous.process.Parent.Identity
			changed := old != nil && (p.ppid != old.PID || (p.process.Parent.Identity != nil && *p.process.Parent.Identity != *old))
			if changed && p.process.OrphanStatus != "proven" {
				p.process.OrphanStatus = "uncertain"
				// A missed or permission-denied scan alone proves nothing. Verify the old
				// identity disappeared, or was replaced, using one bounded stat read.
				b, e := s.read(s.processPath(old.PID), 16384)
				gone := os.IsNotExist(e)
				if e == nil {
					if candidate, e := parseProcess(b, s.boot, old.PID, uint64(os.Getpagesize())); e == nil && candidate.process.Identity != *old {
						gone = true
					}
				}
				if gone {
					p.process.OrphanStatus = "proven"
					p.process.FormerParent = old
				}
			}
		}
		current[pid] = p
	}
	result := s.categories(current, coverage, elapsed)
	s.processes = current
	return result, coverage
}

func identityLess(a, b protocol.Process) bool {
	if a.Identity.StartTicks != b.Identity.StartTicks {
		return a.Identity.StartTicks < b.Identity.StartTicks
	}
	return a.Identity.PID < b.Identity.PID
}

func (s *Sampler) categories(current map[int]processSample, coverage protocol.ProcessCoverage, elapsed *float64) protocol.Processes {
	all := make([]protocol.Process, 0, len(current))
	for _, p := range current {
		all = append(all, p.process)
	}
	sort.Slice(all, func(i, j int) bool { return identityLess(all[i], all[j]) })
	longest := append([]protocol.Process{}, all[:min(10, len(all))]...)
	cpu := []protocol.Process{}
	rss := []protocol.Process{}
	orphans := []protocol.Process{}
	zombies := []protocol.Process{}
	for _, p := range all {
		if p.CPUPercent != nil {
			cpu = append(cpu, p)
		}
		if p.RSSBytes != nil {
			rss = append(rss, p)
		}
		if p.OrphanStatus == "proven" {
			orphans = append(orphans, p)
		}
		if p.State == "Z" {
			zombies = append(zombies, p)
		}
	}
	sort.SliceStable(cpu, func(i, j int) bool { return *cpu[i].CPUPercent > *cpu[j].CPUPercent })
	sort.SliceStable(rss, func(i, j int) bool { return *rss[i].RSSBytes > *rss[j].RSSBytes })
	topCPUCount, topRSSCount := len(cpu), len(rss)
	if len(cpu) > 10 {
		cpu = cpu[:10]
	}
	if len(rss) > 10 {
		rss = rss[:10]
	}
	category := func(items []protocol.Process, total, limit int) protocol.Category {
		c := protocol.Category{Items: items, Completeness: coverage.Status, ChunkIndex: 0, ChunkCount: 1, TotalObserved: total, Reason: coverage.Reason}
		if len(items) > limit {
			c.Items = items[:limit]
			c.Completeness = "partial"
			c.Reason = ptr("category_limit")
		}
		return c
	}
	r := protocol.Processes{TopCPU: category(cpu, topCPUCount, 10), TopRSS: category(rss, topRSSCount, 10), LongestRunning: category(longest, len(all), 10), Orphans: category(orphans, len(orphans), s.config.MaxCategoryItems), Zombies: category(zombies, len(zombies), s.config.MaxCategoryItems)}
	if elapsed == nil && coverage.Status != "unavailable" {
		r.TopCPU.Completeness = "unavailable"
		r.TopCPU.Reason = ptr("first_sample")
	} else if topCPUCount < len(all) && r.TopCPU.Completeness == "complete" {
		r.TopCPU.Completeness = "partial"
		r.TopCPU.Reason = ptr("unavailable")
	}
	if topRSSCount < len(all) && r.TopRSS.Completeness == "complete" {
		r.TopRSS.Completeness = "partial"
		r.TopRSS.Reason = ptr("unavailable")
	}
	return r
}
