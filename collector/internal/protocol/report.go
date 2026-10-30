// Package protocol defines the slice-one privacy allowlist. No arbitrary metadata,
// raw errors, paths, arguments, titles, or environment fields belong on this wire.
package protocol

import (
	"bytes"
	"encoding/json"
	"errors"
	"io"
)

const Version = 1

type Report struct {
	ProtocolVersion int          `json:"protocol_version"`
	MachineID       string       `json:"machine_id"`
	StreamID        string       `json:"stream_id"`
	Sequence        uint64       `json:"sequence"`
	BootID          string       `json:"boot_id"`
	SampledAt       string       `json:"sampled_at"`
	ElapsedSeconds  *float64     `json:"elapsed_seconds"`
	Scope           string       `json:"scope"`
	Capabilities    Capabilities `json:"capabilities"`
	Coverage        Coverage     `json:"coverage"`
	Metrics         Metrics      `json:"metrics"`
	Processes       Processes    `json:"processes"`
}
type Capabilities struct {
	OS                  string `json:"os"`
	ProcessIdentity     bool   `json:"process_identity"`
	CPU                 bool   `json:"cpu"`
	Memory              bool   `json:"memory"`
	Disk                bool   `json:"disk"`
	Network             bool   `json:"network"`
	Load                bool   `json:"load"`
	PSICPU              bool   `json:"psi_cpu"`
	PSIMemory           bool   `json:"psi_memory"`
	PSIIO               bool   `json:"psi_io"`
	ClockTicksPerSecond uint64 `json:"clock_ticks_per_second"`
}
type Coverage struct {
	Processes ProcessCoverage `json:"processes"`
}
type ProcessCoverage struct {
	Status             string  `json:"status"`
	Reason             *string `json:"reason"`
	Discovered         int     `json:"discovered"`
	Sampled            int     `json:"sampled"`
	PermissionDenied   int     `json:"permission_denied"`
	Vanished           int     `json:"vanished"`
	Malformed          int     `json:"malformed"`
	ScanLimited        bool    `json:"scan_limited"`
	EnumerationLimited bool    `json:"enumeration_limited"`
}

// Metric values are null unless status is available; units are fixed by schema.
type Metric struct {
	Value  *float64 `json:"value"`
	Status string   `json:"status"`
}
type Metrics struct {
	CPUPercent Metric    `json:"cpu_percent"`
	Memory     Memory    `json:"memory"`
	Disks      []Disk    `json:"disks"`
	Network    []Network `json:"network"`
	Load       Load      `json:"load"`
	Pressure   Pressure  `json:"pressure"`
}
type Memory struct {
	TotalBytes     Metric `json:"total_bytes"`
	AvailableBytes Metric `json:"available_bytes"`
	UsedBytes      Metric `json:"used_bytes"`
}
type Disk struct {
	Label          string `json:"label"`
	TotalBytes     Metric `json:"total_bytes"`
	AvailableBytes Metric `json:"available_bytes"`
	UsedBytes      Metric `json:"used_bytes"`
}
type Network struct {
	Label                  string `json:"label"`
	ReceiveBytesPerSecond  Metric `json:"receive_bytes_per_second"`
	TransmitBytesPerSecond Metric `json:"transmit_bytes_per_second"`
}
type Load struct {
	One     Metric `json:"one"`
	Five    Metric `json:"five"`
	Fifteen Metric `json:"fifteen"`
}
type Pressure struct {
	CPU    PSI `json:"cpu"`
	Memory PSI `json:"memory"`
	IO     PSI `json:"io"`
}
type PSI struct {
	Some PressureValues `json:"some"`
	Full PressureValues `json:"full"`
}
type PressureValues struct {
	Status            string   `json:"status"`
	Avg10             *float64 `json:"avg10"`
	Avg60             *float64 `json:"avg60"`
	Avg300            *float64 `json:"avg300"`
	TotalMicroseconds *uint64  `json:"total_microseconds"`
}
type Identity struct {
	BootID     string `json:"boot_id"`
	PID        int    `json:"pid"`
	StartTicks uint64 `json:"start_ticks"`
}
type Parent struct {
	Identity *Identity `json:"identity"`
	Status   string    `json:"status"`
}
type Process struct {
	Identity     Identity  `json:"identity"`
	Name         string    `json:"name"`
	State        string    `json:"state"`
	Parent       Parent    `json:"parent"`
	CPUPercent   *float64  `json:"cpu_percent"`
	RSSBytes     *uint64   `json:"rss_bytes"`
	AgeSeconds   *float64  `json:"age_seconds"`
	OrphanStatus string    `json:"orphan_status"`
	FormerParent *Identity `json:"former_parent"`
}
type Category struct {
	Items         []Process `json:"items"`
	Completeness  string    `json:"completeness"`
	ChunkIndex    int       `json:"chunk_index"`
	ChunkCount    int       `json:"chunk_count"`
	TotalObserved int       `json:"total_observed"`
	Reason        *string   `json:"reason"`
}
type Processes struct {
	TopCPU         Category `json:"top_cpu"`
	TopRSS         Category `json:"top_rss"`
	Orphans        Category `json:"orphans"`
	Zombies        Category `json:"zombies"`
	LongestRunning Category `json:"longest_running"`
}

// Decode rejects fields outside the allowlist, including nested sensitive fields.
// Receivers also validate telemetry-v1.schema.json for required fields and ranges.
func Decode(data []byte) (Report, error) {
	var r Report
	d := json.NewDecoder(bytes.NewReader(data))
	d.DisallowUnknownFields()
	if err := d.Decode(&r); err != nil {
		return Report{}, errors.New("invalid_report")
	}
	if err := d.Decode(new(any)); err != io.EOF {
		return Report{}, errors.New("invalid_report")
	}
	if r.ProtocolVersion != Version {
		return Report{}, errors.New("protocol_version_unsupported")
	}
	return r, nil
}
