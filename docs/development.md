# Native development

ALRT targets a Tailscale-private Laravel/Livewire hub and the `nebu-alrt` Go collector. Public examples use synthetic machines and reserved example domains; configure your own identities locally.

## Prerequisites

Linux x86-64, MISE, Python 3 with standard library, Supervisor for disposable acceptance/service-manager fixtures, and a C build toolchain. On Debian/Ubuntu, build prerequisites include `build-essential pkg-config libxml2-dev libcurl4-openssl-dev libonig-dev libssl-dev zlib1g-dev`, plus `curl`, `tar`, `xz`, Git and CA certificates. Installing OS packages is an explicit operator step, not hidden in the application.

`mise.toml` and Linux `mise.lock` pin Node, Go, Caddy, lint/security/task tools. `tools/build-runtime` pins PHP 8.5.12 and SQLite 3.51.3 source checksums; `tools/bootstrap` verifies Composer and Caddy/linter inputs and installs native lockfile dependencies. These are development requirements, not a claim that the planned single-file runtime bundle exists.

## Workflow

Run bootstrap and build assets before PHP view tests. `bin/check` executes real auth tests, formatting/static analysis, PHP tests, Go checks and production assets. `bin/acceptance` covers immutable archive/install/rollback, private ingress, journals, synthetic bridge and action faults. Browser gates start isolated loopback processes and temporary SQLite databases. Install the Chromium version required by `tests/browser/package-lock.json` before running them.

```sh
npm --prefix tests/browser ci --ignore-scripts
(cd tests/browser && npm exec -- playwright install --with-deps chromium)
```

No owner sessions, real collector tokens or public service bindings are needed for tests. Keep all generated `.context` evidence local. Do not upload traces, screenshots or test state without reviewing their contents.

## Production boundary

Deployment requires Tailscale Serve/private HTTPS, explicit owner/device rules, private installation state and an application-owned service manager. Installer tools are included as source but no automatic production workflow is enabled. Use your own inventory, keys and runtime configuration; never check them in. Fresh install filesystem validation and compatible release rollback must remain intact.

`tests/fixtures/retention-prior` contains sanitized source-only compatibility fixtures for historical-reader safety tests. They are never application routes or deployment inputs. Workflow fixtures similarly verify serialization/lease contracts without configuring a real fleet.
