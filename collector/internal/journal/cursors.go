package journal

import "github.com/Nebuless/ALRT/collector/internal/protocol"

func (j *Journal) commit(op operation) {
	j.mu.Lock()
	old := j.state.Cursors[op.hub]
	next := old
	if len(op.records) > 0 {
		i := 0
		for _, en := range j.entries {
			if en.position <= old {
				continue
			}
			if i >= len(op.records) {
				break
			}
			r := op.records[i]
			if en.position != r.Position || en.stream != r.StreamID || en.sequence != r.Sequence {
				j.mu.Unlock()
				return
			}
			next = en.position
			i++
		}
		if i != len(op.records) {
			j.mu.Unlock()
			return
		}
	}
	// Save a proposed cursor separately. It becomes visible only after file and
	// directory synchronization. Filesystem latency never holds the mutex.
	proposal := j.state
	proposal.Delivery.LostRanges = append([]protocol.LostRange{}, j.state.Delivery.LostRanges...)
	proposal.Cursors = copyCursors(j.state.Cursors)
	proposal.Cursors[op.hub] = next
	if op.ack != nil {
		acks := append([]protocol.Acknowledgement{}, proposal.Delivery.Acknowledgements...)
		found := false
		for i, a := range acks {
			if a.HubID == op.hub {
				acks[i] = *op.ack
				found = true
			}
		}
		if !found {
			acks = append(acks, *op.ack)
		}
		proposal.Delivery.Acknowledgements = acks
	}
	j.mu.Unlock()
	if e := j.persistProposal(proposal); e != nil {
		j.failure(e.Error())
		return
	}
	j.mu.Lock()
	j.state.Cursors[op.hub] = next
	if op.ack != nil {
		j.state.Delivery.Acknowledgements = proposal.Delivery.Acknowledgements
	}
	j.mu.Unlock()
}
