package acceptance

import "testing"

func TestCitizensNativeBridge(t *testing.T) {
	root := acceptanceRoot(t)
	runAcceptance(t, root, "python3", "-m", "unittest", "discover", "-s", "tests/acceptance", "-p", "test_citizens_*.py")
}
