# Contributing to ALRT

Contributions are covered by the repository [MIT license](LICENSE). Follow the
[README](README.md) for build, verification and privacy boundaries. Use synthetic
data; never commit credentials, host inventory or private runtime state.

Every normal pull request, including documentation, tests and infrastructure,
adds a new permanent Markdown change fragment. Follow the format and explicit
release process in [.changes/README.md](.changes/README.md). Explain behavior and
limitations for a reader at that commit; keep the title concise. Reviewers should
check that the record accurately describes the PR.

Before review:

```sh
python3 scripts/changes.py check
python3 -m unittest discover -s scripts -p 'test_changes.py'
```

After committing, validate the actual PR diff with a locally available base ref:

```sh
python3 scripts/changes.py check --base BASE
```

The base check examines committed HEAD, not uncommitted changes. It requires the
public merge base locally and does not fetch history. See the fragment README for
the CI contract. Run the relevant application checks from the root README as well.

Keep `CHANGELOG.md` curated: new work belongs under Unreleased until a deliberate
release selection. Merging a PR does not bump a version. Do not edit old fragments
or move already selected records between releases. Add a correction record instead.
