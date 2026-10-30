package actions

import (
	"syscall"
	"time"
	"unsafe"
)

type bootClock struct{}

func (bootClock) Now() (time.Time, time.Duration, error) {
	var ts syscall.Timespec
	_, _, e := syscall.Syscall(syscall.SYS_CLOCK_GETTIME, 7, uintptr(unsafe.Pointer(&ts)), 0) // CLOCK_BOOTTIME includes suspend.
	if e != 0 {
		return time.Time{}, 0, e
	}
	return time.Now(), time.Duration(ts.Sec)*time.Second + time.Duration(ts.Nsec), nil
}
