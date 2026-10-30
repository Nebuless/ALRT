# Permanent change records

Every normal PR adds a new, unique, descriptive `.changes/*.md` file. Documentation,
tests and infrastructure changes count too. A merge is a change record, not a
release: no version bump or tag is created automatically. These files are permanent
and readable on GitHub at the merge commit. Do not edit, rename or delete a merged
fragment; add a new correction fragment instead. No bot, network or private history
is needed.

Use a lowercase hyphenated filename such as `collector-handle-empty-samples.md`.
If that name already exists, choose another descriptive name or append a date.
Files live directly in `.changes/`; subdirectories are not supported.

```markdown
---
type: fixed
title: Handle empty collector samples
---

Describe the user-visible behavior and any relevant limitation or migration.
```

This is a deliberately small frontmatter format, not general YAML: exactly two
unindented fields, `type` and `title`, between `---` lines. Values are plain text;
do not quote them or use multiline YAML. `type` is one of `added`, `changed`,
`fixed`, `removed`, `security`, `docs`, `infrastructure`. The title and Markdown
body must be nonempty. Keep secrets, personal information, host inventories and
private paths out of records. Records are covered by the repository MIT license.

## Contributor and CI checks

Requires Python 3.9+ and Git; uses only the Python standard library.

```sh
python3 scripts/changes.py check
python3 -m unittest discover -s scripts -p 'test_changes.py'
python3 scripts/changes.py check --base BASE
```

The first command validates working-tree files, including untracked fragments.
The base command checks **committed HEAD**, using `merge-base(BASE, HEAD)..HEAD`
with rename detection disabled. BASE must be a locally available base ref or SHA;
CI must supply the PR base and enough public Git ancestry to find the merge base.
Missing/invalid bases fail closed. The command never fetches anything. A fragment
already on the base branch, a modification/rename/deletion, or an uncommitted file
cannot satisfy the new-fragment requirement. At least one added fragment must be
unreleased. All committed fragments and the registry are validated, not just new
ones. There are no path exemptions for normal PRs. The Release tooling workflow
runs this check with the event's base SHA and complete public history.

## Explicit releases and curated changelog

`CHANGELOG.md` is the curated overview. Keep its **Unreleased** section separate
from explicit version sections. Individual merges do not create version sections.
When preparing a release, deliberately select existing fragment filenames in
`releases.json`, under an explicit `X.Y.Z` key. Each fragment can belong to at most
one version. Preserve existing version selections; never recycle their fragments.
The small registry does not assert that a tag, binary or deployment exists.

Print deterministic notes, ordered by type and filename:

```sh
python3 scripts/changes.py notes
python3 scripts/changes.py notes --version 0.1.2
python3 scripts/changes.py notes --version 0.1.2 > /tmp/alrt-0.1.2-notes.md
```

Unreleased notes contain fragments absent from all registry selections. A numbered
version contains exactly its selected fragments. Unknown versions fail. Notes are
written only to stdout; commands never bump versions, rewrite the changelog, remove
fragments, create tags or publish releases. For reproducibility, run at the desired
public commit. Curate the version overview from those notes and verify release
claims separately. A release-preparation PR still adds its own unreleased record.

The gate checks structure and Git diff membership, not truth, prose quality,
complete coverage of every edit, or authenticity of the caller-supplied base.
Reviewers remain responsible for accurate summaries and meaningful release scope.
