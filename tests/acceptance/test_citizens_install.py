import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


class InstallTest(unittest.TestCase):
    def test_prepares_only_owned_unit_and_metadata_extension_without_starting_services(self):
        root = Path(__file__).resolve().parents[2]
        with tempfile.TemporaryDirectory() as temporary:
            fixture = Path(temporary)
            base = fixture / 'installation'
            base.mkdir()
            (base / 'current').symlink_to(root, target_is_directory=True)
            state = fixture / 'private-state'
            agent = fixture / 'pi'
            environment = {**os.environ, 'HOME': str(fixture)}
            command = [sys.executable, str(root / 'deploy/citizens-install'), '--base', str(base), '--state', str(state),
                       '--socket', str(fixture / 'run/citizens.sock'), '--pi-dir', str(agent), '--agent-dir', str(agent)]
            result = subprocess.run(command, capture_output=True, text=True, timeout=5, check=True, env=environment)
            proof = json.loads(result.stdout)
            self.assertFalse(proof['services_started'])
            unit = state / 'nebuless-citizens.service'
            self.assertIn('NoNewPrivileges=true', unit.read_text())
            self.assertEqual(unit.stat().st_mode & 0o777, 0o600)
            extension = agent / 'extensions/nebuless-citizens-config.mjs'
            self.assertIn('NEBULESS_CITIZENS_SOCKET', extension.read_text())
            self.assertNotIn(str(fixture), extension.read_text())
            local = json.loads((fixture / '.local/share/nebuless-citizens/extension.json').read_text())
            self.assertEqual(local['socket'], str(fixture / 'run/citizens.sock'))
            self.assertNotIn('prompt', extension.read_text())
            again = subprocess.run(command, capture_output=True, text=True, timeout=5, check=True, env=environment)
            self.assertFalse(json.loads(again.stdout)['services_started'])
            extension.write_text('unrelated extension')
            conflict = subprocess.run(command, capture_output=True, text=True, timeout=5, env=environment)
            self.assertNotEqual(conflict.returncode, 0)
            self.assertEqual(extension.read_text(), 'unrelated extension')


if __name__ == '__main__':
    unittest.main()
