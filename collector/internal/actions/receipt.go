package actions

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"regexp"
)

type Action struct {
	ID          string  `json:"id"`
	MachineID   string  `json:"machine_id"`
	BootID      string  `json:"boot_id"`
	State       string  `json:"state"`
	ExpiresAt   string  `json:"expires_at"`
	CancelUntil *string `json:"cancel_until"`
}
type Receipt struct {
	Action Action `json:"action"`
	Status string `json:"status"`
}
type Receipts struct {
	Directory string
	ops       fileOperations
}

var actionID = regexp.MustCompile(`^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$`)

func (r *Receipts) Save(a Action, status string) error {
	if !actionID.MatchString(a.ID) {
		return errors.New("invalid_action")
	}
	b, e := json.Marshal(Receipt{Action: a, Status: status})
	if e != nil {
		return errors.New("receipt_unavailable")
	}
	ops := r.ops
	if ops == nil {
		ops = osFiles{}
	}
	return replace(filepath.Join(r.Directory, a.ID+".json"), b, ops)
}
func (r *Receipts) Read(id string) (Receipt, error) {
	var state Receipt
	if !actionID.MatchString(id) {
		return state, errors.New("invalid_action")
	}
	b, e := os.ReadFile(filepath.Join(r.Directory, id+".json"))
	if e != nil {
		return state, errors.New("receipt_unavailable")
	}
	if json.Unmarshal(b, &state) != nil || state.Action.ID != id {
		return Receipt{}, errors.New("receipt_unavailable")
	}
	return state, nil
}
