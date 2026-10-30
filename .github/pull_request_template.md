## Change

Describe the resulting behavior, scope and limitations.

## Verification

List commands run and their results, including any checks not run and why.

- [ ] Add a new permanent `.changes/*.md` record (also for docs/tests/infrastructure).
- [ ] Run `python3 scripts/changes.py check` and the relevant application checks.
- [ ] After committing, run `python3 scripts/changes.py check --base BASE` using the PR base.
- [ ] Keep private runtime state and personal information out of the diff and record.

See [.changes/README.md](../.changes/README.md) for the fragment format, committed
diff gate and explicit release-note selections. Ordinary merges are not releases.
