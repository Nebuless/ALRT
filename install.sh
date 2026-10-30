#!/bin/sh
# Downloads collector only. No root privileges, services, or configuration writes.
set -eu
version=${ALRT_VERSION:-0.1.2}
prefix=${ALRT_INSTALL_DIR:-"$HOME/.local/bin"}
case "$version" in *[!0-9.]*|'') echo 'Invalid ALRT_VERSION' >&2; exit 1 ;; esac
printf '%s\n' "$version" | grep -Eq '^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$' || { echo 'Invalid ALRT_VERSION' >&2; exit 1; }
case "$prefix" in /*) ;; *) echo 'ALRT_INSTALL_DIR must be absolute' >&2; exit 1 ;; esac
[ "$(uname -s)" = Linux ] || { echo 'Only Linux is supported' >&2; exit 1; }
case "$(uname -m)" in x86_64) arch=amd64 ;; aarch64|arm64) arch=arm64 ;; *) echo 'Unsupported architecture' >&2; exit 1 ;; esac
for cmd in curl sha256sum tar mktemp install grep awk; do command -v "$cmd" >/dev/null || { echo "Missing $cmd" >&2; exit 1; }; done
umask 077
work=$(mktemp -d)
stage=''
trap 'rm -rf "$work"; if [ -n "$stage" ]; then rm -f "$stage"; fi' EXIT HUP INT TERM
asset="nebu-alrt_${version}_linux_${arch}.tar.gz"
base="https://github.com/Nebuless/ALRT/releases/download/v$version"
curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --tlsv1.2 --connect-timeout 15 --max-time 180 "$base/$asset" -o "$work/$asset"
curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --tlsv1.2 --connect-timeout 15 --max-time 60 "$base/SHA256SUMS" -o "$work/SHA256SUMS"
hash=$(awk -v name="$asset" '$2 == name && length($1) == 64 && $1 !~ /[^0-9a-f]/ { print $1 }' "$work/SHA256SUMS")
[ "${#hash}" = 64 ] || { echo 'Missing or duplicate checksum' >&2; exit 1; }
(cd "$work"; printf '%s  %s\n' "$hash" "$asset" | sha256sum --check --status)
# Extract just binary bytes, never archive paths, links, ownership, or modes.
tar -xOzf "$work/$asset" -- nebu-alrt > "$work/nebu-alrt"
chmod 700 "$work/nebu-alrt"
case "$("$work/nebu-alrt" version)" in "nebu-alrt $version ("*) ;; *) echo 'Binary version mismatch' >&2; exit 1 ;; esac
mkdir -p "$prefix"
[ ! -L "$prefix/nebu-alrt" ] || { echo 'Refusing to replace symlink' >&2; exit 1; }
if [ -e "$prefix/nebu-alrt" ] && [ ! -f "$prefix/nebu-alrt" ]; then echo 'Destination must be a regular file' >&2; exit 1; fi
stage=$(mktemp "$prefix/.nebu-alrt.XXXXXX")
install -m 755 "$work/nebu-alrt" "$stage"
mv -fT "$stage" "$prefix/nebu-alrt"
stage=''
printf 'Installed nebu-alrt %s to %s/nebu-alrt\nAdd %s to PATH if needed.\n' "$version" "$prefix" "$prefix"
