---
type: added
title: Initial public collector and supporting source infrastructure
---

The initial public source includes the Go `nebu-alrt` observation collector and
delivery CLI, Laravel/Livewire hub source, Python integration source, and build
and verification infrastructure. Linux x86-64 is the currently tested native
build target. The public checkout uses synthetic references and excludes private
runtime configuration, credentials, installed state and private Git history.

This is a source milestone, not a claim of a fully installed hub or a bundled
single-download runtime. The proposed Go supervisor and `nebu-alrt up` command
are not implemented. Deployment automation and signed website releases are not
provided by this record.
