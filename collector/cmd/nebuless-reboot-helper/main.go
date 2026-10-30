package main

import (
	"os"

	"github.com/Nebuless/ALRT/collector/internal/actions"
)

func main() {
	if len(os.Args) != 1 {
		os.Exit(1)
	}
	helper, e := actions.LoadProtectedHelper()
	if e != nil {
		os.Exit(1)
	}
	if actions.ServeHelper(helper, os.Stdin, os.Stdout, actions.FixedReboot) != nil {
		os.Exit(1)
	}
}
