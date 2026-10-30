---
type: infrastructure
title: Keep permanent per-merge change records and explicit release selections
---

Add Markdown fragments for normal PRs, a standard-library Python validator for
the committed PR diff, and deterministic notes for unreleased or explicitly
selected versions. Keep the curated changelog separate from the permanent record
archive. Merges do not automatically bump a version or publish a release.

The tooling is ready for the PR workflow to call; CI workflow integration and
release automation are separate work.
