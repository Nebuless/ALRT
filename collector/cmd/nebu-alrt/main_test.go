package main

import (
	"io"
	"testing"
)

func TestRunRequiresPrivateConfig(t *testing.T) {
	for _, args := range [][]string{{"run"}, {"run", "--config", "/missing/private/config"}, {"run", "--token", "private"}, {"sample", "--count", "0"}} {
		if e := run(args, io.Discard); e == nil {
			t.Fatalf("accepted %v", args)
		}
	}
}
