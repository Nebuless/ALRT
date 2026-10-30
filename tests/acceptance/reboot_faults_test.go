package acceptance

import (
	"os/exec"
	"path/filepath"
	"runtime"
	"testing"
)

// Exercise the actual isolated helper subprocess fault harness repeatedly. The
// harness injects a counting adapter; production shutdown is never invoked.
func TestRebootFaultAcceptance(t *testing.T) {
	_, file, _, ok := runtime.Caller(0)
	if !ok {
		t.Fatal("source location unavailable")
	}
	root := filepath.Dir(filepath.Dir(filepath.Dir(file)))
	cmd := exec.Command("go", "test", "-count=3", "-run", "Test(ProcessCrashesEveryConsumptionBoundary|ProcessCrashesEveryReceiptBoundary|ReceiptDurabilityBeforeACK|EveryDurabilityBoundaryNeverDuplicates|ConcurrentConsumptionAndRestartNeverRetry|PHPWirePermitVerifiedByGo|AuthorityAndTrustedContext|ShortActionBudgetUsesTrustedElapsedDespiteWallClockSkew|MissingCorruptLedgerFailsClosed|ClientReceiptACKPermitAndLostResult|ReceiptRotationIsIndependentOfConsumption)$", "./internal/actions")
	cmd.Dir = filepath.Join(root, "collector")
	if output, e := cmd.CombinedOutput(); e != nil {
		t.Fatalf("isolated reboot fault acceptance: %v\n%s", e, output)
	}
}
