#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
version=$(<VERSION)
[[ "$version" =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ ]] || { echo 'Invalid VERSION' >&2; exit 1; }
arch=${1:?Usage: scripts/package-release.sh amd64|arm64 OUTPUT_DIRECTORY}
[[ "$arch" == amd64 || "$arch" == arm64 ]] || { echo 'Unsupported architecture' >&2; exit 1; }
out=$(realpath -m "${2:?Output directory required}")
mkdir -p "$out"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
revision=$(git rev-parse HEAD)
(
    cd collector
    CGO_ENABLED=0 GOOS=linux GOARCH="$arch" go build -trimpath -buildvcs=false \
        -ldflags "-s -w -buildid= -X main.version=$version -X main.revision=$revision" \
        -o "$work/nebu-alrt" ./cmd/nebu-alrt
)
cp LICENSE "$work/LICENSE"
tar --sort=name --mtime='UTC 1970-01-01' --owner=0 --group=0 --numeric-owner \
    -czf "$out/nebu-alrt_${version}_linux_${arch}.tar.gz" -C "$work" nebu-alrt LICENSE
sha256sum "$out/nebu-alrt_${version}_linux_${arch}.tar.gz"
