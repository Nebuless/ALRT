package acceptance

import (
	"os/exec"
	"path/filepath"
	"runtime"
	"testing"
)

func acceptanceRoot(t *testing.T) string {
	t.Helper()
	_, file, _, ok := runtime.Caller(0)
	if !ok {
		t.Fatal("source location unavailable")
	}
	return filepath.Dir(filepath.Dir(filepath.Dir(file)))
}

func runAcceptance(t *testing.T, directory, command string, args ...string) {
	t.Helper()
	cmd := exec.Command(command, args...)
	cmd.Dir = directory
	if output, err := cmd.CombinedOutput(); err != nil {
		t.Fatalf("acceptance command failed: %v\n%s", err, output)
	} else {
		t.Log(string(output))
	}
}

func TestFleetRecoveryAcceptance(t *testing.T) {
	root := acceptanceRoot(t)
	runAcceptance(t, filepath.Join(root, "collector"), "go", "test", "-count=1", "-v", "-run", "Test(FourHoursThreeIndependentHubsFreshSamplesAndRestart|EstablishedHubReconnectStartsWithFreshContact|EvictedBacklogRemainsExplicitGapInCurrentEnvelope)$", "./internal/uploader")
	runAcceptance(t, filepath.Join(root, "apps/hub"), filepath.Join(root, "bin/php"), "vendor/bin/pest", "--compact", filepath.Join(root, "tests/acceptance/fleet_scenario.php"), "tests/Feature/AlertDeliveryTest.php", "tests/Feature/HubHealthIntegrationTest.php", "tests/Feature/RebootLifecycleTest.php")
}

func TestJournalOverloadAcceptance(t *testing.T) {
	root := acceptanceRoot(t)
	runAcceptance(t, filepath.Join(root, "collector"), "go", "test", "-count=1", "-v", "-run", "Test(RingEvictionIsBoundedAndDisclosed|BlockedWriterLeavesBoundedCurrentMetadataAndQueue|DiskFullAndFailedSyncUseSafeCodes|PartialWriteFailedRollbackHonorsBudget)$", "./internal/journal")
}
