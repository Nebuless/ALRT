// Package sampler reads a small, explicit set of Linux counters. It never reads
// process command lines, environment, working directories, executable links, or
// descriptors. Read failures become fixed codes, never operating-system errors.
package sampler

import (
	"crypto/rand"
	"encoding/binary"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"math"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"syscall"
	"time"

	"github.com/Nebuless/ALRT/collector/internal/protocol"
)

var labelPattern = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$`)
var bootPattern = regexp.MustCompile(`^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$`)

type Config struct {
	ProcRoot  string
	MachineID string
	StreamID  string
	Scope     string
	// Disks maps opaque labels to local mount paths; Interfaces maps opaque labels
	// to local kernel interface names. Neither source identifier is serialized.
	Disks               map[string]string
	Interfaces          map[string]string
	MaxProcesses        int
	MaxDirectoryEntries int
	MaxCategoryItems    int
	ClockTicksPerSecond uint64
}
type cpuCounter struct{ total, idle uint64 }
type netCounter struct{ rx, tx uint64 }
type Sampler struct {
	config    Config
	stream    string
	sequence  uint64
	boot      string
	last      time.Time
	cpu       *cpuCounter
	network   map[string]netCounter
	processes map[int]processSample
	ticks     uint64
}

func New(c Config) (*Sampler, error) {
	if c.ProcRoot == "" {
		c.ProcRoot = "/proc"
	}
	if c.Scope == "" {
		c.Scope = "host"
	}
	if strings.EqualFold(c.MachineID, "primary") || strings.EqualFold(c.MachineID, "primary-hub") {
		c.Scope = "sandbox"
	}
	if !labelPattern.MatchString(c.MachineID) || (c.Scope != "host" && c.Scope != "sandbox") {
		return nil, errors.New("invalid_config")
	}
	if c.StreamID == "" {
		b := make([]byte, 16)
		if _, err := rand.Read(b); err != nil {
			return nil, errors.New("stream_id_unavailable")
		}
		c.StreamID = hex.EncodeToString(b)
	}
	if !labelPattern.MatchString(c.StreamID) {
		return nil, errors.New("invalid_config")
	}
	for label := range c.Disks {
		if !labelPattern.MatchString(label) {
			return nil, errors.New("invalid_resource_label")
		}
	}
	for label := range c.Interfaces {
		if !labelPattern.MatchString(label) {
			return nil, errors.New("invalid_resource_label")
		}
	}
	if len(c.Disks) > 32 || len(c.Interfaces) > 32 {
		return nil, errors.New("resource_limit")
	}
	if c.MaxProcesses <= 0 {
		c.MaxProcesses = 4096
	}
	if c.MaxDirectoryEntries <= 0 {
		c.MaxDirectoryEntries = 65536
	}
	if c.MaxCategoryItems <= 0 {
		c.MaxCategoryItems = 256
	}
	if c.MaxProcesses > 65536 || c.MaxDirectoryEntries > 1000000 || c.MaxCategoryItems > 4096 {
		return nil, errors.New("resource_limit")
	}
	ticks := c.ClockTicksPerSecond
	if ticks == 0 {
		ticks = clockTicks(c.ProcRoot)
	}
	if ticks == 0 || ticks > 1000000 {
		return nil, errors.New("invalid_config")
	}
	return &Sampler{config: c, stream: c.StreamID, ticks: ticks, network: make(map[string]netCounter), processes: make(map[int]processSample)}, nil
}
func read(path string, limit int64) ([]byte, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer func() {
		// Closing a read-only proc descriptor cannot invalidate counters already read.
		_ = f.Close()
	}()
	b, err := io.ReadAll(io.LimitReader(f, limit+1))
	if err != nil {
		return nil, err
	}
	if int64(len(b)) > limit {
		return nil, errors.New("read_limit")
	}
	return b, nil
}
func (s *Sampler) read(name string, limit int64) ([]byte, error) {
	return read(filepath.Join(s.config.ProcRoot, name), limit)
}
func clockTicks(root string) uint64 {
	// Linux exposes USER_HZ in AT_CLKTCK. The documented Linux fallback is 100.
	b, err := read(filepath.Join(root, "self/auxv"), 8192)
	if err == nil {
		size := strconv.IntSize / 8
		for i := 0; i+2*size <= len(b); i += 2 * size {
			var k, v uint64
			if size == 8 {
				k = binary.NativeEndian.Uint64(b[i:])
				v = binary.NativeEndian.Uint64(b[i+size:])
			} else {
				k = uint64(binary.NativeEndian.Uint32(b[i:]))
				v = uint64(binary.NativeEndian.Uint32(b[i+size:]))
			}
			if k == 17 && v > 0 {
				return v
			}
		}
	}
	return 100
}
func ptr[T any](v T) *T                     { return &v }
func missing(status string) protocol.Metric { return protocol.Metric{Status: status} }
func available(value float64) protocol.Metric {
	return protocol.Metric{Status: "available", Value: ptr(value)}
}
func failure(err error) string {
	if os.IsPermission(err) {
		return "permission_denied"
	}
	if os.IsNotExist(err) {
		return "unsupported"
	}
	return "unavailable"
}
func finite(v string) (float64, bool) {
	n, e := strconv.ParseFloat(v, 64)
	return n, e == nil && !math.IsNaN(n) && !math.IsInf(n, 0) && n >= 0
}

// Sample uses the caller's clock so process rates use the actual elapsed interval.
// A sampler is owned by one sampling loop and is not safe for concurrent calls.
func (s *Sampler) Sample(now time.Time) (protocol.Report, error) {
	if !s.last.IsZero() && !now.After(s.last) {
		return protocol.Report{}, errors.New("non_increasing_sample_time")
	}
	bootBytes, err := s.read("sys/kernel/random/boot_id", 128)
	if err != nil {
		return protocol.Report{}, errors.New("boot_id_unavailable")
	}
	boot := strings.TrimSpace(string(bootBytes))
	if !bootPattern.MatchString(boot) {
		return protocol.Report{}, errors.New("boot_id_invalid")
	}
	if s.boot != "" && s.boot != boot {
		s.cpu = nil
		s.processes = make(map[int]processSample)
		s.network = make(map[string]netCounter)
		s.last = time.Time{}
	}
	s.boot = boot
	var elapsed *float64
	if !s.last.IsZero() {
		elapsed = ptr(now.Sub(s.last).Seconds())
	}
	metrics := s.hostMetrics(elapsed)
	processes, coverage := s.sampleProcesses(elapsed)
	s.sequence++
	r := protocol.Report{ProtocolVersion: protocol.Version, MachineID: s.config.MachineID, StreamID: s.stream, Sequence: s.sequence, BootID: boot, SampledAt: now.UTC().Format(time.RFC3339Nano), ElapsedSeconds: elapsed, Scope: s.config.Scope, Metrics: metrics, Processes: processes, Coverage: protocol.Coverage{Processes: coverage}, Capabilities: protocol.Capabilities{OS: "linux", ProcessIdentity: true, CPU: metrics.CPUPercent.Status == "available" || metrics.CPUPercent.Status == "first_sample", Memory: metrics.Memory.TotalBytes.Value != nil, Disk: false, Network: false, Load: metrics.Load.One.Value != nil, PSICPU: metrics.Pressure.CPU.Some.Status == "available", PSIMemory: metrics.Pressure.Memory.Some.Status == "available", PSIIO: metrics.Pressure.IO.Some.Status == "available", ClockTicksPerSecond: s.ticks}}
	for _, d := range metrics.Disks {
		if d.TotalBytes.Value != nil {
			r.Capabilities.Disk = true
		}
	}
	for _, n := range metrics.Network {
		if n.ReceiveBytesPerSecond.Status == "available" || n.ReceiveBytesPerSecond.Status == "first_sample" {
			r.Capabilities.Network = true
		}
	}
	s.last = now
	return r, nil
}
func (s *Sampler) hostMetrics(elapsed *float64) protocol.Metrics {
	m := protocol.Metrics{CPUPercent: missing("unavailable"), Disks: []protocol.Disk{}, Network: []protocol.Network{}}
	b, err := s.read("stat", 1<<20)
	var counter *cpuCounter
	if err != nil {
		m.CPUPercent = missing(failure(err))
	} else {
		lines := strings.SplitN(string(b), "\n", 2)
		f := strings.Fields(lines[0])
		if len(f) >= 5 && f[0] == "cpu" {
			c := cpuCounter{}
			valid := true
			for i := 1; i < len(f) && i <= 8; i++ {
				v, e := strconv.ParseUint(f[i], 10, 64)
				if e != nil || math.MaxUint64-c.total < v {
					valid = false
					break
				}
				c.total += v
				if i == 4 || i == 5 {
					c.idle += v
				}
			}
			if valid {
				counter = &c
				m.CPUPercent = missing("first_sample")
				if s.cpu != nil && elapsed != nil {
					m.CPUPercent = missing("counter_reset")
					if c.total > s.cpu.total && c.idle >= s.cpu.idle && c.idle-s.cpu.idle <= c.total-s.cpu.total {
						m.CPUPercent = available(100 * (1 - float64(c.idle-s.cpu.idle)/float64(c.total-s.cpu.total)))
					}
				}
			}
		}
	}
	s.cpu = counter
	m.Memory = s.memory()
	m.Load = s.load()
	m.Pressure = protocol.Pressure{CPU: s.psi("cpu"), Memory: s.psi("memory"), IO: s.psi("io")}
	for _, label := range sortedKeys(s.config.Disks) {
		path := s.config.Disks[label]
		d := protocol.Disk{Label: label, TotalBytes: missing("unavailable"), AvailableBytes: missing("unavailable"), UsedBytes: missing("unavailable")}
		var st syscall.Statfs_t
		if err := syscall.Statfs(path, &st); err != nil {
			d.TotalBytes = missing(failure(err))
			d.AvailableBytes = d.TotalBytes
			d.UsedBytes = d.TotalBytes
		} else if st.Bsize > 0 && st.Bfree <= st.Blocks && st.Bavail <= st.Blocks {
			d.TotalBytes = available(float64(st.Blocks) * float64(st.Bsize))
			d.AvailableBytes = available(float64(st.Bavail) * float64(st.Bsize))
			d.UsedBytes = available(float64(st.Blocks-st.Bfree) * float64(st.Bsize))
		}
		m.Disks = append(m.Disks, d)
	}
	nets, netStatus := s.readNetwork()
	next := make(map[string]netCounter)
	for _, label := range sortedKeys(s.config.Interfaces) {
		name := s.config.Interfaces[label]
		n := protocol.Network{Label: label, ReceiveBytesPerSecond: missing(netStatus), TransmitBytesPerSecond: missing(netStatus)}
		if c, ok := nets[name]; ok {
			n.ReceiveBytesPerSecond = missing("first_sample")
			n.TransmitBytesPerSecond = missing("first_sample")
			if p, exists := s.network[label]; exists && elapsed != nil {
				n.ReceiveBytesPerSecond = missing("counter_reset")
				n.TransmitBytesPerSecond = missing("counter_reset")
				if c.rx >= p.rx {
					n.ReceiveBytesPerSecond = available(float64(c.rx-p.rx) / *elapsed)
				}
				if c.tx >= p.tx {
					n.TransmitBytesPerSecond = available(float64(c.tx-p.tx) / *elapsed)
				}
			}
			next[label] = c
		}
		m.Network = append(m.Network, n)
	}
	s.network = next
	return m
}
func sortedKeys(m map[string]string) []string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	return keys
}
func (s *Sampler) memory() protocol.Memory {
	unknown := missing("unavailable")
	m := protocol.Memory{TotalBytes: unknown, AvailableBytes: unknown, UsedBytes: unknown}
	b, err := s.read("meminfo", 65536)
	if err != nil {
		m.TotalBytes = missing(failure(err))
		m.AvailableBytes = m.TotalBytes
		m.UsedBytes = m.TotalBytes
		return m
	}
	for _, line := range strings.Split(string(b), "\n") {
		f := strings.Fields(line)
		if len(f) != 3 || f[2] != "kB" {
			continue
		}
		v, ok := finite(f[1])
		if !ok {
			continue
		}
		switch f[0] {
		case "MemTotal:":
			m.TotalBytes = available(v * 1024)
		case "MemAvailable:":
			m.AvailableBytes = available(v * 1024)
		}
	}
	if m.TotalBytes.Value != nil && m.AvailableBytes.Value != nil && *m.TotalBytes.Value >= *m.AvailableBytes.Value {
		m.UsedBytes = available(*m.TotalBytes.Value - *m.AvailableBytes.Value)
	}
	return m
}
func (s *Sampler) load() protocol.Load {
	unknown := missing("unavailable")
	m := protocol.Load{One: unknown, Five: unknown, Fifteen: unknown}
	b, err := s.read("loadavg", 1024)
	if err != nil {
		m.One = missing(failure(err))
		m.Five = m.One
		m.Fifteen = m.One
		return m
	}
	f := strings.Fields(string(b))
	if len(f) >= 3 {
		targets := []*protocol.Metric{&m.One, &m.Five, &m.Fifteen}
		for i, target := range targets {
			if v, ok := finite(f[i]); ok {
				*target = available(v)
			}
		}
	}
	return m
}
func (s *Sampler) psi(name string) protocol.PSI {
	unknown := protocol.PressureValues{Status: "unsupported"}
	m := protocol.PSI{Some: unknown, Full: unknown}
	b, err := s.read("pressure/"+name, 4096)
	if err != nil {
		m.Some.Status = failure(err)
		m.Full.Status = m.Some.Status
		return m
	}
	m.Some.Status = "unavailable"
	m.Full.Status = "unavailable"
	for _, line := range strings.Split(string(b), "\n") {
		f := strings.Fields(line)
		if len(f) != 5 {
			continue
		}
		p := protocol.PressureValues{Status: "available"}
		valid := true
		for i, key := range []string{"avg10", "avg60", "avg300", "total"} {
			pair := strings.SplitN(f[i+1], "=", 2)
			if len(pair) != 2 || pair[0] != key {
				valid = false
				break
			}
			if i == 3 {
				v, e := strconv.ParseUint(pair[1], 10, 64)
				if e != nil {
					valid = false
					break
				}
				p.TotalMicroseconds = ptr(v)
			} else {
				v, ok := finite(pair[1])
				if !ok || v > 100 {
					valid = false
					break
				}
				switch i {
				case 0:
					p.Avg10 = ptr(v)
				case 1:
					p.Avg60 = ptr(v)
				case 2:
					p.Avg300 = ptr(v)
				}
			}
		}
		if valid {
			switch f[0] {
			case "some":
				m.Some = p
			case "full":
				m.Full = p
			}
		}
	}
	return m
}
func (s *Sampler) readNetwork() (map[string]netCounter, string) {
	result := make(map[string]netCounter)
	b, err := s.read("net/dev", 1<<20)
	if err != nil {
		return result, failure(err)
	}
	for _, line := range strings.Split(string(b), "\n") {
		parts := strings.SplitN(line, ":", 2)
		if len(parts) != 2 {
			continue
		}
		f := strings.Fields(parts[1])
		if len(f) != 16 {
			continue
		}
		rx, e1 := strconv.ParseUint(f[0], 10, 64)
		tx, e2 := strconv.ParseUint(f[8], 10, 64)
		if e1 == nil && e2 == nil {
			result[strings.TrimSpace(parts[0])] = netCounter{rx, tx}
		}
	}
	return result, "unavailable"
}

func (s *Sampler) processPath(pid int) string { return fmt.Sprintf("%d/stat", pid) }
