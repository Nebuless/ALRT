# ALRT

Tailscale-native private machine and agent observability by Nebuless.

ALRT uses Laravel and Livewire for the application, a Go CLI/collector named **`nebu-alrt`**, SQLite for local state and Python for agent integration. The modern web interface provides planetary host instruments, Citizens agent inventory and evidence-based reporting.

## Access model

Tailscale is the intended access and private-network boundary. Run hubs behind Tailscale Serve with an explicit owner/device policy; do not expose the backend directly or replace identity validation with anonymous localhost access. Optional legacy Better Auth source remains for compatibility, not the recommended deployment profile.

No personal host inventory, tailnet configuration, real credentials, installed state or private Git history is included. Names such as `Primary-Hub`, `Alpha-Hub` and `Beta-Restricted` are synthetic reference topology labels. The beta-restricted privacy and primary-only action boundaries remain enforced; this initial source release does not claim arbitrary topology support.

## Current CLI

Prebuilt collector downloads target Linux x86-64 and ARM64. No Go, Node, PHP or Python installation is needed to run this collector binary. Download the versioned installer, inspect it, then run without sudo:

```sh
curl -fL --proto '=https' --proto-redir '=https' \
  https://github.com/Nebuless/ALRT/releases/download/v0.1.2/install.sh -o install-alrt.sh
sh install-alrt.sh
"$HOME/.local/bin/nebu-alrt" version
"$HOME/.local/bin/nebu-alrt" sample --machine-id example-host --scope sandbox --count 1
```

Installer verifies archive SHA-256 against the same GitHub release, checks binary version, then atomically replaces `~/.local/bin/nebu-alrt`. Checksums detect corruption; they do not provide independent publisher authentication. Set `ALRT_VERSION=0.1.2` to select a release or `ALRT_INSTALL_DIR=/absolute/path` for another destination. Run the selected release's installer again to upgrade or downgrade; configuration is untouched. Add `~/.local/bin` to `PATH` if needed. Remove that binary to uninstall. No services, Tailscale enrollment or configuration are created automatically.

To build from source instead:

```sh
cd collector
go build -trimpath -o ../bin/nebu-alrt ./cmd/nebu-alrt
../bin/nebu-alrt sample --machine-id example-host --scope sandbox --count 1
../bin/nebu-alrt run --config /absolute/path/to/private-collector.json
```

The executable collects/delivers observations. It is **not the full web application installer**. The proposed Go supervisor bundling Laravel/PHP and Python is not implemented; there is no `nebu-alrt up` command. `run` needs a separately configured hub, Tailscale connectivity and private enrollment configuration. End-user application bundling and signed website releases are planned separately.

## Changes and releases

Browse [per-merge change records](.changes/), [curated changelog](CHANGELOG.md) or [commit history](https://github.com/Nebuless/ALRT/commits/main) for ongoing work. Normal PR merges never publish a release. [Contributing](CONTRIBUTING.md) explains required change records; [release operations](docs/releases.md) explains explicit versions, checks and publication.

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
