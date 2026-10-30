# Changelog

This is the curated ALRT overview. [Permanent change records](.changes/README.md)
retain per-merge detail; ordinary merges are not releases. Version selections
describe source scope and do not by themselves establish a published artifact or
an installed deployment.

## Unreleased

- Add permanent PR change fragments, local and committed-diff validation, and
  reproducible release-note selection, enforced by pull-request CI.

## 0.1.2

- Build with Go 1.27.3 to address GO-2026-6809 in certificate trust verification.
- Reject nonregular install destinations, preserve historical release selections
  and refuse pre-existing tags when creating verified release drafts.
- Initial public Go `nebu-alrt` collector/delivery CLI source, with supporting
  Laravel/Livewire hub and Python integration source.
- Public build and verification infrastructure, with synthetic references and
  private runtime state excluded. Linux x86-64 is the currently tested native
  build target.
- Versioned static Linux collector archives, checksum-checked user-local install,
  version/help commands and explicit draft-release automation. Native x86-64 and
  ARM64 package/install checks run in hosted CI before publication.
- This source milestone does not claim a fully installed hub. The proposed
  bundled Go supervisor, `nebu-alrt up`, deployment automation and signed website
  releases are not provided here.

Reproduce detailed notes with `python3 scripts/changes.py notes --version 0.1.2`.
