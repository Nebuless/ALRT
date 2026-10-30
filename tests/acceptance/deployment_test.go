package acceptance

import (
	"os/exec"
	"path/filepath"
	"runtime"
	"testing"
)

func TestDeploymentAcceptance(t *testing.T) {
	_, file, _, _ := runtime.Caller(0)
	root := filepath.Dir(filepath.Dir(filepath.Dir(file)))
	cmd := exec.Command("python3", "tests/acceptance/deployment.py")
	cmd.Dir = root
	if out, err := cmd.CombinedOutput(); err != nil {
		t.Fatalf("deployment acceptance: %v\n%s", err, out)
	}
}

func TestTailscaleAuthenticationDeployment(t *testing.T) {
	_, file, _, _ := runtime.Caller(0)
	root := filepath.Dir(filepath.Dir(filepath.Dir(file)))
	cmd := exec.Command("python3", "tests/acceptance/tailscale_auth.py")
	cmd.Dir = root
	if out, err := cmd.CombinedOutput(); err != nil {
		t.Fatalf("private authentication deployment: %v\n%s", err, out)
	}
}

func TestRestrictedUserspaceRoute(t *testing.T) {
	_, file, _, _ := runtime.Caller(0)
	root := filepath.Dir(filepath.Dir(filepath.Dir(file)))
	cmd := exec.Command("python3", "tests/acceptance/connect_proxy.py")
	cmd.Dir = root
	if out, err := cmd.CombinedOutput(); err != nil {
		t.Fatalf("restricted route acceptance: %v\n%s", err, out)
	}
}

func TestProtectedEnrollmentSetup(t *testing.T) {
	_, file, _, _ := runtime.Caller(0)
	root := filepath.Dir(filepath.Dir(filepath.Dir(file)))
	cmd := exec.Command("python3", "tests/acceptance/reboot_setup.py")
	cmd.Dir = root
	if out, err := cmd.CombinedOutput(); err != nil {
		t.Fatalf("isolated protected enrollment: %v\n%s", err, out)
	}
}
