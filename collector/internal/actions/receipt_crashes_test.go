package actions

import (
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"testing"
)

func TestReceiptChild(t *testing.T) {
	directory := os.Getenv("NEBULESS_TEST_RECEIPT_DIRECTORY")
	if directory == "" {
		return
	}
	boundary, _ := strconv.Atoi(os.Getenv("NEBULESS_TEST_RECEIPT_BOUNDARY"))
	receipts := &Receipts{Directory: directory}
	if boundary > 0 {
		receipts.ops = &crashFiles{at: boundary, after: os.Getenv("NEBULESS_TEST_RECEIPT_AFTER") == "1"}
	}
	action := Action{ID: "22222222-2222-4222-8222-222222222222", MachineID: "machine", BootID: "boot", ExpiresAt: "2033-05-18T09:33:20Z"}
	if receipts.Save(action, "received") != nil {
		os.Exit(24)
	}
	// This is the observable pickup ACK boundary, after both receipt syncs.
	if os.WriteFile(filepath.Join(directory, "acked"), []byte("acked"), 0600) != nil {
		os.Exit(25)
	}
	os.Exit(0)
}

func TestProcessCrashesEveryReceiptBoundary(t *testing.T) {
	for boundary := 1; boundary <= 8; boundary++ {
		for _, after := range []bool{false, true} {
			t.Run(strconv.Itoa(boundary)+"/"+strconv.FormatBool(after), func(t *testing.T) {
				directory := t.TempDir()
				run := func(fail bool) error {
					cmd := exec.Command(os.Args[0], "-test.run=^TestReceiptChild$")
					cmd.Env = append(os.Environ(), "NEBULESS_TEST_RECEIPT_DIRECTORY="+directory)
					if fail {
						cmd.Env = append(cmd.Env, "NEBULESS_TEST_RECEIPT_BOUNDARY="+strconv.Itoa(boundary))
						if after {
							cmd.Env = append(cmd.Env, "NEBULESS_TEST_RECEIPT_AFTER=1")
						}
					}
					return cmd.Run()
				}
				if e := run(true); e == nil {
					t.Fatal("injected process crash did not happen")
				}
				if _, e := os.Stat(filepath.Join(directory, "acked")); !os.IsNotExist(e) {
					t.Fatal("ACK happened before receipt durability completed")
				}
				if e := run(false); e != nil {
					t.Fatal("restart failed", e)
				}
				if b, e := os.ReadFile(filepath.Join(directory, "acked")); e != nil || string(b) != "acked" {
					t.Fatal("durable receipt was not acknowledged on restart")
				}
				receipts := &Receipts{Directory: directory}
				state, e := receipts.Read("22222222-2222-4222-8222-222222222222")
				if e != nil || state.Status != "received" {
					t.Fatal("receipt lost across crash/restart", e)
				}
			})
		}
	}
}
