import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
import uuid
import sys
import shutil
import socketserver
import struct
import threading
from unittest.mock import patch


spec = importlib.util.spec_from_file_location('citizens', Path(__file__).resolve().parents[2] / 'deploy/citizens.py')
citizens = importlib.util.module_from_spec(spec)
spec.loader.exec_module(citizens)


class BridgeTest(unittest.TestCase):
    class PeerSocket:
        def __init__(self, sock, credentials):
            self.sock = sock
            self.credentials = credentials

        def settimeout(self, value):
            self.sock.settimeout(value)

        def gettimeout(self):
            return self.sock.gettimeout()

        def getsockopt(self, *_args):
            return self.credentials

        def fileno(self):
            return self.sock.fileno()

        def __getattr__(self, name):
            return getattr(self.sock, name)

        def shutdown(self, how):
            self.sock.shutdown(how)

        def close(self):
            self.sock.close()

    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.clock = 1_800_000_000.0
        self.bridge = citizens.Bridge(Path(self.directory.name), clock=lambda: self.clock)

    def tearDown(self):
        self.directory.cleanup()

    def event(self, state='working', session=None, pid=None):
        return self.bridge.register({'session_id': session or str(uuid.uuid4()), 'parent_id': None, 'tool': 'pi', 'state': state}, pid or os.getpid(), os.getuid())

    def test_unknown_fields_and_other_owners_cannot_register(self):
        with self.assertRaises(ValueError):
            self.bridge.register({'session_id': str(uuid.uuid4()), 'tool': 'pi', 'state': 'idle', 'prompt': 'secret'}, os.getpid(), os.getuid())
        with self.assertRaises(PermissionError):
            self.bridge.register({'session_id': str(uuid.uuid4()), 'parent_id': None, 'tool': 'pi', 'state': 'idle'}, os.getpid(), os.getuid() + 1)

    def test_metrics_identity_expiry_and_shared_runtime(self):
        session = str(uuid.uuid4())
        self.event(session=session)
        snapshot = self.bridge.snapshot()
        row = snapshot['citizens'][0]
        self.assertEqual(row['attribution'], 'dedicated')
        self.assertIsNone(row['cpu_percent'])
        self.assertGreater(row['rss_bytes'], 0)
        self.assertEqual(row['process']['pid'], os.getpid())
        self.event(session=str(uuid.uuid4()))
        rows = self.bridge.snapshot()['citizens']
        self.assertTrue(all(row['attribution'] == 'shared' and not row['can_terminate'] for row in rows))
        self.clock += 11
        rows = self.bridge.snapshot()['citizens']
        self.assertTrue(all(row['state'] == 'unknown' and row['rss_bytes'] is None and not row['can_terminate'] for row in rows))
        self.assertNotIn('session_id', json.dumps(snapshot))

    def test_kill_exact_disposable_child_and_durable_duplicate(self):
        child = subprocess.Popen(['/usr/bin/sleep', '60'])
        try:
            self.event(pid=child.pid)
            row = self.bridge.snapshot()['citizens'][0]
            command = {'id': str(uuid.uuid4()), 'citizen_id': row['id'], 'bridge_id': self.bridge.id,
                       **row['process'], 'expires_at': citizens.timestamp(self.clock + 5)}
            self.assertEqual(self.bridge.commands([command]), [{'id': command['id'], 'status': 'terminated'}])
            child.wait(timeout=5)
            self.assertEqual(child.returncode, -9)
            reopened = citizens.Bridge(Path(self.directory.name), clock=lambda: self.clock)
            self.assertEqual(reopened.commands([command]), [{'id': command['id'], 'status': 'terminated'}])
        finally:
            if child.poll() is None:
                child.kill()
                child.wait(timeout=5)

    def test_reused_identity_expired_shared_and_wrong_bridge_never_signal(self):
        self.event()
        row = self.bridge.snapshot()['citizens'][0]
        command = {'id': str(uuid.uuid4()), 'citizen_id': row['id'], 'bridge_id': self.bridge.id,
                   **row['process'], 'expires_at': citizens.timestamp(self.clock + 5)}
        command['start_ticks'] += 1
        self.assertEqual(self.bridge.commands([command])[0]['status'], 'identity_changed')
        command.update(id=str(uuid.uuid4()), start_ticks=row['process']['start_ticks'], expires_at=citizens.timestamp(self.clock))
        self.assertEqual(self.bridge.commands([command])[0]['status'], 'expired')
        command.update(id=str(uuid.uuid4()), expires_at=citizens.timestamp(self.clock + 5), bridge_id=str(uuid.uuid4()))
        self.assertEqual(self.bridge.commands([command])[0]['status'], 'identity_changed')

    def test_corrupt_receipts_fail_closed(self):
        (Path(self.directory.name) / 'receipts.json').write_text('{')
        with self.assertRaises(ValueError):
            citizens.Bridge(Path(self.directory.name), clock=lambda: self.clock)

    def test_shared_process_measurements_are_identical_and_unknown_cannot_kill(self):
        self.event(session='first')
        self.event(session='second')
        self.bridge.snapshot()
        self.clock += 2
        rows = self.bridge.snapshot()['citizens']
        self.assertEqual(rows[0]['cpu_percent'], rows[1]['cpu_percent'])
        self.assertEqual(rows[0]['rss_bytes'], rows[1]['rss_bytes'])
        self.assertFalse(any(row['can_terminate'] for row in rows))
        self.event(state='unknown', session='third')
        row = next(row for row in self.bridge.snapshot()['citizens'] if row['state'] == 'unknown')
        self.assertFalse(row['can_terminate'])

    def test_observer_shared_attribution_is_atomic_and_sticky(self):
        session = str(uuid.uuid4())
        event = {'session_id': session, 'parent_id': None, 'tool': 'omo', 'state': 'working'}
        self.bridge.register(event, os.getpid(), os.getuid(), shared=True)
        row = self.bridge.snapshot()['citizens'][0]
        self.assertEqual(row['attribution'], 'shared')
        self.assertFalse(row['can_terminate'])
        self.bridge.register(event, os.getpid(), os.getuid(), shared=False)
        row = self.bridge.snapshot()['citizens'][0]
        self.assertEqual(row['attribution'], 'shared')

    def test_distinct_collector_is_read_only_without_opening_pidfd(self):
        bridge = citizens.Bridge(Path(self.directory.name), clock=lambda: self.clock, control_allowed=False)
        child = subprocess.Popen(['/usr/bin/sleep', '60'])
        try:
            bridge.register({'session_id': 'cross-uid', 'parent_id': None, 'tool': 'pi', 'state': 'working'}, child.pid, os.getuid())
            with patch.object(citizens, 'process_fd', side_effect=AssertionError('must not open pidfd')), patch.object(citizens, 'send_kill') as send_kill:
                row = bridge.snapshot()['citizens'][0]
                self.assertFalse(row['can_terminate'])
                command = {'id': str(uuid.uuid4()), 'citizen_id': row['id'], 'bridge_id': bridge.id,
                           **row['process'], 'expires_at': citizens.timestamp(self.clock + 5)}
                self.assertEqual(bridge.commands([command])[0]['status'], 'unsupported')
                send_kill.assert_not_called()
            self.assertIsNone(child.poll())
        finally:
            child.terminate()
            child.wait(timeout=5)

    def test_peer_credentials_reject_before_parsing_and_release_admission(self):
        endpoint = Path(self.directory.name) / 'socket'
        server = citizens.Server(str(endpoint), citizens.Handler)
        server.collector_uid = os.getuid()
        raw_request, peer = __import__('socket').socketpair()
        request = self.PeerSocket(raw_request, struct.pack('3i', 12, os.getuid() + 1, os.getgid()))
        with patch.object(socketserver.UnixStreamServer, 'get_request', return_value=(request, None)), \
             patch.object(server, 'shutdown_request', side_effect=lambda connection: connection.close()) as shutdown:
            accepted, _ = server.get_request()
            server.process_request(accepted, None)
            shutdown.assert_called_once_with(request)
        for _ in range(16):
            self.assertTrue(server.slots.acquire(blocking=False))
        self.assertFalse(server.slots.acquire(blocking=False))
        peer.close()
        server.server_close()

    def test_accepted_peer_gets_timeout_before_http_parsing(self):
        endpoint = Path(self.directory.name) / 'socket'
        server = citizens.Server(str(endpoint), citizens.Handler)
        server.collector_uid = os.getuid()
        raw_request, peer = __import__('socket').socketpair()
        request = self.PeerSocket(raw_request, struct.pack('3i', 12, os.getuid(), os.getgid()))
        with patch.object(socketserver.UnixStreamServer, 'get_request', return_value=(request, None)):
            accepted, _ = server.get_request()
        self.assertEqual(accepted.gettimeout(), 5)
        self.assertEqual(server.peer_credentials[accepted.fileno()], (12, os.getuid()))
        self.assertEqual(accepted.gettimeout(), 5)
        accepted.close()
        server.peer_credentials.pop(accepted.fileno(), None)
        peer.close()
        server.server_close()

    def test_dispatch_failure_drops_credentials_and_releases_all_slots(self):
        endpoint = Path(self.directory.name) / 'socket'
        with citizens.Server(str(endpoint), citizens.Handler) as server:
            server.collector_uid = os.getuid()
            request, peer = __import__('socket').socketpair()
            try:
                descriptor = request.fileno()
                server.peer_credentials[descriptor] = (os.getpid(), os.getuid())
                with patch.object(socketserver.ThreadingMixIn, 'process_request', side_effect=RuntimeError('thread unavailable')):
                    with self.assertRaises(RuntimeError):
                        server.process_request(request, None)
                self.assertEqual(server.peer_credentials, {})
                for _ in range(16):
                    self.assertTrue(server.slots.acquire(blocking=False))
            finally:
                request.close()
                peer.close()

    def test_closed_request_cleanup_uses_original_descriptor(self):
        endpoint = Path(self.directory.name) / 'socket'
        with citizens.Server(str(endpoint), citizens.Handler) as server:
            server.slots.acquire()
            request, peer = __import__('socket').socketpair()
            descriptor = request.fileno()
            server.peer_credentials[descriptor] = (os.getpid(), os.getuid())
            with patch.object(socketserver.ThreadingMixIn, 'process_request_thread', side_effect=lambda connection, address: connection.close()):
                server.process_request_thread(request, None)
            peer.close()
            self.assertEqual(server.peer_credentials, {})
            for _ in range(16):
                self.assertTrue(server.slots.acquire(blocking=False))

    def test_corrupt_deleted_ledger_after_initialization_is_not_recreated(self):
        (Path(self.directory.name) / 'receipts.json').unlink()
        with self.assertRaises(ValueError):
            citizens.Bridge(Path(self.directory.name), clock=lambda: self.clock)

    def test_receipt_write_failure_never_signals_and_unknown_fence_survives_restart(self):
        child = subprocess.Popen(['/usr/bin/sleep', '60'])
        try:
            self.event(pid=child.pid)
            row = self.bridge.snapshot()['citizens'][0]
            command = {'id': str(uuid.uuid4()), 'citizen_id': row['id'], 'bridge_id': self.bridge.id,
                       **row['process'], 'expires_at': citizens.timestamp(self.clock + 5)}
            with patch.object(citizens, 'atomic_json', side_effect=OSError('synthetic_disk_failure')), patch.object(citizens, 'send_kill') as signal:
                with self.assertRaises(OSError):
                    self.bridge.commands([command])
                signal.assert_not_called()
            command['id'] = str(uuid.uuid4())
            with patch.object(citizens, 'send_kill', side_effect=OSError(5, 'synthetic_signal_failure')):
                self.assertEqual(self.bridge.commands([command])[0]['status'], 'unknown')
            reopened = citizens.Bridge(Path(self.directory.name), clock=lambda: self.clock)
            with patch.object(citizens, 'send_kill') as signal:
                self.assertEqual(reopened.commands([command])[0]['status'], 'unknown')
                signal.assert_not_called()
            self.assertIsNone(child.poll())
        finally:
            child.terminate()
            child.wait(timeout=5)

    def test_source_health_recovers_without_hiding_fresh_pi_rows(self):
        self.event()
        self.bridge.source_health('omo', False)
        snapshot = self.bridge.snapshot()
        self.assertEqual(snapshot['coverage'], 'partial')
        self.assertGreater(snapshot['citizens'][0]['rss_bytes'], 0)
        self.bridge.source_health('omo', True)
        self.assertEqual(self.bridge.snapshot()['coverage'], 'complete')
        self.bridge.source_health('omo', False)
        self.bridge.source_health('omo', True)
        self.assertEqual(self.bridge.snapshot()['coverage'], 'complete')

    def test_dead_process_rows_expire_at_their_published_observation_cutoff(self):
        child = subprocess.Popen(['/usr/bin/sleep', '60'])
        self.event(pid=child.pid)
        child.terminate()
        child.wait(timeout=5)
        self.clock += 14399
        self.assertEqual(len(self.bridge.snapshot()['citizens']), 1)
        self.clock += 1
        self.assertEqual(len(self.bridge.snapshot()['citizens']), 1)
        self.clock += 0.001
        self.assertEqual(self.bridge.snapshot()['citizens'], [])

    def test_abrupt_exit_reconciliation_admits_new_live_session(self):
        child = subprocess.Popen(['/usr/bin/sleep', '60'])
        for index in range(128):
            self.event(session='dead-'+str(index), pid=child.pid)
        child.terminate()
        child.wait(timeout=5)
        self.assertTrue(all(row['state'] == 'stopped' for row in self.bridge.snapshot()['citizens']))
        self.event(session='replacement')
        self.assertEqual(sum(row['state'] == 'working' for row in self.bridge.snapshot()['citizens']), 1)

    def test_live_admission_evicts_stopped_history_and_reports_actual_drops(self):
        for index in range(128):
            self.event(state='stopped', session=str(index))
        self.event(session='new-live')
        rows = self.bridge.snapshot()['citizens']
        self.assertEqual(len(rows), 128)
        self.assertEqual(sum(row['state'] == 'working' for row in rows), 1)
        for index in range(127):
            self.event(session='live-'+str(index))
        with self.assertRaises(ValueError):
            self.event(session='beyond-capacity')
        self.assertEqual(self.bridge.snapshot()['coverage'], 'partial')
        self.event(state='stopped', session='new-live')
        self.event(session='beyond-capacity')
        self.assertEqual(self.bridge.snapshot()['coverage'], 'complete')

    @unittest.skipUnless(os.getuid() == 0, 'Distinct-UID fixture requires root test runner')
    def test_enrolled_nonroot_agent_and_distinct_collector_socket_access(self):
        root = Path(self.directory.name)
        os.chmod(root, 0o755)
        shared = root / 'socket'
        shared.mkdir()
        os.chown(shared, 65534, 1)
        os.chmod(shared, 0o750)
        state = root / 'state'
        state.mkdir(mode=0o700)
        os.chown(state, 65534, 65534)
        endpoint = shared / 'citizens.sock'
        code = root / 'code'
        code.mkdir(mode=0o755)
        source = Path(__file__).resolve().parents[2] / 'deploy'
        for name in ['citizens.py', 'citizens_omo.py']:
            shutil.copyfile(source / name, code / name)
            os.chmod(code / name, 0o644)
        process = subprocess.Popen(['setpriv', '--reuid=65534', '--regid=65534', '--groups=1', sys.executable,
                                    str(code / 'citizens.py'), '--state', str(state), '--socket', str(endpoint), '--collector-uid', '1'], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        try:
            import selectors
            with selectors.DefaultSelector() as selector:
                selector.register(process.stdout, selectors.EVENT_READ)
                self.assertTrue(selector.select(5), 'Cross-UID bridge did not become ready')
                self.assertEqual(process.stdout.readline().strip(), 'CITIZENS_BRIDGE_READY')
            code = 'import socket,http.client,json,sys; s=socket.socket(socket.AF_UNIX);s.connect(sys.argv[1]);c=http.client.HTTPConnection("unix");c.sock=s;c.request("GET","/snapshot");r=c.getresponse();print(r.status);print(json.loads(r.read())["coverage"])'
            result = subprocess.run(['setpriv', '--reuid=1', '--regid=1', '--clear-groups', sys.executable, '-c', code, str(endpoint)], capture_output=True, text=True, timeout=5)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(result.stdout.splitlines(), ['200', 'complete'])
            command = 'import socket,http.client,sys; s=socket.socket(socket.AF_UNIX);s.connect(sys.argv[1]);c=http.client.HTTPConnection("unix");c.sock=s;c.request("POST","/commands",body="{}",headers={"Content-Length":"2"});print(c.getresponse().status)'
            denied_control = subprocess.run(['setpriv', '--reuid=1', '--regid=1', '--clear-groups', sys.executable, '-c', command, str(endpoint)], capture_output=True, text=True, timeout=5)
            self.assertEqual(denied_control.returncode, 0, denied_control.stderr)
            self.assertEqual(denied_control.stdout.strip(), '503')
            denied = subprocess.run(['setpriv', '--reuid=2', '--regid=2', '--clear-groups', sys.executable, '-c', code, str(endpoint)], capture_output=True, text=True, timeout=5)
            self.assertNotEqual(denied.returncode, 0)
        finally:
            process.terminate()
            process.wait(timeout=5)
            process.stdout.close()
            process.stderr.close()


if __name__ == '__main__':
    unittest.main()
