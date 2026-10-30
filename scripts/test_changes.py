"""Machine contract tests for change records (MIT licensed).

Git fixtures create commits only inside disposable temporary repositories.
"""

import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

import changes


VALID = "---\ntype: fixed\ntitle: Example change\n---\n\nExample body.\n"


class FormatTests(unittest.TestCase):
    def test_valid_frontmatter(self):
        self.assertEqual(changes.fragment("example-change.md", VALID),
                         ("fixed", "Example change", "Example body."))
        self.assertEqual(changes.fragment("example-change.md", VALID.replace("\n", "\r\n"))[0], "fixed")

    def test_invalid_frontmatter_and_names(self):
        invalid = [VALID.lstrip("-"), VALID.replace("---\n\n", "\n", 1),
                   VALID.replace("type: fixed", "type: invalid"),
                   VALID.replace("title: Example change", "title:"),
                   VALID.replace("title: Example change", "type: added"),
                   VALID.replace("title: Example change", "extra: value\ntitle: Example change"),
                   VALID.replace("Example body.", " ")]
        for source in invalid:
            with self.subTest(source=source), self.assertRaises(changes.Invalid):
                changes.fragment("example-change.md", source)
        for name in ("short.md", "Upper-Case.md", "../escape-file.md", "nested/change-file.md"):
            with self.subTest(name=name), self.assertRaises(changes.Invalid):
                changes.fragment(name, VALID)

    def test_registry_selections(self):
        records = {"example-change.md": changes.fragment("example-change.md", VALID)}
        self.assertEqual(changes.registry('{"0.1.2":["example-change.md"]}', records),
                         {"0.1.2": ["example-change.md"]})
        invalid = ['[]', '{"v0.1.2":["example-change.md"]}',
                   '{"01.1.2":["example-change.md"]}', '{"0.1.2":[]}',
                   '{"0.1.2":["missing-record.md"]}', '{"0.1.2":[1]}',
                   '{"0.1.2":"example-change.md"}',
                   '{"0.1.2":["example-change.md","example-change.md"]}',
                   '{"0.1.2":["example-change.md"],"0.1.3":["example-change.md"]}',
                   '{"0.1.2":["example-change.md"],"0.1.2":["example-change.md"]}']
        for source in invalid:
            with self.subTest(source=source), self.assertRaises(changes.Invalid):
                changes.registry(source, records)

    def test_notes_version_membership_and_order(self):
        record = changes.fragment("example-change.md", VALID)
        records = {"zeta-change.md": record, "alpha-change.md": record, "old-change.md": record}
        releases = {"0.1.2": ["old-change.md"]}
        unreleased = changes.notes(records, releases, "unreleased")
        self.assertNotIn(".changes/old-change.md", unreleased)
        self.assertLess(unreleased.index(".changes/alpha-change.md"), unreleased.index(".changes/zeta-change.md"))
        released = changes.notes(records, releases, "0.1.2")
        self.assertIn(".changes/old-change.md", released)
        self.assertNotIn(".changes/alpha-change.md", released)
        self.assertEqual(changes.notes({}, {}, "unreleased"), "## Unreleased\n")
        with self.assertRaises(changes.Invalid):
            changes.notes(records, releases, "9.0.0")


class DiffTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.git("init", "-q")
        self.write(".changes/releases.json", "{}\n")
        self.write(".changes/existing-record.md", VALID)
        self.commit()
        self.base = self.git("rev-parse", "HEAD").strip()

    def git(self, *args):
        env = {key: value for key, value in os.environ.items()
               if not key.startswith("GIT_")}
        env.update(GIT_CONFIG_NOSYSTEM="1", GIT_CONFIG_GLOBAL=os.devnull)
        return subprocess.run(
            ["git", "-C", str(self.root), "-c", "user.name=Fixture",
             "-c", "user.email=fixture@example.invalid", "-c", "core.hooksPath=/dev/null",
             "-c", "commit.gpgsign=false", *args], env=env, check=True,
            capture_output=True, text=True).stdout

    def write(self, name, content):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content, encoding="utf-8")

    def commit(self):
        self.git("add", ".")
        self.git("commit", "-qm", "fixture")

    def test_new_record_passes(self):
        self.write(".changes/new-record.md", VALID)
        self.write("code.txt", "change")
        self.commit()
        self.assertEqual(changes.check(self.root, self.base), 2)

    def test_existing_base_record_and_uncommitted_files_do_not_count(self):
        self.write("code.txt", "change")
        self.commit()
        self.write(".changes/new-record.md", VALID)
        self.git("add", ".changes/new-record.md")
        self.assertEqual(changes.check(self.root), 2)
        with self.assertRaises(changes.Invalid):
            changes.check(self.root, self.base)

    def test_edit_delete_and_rename_rejected_even_with_new_record(self):
        for operation in ("edit", "delete", "rename"):
            with self.subTest(operation=operation):
                self.git("reset", "--hard", self.base)
                path = self.root / ".changes/existing-record.md"
                if operation == "edit":
                    path.write_text(VALID + "Correction.\n", encoding="utf-8")
                elif operation == "delete":
                    path.unlink()
                else:
                    path.rename(path.with_name("renamed-record.md"))
                self.write(".changes/new-record.md", VALID)
                self.commit()
                with self.assertRaises(changes.Invalid):
                    changes.check(self.root, self.base)

    def test_invalid_committed_record_cannot_be_repaired_only_locally(self):
        self.write(".changes/new-record.md", "invalid")
        self.commit()
        self.write(".changes/new-record.md", VALID)
        with self.assertRaises(changes.Invalid):
            changes.check(self.root, self.base)

    def test_released_only_addition_rejected(self):
        self.write(".changes/new-record.md", VALID)
        self.write(".changes/releases.json", json.dumps({"0.1.2": ["new-record.md"]}))
        self.commit()
        with self.assertRaises(changes.Invalid):
            changes.check(self.root, self.base)

    def test_missing_base_fails_closed(self):
        with self.assertRaises(changes.Invalid):
            changes.check(self.root, "refs/heads/does-not-exist")

    def test_historical_release_membership_cannot_change(self):
        self.write(".changes/releases.json", json.dumps({"0.1.2": ["existing-record.md"]}))
        self.commit()
        base = self.git("rev-parse", "HEAD").strip()
        self.write(".changes/new-record.md", VALID)
        self.write(".changes/releases.json", json.dumps({"0.1.3": ["existing-record.md"]}))
        self.commit()
        with self.assertRaises(changes.Invalid):
            changes.check(self.root, base)

    def test_merge_base_excludes_base_branch_only_changes(self):
        self.git("checkout", "-qb", "feature")
        self.write(".changes/new-record.md", VALID)
        self.commit()
        head = self.git("rev-parse", "HEAD").strip()
        self.git("checkout", "-qb", "base-advanced", self.base)
        self.write(".changes/base-only.md", VALID)
        self.commit()
        base = self.git("rev-parse", "HEAD").strip()
        self.git("checkout", "-q", head)
        self.assertEqual(changes.check(self.root, base), 2)

    def test_symlinks_rejected(self):
        (self.root / ".changes/link-record.md").symlink_to("existing-record.md")
        with self.assertRaises(changes.Invalid):
            changes.check(self.root)
        self.commit()
        with self.assertRaises(changes.Invalid):
            changes.check(self.root, self.base)

    def test_cli_exit_codes(self):
        script = self.root / "scripts/changes.py"
        script.parent.mkdir()
        shutil.copyfile(Path(changes.__file__), script)

        def run(*args):
            return subprocess.run([sys.executable, "-B", str(script), *args],
                                  capture_output=True, text=True)

        self.assertEqual(run("check").returncode, 0)
        self.assertEqual(run("check", "--base", self.base).returncode, 1)
        self.write(".changes/new-record.md", VALID)
        self.commit()
        self.assertEqual(run("check", "--base", self.base).returncode, 0)
        self.assertEqual(run("notes").returncode, 0)
        unknown = run("notes", "--version", "9.0.0")
        self.assertEqual(unknown.returncode, 1)
        self.assertEqual(unknown.stdout, "")
        self.assertTrue(unknown.stderr)


if __name__ == "__main__":
    unittest.main()
