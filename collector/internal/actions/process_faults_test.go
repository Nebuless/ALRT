package actions

import (
	"bufio"
	"bytes"
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"errors"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"testing"
	"time"
)

// Subprocess-only adapter. Production main never reads these variables and
// always loads its fixed protected paths and fixed graceful reboot adapter.
func TestHelperChild(t *testing.T) {
	path := os.Getenv("NEBULESS_TEST_LEDGER")
	if path == "" {
		return
	}
	public, _ := base64.StdEncoding.DecodeString(os.Getenv("NEBULESS_TEST_PUBLIC"))
	boundary, _ := strconv.Atoi(os.Getenv("NEBULESS_TEST_BOUNDARY"))
	h := &Helper{Policy: Policy{MachineID: "machine", BootID: "boot", Issuer: "primary", KeyID: "key", PublicKey: ed25519.PublicKey(public), CredentialHash: credentialHash("local-only")}, Ledger: &Ledger{Path: path}, clock: &fakeClock{}}
	h.clock = &fakeClock{wall: testWall(), elapsed: 1}
	if boundary > 0 {
		h.Ledger.ops = &crashFiles{at: boundary, after: os.Getenv("NEBULESS_TEST_AFTER") == "1"}
	}
	e := ServeHelper(h, os.Stdin, os.Stdout, func() error {
		if os.Getenv("NEBULESS_TEST_BEFORE_INVOKE") == "1" {
			os.Exit(23)
		}
		f, err := os.OpenFile(filepath.Join(filepath.Dir(path), "invocations"), os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0600)
		if err != nil {
			return err
		}
		_, err = f.Write([]byte("invoked\n"))
		if err == nil {
			err = f.Sync()
		}
		closeErr := f.Close()
		if err != nil {
			return err
		}
		if closeErr != nil {
			return closeErr
		}
		os.Exit(23)
		return nil
	})
	if e != nil {
		os.Exit(24)
	}
	os.Exit(0)
}
func testWall() time.Time { return time.Unix(2000000000, 0) }

type crashFiles struct {
	step, at int
	after    bool
}

func (f *crashFiles) before() {
	f.step++
	if f.step == f.at && !f.after {
		os.Exit(23)
	}
}
func (f *crashFiles) end() {
	if f.step == f.at && f.after {
		os.Exit(23)
	}
}
func (f *crashFiles) OpenFile(p string, flags int, mode os.FileMode) (durableFile, error) {
	f.before()
	file, e := os.OpenFile(p, flags, mode)
	f.end()
	if e != nil {
		return nil, e
	}
	return &crashFile{File: file, fs: f}, nil
}
func (f *crashFiles) Rename(a, b string) error { f.before(); e := os.Rename(a, b); f.end(); return e }
func (f *crashFiles) Remove(p string) error    { return os.Remove(p) }

type crashFile struct {
	*os.File
	fs *crashFiles
}

func (f *crashFile) Write(b []byte) (int, error) {
	f.fs.before()
	n, e := f.File.Write(b)
	f.fs.end()
	return n, e
}
func (f *crashFile) Sync() error  { f.fs.before(); e := f.File.Sync(); f.fs.end(); return e }
func (f *crashFile) Close() error { f.fs.before(); e := f.File.Close(); f.fs.end(); return e }

func TestProcessCrashesEveryConsumptionBoundary(t *testing.T) {
	for boundary := 1; boundary <= 10; boundary++ {
		for _, after := range []bool{false, true} {
			t.Run(strconv.Itoa(boundary)+"/"+strconv.FormatBool(after), func(t *testing.T) {
				h, key, _, path := enrolled(t)
				attempt := func(fail bool) {
					cmd := exec.Command(os.Args[0], "-test.run=^TestHelperChild$")
					cmd.Env = append(os.Environ(), "NEBULESS_TEST_LEDGER="+path, "NEBULESS_TEST_PUBLIC="+base64.StdEncoding.EncodeToString(h.Policy.PublicKey))
					if fail {
						switch boundary {
						case 9:
							cmd.Env = append(cmd.Env, "NEBULESS_TEST_BEFORE_INVOKE=1")
						case 10:
						default:
							cmd.Env = append(cmd.Env, "NEBULESS_TEST_BOUNDARY="+strconv.Itoa(boundary))
							if after {
								cmd.Env = append(cmd.Env, "NEBULESS_TEST_AFTER=1")
							}
						}
					}
					in, e := cmd.StdinPipe()
					if e != nil {
						t.Fatal(e)
					}
					out, e := cmd.StdoutPipe()
					if e != nil {
						t.Fatal(e)
					}
					cmd.Stderr = io.Discard
					if cmd.Start() != nil {
						t.Fatal("child failed")
					}
					encoder := json.NewEncoder(in)
					_ = encoder.Encode(map[string]string{"credential": "local-only"})
					scanner := bufio.NewScanner(out)
					if scanner.Scan() {
						var nonce struct {
							Nonce string `json:"nonce"`
						}
						if json.Unmarshal(scanner.Bytes(), &nonce) != nil {
							t.Fatal("nonce")
						}
						_ = encoder.Encode(signed(t, h, key, &Pending{Nonce: nonce.Nonce}, nil))
					}
					_ = in.Close()
					_ = cmd.Wait()
				}
				attempt(true)
				attempt(false)
				attempt(false)
				b, e := os.ReadFile(filepath.Join(filepath.Dir(path), "invocations"))
				if e != nil && !errors.Is(e, os.ErrNotExist) {
					t.Fatal(e)
				}
				count := bytes.Count(b, []byte("invoked\n"))
				if count > 1 {
					t.Fatalf("invocations %d", count)
				}
				if boundary == 9 && count != 0 {
					t.Fatal("consumed action retried after crash before invocation")
				}
			})
		}
	}
}
