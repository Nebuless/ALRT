// Package journal owns a bounded telemetry ring. Only its writer performs mutations;
// no filesystem operation runs under the shared metadata mutex.
package journal

import (
	"encoding/binary"
	"encoding/json"
	"errors"
	"fmt"
	"hash/crc32"
	"io"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"

	"github.com/Nebuless/ALRT/collector/internal/protocol"
)

const reserveBytes int64 = 64 << 10
const headerBytes = 20
const maxSegments = 4096
const maxRecords = 131072

type Config struct {
	Directory    string
	BudgetBytes  int64
	SegmentBytes int64
	Destinations []string
}
type Record struct {
	Position uint64
	Data     []byte
	StreamID string
	Sequence uint64
}
type entry struct {
	position uint64
	path     string
	offset   int64
	length   int
	stream   string
	sequence uint64
	sampled  string
}
type segment struct {
	path    string
	bytes   int64
	entries []entry
}
type persisted struct {
	Version  int               `json:"version"`
	Next     uint64            `json:"next"`
	Cursors  map[string]uint64 `json:"cursors"`
	Delivery protocol.Delivery `json:"delivery"`
	Clean    bool              `json:"clean"`
}
type operation struct {
	data    []byte
	hub     string
	records []Record
	ack     *protocol.Acknowledgement
}
type Journal struct {
	cfg        Config
	mu         sync.Mutex
	state      persisted
	entries    []entry
	pending    bool
	closed     bool
	lastIO     time.Time
	queue      chan operation
	stop       chan struct{}
	done       chan struct{}
	closeOnce  sync.Once
	lastErr    error
	faultEpoch uint64
	// Owned solely by the writer, including recovery before it starts.
	segments []segment
	lastSync time.Time
	lock     *os.File
	file     *os.File
}

func Open(c Config) (*Journal, error) {
	if c.BudgetBytes == 0 {
		c.BudgetBytes = 512 << 20
	}
	if c.SegmentBytes == 0 {
		c.SegmentBytes = 4 << 20
	}
	if c.Directory == "" || c.BudgetBytes < reserveBytes+2*c.SegmentBytes || c.SegmentBytes < 4096 || c.SegmentBytes > 16<<20 || c.BudgetBytes/c.SegmentBytes > maxSegments || len(c.Destinations) == 0 || len(c.Destinations) > 8 {
		return nil, errors.New("invalid_journal_config")
	}
	if e := os.MkdirAll(c.Directory, 0700); e != nil {
		return nil, errors.New("journal_open_failed")
	}
	// Hold the directory's one-writer lease before recovery or metadata mutation.
	lock, e := os.OpenFile(filepath.Join(c.Directory, "writer.lock"), os.O_CREATE|os.O_RDWR, 0600)
	if e != nil {
		return nil, errors.New("journal_open_failed")
	}
	if e = syscall.Flock(int(lock.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); e != nil {
		_ = lock.Close() // Closing the unbuffered lease descriptor is cleanup; its file holds no telemetry.
		if e == syscall.EWOULDBLOCK {
			return nil, errors.New("journal_locked")
		}
		return nil, errors.New("journal_open_failed")
	}
	started := false
	defer func() {
		if !started {
			_ = syscall.Flock(int(lock.Fd()), syscall.LOCK_UN) // Closing the descriptor also releases this cleanup-only lease.
			_ = lock.Close()                                   // Closing the unbuffered lease descriptor is cleanup; its file holds no telemetry.
		}
	}()
	if info, err := lock.Stat(); err != nil || !info.Mode().IsRegular() || info.Size() != 0 {
		return nil, errors.New("journal_storage_invalid")
	}

	j := &Journal{cfg: c, lock: lock, queue: make(chan operation, 32), stop: make(chan struct{}), done: make(chan struct{}), lastIO: time.Now(), lastSync: time.Now(), state: persisted{Version: 1, Next: 1, Cursors: map[string]uint64{}, Delivery: protocol.Delivery{Version: 1, Recording: "recording", PossibleLossSeconds: 30, LostRanges: []protocol.LostRange{}, Acknowledgements: []protocol.Acknowledgement{}}}}
	b, e := readBounded(filepath.Join(c.Directory, "state.json"), reserveBytes/2)
	if e == nil {
		if json.Unmarshal(b, &j.state) != nil || j.state.Version != 1 || len(j.state.Cursors) > 8 || len(j.state.Delivery.LostRanges) > 32 || len(j.state.Delivery.Acknowledgements) > 8 {
			return nil, errors.New("journal_metadata_invalid")
		}
		if !j.state.Clean {
			j.state.Delivery.UnknownCoverage = true
		}
	} else if !os.IsNotExist(e) {
		return nil, errors.New("journal_metadata_invalid")
	}
	if j.state.Cursors == nil {
		return nil, errors.New("journal_metadata_invalid")
	}
	cursors := map[string]uint64{}
	for _, id := range c.Destinations {
		if id == "" {
			return nil, errors.New("invalid_journal_config")
		}
		if _, ok := cursors[id]; ok {
			return nil, errors.New("invalid_journal_config")
		}
		cursors[id] = j.state.Cursors[id]
	}
	j.state.Cursors = cursors
	acks := make([]protocol.Acknowledgement, 0, 8)
	for _, a := range j.state.Delivery.Acknowledgements {
		if _, ok := cursors[a.HubID]; ok {
			acks = append(acks, a)
		}
	}
	j.state.Delivery.Acknowledgements = acks
	if e = j.recover(); e != nil {
		return nil, e
	}
	j.state.Clean = false
	if e = j.persist(); e != nil {
		return nil, e
	}
	started = true
	go j.run()
	return j, nil
}
func readBounded(path string, max int64) ([]byte, error) {
	f, e := os.Open(path)
	if e != nil {
		return nil, e
	}
	defer func() { _ = f.Close() }() // Read-only descriptor; close cannot invalidate the completed read or sync.
	b, e := io.ReadAll(io.LimitReader(f, max+1))
	if int64(len(b)) > max {
		return nil, errors.New("read_limit")
	}
	return b, e
}
func (j *Journal) recover() error {
	directory, e := os.Open(j.cfg.Directory)
	if e != nil {
		return errors.New("journal_open_failed")
	}
	files, e := directory.Readdir(maxSegments + 4)
	_ = directory.Close() // Read-only directory enumeration has already completed.
	if e == io.EOF {
		e = nil
	}
	if e != nil {
		return errors.New("journal_open_failed")
	}
	if len(files) > maxSegments+3 {
		return errors.New("journal_storage_limit")
	}
	var names []string
	var bytes int64
	for _, f := range files {
		if f.Name() == "state.tmp" {
			if e = os.Remove(filepath.Join(j.cfg.Directory, f.Name())); e != nil {
				return errors.New("journal_write_failed")
			}
			continue
		}
		if f.Name() == "state.json" || f.Name() == "writer.lock" {
			continue
		}
		if !strings.HasSuffix(f.Name(), ".seg") || f.IsDir() {
			return errors.New("journal_storage_invalid")
		}
		names = append(names, f.Name())
	}
	sort.Strings(names)
	var previous uint64
	for _, name := range names {
		start, e := strconv.ParseUint(strings.TrimSuffix(name, ".seg"), 10, 64)
		if e != nil || start == 0 {
			return errors.New("journal_storage_invalid")
		}
		path := filepath.Join(j.cfg.Directory, name)
		info, e := os.Lstat(path)
		if e != nil || !info.Mode().IsRegular() || info.Size() > j.cfg.SegmentBytes || bytes+info.Size() > j.cfg.BudgetBytes-reserveBytes {
			return errors.New("journal_storage_limit")
		}
		f, e := os.OpenFile(path, os.O_RDWR, 0600)
		if e != nil {
			return errors.New("journal_open_failed")
		}
		s := segment{path: path}
		for {
			header := make([]byte, headerBytes)
			n, e := io.ReadFull(f, header)
			if e == io.EOF && n == 0 {
				break
			}
			if e == nil && string(header[:3]) == "NBJ" && header[3] != '1' {
				_ = f.Close() // Cleanup preserves the primary failure.
				return errors.New("journal_version_unsupported")
			}
			valid := e == nil && string(header[:4]) == "NBJ1"
			length := int(binary.BigEndian.Uint32(header[4:8]))
			pos := binary.BigEndian.Uint64(header[8:16])
			if !valid || length < 1 || length > protocol.MaxReportBytes || s.bytes+int64(headerBytes+length) > j.cfg.SegmentBytes || pos <= previous {
				j.recoveryLoss()
				break
			}
			b := make([]byte, length)
			if _, e = io.ReadFull(f, b); e != nil || crc32.Update(crc32.ChecksumIEEE(header[8:16]), crc32.IEEETable, b) != binary.BigEndian.Uint32(header[16:20]) {
				j.recoveryLoss()
				break
			}
			r, e := protocol.Decode(b)
			if e != nil && e.Error() == "protocol_version_unsupported" {
				_ = f.Close() // Cleanup preserves the primary failure.
				return e
			}
			if e != nil {
				j.recoveryLoss()
				break
			}
			en := entry{position: pos, path: path, offset: s.bytes + headerBytes, length: length, stream: r.StreamID, sequence: r.Sequence, sampled: r.SampledAt}
			s.entries = append(s.entries, en)
			j.entries = append(j.entries, en)
			if len(j.entries) > maxRecords {
				_ = f.Close() // Cleanup preserves the primary failure.
				return errors.New("journal_storage_limit")
			}
			s.bytes += int64(headerBytes + length)
			previous = pos
			if pos >= j.state.Next {
				j.state.Next = pos + 1
			}
		}
		if e = f.Truncate(s.bytes); e != nil {
			_ = f.Close() // Cleanup preserves the primary failure.
			return errors.New("journal_write_failed")
		}
		if e = f.Sync(); e != nil {
			_ = f.Close() // Cleanup preserves the primary failure.
			return errors.New("journal_sync_failed")
		}
		if e = f.Close(); e != nil {
			return errors.New("journal_write_failed")
		}
		j.segments = append(j.segments, s)
		bytes += s.bytes
	}
	if bytes > j.cfg.BudgetBytes-reserveBytes {
		return errors.New("journal_storage_limit")
	}
	return nil
}
func (j *Journal) recoveryLoss() {
	j.state.Delivery.UnknownCoverage = true
	j.state.Delivery.Recording = "recovery_loss"
}
func (j *Journal) failure(code string) {
	j.mu.Lock()
	j.faultEpoch++
	j.state.Delivery.Recording = code
	j.state.Delivery.UnknownCoverage = true
	j.lastErr = errors.New(code)
	j.mu.Unlock()
}
func (j *Journal) Append(data []byte) bool {
	r, e := protocol.Decode(data)
	if e != nil || len(data) > protocol.MaxReportBytes {
		return false
	}
	j.mu.Lock()
	defer j.mu.Unlock()
	if j.closed {
		return false
	}
	select {
	case j.queue <- operation{data: append([]byte(nil), data...)}:
		return true
	default:
		j.lossLocked("", r, "queue_full")
		j.faultEpoch++
		j.state.Delivery.Recording = "queue_full"
		return false
	}
}
func (j *Journal) Delivery() protocol.Delivery {
	j.mu.Lock()
	defer j.mu.Unlock()
	d := j.state.Delivery
	d.LostRanges = append([]protocol.LostRange{}, d.LostRanges...)
	d.Acknowledgements = append([]protocol.Acknowledgement{}, d.Acknowledgements...)
	if j.pending && time.Since(j.lastIO) > 30*time.Second {
		d.Recording = "storage_blocked"
		d.UnknownCoverage = true
	}
	return d
}

// Discard only a frame whose identity/time came from the previously verified
// index. Its loss is captured by the next synchronized cursor transaction.
func (j *Journal) discardDamaged(en entry) {
	j.mu.Lock()
	defer j.mu.Unlock()
	found := false
	kept := make([]entry, 0, len(j.entries))
	for _, candidate := range j.entries {
		if candidate.position == en.position {
			found = true
			continue
		}
		kept = append(kept, candidate)
	}
	if !found {
		return
	}
	j.entries = kept
	j.faultEpoch++
	j.state.Delivery.Recording = "journal_corrupt"
	for hub, cursor := range j.state.Cursors {
		if cursor < en.position {
			j.lossLocked(hub, protocol.Report{StreamID: en.stream, Sequence: en.sequence, SampledAt: en.sampled}, "corruption")
		}
	}
}
func (j *Journal) Cursor(hub string) uint64 {
	j.mu.Lock()
	defer j.mu.Unlock()
	return j.state.Cursors[hub]
}
func (j *Journal) Batch(hub string) ([]Record, error) {
	j.mu.Lock()
	cursor, ok := j.state.Cursors[hub]
	var selected []entry
	size := len(`{"reports":[]}`)
	if ok {
		start := sort.Search(len(j.entries), func(i int) bool {
			return j.entries[i].position > cursor
		})
		for _, e := range j.entries[start:] {
			if len(selected) == 128 || size+e.length+1 > protocol.MaxBatchBytes {
				break
			}
			selected = append(selected, e)
			size += e.length + 1
		}
	}
	j.mu.Unlock()
	if !ok {
		return nil, errors.New("unknown_destination")
	}
	records := make([]Record, 0, len(selected))
	for _, e := range selected {
		f, err := os.Open(e.path)
		if err != nil {
			return nil, errors.New("journal_read_failed")
		}
		frame := make([]byte, headerBytes+e.length)
		_, err = f.ReadAt(frame, e.offset-headerBytes)
		b := frame[headerBytes:]
		_ = f.Close() // Read-only replay descriptor; validated bytes have already been read.
		if err != nil {
			return nil, errors.New("journal_read_failed")
		}
		if string(frame[:4]) != "NBJ1" || int(binary.BigEndian.Uint32(frame[4:8])) != e.length || binary.BigEndian.Uint64(frame[8:16]) != e.position || crc32.Update(crc32.ChecksumIEEE(frame[8:16]), crc32.IEEETable, b) != binary.BigEndian.Uint32(frame[16:20]) {
			j.discardDamaged(e)
			continue
		}
		records = append(records, Record{Position: e.position, Data: b, StreamID: e.stream, Sequence: e.sequence})
	}
	return records, nil
}

// Commit submits only a sent, acknowledged prefix. The writer checks it against
// the retained prefix again, so eviction or a contact ACK cannot skip backlog.
func (j *Journal) Commit(hub string, records []Record) bool {
	if len(records) == 0 || len(records) > 128 {
		return false
	}
	copyRecords := make([]Record, len(records))
	for i, r := range records {
		copyRecords[i] = Record{Position: r.Position, StreamID: r.StreamID, Sequence: r.Sequence}
	}
	return j.submit(operation{hub: hub, records: copyRecords})
}
func (j *Journal) NoteACK(a protocol.Acknowledgement) bool {
	return j.submit(operation{hub: a.HubID, ack: &a})
}
func (j *Journal) submit(op operation) bool {
	j.mu.Lock()
	defer j.mu.Unlock()
	if j.closed {
		return false
	}
	if _, ok := j.state.Cursors[op.hub]; !ok {
		return false
	}
	select {
	case j.queue <- op:
		return true
	default:
		return false
	}
}
func (j *Journal) Close() error {
	j.closeOnce.Do(func() { j.mu.Lock(); j.closed = true; j.mu.Unlock(); close(j.stop) })
	select {
	case <-j.done:
		j.mu.Lock()
		defer j.mu.Unlock()
		return j.lastErr
	case <-time.After(2 * time.Second):
		return errors.New("journal_storage_blocked")
	}
}
func (j *Journal) run() {
	defer close(j.done)
	defer func() {
		// Neither operation writes telemetry; closing always releases the lease.
		_ = syscall.Flock(int(j.lock.Fd()), syscall.LOCK_UN)
		_ = j.lock.Close()
	}()

	ticker := time.NewTicker(30 * time.Second)
	defer ticker.Stop()
	for {
		select {
		case op := <-j.queue:
			j.perform(op)
		case <-ticker.C:
			j.flush(false)
		case <-j.stop:
			for {
				select {
				case op := <-j.queue:
					j.perform(op)
				default:
					j.flush(true)
					if j.file != nil {
						if err := j.file.Close(); err != nil {
							j.failure("journal_write_failed")
						}
					}
					return
				}
			}
		}
	}
}
func (j *Journal) perform(op operation) {
	j.mu.Lock()
	j.pending = true
	j.lastIO = time.Now()
	j.mu.Unlock()
	defer func() { j.mu.Lock(); j.pending = false; j.mu.Unlock() }()
	if op.data != nil {
		if e := j.appendDisk(op.data); e != nil {
			j.failure(e.Error())
		}
	} else {
		j.commit(op)
	}
	if time.Since(j.lastSync) >= 30*time.Second {
		j.flush(false)
	}
}
func (j *Journal) appendDisk(data []byte) error {
	r, _ := protocol.Decode(data)
	frameSize := int64(headerBytes + len(data))
	if frameSize > j.cfg.SegmentBytes {
		j.mu.Lock()
		j.lossLocked("", r, "write_failed")
		j.mu.Unlock()
		return errors.New("journal_write_failed")
	}
	j.mu.Lock()
	pos := j.state.Next
	j.state.Next++
	j.mu.Unlock()
	if j.file == nil || len(j.segments) == 0 || j.segments[len(j.segments)-1].bytes+frameSize > j.cfg.SegmentBytes {
		if j.file != nil {
			if e := j.file.Sync(); e != nil {
				return errors.New("journal_sync_failed")
			}
			closeErr := j.file.Close()
			j.file = nil
			if closeErr != nil {
				return errors.New("journal_write_failed")
			}
		}
		path := filepath.Join(j.cfg.Directory, fmt.Sprintf("%020d.seg", pos))
		f, e := os.OpenFile(path, os.O_CREATE|os.O_EXCL|os.O_RDWR, 0600)
		if e != nil {
			return errors.New("journal_write_failed")
		}
		j.file = f
		j.segments = append(j.segments, segment{path: path})
		if e = syncDirectory(j.cfg.Directory); e != nil {
			return errors.New("journal_sync_failed")
		}
	}
	var total int64
	for _, s := range j.segments {
		total += s.bytes
	}
	for total+frameSize > j.cfg.BudgetBytes-reserveBytes || len(j.segments) > maxSegments || len(j.entries) >= maxRecords {
		if len(j.segments) < 2 {
			return errors.New("journal_write_failed")
		}
		s := j.segments[0]
		j.mu.Lock()
		for _, en := range s.entries {
			for hub, cursor := range j.state.Cursors {
				if en.position > cursor {
					j.lossLocked(hub, protocol.Report{StreamID: en.stream, Sequence: en.sequence, SampledAt: en.sampled}, "eviction")
				}
			}
		}
		j.mu.Unlock()
		if e := j.persist(); e != nil {
			return e
		}
		if e := os.Remove(s.path); e != nil {
			return errors.New("journal_write_failed")
		}
		if e := syncDirectory(j.cfg.Directory); e != nil {
			return errors.New("journal_sync_failed")
		}
		j.mu.Lock()
		cut := 0
		for cut < len(j.entries) && j.entries[cut].path == s.path {
			cut++
		}
		j.entries = append([]entry(nil), j.entries[cut:]...)
		j.mu.Unlock()
		total -= s.bytes
		j.segments = j.segments[1:]
	}
	s := &j.segments[len(j.segments)-1]
	header := make([]byte, headerBytes)
	copy(header, "NBJ1")
	binary.BigEndian.PutUint32(header[4:8], uint32(len(data)))
	binary.BigEndian.PutUint64(header[8:16], pos)
	binary.BigEndian.PutUint32(header[16:20], crc32.Update(crc32.ChecksumIEEE(header[8:16]), crc32.IEEETable, data))
	frame := append(header, data...)
	n, e := j.file.Write(frame)
	if e != nil || n != len(frame) {
		if err := j.file.Truncate(s.bytes); err != nil {
			// The abandoned tail may contain a partial frame. Charge the full
			// bounded segment until eviction instead of undercounting disk use.
			s.bytes = j.cfg.SegmentBytes
			_ = j.file.Close() // Failed rollback already determines the write failure; retire this descriptor.
			j.file = nil
		} else if _, err = j.file.Seek(s.bytes, io.SeekStart); err != nil {
			_ = j.file.Close() // Failed repositioning makes further writes unsafe; retire this descriptor.
			j.file = nil
		}
		j.mu.Lock()
		j.lossLocked("", r, "write_failed")
		j.mu.Unlock()
		return errors.New("journal_write_failed")
	}
	en := entry{position: pos, path: s.path, offset: s.bytes + headerBytes, length: len(data), stream: r.StreamID, sequence: r.Sequence, sampled: r.SampledAt}
	s.bytes += frameSize
	s.entries = append(s.entries, en)
	j.mu.Lock()
	j.entries = append(j.entries, en)
	j.mu.Unlock()
	return nil
}
func (j *Journal) lossLocked(hub string, r protocol.Report, reason string) {
	if hub == "" {
		for id := range j.state.Cursors {
			j.lossLocked(id, r, reason)
		}
		return
	}
	d := &j.state.Delivery
	n := len(d.LostRanges)
	if n > 0 {
		last := &d.LostRanges[n-1]
		if last.HubID == hub && last.StreamID == r.StreamID && last.Reason == reason && last.LastSequence+1 == r.Sequence {
			last.LastSequence = r.Sequence
			last.To = r.SampledAt
			return
		}
	}
	if n >= 32 {
		d.UnknownCoverage = true
		d.LostRanges = d.LostRanges[1:]
	}
	d.LostRanges = append(d.LostRanges, protocol.LostRange{HubID: hub, StreamID: r.StreamID, FirstSequence: r.Sequence, LastSequence: r.Sequence, From: r.SampledAt, To: r.SampledAt, Reason: reason})
}
func (j *Journal) flush(clean bool) {
	j.mu.Lock()
	j.pending = true
	j.lastIO = time.Now()
	j.mu.Unlock()
	defer func() { j.mu.Lock(); j.pending = false; j.mu.Unlock() }()
	if j.file != nil {
		if e := j.file.Sync(); e != nil {
			j.failure("journal_sync_failed")
			return
		}
	}
	j.mu.Lock()
	proposal := j.state
	proposal.Cursors = copyCursors(j.state.Cursors)
	proposal.Delivery.LostRanges = append([]protocol.LostRange{}, j.state.Delivery.LostRanges...)
	proposal.Delivery.Acknowledgements = append([]protocol.Acknowledgement{}, j.state.Delivery.Acknowledgements...)
	proposal.Clean = clean
	proposal.Delivery.Recording = "recording"
	epoch := j.faultEpoch
	j.mu.Unlock()
	if e := j.persistProposal(proposal); e != nil {
		j.failure(e.Error())
		return
	}
	j.mu.Lock()
	j.lastSync = time.Now()
	j.state.Clean = clean
	if j.faultEpoch == epoch {
		j.state.Delivery.Recording = "recording"
		j.lastErr = nil
	}
	j.mu.Unlock()
}
func syncDirectory(path string) error {
	f, e := os.Open(path)
	if e != nil {
		return e
	}
	defer func() { _ = f.Close() }() // Read-only descriptor; close cannot invalidate the completed read or sync.
	return f.Sync()
}
func (j *Journal) persist() error {
	j.mu.Lock()
	state := j.state
	state.Cursors = copyCursors(j.state.Cursors)
	state.Delivery.LostRanges = append([]protocol.LostRange{}, j.state.Delivery.LostRanges...)
	state.Delivery.Acknowledgements = append([]protocol.Acknowledgement{}, j.state.Delivery.Acknowledgements...)
	j.mu.Unlock()
	return j.persistProposal(state)
}
func copyCursors(in map[string]uint64) map[string]uint64 {
	out := make(map[string]uint64, len(in))
	for k, v := range in {
		out[k] = v
	}
	return out
}
func (j *Journal) persistProposal(state persisted) error {
	b, e := json.Marshal(state)
	if e != nil || len(b) > int(reserveBytes/2) {
		return errors.New("journal_metadata_limit")
	}
	tmp := filepath.Join(j.cfg.Directory, "state.tmp")
	f, e := os.OpenFile(tmp, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0600)
	if e != nil {
		return errors.New("journal_write_failed")
	}
	n, e := f.Write(b)
	if e != nil || n != len(b) {
		_ = f.Close() // Cleanup preserves the primary failure.
		return errors.New("journal_write_failed")
	}
	if e = f.Sync(); e != nil {
		_ = f.Close() // Cleanup preserves the primary failure.
		return errors.New("journal_sync_failed")
	}
	if e = f.Close(); e != nil {
		return errors.New("journal_write_failed")
	}
	if e = os.Rename(tmp, filepath.Join(j.cfg.Directory, "state.json")); e != nil {
		return errors.New("journal_write_failed")
	}
	if e = syncDirectory(j.cfg.Directory); e != nil {
		return errors.New("journal_sync_failed")
	}
	return nil
}
