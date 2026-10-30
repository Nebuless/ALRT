package journal

import (
	"bytes"
	"encoding/binary"
	"encoding/json"
	"hash/crc32"
	"io"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
	"time"

	"github.com/Nebuless/ALRT/collector/internal/protocol"
)

func checkIO(t *testing.T, err error) {
	t.Helper()
	if err != nil {
		t.Fatal(err)
	}
}
func closeJournal(t *testing.T, j *Journal) {
	t.Helper()
	if err := j.Close(); err != nil {
		t.Errorf("journal close: %v", err)
	}
}
func report(t *testing.T, seq uint64) []byte {
	t.Helper()
	b, e := os.ReadFile("../../../contracts/fixtures/telemetry/valid-sample.json")
	if e != nil {
		t.Fatal(e)
	}
	r, e := protocol.Decode(b)
	if e != nil {
		t.Fatal(e)
	}
	r.Sequence = seq
	b, e = json.Marshal(r)
	if e != nil {
		t.Fatal(e)
	}
	return b
}
func waitFor(t *testing.T, f func() bool) {
	t.Helper()
	end := time.Now().Add(3 * time.Second)
	for time.Now().Before(end) {
		if f() {
			return
		}
		time.Sleep(time.Millisecond)
	}
	t.Fatal("timed out")
}
func TestRecoveryAndIndependentPrefix(t *testing.T) {
	d := t.TempDir()
	cfg := Config{Directory: d, BudgetBytes: 1 << 20, SegmentBytes: 16 << 10, Destinations: []string{"hub-a", "hub-b"}}
	j, e := Open(cfg)
	if e != nil {
		t.Fatal(e)
	}
	for n := uint64(1); n <= 8; n++ {
		if !j.Append(report(t, n)) {
			t.Fatal("append refused")
		}
	}
	if e = j.Close(); e != nil {
		t.Fatal(e)
	}
	j, e = Open(cfg)
	if e != nil {
		t.Fatal(e)
	}
	batch, e := j.Batch("hub-a")
	if e != nil || len(batch) != 8 {
		t.Fatalf("recover %d %v", len(batch), e)
	}
	if !j.Commit("hub-a", batch[:2]) {
		t.Fatal("commit refused")
	}
	waitFor(t, func() bool { return j.Cursor("hub-a") == batch[1].Position })
	if e = j.Close(); e != nil {
		t.Fatal(e)
	}
	j, e = Open(cfg)
	if e != nil {
		t.Fatal(e)
	}
	defer closeJournal(t, j)
	a, _ := j.Batch("hub-a")
	b, _ := j.Batch("hub-b")
	if len(a) != 6 || len(b) != 8 {
		t.Fatalf("independent durable progress %d %d", len(a), len(b))
	}
}
func TestTornAndCorruptTailRecoversPrefix(t *testing.T) {
	for _, corrupt := range []bool{false, true} {
		t.Run(map[bool]string{false: "torn", true: "corrupt"}[corrupt], func(t *testing.T) {
			d := t.TempDir()
			cfg := Config{Directory: d, BudgetBytes: 1 << 20, SegmentBytes: 64 << 10, Destinations: []string{"hub"}}
			j, e := Open(cfg)
			if e != nil {
				t.Fatal(e)
			}
			j.Append(report(t, 1))
			j.Append(report(t, 2))
			closeJournal(t, j)
			files, globErr := filepath.Glob(filepath.Join(d, "*.seg"))
			checkIO(t, globErr)
			if len(files) != 1 {
				t.Fatalf("segment fixture count %d", len(files))
			}
			b, readErr := os.ReadFile(files[0])
			checkIO(t, readErr)
			if corrupt {
				b[len(b)-1] ^= 255
			} else {
				b = b[:len(b)-10]
			}
			checkIO(t, os.WriteFile(files[0], b, 0600))
			j, e = Open(cfg)
			if e != nil {
				t.Fatal(e)
			}
			defer closeJournal(t, j)
			records, e := j.Batch("hub")
			if e != nil || len(records) != 1 || !j.Delivery().UnknownCoverage {
				t.Fatalf("prefix/loss %d %v %#v", len(records), e, j.Delivery())
			}
		})
	}
}
func TestRingEvictionIsBoundedAndDisclosed(t *testing.T) {
	d := t.TempDir()
	j, e := Open(Config{Directory: d, BudgetBytes: 160 << 10, SegmentBytes: 8 << 10, Destinations: []string{"hub"}})
	if e != nil {
		t.Fatal(e)
	}
	for n := uint64(1); n <= 40; n++ {
		j.Append(report(t, n))
		time.Sleep(time.Millisecond)
	}
	closeJournal(t, j)
	var total int64
	checkIO(t, filepath.WalkDir(d, func(p string, e os.DirEntry, err error) error {
		if err == nil && !e.IsDir() {
			i, infoErr := e.Info()
			if infoErr != nil {
				return infoErr
			}
			total += i.Size()
		}
		return err
	}))
	if total > 160<<10 {
		t.Fatalf("budget %d", total)
	}
	j, e = Open(Config{Directory: d, BudgetBytes: 160 << 10, SegmentBytes: 8 << 10, Destinations: []string{"hub"}})
	if e != nil {
		t.Fatal(e)
	}
	defer closeJournal(t, j)
	if len(j.Delivery().LostRanges) == 0 && !j.Delivery().UnknownCoverage {
		t.Fatal("eviction concealed")
	}
}

// These faults use real OS descriptors. The queue synchronizes descriptor setup
// before the writer uses it; no production fault-injection switch is needed.
func TestBlockedWriterLeavesBoundedCurrentMetadataAndQueue(t *testing.T) {
	j, e := Open(Config{Directory: t.TempDir(), Destinations: []string{"hub"}})
	if e != nil {
		t.Fatal(e)
	}
	reader, writer, e := os.Pipe()
	if e != nil {
		t.Fatal(e)
	}
	// An entire report is larger than this pipe's capacity, so the write blocks.
	if _, _, errno := syscall.Syscall(syscall.SYS_FCNTL, writer.Fd(), syscall.F_SETPIPE_SZ, 4096); errno != 0 {
		t.Fatal(errno)
	}
	j.file = writer
	j.segments = []segment{{path: filepath.Join(j.cfg.Directory, "blocked.seg")}}
	j.Append(report(t, 1))
	waitFor(t, func() bool { j.mu.Lock(); defer j.mu.Unlock(); return j.pending })
	for n := uint64(2); n < 80; n++ {
		j.Append(report(t, n))
	}
	started := time.Now()
	d := j.Delivery()
	j.Cursor("hub")
	if _, err := j.Batch("hub"); err != nil {
		t.Fatal(err)
	}
	if time.Since(started) > 100*time.Millisecond || len(j.queue) > cap(j.queue) || d.Recording != "queue_full" {
		t.Fatalf("blocked metadata/queue %#v", d)
	}
	j.mu.Lock()
	j.lastIO = time.Now().Add(-31 * time.Second)
	j.mu.Unlock()
	if j.Delivery().Recording != "storage_blocked" {
		t.Fatal("blocked operation hidden")
	}
	checkIO(t, reader.Close())
	closeJournal(t, j)
}
func TestDiskFullAndFailedSyncUseSafeCodes(t *testing.T) {
	for _, kind := range []string{"write", "sync"} {
		t.Run(kind, func(t *testing.T) {
			j, e := Open(Config{Directory: t.TempDir(), Destinations: []string{"hub"}})
			if e != nil {
				t.Fatal(e)
			}
			var reader *os.File
			if kind == "write" {
				j.file, e = os.OpenFile("/dev/full", os.O_WRONLY, 0)
			} else {
				reader, j.file, e = os.Pipe()
			}
			if e != nil {
				t.Fatal(e)
			}
			j.segments = []segment{{path: "fault.seg"}}
			if kind == "write" {
				j.Append(report(t, 1))
				waitFor(t, func() bool { return j.Delivery().Recording == "journal_write_failed" })
			} else {
				j.Append(report(t, 1))
				waitFor(t, func() bool { j.mu.Lock(); defer j.mu.Unlock(); return len(j.entries) == 1 })
			}
			e = j.Close()
			if reader != nil {
				checkIO(t, reader.Close())
			}
			if (kind == "sync" && e == nil) || (e != nil && strings.Contains(e.Error(), "/")) || !j.Delivery().UnknownCoverage {
				t.Fatalf("failure not safe/disclosed: %v", e)
			}
		})
	}
}
func TestCursorReplacementFailureDoesNotExposeProgress(t *testing.T) {
	j, e := Open(Config{Directory: t.TempDir(), Destinations: []string{"hub"}})
	if e != nil {
		t.Fatal(e)
	}
	j.Append(report(t, 1))
	waitFor(t, func() bool { b, _ := j.Batch("hub"); return len(b) == 1 })
	if e = os.Symlink("/dev/full", filepath.Join(j.cfg.Directory, "state.tmp")); e != nil {
		t.Fatal(e)
	}
	b, _ := j.Batch("hub")
	j.Commit("hub", b)
	waitFor(t, func() bool { return j.Delivery().Recording == "journal_write_failed" })
	if j.Cursor("hub") != 0 {
		t.Fatal("uncommitted cursor visible")
	}
	checkIO(t, os.Remove(filepath.Join(j.cfg.Directory, "state.tmp")))
	closeJournal(t, j)
}

func TestPostOpenCorruptionDisclosesLossAndContinuesPastDamagedFrame(t *testing.T) {
	j, e := Open(Config{Directory: t.TempDir(), Destinations: []string{"hub"}})
	if e != nil {
		t.Fatal(e)
	}
	defer closeJournal(t, j)
	j.Append(report(t, 1))
	j.Append(report(t, 2))
	waitFor(t, func() bool { b, _ := j.Batch("hub"); return len(b) == 2 })
	j.mu.Lock()
	en := j.entries[0]
	j.mu.Unlock()
	f, e := os.OpenFile(en.path, os.O_RDWR, 0600)
	if e != nil {
		t.Fatal(e)
	}
	if n, err := f.WriteAt([]byte{255}, en.offset); err != nil || n != 1 {
		t.Fatalf("corruption injection: wrote %d: %v", n, err)
	}
	checkIO(t, f.Close())
	b, e := j.Batch("hub")
	if e != nil || len(b) != 1 || b[0].Sequence != 2 || len(j.Delivery().LostRanges) == 0 {
		t.Fatalf("damaged frame trapped progress %d %v %#v", len(b), e, j.Delivery())
	}
}

func TestFlushSyncFailureIsVisibleWithoutFillingSamplingQueue(t *testing.T) {
	// flock does not stall fsync on a regular Linux file, and the kernel offers no
	// deterministic blocking-fsync descriptor. Exercise the real flush error
	// boundary using a pipe; pending-operation duration uses the same metadata
	// path proven with a blocked real write above.
	j, e := Open(Config{Directory: t.TempDir(), Destinations: []string{"hub"}})
	if e != nil {
		t.Fatal(e)
	}
	reader, writer, e := os.Pipe()
	if e != nil {
		t.Fatal(e)
	}
	j.file = writer
	j.segments = []segment{{path: "flush.seg"}}
	if err := j.Close(); err == nil || err.Error() != "journal_sync_failed" {
		t.Fatalf("flush error: %v", err)
	}
	checkIO(t, reader.Close())
	if j.Delivery().Recording != "journal_sync_failed" {
		t.Fatal("flush failure hidden")
	}
}

func TestUnsupportedStoredVersionsPreserveJournalBytes(t *testing.T) {
	for _, kind := range []string{"report", "frame"} {
		t.Run(kind, func(t *testing.T) {
			d := t.TempDir()
			cfg := Config{Directory: d, Destinations: []string{"hub"}}
			j, e := Open(cfg)
			if e != nil {
				t.Fatal(e)
			}
			j.Append(report(t, 1))
			j.Append(report(t, 2))
			closeJournal(t, j)
			files, globErr := filepath.Glob(filepath.Join(d, "*.seg"))
			checkIO(t, globErr)
			if len(files) != 1 {
				t.Fatalf("segment fixture count %d", len(files))
			}
			b, readErr := os.ReadFile(files[0])
			checkIO(t, readErr)
			offset := headerBytes + int(binary.BigEndian.Uint32(b[4:8]))
			want := "journal_version_unsupported"
			if kind == "frame" {
				copy(b[offset:offset+4], "NBJ2")
			} else {
				length := int(binary.BigEndian.Uint32(b[offset+4 : offset+8]))
				payload := b[offset+headerBytes : offset+headerBytes+length]
				changed := bytes.Replace(payload, []byte(`"protocol_version":1`), []byte(`"protocol_version":2`), 1)
				copy(payload, changed)
				binary.BigEndian.PutUint32(b[offset+16:offset+20], crc32.ChecksumIEEE(append(b[offset+8:offset+16:offset+16], payload...)))
				want = "protocol_version_unsupported"
			}
			checkIO(t, os.WriteFile(files[0], b, 0600))
			opened, e := Open(cfg)
			if opened != nil {
				closeJournal(t, opened)
			}
			after, readErr := os.ReadFile(files[0])
			checkIO(t, readErr)
			if e == nil || e.Error() != want || !bytes.Equal(b, after) {
				t.Fatalf("unsupported journal modified: %v bytes %d/%d", e, len(b), len(after))
			}
		})
	}
}

func TestRecordingRecoversAfterTransientWriteFailureButKeepsLoss(t *testing.T) {
	j, e := Open(Config{Directory: t.TempDir(), Destinations: []string{"hub"}})
	if e != nil {
		t.Fatal(e)
	}
	j.file, e = os.OpenFile("/dev/full", os.O_WRONLY, 0)
	if e != nil {
		t.Fatal(e)
	}
	j.segments = []segment{{path: filepath.Join(j.cfg.Directory, "fault.seg")}}
	j.Append(report(t, 1))
	waitFor(t, func() bool { return j.Delivery().Recording == "journal_write_failed" })
	j.Append(report(t, 2))
	waitFor(t, func() bool { b, _ := j.Batch("hub"); return len(b) == 1 && b[0].Sequence == 2 })
	e = j.Close()
	d := j.Delivery()
	if e != nil || d.Recording != "recording" || len(d.LostRanges) == 0 || !d.UnknownCoverage {
		t.Fatalf("recording failed to recover or historical loss erased: %v %#v", e, d)
	}
}

func TestRecordingRecoversAfterTransientSyncFailure(t *testing.T) {
	j, e := Open(Config{Directory: t.TempDir(), Destinations: []string{"hub"}})
	if e != nil {
		t.Fatal(e)
	}
	reader, writer, e := os.Pipe()
	if e != nil {
		t.Fatal(e)
	}
	defer func() { checkIO(t, reader.Close()) }()
	path := filepath.Join(j.cfg.Directory, "00000000000000000001.seg")
	j.file = writer
	j.segments = []segment{{path: path}}
	j.lastSync = time.Now().Add(-31 * time.Second)
	j.Append(report(t, 1))
	waitFor(t, func() bool {
		j.mu.Lock()
		defer j.mu.Unlock()
		return j.state.Delivery.Recording == "journal_sync_failed" && !j.pending
	})
	frame := make([]byte, headerBytes+len(report(t, 1)))
	if _, e = io.ReadFull(reader, frame); e != nil {
		t.Fatal(e)
	}
	checkIO(t, writer.Close())
	replacement, e := os.OpenFile(path, os.O_CREATE|os.O_RDWR, 0600)
	if e != nil {
		t.Fatal(e)
	}
	if _, e = replacement.Write(frame); e != nil {
		t.Fatal(e)
	}
	j.file = replacement
	j.Append(report(t, 2))
	waitFor(t, func() bool { return j.Delivery().Recording == "recording" })
	if e = j.Close(); e != nil {
		t.Fatal(e)
	}
	if !j.Delivery().UnknownCoverage {
		t.Fatal("possible loss erased on sync recovery")
	}
}

func TestSecondOpenCannotMutateLiveJournal(t *testing.T) {
	cfg := Config{Directory: t.TempDir(), Destinations: []string{"hub"}}
	j, e := Open(cfg)
	if e != nil {
		t.Fatal(e)
	}
	defer closeJournal(t, j)
	j.Append(report(t, 1))
	waitFor(t, func() bool { b, _ := j.Batch("hub"); return len(b) == 1 })
	second, e := Open(cfg)
	if second != nil {
		closeJournal(t, second)
	}
	if e == nil || e.Error() != "journal_locked" {
		t.Fatalf("second writer permitted: %v", e)
	}
	j.Append(report(t, 2))
	waitFor(t, func() bool { b, _ := j.Batch("hub"); return len(b) == 2 })
	if e = j.Close(); e != nil {
		t.Fatal(e)
	}
	reopened, e := Open(cfg)
	if e != nil {
		t.Fatal(e)
	}
	defer closeJournal(t, reopened)
	b, e := reopened.Batch("hub")
	if e != nil || len(b) != 2 {
		t.Fatalf("live journal damaged %d %v", len(b), e)
	}
}
