package main

import (
	"bytes"
	"io"
	"strings"
	"testing"
)

func TestVersionAndHelp(t *testing.T) {
	for _, command := range []string{"version", "--version", "--help", "help"} {
		var out bytes.Buffer
		if err := run([]string{command}, &out); err != nil || !strings.Contains(out.String(), "nebu-alrt") {
			t.Fatalf("%s: output=%q error=%v", command, out.String(), err)
		}
		if err := run([]string{command, "extra"}, io.Discard); err == nil {
			t.Fatalf("accepted extra argument for %s", command)
		}
	}
}

func TestRunRequiresPrivateConfig(t *testing.T) {
	for _, args := range [][]string{{"run"}, {"run", "--config", "/missing/private/config"}, {"run", "--token", "private"}, {"sample", "--count", "0"}} {
		if e := run(args, io.Discard); e == nil {
			t.Fatalf("accepted %v", args)
		}
	}
}
