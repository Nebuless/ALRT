# Telemetry v1 fixtures

`valid-sample.json` is the canonical synthetic report for hub integration. Its
machine ID is `22222222-2222-4222-8222-222222222222`. Validate reports against
`../../telemetry-v1.schema.json`; every object rejects additional properties.
The Go decoder additionally rejects unknown fields and unsupported major versions.

Values and uncertainty:

- Memory, disk and process RSS use bytes. Network rates use bytes/second.
- CPU uses percent. Process CPU excludes waited-child counters and can exceed
  100%. The first observation of each process identity has a null CPU value.
- Process identity is boot ID, PID and start ticks. Tick frequency is reported
  in capabilities. Process names contain only bounded, sanitized proc stat comm.
- PSI averages use percent, total uses microseconds. Unavailable readings are
  null with a fixed status code. No fallback invents zero measurements.
- A process's `parent` identifies an observed current parent or marks it unknown.
  `former_parent` records the exited identity behind a proven orphan observation.
  First-seen PID 1 children are uncertain; only proven orphans enter `orphans`.
  A proven observation persists while that process identity survives.
- Top CPU/RSS and longest-running lists contain at most ten entries. CPU lists
  can be partial while newly seen identities have unknown rates. Longest order
  uses OS start ticks, including after collector restart.
- Orphan and zombie categories have configurable item budgets. A truncated list
  reports `partial`, `category_limit` and its observed total. This version emits
  one chunk (`chunk_index: 0`, `chunk_count: 1`); it does not claim later chunks.
- Process scan, directory enumeration, permission and race failures reduce
  coverage. Observed counts never assert a complete population when partial.
- Disk and network labels are configured opaque identifiers. Their mount paths
  and kernel interface names are never encoded. Empty arrays mean no resources
  were configured; the associated capability is false.

## Local verification

From `collector`, emit two samples with actual elapsed rates:

```sh
go run ./cmd/nebu-alrt sample \
  --machine-id 22222222-2222-4222-8222-222222222222 \
  --scope sandbox --disk system=/ --interface uplink=eth0 \
  --count 2 --interval 200ms
```

Sandbox hub enrollment must configure `scope: sandbox`, including when machine IDs are
UUIDs. Scope describes the measurement boundary, not an underlying physical host.
The command defaults to one sample; a new command invocation starts a fresh
stream and resets sequence to one while retaining the OS boot ID.

Fixture-based Go tests also cover PSI presence, PID and parent reuse, parent
exit/adoption evidence, missing zombie parents, vanished process stat files,
counter rollback, waited-child exclusion, scan/category limits, restart ordering,
and privacy canaries in prohibited proc files. They never read those files.
