---
type: added
title: Add versioned Linux collector installs and explicit release drafts
---
Build static nebu-alrt collector archives for Linux x86-64 and ARM64 with version and source revision. A user-local installer verifies checksums and binary version, then atomically replaces only the executable. Native packaging checks exercise installation and one real sample. Explicit main-branch release runs create reviewed drafts; ordinary PR merges do not publish releases. Full Laravel/PHP/Python application bundling remains separate.
