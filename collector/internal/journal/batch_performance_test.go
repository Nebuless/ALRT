package journal

import (
	"bytes"
	"encoding/binary"
	"fmt"
	"hash/crc32"
	"os"
	"path/filepath"
	"testing"

	"github.com/Nebuless/ALRT/collector/internal/protocol"
)

// Caught-up calls exercise the real Batch selection without filesystem I/O.
func BenchmarkBatchCaughtUp(b *testing.B) {
	for _, n := range []int{0, 4096, 20000} {
		b.Run(fmt.Sprint(n), func(b *testing.B) {
			j := &Journal{state: persisted{Cursors: map[string]uint64{"hub": uint64(3*n + 2)}}, entries: make([]entry, n)}
			for i := range j.entries {
				j.entries[i].position = uint64(3*i + 2)
			}
			b.ReportAllocs()
			b.ResetTimer()
			for i := 0; i < b.N; i++ {
				records, err := j.Batch("hub")
				if err != nil || records == nil || len(records) != 0 {
					b.Fatalf("caught-up batch: %v, %v", records, err)
				}
			}
		})
	}
}

// Build only local synthetic NBJ1 frames; no Open, writer, clock or daemon.
func batchFrameJournal(t *testing.T, positions []uint64, lengths []int) (*Journal, [][]byte) {
	t.Helper()
	path := filepath.Join(t.TempDir(), "synthetic.seg")
	var frames []byte
	var entries []entry
	var payloads [][]byte
	for i, pos := range positions {
		data := bytes.Repeat([]byte{byte(i + 1)}, lengths[i])
		header := make([]byte, headerBytes)
		copy(header, "NBJ1")
		binary.BigEndian.PutUint32(header[4:8], uint32(len(data)))
		binary.BigEndian.PutUint64(header[8:16], pos)
		binary.BigEndian.PutUint32(header[16:20], crc32.Update(crc32.ChecksumIEEE(header[8:16]), crc32.IEEETable, data))
		entries = append(entries, entry{position: pos, path: path, offset: int64(len(frames) + headerBytes), length: len(data), stream: "synthetic", sequence: uint64(i + 1)})
		frames = append(frames, header...)
		frames = append(frames, data...)
		payloads = append(payloads, data)
	}
	if err := os.WriteFile(path, frames, 0600); err != nil {
		t.Fatal(err)
	}
	return &Journal{state: persisted{Cursors: map[string]uint64{"hub": 0}}, entries: entries}, payloads
}

func checkBatchFrames(t *testing.T, j *Journal, payloads [][]byte, first, count int) {
	t.Helper()
	records, err := j.Batch("hub")
	if err != nil || records == nil || len(records) != count {
		t.Fatalf("batch count=%d nil=%v err=%v; want %d", len(records), records == nil, err, count)
	}
	for i, r := range records {
		en := j.entries[first+i]
		if r.Position != en.position || r.StreamID != "synthetic" || r.Sequence != en.sequence || !bytes.Equal(r.Data, payloads[int(en.sequence)-1]) {
			t.Fatalf("record %d: identity or payload mismatch", i)
		}
	}
}

func TestBatchCursorSearchBoundaries(t *testing.T) {
	j, payloads := batchFrameJournal(t, []uint64{2, 5, 9, 14}, []int{3, 4, 5, 6})
	for _, tc := range []struct {
		name         string
		cursor       uint64
		first, count int
	}{
		{"before", 0, 0, 4}, {"at_first", 2, 1, 3}, {"between", 4, 1, 3},
		{"at_middle", 9, 3, 1}, {"at_last", 14, 4, 0}, {"after", 20, 4, 0},
	} {
		t.Run(tc.name, func(t *testing.T) {
			j.state.Cursors["hub"] = tc.cursor
			checkBatchFrames(t, j, payloads, tc.first, tc.count)
		})
	}
	records, err := j.Batch("unknown")
	if records != nil || err == nil || err.Error() != "unknown_destination" {
		t.Fatalf("unknown destination: %v, %v", records, err)
	}
	empty := &Journal{state: persisted{Cursors: map[string]uint64{"hub": 0}}}
	checkBatchFrames(t, empty, nil, 0, 0)
	records, err = empty.Batch("unknown")
	if records != nil || err == nil || err.Error() != "unknown_destination" {
		t.Fatalf("empty unknown destination: %v, %v", records, err)
	}
}

func TestBatchCursorSearchCapsAndRetainedSuffix(t *testing.T) {
	positions := make([]uint64, 260)
	lengths := make([]int, len(positions))
	for i := range positions {
		positions[i] = uint64(3*i + 2)
		lengths[i] = 8
	}
	j, payloads := batchFrameJournal(t, positions, lengths)
	checkBatchFrames(t, j, payloads, 0, 128)
	j.state.Cursors["hub"] = positions[127]
	checkBatchFrames(t, j, payloads, 128, 128)
	// Eviction retains a suffix, and damaged-frame removal may leave further gaps.
	j.entries = j.entries[100:]
	j.entries = append(j.entries[:20:20], j.entries[21:]...)
	j.state.Cursors["hub"] = positions[119] + 1
	checkBatchFrames(t, j, payloads, 20, 128)
	j.state.Cursors["hub"] = 0
	checkBatchFrames(t, j, payloads, 0, 128)

	overhead := len(`{"reports":[]}`)
	lengths = []int{protocol.MaxReportBytes, protocol.MaxReportBytes, protocol.MaxBatchBytes - overhead - 2*protocol.MaxReportBytes - 3, 1}
	j, payloads = batchFrameJournal(t, []uint64{2, 5, 9, 14}, lengths)
	checkBatchFrames(t, j, payloads, 0, 3) // Exactly the byte cap, including separators.
	j.entries[2].length++                  // One more byte excludes that frame before any I/O.
	checkBatchFrames(t, j, payloads, 0, 2)
}
