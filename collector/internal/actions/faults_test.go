package actions

import (
	"errors"
	"os"
)

type failingFiles struct {
	step, stop int
	failed     bool
}

func (f *failingFiles) next() error {
	f.step++
	if f.step == f.stop {
		f.failed = true
		return errors.New("disk_full_or_crash")
	}
	return nil
}
func (f *failingFiles) OpenFile(p string, flags int, mode os.FileMode) (durableFile, error) {
	if e := f.next(); e != nil {
		return nil, e
	}
	file, e := os.OpenFile(p, flags, mode)
	if e != nil {
		return nil, e
	}
	return &failingFile{File: file, fs: f}, nil
}
func (f *failingFiles) Rename(a, b string) error {
	if e := f.next(); e != nil {
		return e
	}
	return os.Rename(a, b)
}
func (f *failingFiles) Remove(p string) error { return os.Remove(p) }

type failingFile struct {
	*os.File
	fs *failingFiles
}

func (f *failingFile) Write(b []byte) (int, error) {
	if e := f.fs.next(); e != nil {
		return 0, e
	}
	return f.File.Write(b)
}
func (f *failingFile) Sync() error {
	if e := f.fs.next(); e != nil {
		return e
	}
	return f.File.Sync()
}
func (f *failingFile) Close() error {
	injected := f.fs.next()
	e := f.File.Close()
	if injected != nil {
		return injected
	}
	return e
}
