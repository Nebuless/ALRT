package daemon

import (
	"context"
	"encoding/json"
	"errors"
	"github.com/Nebuless/ALRT/collector/internal/actions"
	"io"
	"net/http"
	"net/url"
	"os"
	"strings"
	"sync"
	"time"

	"github.com/Nebuless/ALRT/collector/internal/citizens"
	"github.com/Nebuless/ALRT/collector/internal/journal"
	"github.com/Nebuless/ALRT/collector/internal/protocol"
	"github.com/Nebuless/ALRT/collector/internal/sampler"
	"github.com/Nebuless/ALRT/collector/internal/uploader"
)

func Run(ctx context.Context, c Config, statusOutput io.Writer) error {
	if e := c.Validate(); e != nil {
		return e
	}
	var citizensBoot string
	if c.CitizensSocket != "" {
		b, err := os.ReadFile("/proc/sys/kernel/random/boot_id")
		if err != nil || !uuid.MatchString(strings.TrimSpace(string(b))) {
			return errors.New("citizens_boot_unavailable")
		}
		citizensBoot = strings.TrimSpace(string(b))
	}
	s, e := sampler.New(sampler.Config{MachineID: c.MachineID, Scope: c.Scope, Disks: c.Disks, Interfaces: c.Interfaces})
	if e != nil {
		return e
	}
	ids := make([]string, len(c.Destinations))
	for i, d := range c.Destinations {
		ids[i] = d.ID
	}
	j, e := journal.Open(journal.Config{Directory: c.TelemetryDirectory, BudgetBytes: c.BudgetBytes, SegmentBytes: c.SegmentBytes, Destinations: ids})
	if e != nil {
		return e
	}
	child, cancel := context.WithCancel(ctx)
	var tasks sync.WaitGroup
	workers := make([]*uploader.Worker, len(c.Destinations))
	for i, d := range c.Destinations {
		w := uploader.New(d, j, nil)
		if d.ID == c.PrimaryHubID {
			contacts := make(chan string, 1)
			w.OnContact = func(r protocol.Report) {
				select {
				case contacts <- r.BootID:
				default:
				}
			}
			transport := http.DefaultTransport.(*http.Transport).Clone()
			transport.Proxy = nil
			if d.ProxyURL != "" {
				proxy, _ := url.Parse(d.ProxyURL)
				transport.Proxy = http.ProxyURL(proxy)
			}
			actionClient := &actions.Client{MachineID: c.MachineID, URL: d.URL, Token: d.Token, HTTP: &http.Client{Transport: transport}, Receipts: &actions.Receipts{Directory: c.ActionDirectory}, Helper: actions.ProcessHelper{}}
			tasks.Add(1)
			go func() {
				defer tasks.Done()
				if os.MkdirAll(c.ActionDirectory, 0700) != nil {
					return
				}
				for {
					select {
					case <-child.Done():
						return
					case boot := <-contacts:
						_ = actionClient.Step(child, boot)
					}
				}
			}()
		}
		workers[i] = w
		tasks.Add(1)
		go func() { defer tasks.Done(); w.Run(child) }()
	}
	defer func() { cancel(); tasks.Wait() }()
	if c.CitizensSocket != "" {
		for _, d := range c.Destinations {
			w := citizens.New(c.CitizensSocket, citizensBoot, c.MachineID, d, d.ID == c.PrimaryHubID, nil)
			tasks.Add(1)
			go func() { defer tasks.Done(); w.Run(child) }()
		}
	}
	if statusOutput != nil {
		go reportStatus(child, statusOutput, c, workers, j)
	}
	sample := func() error {
		r, e := s.Sample(time.Now())
		if e != nil {
			return e
		}
		b, e := protocol.Prepare(r)
		if e != nil {
			return e
		}
		j.Append(b)
		for _, w := range workers {
			w.Publish(b)
		}
		return nil
	}
	if e = sample(); e != nil {
		if closeErr := j.Close(); closeErr != nil {
			return closeErr
		}
		return e
	}
	ticker := time.NewTicker(time.Duration(c.IntervalSeconds) * time.Second)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			cancel()
			if e = j.Close(); e != nil {
				return e
			}
			return nil
		case <-ticker.C:
			if e = sample(); e != nil {
				if closeErr := j.Close(); closeErr != nil {
					return closeErr
				}
				return errors.New("sampling_failed")
			}
		}
	}
}

// Status output contains only component/hub UUID and fixed safe codes. A blocked
// log sink owns one goroutine and cannot stall sampling, contact or shutdown.
func reportStatus(ctx context.Context, out io.Writer, c Config, workers []*uploader.Worker, j *journal.Journal) {
	ticker := time.NewTicker(250 * time.Millisecond)
	defer ticker.Stop()
	encoder := json.NewEncoder(out)
	previous := map[string]string{}
	emit := func(key, component, hub, code string) bool {
		if previous[key] == code {
			return true
		}
		previous[key] = code
		return encoder.Encode(struct {
			Component string `json:"component"`
			HubID     string `json:"hub_id,omitempty"`
			Status    string `json:"status"`
		}{component, hub, code}) == nil
	}
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			for i, w := range workers {
				if !emit(c.Destinations[i].ID, "delivery", c.Destinations[i].ID, w.Status()) {
					return
				}
			}
			if !emit("journal", "journal", "", j.Delivery().Recording) {
				return
			}
		}
	}
}
