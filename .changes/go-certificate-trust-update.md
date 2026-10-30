---
type: security
title: Build collector releases with corrected certificate trust
---
Pin Go 1.27.3 in local tooling and CI to address GO-2026-6809 in crypto/x509. Rebuild release artifacts with the fixed standard library before publication.
