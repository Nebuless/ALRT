#!/usr/bin/env python3
"""Exercise the installer with local transport fixtures and real release bytes."""
import argparse
import hashlib
import io
import json
import os
from pathlib import Path
import platform
import shutil
import subprocess
import tarfile
import tempfile
import unittest

ROOT = Path(__file__).resolve().parent.parent
ARTIFACTS = None


class InstallTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.assets = self.root / "assets"
        self.assets.mkdir()
        self.prefix = self.root / "installed bin"
        self.prefix.mkdir()
        self.target = self.prefix / "nebu-alrt"
        self.target.write_text("previous binary")
        self.version = (ROOT / "VERSION").read_text().strip()
        arch = "arm64" if platform.machine() in ("aarch64", "arm64") else "amd64"
        self.asset = f"nebu-alrt_{self.version}_linux_{arch}.tar.gz"
        archive = self.assets / self.asset
        if ARTIFACTS:
            shutil.copyfile(ARTIFACTS / self.asset, archive)
        else:
            binary = f"#!/bin/sh\nprintf 'nebu-alrt {self.version} (fixture)\\n'\n".encode()
            with tarfile.open(archive, "w:gz") as tar:
                entry = tarfile.TarInfo("nebu-alrt")
                entry.size = len(binary)
                entry.mode = 0o755
                tar.addfile(entry, io.BytesIO(binary))
        digest = hashlib.sha256(archive.read_bytes()).hexdigest()
        self.checksum = f"{digest}  {self.asset}\n"
        (self.assets / "SHA256SUMS").write_text(self.checksum)
        tools = self.root / "tools"
        tools.mkdir()
        curl = tools / "curl"
        curl.write_text("""#!/usr/bin/env python3
import os, pathlib, shutil, sys
args = sys.argv[1:]
url = next(arg for arg in args if arg.startswith('https://'))
assert url.startswith('https://github.com/Nebuless/ALRT/releases/download/v')
assert args[args.index('--proto') + 1] == '=https'
assert args[args.index('--proto-redir') + 1] == '=https'
source = pathlib.Path(os.environ['TEST_ASSETS']) / url.rsplit('/', 1)[-1]
shutil.copyfile(source, args[args.index('-o') + 1])
""")
        curl.chmod(0o755)
        self.env = dict(os.environ, PATH=f"{tools}:{os.environ['PATH']}",
                        TEST_ASSETS=str(self.assets), ALRT_INSTALL_DIR=str(self.prefix),
                        ALRT_VERSION=self.version)

    def install(self):
        return subprocess.run(["sh", str(ROOT / "install.sh")], env=self.env,
                              capture_output=True, text=True, timeout=30)

    def test_install_and_upgrade(self):
        result = self.install()
        self.assertEqual(result.returncode, 0, result.stderr)
        result = self.install()
        self.assertEqual(result.returncode, 0, result.stderr)
        version = subprocess.check_output([str(self.target), "version"], text=True)
        self.assertTrue(version.startswith(f"nebu-alrt {self.version} ("))
        self.assertEqual(self.target.stat().st_mode & 0o777, 0o755)
        if ARTIFACTS:
            sample = subprocess.check_output([str(self.target), "sample", "--machine-id",
                                              "install-check", "--scope", "sandbox", "--count", "1"],
                                             text=True, timeout=30)
            self.assertIsInstance(json.loads(sample), dict)

    def test_bad_checksum_preserves_install(self):
        (self.assets / "SHA256SUMS").write_text("0" * 64 + f"  {self.asset}\n")
        self.assertNotEqual(self.install().returncode, 0)
        self.assertEqual(self.target.read_text(), "previous binary")

    def test_duplicate_checksum_preserves_install(self):
        (self.assets / "SHA256SUMS").write_text(self.checksum * 2)
        self.assertNotEqual(self.install().returncode, 0)
        self.assertEqual(self.target.read_text(), "previous binary")

    def test_symlink_is_not_replaced(self):
        other = self.root / "other"
        other.write_text("untouched")
        self.target.unlink()
        self.target.symlink_to(other)
        self.assertNotEqual(self.install().returncode, 0)
        self.assertTrue(self.target.is_symlink())
        self.assertEqual(other.read_text(), "untouched")

    def test_invalid_version(self):
        self.env["ALRT_VERSION"] = "1.2.3/../../bad"
        self.assertNotEqual(self.install().returncode, 0)
        self.assertEqual(self.target.read_text(), "previous binary")

    def test_relative_install_path(self):
        self.env["ALRT_INSTALL_DIR"] = "relative"
        self.assertNotEqual(self.install().returncode, 0)
        self.assertEqual(self.target.read_text(), "previous binary")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--artifact-dir", type=Path)
    options, remaining = parser.parse_known_args()
    ARTIFACTS = options.artifact_dir.resolve() if options.artifact_dir else None
    unittest.main(argv=[__file__, *remaining])
