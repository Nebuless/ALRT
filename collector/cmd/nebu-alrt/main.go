package main

import (
	"context"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"os"
	"os/signal"
	"strings"
	"syscall"
	"time"

	"github.com/Nebuless/ALRT/collector/internal/daemon"
	"github.com/Nebuless/ALRT/collector/internal/sampler"
)

type resources map[string]string

func (r resources) String() string { return "configured" }
func (r resources) Set(value string) error {
	pair := strings.SplitN(value, "=", 2)
	if len(pair) != 2 || pair[0] == "" || pair[1] == "" {
		return fmt.Errorf("invalid_resource")
	}
	r[pair[0]] = pair[1]
	return nil
}

func main() {
	if err := run(os.Args[1:], os.Stdout); err != nil {
		fmt.Fprintln(os.Stderr, err.Error())
		os.Exit(1)
	}
}

func run(args []string, out io.Writer) error {
	if len(args) > 0 && args[0] == "run" {
		flags := flag.NewFlagSet("run", flag.ContinueOnError)
		flags.SetOutput(io.Discard)
		path := flags.String("config", "", "private configuration file")
		if flags.Parse(args[1:]) != nil || flags.NArg() != 0 || *path == "" {
			return fmt.Errorf("invalid_arguments")
		}
		config, err := daemon.Load(*path)
		if err != nil {
			return err
		}
		ctx, cancel := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
		defer cancel()
		return daemon.Run(ctx, config, out)
	}
	if len(args) == 0 || args[0] != "sample" {
		return fmt.Errorf("usage: nebu-alrt run --config FILE | sample --machine-id ID [--scope host|sandbox] [--disk LABEL=PATH] [--interface LABEL=NAME] [--count N] [--interval 10s]")
	}
	flags := flag.NewFlagSet("sample", flag.ContinueOnError)
	flags.SetOutput(io.Discard)
	machine := flags.String("machine-id", "", "configured machine identifier")
	scope := flags.String("scope", "host", "measurement scope")
	count := flags.Int("count", 1, "number of samples")
	interval := flags.Duration("interval", 10*time.Second, "sampling interval")
	maxProcesses := flags.Int("max-processes", 4096, "process scan budget")
	maxCategory := flags.Int("max-category-items", 256, "category item budget")
	disks := resources{}
	interfaces := resources{}
	flags.Var(disks, "disk", "opaque label and local disk path")
	flags.Var(interfaces, "interface", "opaque label and local interface name")
	if flags.Parse(args[1:]) != nil || flags.NArg() != 0 {
		return fmt.Errorf("invalid_arguments")
	}
	if *count < 1 || *count > 1000 || *interval <= 0 {
		return fmt.Errorf("invalid_arguments")
	}
	s, err := sampler.New(sampler.Config{MachineID: *machine, Scope: *scope, Disks: disks, Interfaces: interfaces, MaxProcesses: *maxProcesses, MaxCategoryItems: *maxCategory})
	if err != nil {
		return err
	}
	encoder := json.NewEncoder(out)
	for i := 0; i < *count; i++ {
		if i > 0 {
			time.Sleep(*interval)
		}
		report, err := s.Sample(time.Now())
		if err != nil {
			return err
		}
		if encoder.Encode(report) != nil {
			return fmt.Errorf("report_write_failed")
		}
	}
	return nil
}
