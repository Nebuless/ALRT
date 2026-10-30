package actions

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"os"
	"os/exec"
	"strconv"
	"strings"
	"syscall"
)

const HelperPath = "/usr/local/libexec/nebuless-reboot-helper"
const PolicyPath = "/etc/nebuless/reboot-policy.json"
const LedgerPath = "/var/lib/nebuless-reboot/consumed.json"

func protected(path string, directory bool) bool {
	info, e := os.Lstat(path)
	if e != nil || info.Mode()&os.ModeSymlink != 0 || info.IsDir() != directory || info.Mode().Perm()&0022 != 0 {
		return false
	}
	stat, ok := info.Sys().(*syscall.Stat_t)
	return ok && stat.Uid == 0 && (directory || info.Mode().IsRegular())
}

// LoadProtectedHelper performs no enrollment or recovery. Missing policy,
// consumption history, lock or privilege always disables execution.
func LoadProtectedHelper() (*Helper, error) {
	if os.Geteuid() != 0 {
		return nil, errors.New("helper_unavailable")
	}
	for _, p := range []string{"/etc/nebuless", "/var/lib/nebuless-reboot", "/usr/local/libexec"} {
		if !protected(p, true) {
			return nil, errState
		}
	}
	for _, p := range []string{PolicyPath, LedgerPath, "/var/lib/nebuless-reboot/lock", HelperPath} {
		if !protected(p, false) {
			return nil, errState
		}
	}
	f, e := os.Open(PolicyPath)
	if e != nil {
		return nil, errState
	}
	defer func() { _ = f.Close() }()
	var policy Policy
	decoder := json.NewDecoder(io.LimitReader(f, 16384))
	decoder.DisallowUnknownFields()
	if decoder.Decode(&policy) != nil || decoder.Decode(new(any)) != io.EOF {
		return nil, errState
	}
	uid, e := strconv.Atoi(os.Getenv("SUDO_UID"))
	if e != nil || uid == 0 || policy.CollectorUID != uid || !actionID.MatchString(policy.MachineID) || !actionID.MatchString(policy.Issuer) || policy.KeyID == "" || len(policy.PublicKey) != 32 || len(policy.CredentialHash) != 64 {
		return nil, errState
	}
	if policy.MachineName == "" || policy.MachineName == "Primary-Hub" {
		return nil, errors.New("target_unavailable")
	}
	boot, e := os.ReadFile("/proc/sys/kernel/random/boot_id")
	if e != nil {
		return nil, errors.New("boot_unavailable")
	}
	policy.BootID = strings.TrimSpace(string(boot))
	h := &Helper{Policy: policy, Ledger: &Ledger{Path: LedgerPath}}
	if h.Ledger.Check() != nil {
		return nil, errState
	}
	return h, nil
}

// FixedReboot is called only after permit checks and durable consumption. No
// command, path or arguments are accepted from policy, CLI or network input.
func FixedReboot() error {
	return exec.Command("/usr/sbin/shutdown", "-r", "now").Run()
}

type LocalHelper interface {
	Probe(context.Context) error
	Attempt(context.Context, func(string) (Envelope, error)) (string, error)
}

// ProcessHelper passes the injected local credential solely to the fixed
// helper's stdin. It is never an argv item, report, journal field or log entry.
type ProcessHelper struct{}

func (ProcessHelper) command(ctx context.Context) (*exec.Cmd, io.WriteCloser, io.ReadCloser, error) {
	cmd := exec.CommandContext(ctx, "/usr/bin/sudo", "-n", "--", HelperPath)
	cmd.Env = []string{"PATH=/usr/sbin:/usr/bin:/sbin:/bin"}
	stdin, e := cmd.StdinPipe()
	if e != nil {
		return nil, nil, nil, errors.New("helper_unavailable")
	}
	stdout, e := cmd.StdoutPipe()
	if e != nil {
		_ = stdin.Close()
		return nil, nil, nil, errors.New("helper_unavailable")
	}
	cmd.Stderr = io.Discard
	if cmd.Start() != nil {
		_ = stdin.Close()
		_ = stdout.Close()
		return nil, nil, nil, errors.New("helper_unavailable")
	}
	return cmd, stdin, stdout, nil
}
func (p ProcessHelper) Probe(ctx context.Context) error {
	credential := os.Getenv("NEBULESS_REBOOT_CREDENTIAL")
	if credential == "" {
		return errors.New("credential_missing")
	}
	cmd, in, out, e := p.command(ctx)
	if e != nil {
		return e
	}
	encodeErr := json.NewEncoder(in).Encode(struct {
		Credential string `json:"credential"`
		Probe      bool   `json:"probe"`
	}{credential, true})
	_ = in.Close()
	var result struct {
		Available bool `json:"available"`
	}
	decodeErr := json.NewDecoder(io.LimitReader(out, 4096)).Decode(&result)
	waitErr := cmd.Wait()
	if encodeErr != nil || decodeErr != nil || waitErr != nil || !result.Available {
		return errors.New("helper_unavailable")
	}
	return nil
}
func (p ProcessHelper) Attempt(ctx context.Context, request func(string) (Envelope, error)) (string, error) {
	credential := os.Getenv("NEBULESS_REBOOT_CREDENTIAL")
	if credential == "" {
		return "unknown", errors.New("credential_missing")
	}
	cmd, in, out, e := p.command(ctx)
	if e != nil {
		return "unknown", e
	}
	defer func() {
		_ = in.Close()
		if cmd.ProcessState == nil {
			_ = cmd.Process.Kill()
			_ = cmd.Wait()
		}
	}()
	encoder := json.NewEncoder(in)
	if encoder.Encode(struct {
		Credential string `json:"credential"`
	}{credential}) != nil {
		return "unknown", errors.New("helper_unavailable")
	}
	decoder := json.NewDecoder(io.LimitReader(out, 16384))
	var pending struct {
		Nonce string `json:"nonce"`
	}
	if decoder.Decode(&pending) != nil || len(pending.Nonce) != 64 {
		return "unknown", errors.New("helper_unavailable")
	}
	envelope, e := request(pending.Nonce)
	if e != nil {
		return "unknown", e
	}
	if encoder.Encode(envelope) != nil {
		return "unknown", errors.New("helper_unavailable")
	}
	_ = in.Close()
	var result struct {
		Status string `json:"status"`
	}
	if decoder.Decode(&result) != nil {
		return "unknown", errors.New("helper_unavailable")
	}
	if cmd.Wait() != nil || result.Status != "attempted" {
		return "unknown", errors.New("helper_unavailable")
	}
	return "attempted", nil
}

// ServeHelper's adapter is supplied by the fixed executable. Tests supply only
// a counter. Every failure is a fixed code and never echoes input material.
func ServeHelper(h *Helper, in io.Reader, out io.Writer, invoke func() error) error {
	decoder := json.NewDecoder(io.LimitReader(in, 16384))
	decoder.DisallowUnknownFields()
	var hello struct {
		Credential string `json:"credential"`
		Probe      bool   `json:"probe"`
	}
	if decoder.Decode(&hello) != nil {
		return errors.New("helper_unavailable")
	}
	pending, e := h.Begin(hello.Credential)
	hello.Credential = ""
	if e != nil {
		return e
	}
	encoder := json.NewEncoder(out)
	if hello.Probe {
		return encoder.Encode(struct {
			Available bool `json:"available"`
		}{true})
	}
	if encoder.Encode(struct {
		Nonce string `json:"nonce"`
	}{pending.Nonce}) != nil {
		return errors.New("helper_unavailable")
	}
	var envelope Envelope
	if decoder.Decode(&envelope) != nil {
		return errors.New("permit_refused")
	}
	if e = h.Execute(pending, envelope, invoke); e != nil {
		return e
	}
	return encoder.Encode(struct {
		Status string `json:"status"`
	}{"attempted"})
}
