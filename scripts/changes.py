#!/usr/bin/env python3
"""Permanent ALRT change records and reproducible release notes (MIT licensed)."""

import argparse
import json
from pathlib import Path
import re
import subprocess
import sys


ROOT = Path(__file__).resolve().parent.parent
TYPES = ("added", "changed", "fixed", "removed", "security", "docs", "infrastructure")
NAME = re.compile(r"[a-z0-9]+(?:-[a-z0-9]+)+\.md\Z")
VERSION = re.compile(r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\Z")


class Invalid(ValueError):
    pass


def fragment(name, source):
    if not NAME.fullmatch(name):
        raise Invalid(f"{name}: use a unique descriptive lowercase hyphenated .md filename")
    lines = source.splitlines()
    if not lines or lines[0] != "---":
        raise Invalid(f"{name}: expected opening ---")
    try:
        end = lines.index("---", 1)
    except ValueError as exc:
        raise Invalid(f"{name}: missing closing ---") from exc
    fields = {}
    for line in lines[1:end]:
        key, separator, value = line.partition(":")
        value = value.strip()
        if not separator or key not in ("type", "title") or key in fields or not value:
            raise Invalid(f"{name}: expected exactly one plain type and title field")
        fields[key] = value
    if set(fields) != {"type", "title"} or fields["type"] not in TYPES:
        raise Invalid(f"{name}: type must be one of {', '.join(TYPES)}; title is required")
    body = "\n".join(lines[end + 1:]).strip()
    if not body:
        raise Invalid(f"{name}: a Markdown body is required")
    return fields["type"], fields["title"], body


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise Invalid(f"duplicate registry version: {key}")
        result[key] = value
    return result


def registry(source, records):
    releases = json.loads(source, object_pairs_hook=unique_object)
    if not isinstance(releases, dict):
        raise Invalid("releases.json must map versions to fragment filename lists")
    assigned = set()
    for version, names in releases.items():
        if not VERSION.fullmatch(version) or not isinstance(names, list) or not names:
            raise Invalid(f"{version}: expected X.Y.Z and a nonempty filename list")
        for name in names:
            if not isinstance(name, str) or name not in records:
                raise Invalid(f"{version}: unknown fragment {name!r}")
            if name in assigned:
                raise Invalid(f"fragment assigned more than once: {name}")
            assigned.add(name)
    return releases


def git(root, *args):
    result = subprocess.run(["git", "-C", str(root), *args], capture_output=True)
    if result.returncode:
        raise Invalid(result.stderr.decode("utf-8", errors="replace").strip())
    return result.stdout


def load(root, revision=None):
    if revision:
        entries = git(root, "ls-tree", "-r", "-z", revision, "--", ".changes").split(b"\0")
        sources = {}
        for entry in filter(None, entries):
            metadata, path = entry.split(b"\t", 1)
            name = path.decode("utf-8")
            if metadata.split()[0] not in (b"100644", b"100755"):
                raise Invalid(f"{name}: change records must be regular files")
            sources[name] = git(root, "show", f"{revision}:{name}").decode("utf-8")
    else:
        sources = {}
        for path in sorted((root / ".changes").rglob("*")):
            if path.is_symlink():
                raise Invalid(f"{path.name}: change records must be regular files")
            if path.is_file():
                sources[path.relative_to(root).as_posix()] = path.read_text(encoding="utf-8")
    records = {}
    for path, source in sorted(sources.items()):
        if path in (".changes/README.md", ".changes/releases.json"):
            continue
        name = path.removeprefix(".changes/")
        records[name] = fragment(name, source)
    if ".changes/releases.json" not in sources:
        raise Invalid("missing .changes/releases.json")
    return records, registry(sources[".changes/releases.json"], records)


def check(root, base=None):
    # CI always inspects committed content, independent of local/staged edits.
    records, releases = load(root, "HEAD" if base else None)
    if base:
        ancestor = git(root, "merge-base", base, "HEAD").decode().strip()
        old_registry_path = git(root, "ls-tree", ancestor, "--", ".changes/releases.json")
        if old_registry_path:
            _, old_releases = load(root, ancestor)
            for version, names in old_releases.items():
                if version not in releases or set(releases[version]) != set(names):
                    raise Invalid(f"{version}: historical release selection is immutable")
        changes = git(root, "diff", "--no-renames", "--name-status", "-z", ancestor, "HEAD", "--", ".changes").split(b"\0")
        added = set()
        for index in range(0, len(changes) - 1, 2):
            status, path = changes[index:index + 2]
            name = path.decode("utf-8").removeprefix(".changes/")
            if name in ("README.md", "releases.json"):
                continue
            if status != b"A":
                raise Invalid(f"{name}: committed fragments are permanent; add a correction fragment")
            added.add(name)
        if not added:
            raise Invalid("PR diff must add at least one NEW valid .changes/*.md fragment")
        # A new PR record must remain visible in the unreleased selection.
        assigned = {name for names in releases.values() for name in names}
        if not added - assigned:
            raise Invalid("PR diff must add at least one unreleased fragment")
    return len(records)


def notes(records, releases, version):
    if version == "unreleased":
        assigned = {name for names in releases.values() for name in names}
        selected = set(records) - assigned
        title = "Unreleased"
    else:
        if version not in releases:
            raise Invalid(f"unknown version: {version}")
        selected = set(releases[version])
        title = version
    lines = [f"## {title}", ""]
    for kind in TYPES:
        group = [name for name in sorted(selected) if records[name][0] == kind]
        if group:
            lines.extend([f"### {kind.capitalize()}", ""])
            for name in group:
                _, heading, body = records[name]
                ref = "main" if version == "unreleased" else f"v{version}"
                lines.extend([f"#### {heading}", "", body, "", f"Record: [.changes/{name}](https://github.com/Nebuless/ALRT/blob/{ref}/.changes/{name})", ""])
    return "\n".join(lines).rstrip() + "\n"


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    validate = commands.add_parser("check", help="validate local files or the committed PR diff")
    validate.add_argument("--base", help="local Git base ref; checks merge-base(BASE, HEAD)..HEAD")
    render = commands.add_parser("notes", help="print deterministic Markdown; never edits files")
    render.add_argument("--version", default="unreleased", help="unreleased (default) or registered X.Y.Z")
    args = parser.parse_args()
    try:
        if args.command == "check":
            print(f"Validated {check(ROOT, args.base)} change records.")
        else:
            print(notes(*load(ROOT), args.version), end="")
    except (Invalid, OSError, UnicodeError, json.JSONDecodeError) as exc:
        print(f"changes: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
