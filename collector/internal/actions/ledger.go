// Package actions implements the primary-only reboot exchange. Telemetry never
// owns the protected consumption ledger, which is the execution authority.
package actions

import (
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"os"
	"path/filepath"
	"syscall"
)

var errState = errors.New("protected_state_unavailable")

type durableFile interface {
	io.Writer
	Sync() error
	Close() error
}
type fileOperations interface {
	OpenFile(string, int, os.FileMode) (durableFile, error)
	Rename(string, string) error
	Remove(string) error
}
type osFiles struct{}

func (osFiles) OpenFile(p string, flags int, mode os.FileMode) (durableFile, error) {
	return os.OpenFile(p, flags, mode)
}
func (osFiles) Rename(a, b string) error { return os.Rename(a, b) }
func (osFiles) Remove(p string) error    { return os.Remove(p) }

// Replace is atomic and does not report success until both data and the
// directory entry are durable. A failure leaves the caller forbidden to act.
func replace(path string, b []byte, ops fileOperations) error {
	var nonce [16]byte
	if _, e := rand.Read(nonce[:]); e != nil {
		return errState
	}
	tmp := path + "." + hex.EncodeToString(nonce[:]) + ".tmp"
	f, e := ops.OpenFile(tmp, os.O_WRONLY|os.O_CREATE|os.O_EXCL|syscall.O_NOFOLLOW, 0600)
	if e != nil {
		return errState
	}
	defer func() { _ = ops.Remove(tmp) }()
	n, e := f.Write(b)
	if e != nil || n != len(b) {
		_ = f.Close()
		return errState
	}
	if e = f.Sync(); e != nil {
		_ = f.Close()
		return errState
	}
	if e = f.Close(); e != nil {
		return errState
	}
	if e = ops.Rename(tmp, path); e != nil {
		return errState
	}
	dir, e := ops.OpenFile(filepath.Dir(path), os.O_RDONLY|syscall.O_DIRECTORY|syscall.O_NOFOLLOW, 0)
	if e != nil {
		return errState
	}
	if e = dir.Sync(); e != nil {
		_ = dir.Close()
		return errState
	}
	if e = dir.Close(); e != nil {
		return errState
	}
	return nil
}

type Ledger struct {
	Path string
	ops  fileOperations
}
type consumption struct {
	Version  int             `json:"version"`
	Consumed map[string]bool `json:"consumed"`
}

func (l *Ledger) read() (consumption, error) {
	var state consumption
	f, e := os.OpenFile(l.Path, os.O_RDONLY|syscall.O_NOFOLLOW, 0)
	if e != nil {
		return state, errState
	}
	defer func() { _ = f.Close() }()
	decoder := json.NewDecoder(f)
	decoder.DisallowUnknownFields()
	if decoder.Decode(&state) != nil || decoder.Decode(new(any)) != io.EOF || state.Version != 1 || state.Consumed == nil {
		return state, errState
	}
	for id, consumed := range state.Consumed {
		if id == "" || !consumed {
			return state, errState
		}
	}
	return state, nil
}
func (l *Ledger) lock() (*os.File, error) {
	f, e := os.OpenFile(filepath.Join(filepath.Dir(l.Path), "lock"), os.O_RDWR|syscall.O_NOFOLLOW, 0)
	if e != nil {
		return nil, errState
	}
	if e = syscall.Flock(int(f.Fd()), syscall.LOCK_EX); e != nil {
		_ = f.Close()
		return nil, errState
	}
	return f, nil
}
func (l *Ledger) Check() error {
	f, e := l.lock()
	if e != nil {
		return e
	}
	defer func() { _ = f.Close() }()
	_, e = l.read()
	return e
}
func (l *Ledger) consume(id string) error {
	lock, e := l.lock()
	if e != nil {
		return e
	}
	defer func() { _ = lock.Close() }()
	state, e := l.read()
	if e != nil {
		return e
	}
	if state.Consumed[id] {
		return errors.New("already_consumed")
	}
	state.Consumed[id] = true
	b, e := json.Marshal(state)
	if e != nil {
		return errState
	}
	ops := l.ops
	if ops == nil {
		ops = osFiles{}
	}
	return replace(l.Path, b, ops)
}
