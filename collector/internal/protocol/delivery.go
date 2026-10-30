package protocol

import (
	"encoding/json"
	"errors"
)

const MaxReportBytes = 96 << 10
const MaxBatchBytes = 256 << 10

type Ack struct {
	StreamID string `json:"stream_id"`
	Sequence uint64 `json:"sequence"`
}
type LostRange struct {
	HubID         string `json:"hub_id"`
	StreamID      string `json:"stream_id"`
	FirstSequence uint64 `json:"first_sequence"`
	LastSequence  uint64 `json:"last_sequence"`
	From          string `json:"from"`
	To            string `json:"to"`
	Reason        string `json:"reason"`
}
type Acknowledgement struct {
	HubID          string `json:"hub_id"`
	StreamID       string `json:"stream_id"`
	Sequence       uint64 `json:"sequence"`
	AcknowledgedAt string `json:"acknowledged_at"`
}
type Delivery struct {
	Version             int               `json:"version"`
	Recording           string            `json:"recording"`
	PossibleLossSeconds int               `json:"possible_loss_seconds"`
	UnknownCoverage     bool              `json:"unknown_coverage"`
	LostRanges          []LostRange       `json:"lost_ranges"`
	Acknowledgements    []Acknowledgement `json:"acknowledgements"`
}

// Prepare chooses the immutable snapshot before either path sees its identity.
// All categories retain single-chunk partial coverage when truncated.
func Prepare(r Report) ([]byte, error) {
	categories := []*Category{&r.Processes.Orphans, &r.Processes.Zombies}
	for _, c := range categories {
		if len(c.Items) > 64 {
			c.Items = c.Items[:64]
			c.Completeness = "partial"
			reason := "category_limit"
			c.Reason = &reason
			c.ChunkIndex = 0
			c.ChunkCount = 1
		}
	}
	b, e := json.Marshal(r)
	for e == nil && len(b) > MaxReportBytes {
		changed := false
		for _, c := range categories {
			if len(c.Items) > 0 {
				c.Items = c.Items[:len(c.Items)/2]
				c.Completeness = "partial"
				reason := "category_limit"
				c.Reason = &reason
				changed = true
			}
		}
		if !changed {
			return nil, errors.New("report_too_large")
		}
		b, e = json.Marshal(r)
	}
	if e != nil {
		return nil, errors.New("invalid_report")
	}
	return b, nil
}
