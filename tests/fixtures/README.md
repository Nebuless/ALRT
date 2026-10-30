# Sanitized compatibility fixtures

All machine names, identities, URLs and credentials in these fixtures are synthetic. They are not a deployed inventory or runnable production configuration.

- `retention-prior/` preserves the narrow prior-reader source needed to test pruned-state compatibility without importing private Git history. Fixtures are loaded only by the explicit acceptance test and are not application routes or release payloads.
- `workflows/` contains inert workflow examples used to verify shared-lease and serial-deployment contracts. They are outside `.github/workflows` and cannot trigger deployments.

Keep all real inventories, keys, session state and generated evidence outside version control.
