package journal

import (
	"os"
	"os/exec"
	"path/filepath"
	"syscall"
	"testing"
	"unsafe"
)

// The child changes only its own file-size limit and denies only its own
// ftruncate calls. This exercises actual partial writes without production hooks.
func TestPartialWriteFailedRollbackHonorsBudget(t *testing.T) {
	const marker = "NEBULESS_PARTIAL_WRITE_CHILD"
	if os.Getenv(marker) != "1" {
		cmd := exec.Command(os.Args[0], "-test.run=^TestPartialWriteFailedRollbackHonorsBudget$", "-test.v")
		cmd.Env = append(os.Environ(), marker+"=1")
		out, err := cmd.CombinedOutput()
		if err != nil {
			t.Fatalf("isolated storage fault: %v\n%s", err, out)
		}
		t.Log(string(out))
		return
	}
	j, err := Open(Config{Directory: t.TempDir(), BudgetBytes: 96 << 10, SegmentBytes: 16 << 10, Destinations: []string{"hub"}})
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = j.Close() }()
	var limit syscall.Rlimit
	if err = syscall.Getrlimit(syscall.RLIMIT_FSIZE, &limit); err != nil {
		t.Fatal(err)
	}
	limit.Cur = 3000
	if err = syscall.Setrlimit(syscall.RLIMIT_FSIZE, &limit); err != nil {
		t.Fatal(err)
	}
	// PR_SET_NO_NEW_PRIVS, then SECCOMP_SET_MODE_FILTER with TSYNC so the
	// journal writer cannot escape the fault by running on another Go thread.
	if _, _, errno := syscall.RawSyscall6(syscall.SYS_PRCTL, 38, 1, 0, 0, 0, 0); errno != 0 {
		t.Fatal(errno)
	}
	filters := []syscall.SockFilter{
		{Code: syscall.BPF_LD | syscall.BPF_W | syscall.BPF_ABS, K: 0},
		{Code: syscall.BPF_JMP | syscall.BPF_JEQ | syscall.BPF_K, K: uint32(syscall.SYS_FTRUNCATE), Jf: 1},
		{Code: syscall.BPF_RET | syscall.BPF_K, K: 0x00050000 | uint32(syscall.EPERM)},
		{Code: syscall.BPF_RET | syscall.BPF_K, K: 0x7fff0000},
	}
	program := syscall.SockFprog{Len: uint16(len(filters)), Filter: &filters[0]}
	const sysSeccomp = 317 // Linux amd64; this file's suffix scopes the syscall number.
	if _, _, errno := syscall.RawSyscall6(sysSeccomp, 1, 1, uintptr(unsafe.Pointer(&program)), 0, 0, 0); errno != 0 {
		t.Fatal(errno)
	}
	for sequence := uint64(1); sequence <= 40; sequence++ {
		if !j.Append(report(t, sequence)) {
			t.Fatal("unexpected queue refusal")
		}
		waitFor(t, func() bool { j.mu.Lock(); defer j.mu.Unlock(); return j.faultEpoch >= sequence })
	}
	var physical int64
	files, err := os.ReadDir(j.cfg.Directory)
	if err != nil {
		t.Fatal(err)
	}
	for _, file := range files {
		info, err := os.Stat(filepath.Join(j.cfg.Directory, file.Name()))
		if err != nil {
			t.Fatal(err)
		}
		physical += info.Size()
	}
	t.Logf("physical=%d configured_budget=%d segments=%d", physical, j.cfg.BudgetBytes, len(j.segments))
	if physical > j.cfg.BudgetBytes {
		t.Fatalf("partial writes with failed rollback exceeded telemetry budget: %d > %d", physical, j.cfg.BudgetBytes)
	}
}
