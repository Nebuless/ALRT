# PR and release workflow

Every change arrives through a pull request into `main`, normally squash-merged with a concise descriptive title. Add a permanent `.changes/*.md` record, including for tests, docs and infrastructure. CI rejects PRs without a new valid record and rejects edits/deletions of old records. GitHub's commit history links merge commits to PRs; the record beside each commit explains the change. No release or version bump happens on an ordinary merge.

`main` requires an up-to-date PR with passing application, tooling and both native package checks, resolved conversations and linear history. Direct pushes, force pushes and branch deletion are blocked, including for admins. Squash merges keep one visible commit per PR; merged branches are automatically removed. Independent review is a maintainer responsibility; the small project does not require a second GitHub account to approve its own initial setup.

`CHANGELOG.md` is the curated overview. `.changes/` is the detailed ongoing log. `.changes/releases.json` deliberately selects records for each explicit release; `python3 scripts/changes.py notes --version 0.1.2` prints deterministic notes. Keep records free of personal information and runtime secrets.

## Prepare an explicit collector release

1. Open a release-preparation PR. Set `VERSION` to the desired `X.Y.Z`, update the installer's default version and README install URL, select completed records in `.changes/releases.json`, and curate `CHANGELOG.md`. Include a new release-preparation record. Clearly label collector-only scope until whole-application bundling exists.
2. Pass `Check` and `Release tooling` workflows, including native Linux x86-64 and ARM64 package/install smoke tests. Run relevant checks and independently review installer, workflow and behavior changes. Merge through the PR.
3. From Actions, manually run **Release** on `main`. The workflow uses that exact triggering commit; it runs the full application quality gate, native Go tests/vet, builds static archives, installs and samples each architecture, and uploads versioned artifacts. Only its final draft-creation job has `contents: write`. No schedule or per-commit release trigger exists.
4. Inspect the draft `vX.Y.Z`: confirm target commit, notes, both archives, `install.sh` and `SHA256SUMS`. Check workflow success. Download and check actual draft assets before publishing. Failed builds create no draft; failed uploads leave a draft, not a public release. A rerun refuses an existing release rather than overwriting it. Repair a failed draft explicitly after inspection.
5. Publish the reviewed draft, then verify the public installer and downloaded binary version/sample. A published release is never edited to replace bytes: prepare a new version for corrections. Roll back a local collector with a prior version's installer and `ALRT_VERSION`; this replaces only the executable and does not roll back configuration or hub database state. With no prior published release, retain the previous binary yourself before installing.

Default credentials are read-only. PR workflows use `pull_request`, never `pull_request_target`, and receive no publish credentials. Third-party actions use immutable commits; Go and local validators have exact MISE pins. Build artifacts are produced on GitHub-hosted native architecture runners with `CGO_ENABLED=0`; checksums are generated from the exact uploaded archives. Archive checksums share GitHub's trust boundary and are not cryptographic publisher signatures.

## Reference

Adapted from [T3 Code release workflow](https://github.com/pingdotgg/t3code/blob/main/.github/workflows/release.yml): separate quality checks, native platform packaging, final-byte checksums and explicit stable releases. ALRT deliberately omits T3's scheduled nightly train, desktop stores, npm launcher and AI-generated release summaries. Permanent reviewed Markdown records fit this repository without another publishing service or API secret.
