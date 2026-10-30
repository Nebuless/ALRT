---
type: fixed
title: Synchronize uploader ACK and eviction verification
---

Replace scheduler-dependent waits in the uploader ACK-prefix and eviction tests
with bounded completion events and real journal drain/reopen barriers. Preserve
the cursor, loss-disclosure and no-invented-record assertions. Production behavior
is unchanged.

Verification on Go 1.27.3, linux/amd64:

- Before: 20 repetitions of the two affected tests failed seven partial-ACK
  subtests with `cursor 0 want 1`.
- After: the same 20 repetitions passed (14.629s).
- Full uploader package passed with `-count=1 -timeout=120s` (17.785s).
- Full uploader package passed with `-race -count=1 -timeout=180s` (43.745s).
- `go vet ./internal/uploader` and `git diff --check` passed.

The ACK test previously allowed only 80ms for contact, replay and asynchronous
cursor persistence. `Append` also only enqueues work. The test now drains and
reopens its fixture, joins the real contact loop after accepted contact, then
joins the real replay loop after exactly one fully decoded HTTP response. A
pass-through transport observes response closure and cancels the next replay
iteration; it does not synthesize requests or responses. Finally, journal close
drains queued ACK/Commit operations before the cursor assertion. This also avoids
assuming that `Worker.Run` cancellation joins replay: it deliberately may return
without waiting for filesystem work.

The eviction fixture previously paced 40 appends with 2ms sleeps against a
32-slot queue. It now drains batches of 16 through Close/Open, retains all 40
appends and the final recovery boundary, and asserts persisted eviction before
starting current delivery. The supplied native ARM64 log records `queue full`,
followed by temporary-directory cleanup failure; its reported missing-gap variant
was not reproduced locally. Journal eviction records loss before deleting a
segment, and Close drains queued writes and flushes metadata. No production
root cause was established, and native ARM64 verification remains with the lead.
