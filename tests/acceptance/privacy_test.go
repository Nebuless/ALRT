package acceptance

import "testing"

func TestPrivacyAcceptance(t *testing.T) {
	root := acceptanceRoot(t)
	runAcceptance(t, root, "python3", "tests/acceptance/privacy.py")
}
