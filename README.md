# ALRT

Tailscale-native private machine and agent observability by Nebuless.

ALRT uses Laravel and Livewire for the application, a Go CLI/collector named **`nebu-alrt`**, SQLite for local state and Python for agent integration. The modern web interface provides planetary host instruments, Citizens agent inventory and evidence-based reporting.

## Access model

Tailscale is the intended access and private-network boundary. Run hubs behind Tailscale Serve with an explicit owner/device policy; do not expose the backend directly or replace identity validation with anonymous localhost access. Optional legacy Better Auth source remains for compatibility, not the recommended deployment profile.

No personal host inventory, tailnet configuration, real credentials, installed state or private Git history is included. Names such as `Primary-Hub`, `Alpha-Hub` and `Beta-Restricted` are synthetic reference topology labels. The beta-restricted privacy and primary-only action boundaries remain enforced; this initial source release does not claim arbitrary topology support.

## Current CLI

```sh
cd collector
go build -trimpath -o ../bin/nebu-alrt ./cmd/nebu-alrt
../bin/nebu-alrt sample --machine-id example-host --scope sandbox --count 1
../bin/nebu-alrt run --config /absolute/path/to/private-collector.json
```

The current executable collects/delivers observations. The proposed single-download Go supervisor bundling Laravel/PHP and Python is **not implemented yet**; there is no `nebu-alrt up` command in this release. End-user runtime bundling and signed website releases are planned separately.

## Build and checks

Linux x86-64 is the current tested native build target. Tool versions are pinned in `mise.toml`, `mise.lock` and native dependency lockfiles. PHP/SQLite use checksum-verified source builds with the required ABI/extensions.

```sh
mise install
mise exec -- tools/bootstrap
mise exec -- npm --prefix apps/hub run build
mise exec -- ./bin/check
mise exec -- ./bin/acceptance
mise exec -- ./bin/browser-check
mise exec -- ./bin/tailscale-browser-check
```

Bootstrap needs the compiler/system development libraries documented in [development](docs/development.md). It does not install production services. Browser checks use disposable synthetic identity/data and fake actions, not your tailnet or working agents.

## Safety and privacy

- Missing/stale readings are not fabricated as zero or current health.
- Process and reboot controls require fresh identity, explicit confirmation and local safety checks.
- A distinct-UID Citizens collector is inventory-only; same-UID collectors already possess OS signaling rights.
- Keep `.env`, databases, session/enrollment files, runtime configuration, journals, action receipts and logs private.
- Deployment automation is not enabled by this public repository. Historical workflow examples under test fixtures are inert contract data.

## Licensing

ALRT is licensed under the [MIT License](LICENSE). Third-party dependencies and bundled font assets retain their own notices/licenses; preserve those notices.
