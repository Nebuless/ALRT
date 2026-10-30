// The acceptance-only command drives the real transport against a disposable HTTP fixture.
package main

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/Nebuless/ALRT/collector/internal/citizens"
	"github.com/Nebuless/ALRT/collector/internal/uploader"
)

func main() {
	b, err := os.ReadFile(os.Args[1])
	if err != nil {
		panic("private_fixture_required")
	}
	var config struct{ Socket, URL, Machine, Token, Boot string }
	if json.Unmarshal(b, &config) != nil || len(config.Token) != 64 {
		panic("invalid_fixture")
	}
	ctx, cancel := signal.NotifyContext(context.Background(), syscall.SIGTERM, os.Interrupt)
	defer cancel()
	worker := citizens.New(config.Socket, config.Boot, config.Machine, uploader.Destination{URL: config.URL, Token: config.Token}, true, nil)
	tick := time.NewTicker(250 * time.Millisecond)
	defer tick.Stop()
	for {
		if worker.Step(ctx) == nil {
			fmt.Println("CITIZENS_WIRE_ACCEPTED")
		}
		select {
		case <-ctx.Done():
			return
		case <-tick.C:
		}
	}
}
