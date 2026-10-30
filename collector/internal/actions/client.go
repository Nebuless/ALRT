package actions

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"strings"
	"time"
)

type Client struct {
	MachineID string
	URL       string
	Token     string
	HTTP      *http.Client
	Receipts  *Receipts
	Helper    LocalHelper
}

func (c *Client) post(ctx context.Context, path string, body, result any) error {
	b, e := json.Marshal(body)
	if e != nil {
		return errors.New("action_unavailable")
	}
	request, e := http.NewRequestWithContext(ctx, http.MethodPost, strings.TrimRight(c.URL, "/")+"/api/v1/collector/"+c.MachineID+"/actions/"+path, bytes.NewReader(b))
	if e != nil {
		return errors.New("action_unavailable")
	}
	request.Header.Set("Authorization", "Bearer "+c.Token)
	request.Header.Set("Content-Type", "application/json")
	client := *c.HTTP
	client.Timeout = 5 * time.Second
	client.CheckRedirect = func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }
	response, e := client.Do(request)
	if e != nil {
		return errors.New("primary_unavailable")
	}
	defer func() { _ = response.Body.Close() }()
	if response.StatusCode != 200 {
		return errors.New("action_refused")
	}
	decoder := json.NewDecoder(io.LimitReader(response.Body, 16385))
	if decoder.Decode(result) != nil || decoder.Decode(new(any)) != io.EOF {
		return errors.New("invalid_action_response")
	}
	return nil
}
func (c *Client) report(ctx context.Context, a Action, boot, status string) error {
	var result struct {
		Accepted bool `json:"accepted"`
	}
	return c.post(ctx, a.ID+"/result", map[string]string{"boot_id": boot, "status": status}, &result)
}

// Step runs in the primary's independent bounded worker after a successful
// current contact. No action traffic or helper IO can block observation.
func (c *Client) Step(ctx context.Context, boot string) error {
	probeCtx, cancel := context.WithTimeout(ctx, 3*time.Second)
	e := c.Helper.Probe(probeCtx)
	cancel()
	reason := ""
	if e != nil {
		reason = "helper_unavailable"
	}
	var pickup struct {
		Action *Action `json:"action"`
	}
	if e = c.post(ctx, "pickup", map[string]any{"boot_id": boot, "available": reason == "", "reason": nilIfEmpty(reason)}, &pickup); e != nil {
		return e
	}
	if pickup.Action == nil {
		return nil
	}
	a := *pickup.Action
	expiry, e := time.Parse(time.RFC3339, a.ExpiresAt)
	if e != nil || !actionID.MatchString(a.ID) || a.MachineID != c.MachineID || a.BootID != boot || !expiry.After(time.Now()) {
		return errors.New("invalid_action_response")
	}
	if receipt, readErr := c.Receipts.Read(a.ID); readErr == nil && (receipt.Status == "unknown" || receipt.Status == "attempted") {
		return c.report(ctx, a, boot, receipt.Status)
	}
	if e = c.Receipts.Save(a, "received"); e != nil {
		return e
	}
	var ack struct {
		Action Action `json:"action"`
	}
	if e = c.post(ctx, a.ID+"/ack", map[string]string{"boot_id": boot}, &ack); e != nil {
		return e
	}
	if ack.Action.ID != a.ID || ack.Action.BootID != boot || ack.Action.MachineID != c.MachineID {
		return errors.New("invalid_action_response")
	}
	if ack.Action.State != "awaiting_primary" && ack.Action.State != "authorized" {
		return nil
	}
	attemptCtx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()
	delivered := false
	status, e := c.Helper.Attempt(attemptCtx, func(nonce string) (Envelope, error) {
		var envelope Envelope
		if len(nonce) != 64 {
			return envelope, errors.New("helper_unavailable")
		}
		if err := c.post(attemptCtx, a.ID+"/permit", map[string]string{"boot_id": boot, "nonce": nonce}, &envelope); err != nil {
			return envelope, err
		}
		// Persist uncertainty BEFORE forwarding any permit to the helper. A restart
		// may lose an operation here; it must never replay an uncertain attempt.
		if err := c.Receipts.Save(a, "unknown"); err != nil {
			return Envelope{}, err
		}
		delivered = true
		return envelope, nil
	})
	if !delivered {
		return e
	}
	if e != nil || status != "attempted" {
		status = "unknown"
	}
	if saveErr := c.Receipts.Save(a, status); saveErr != nil {
		return saveErr
	}
	return c.report(ctx, a, boot, status)
}
func nilIfEmpty(s string) any {
	if s == "" {
		return nil
	}
	return s
}
