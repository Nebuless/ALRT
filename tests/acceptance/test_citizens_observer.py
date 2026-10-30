import importlib.util
import json
import os
from pathlib import Path
import socket
import tempfile
import threading
import unittest
from unittest.mock import patch
import secrets
import hashlib
import hmac
import uuid


root = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('observer', root / 'deploy/citizens_omo.py')
observer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(observer)
spec = importlib.util.spec_from_file_location('bridge', root / 'deploy/citizens.py')
bridge = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bridge)


class ObserverTest(unittest.TestCase):
    def test_omo_shared_attribution_is_set_during_registration(self):
        with tempfile.TemporaryDirectory() as temporary:
            local = bridge.Bridge(Path(temporary) / 'state')
            monitor = observer.Observer(local, Path(temporary))
            session = 'synthetic-shared-session'
            opaque = local.opaque('omo:' + session)
            with patch.object(local, 'register', wraps=local.register) as register:
                local.register({'session_id': session, 'parent_id': None, 'tool': 'omo', 'state': 'working'}, os.getpid(), os.getuid(), shared=True)
            register.assert_called_once_with({'session_id': session, 'parent_id': None, 'tool': 'omo', 'state': 'working'}, os.getpid(), os.getuid(), shared=True)
            row = local.rows[opaque]
            self.assertTrue(row['shared'])
            self.assertEqual(local.snapshot()['citizens'][0]['attribution'], 'shared')

    def test_endpoint_replacement_and_discovery_cap_expose_current_coverage(self):
        with tempfile.TemporaryDirectory() as temporary:
            local = bridge.Bridge(Path(temporary) / 'state')
            monitor = observer.Observer(local, Path(temporary))
            monitor.reconcile([('A', 'tui')])
            monitor.source_health('A', True)
            self.assertEqual(local.snapshot()['coverage'], 'complete')
            monitor.source_health('A', False)
            self.assertEqual(local.snapshot()['coverage'], 'partial')
            monitor.reconcile([('B', 'tui')])
            monitor.source_health('B', True)
            self.assertEqual(local.snapshot()['coverage'], 'complete')
            monitor.reconcile([(str(index), 'tui') for index in range(17)])
            for index in range(17):
                monitor.source_health(str(index), True)
            self.assertEqual(local.snapshot()['coverage'], 'partial')
            monitor.reconcile([('B', 'tui')])
            monitor.source_health('B', True)
            self.assertEqual(local.snapshot()['coverage'], 'complete')

    def test_snapshot_admission_puts_live_sessions_before_stopped_history(self):
        with tempfile.TemporaryDirectory() as temporary:
            local = bridge.Bridge(Path(temporary) / 'state')
            monitor = observer.Observer(local, Path(temporary))
            monitor.reconcile([('A', 'tui')])
            stopped = [{'sessionId': str(index), 'status': 'closed'} for index in range(128)]
            live = {'sessionId': 'active', 'status': 'running'}
            rows = monitor.snapshot_rows('A', stopped + [live])
            self.assertEqual(len(rows), 128)
            self.assertEqual(rows[0], live)
            monitor.source_health('A', True)
            self.assertEqual(local.snapshot()['coverage'], 'partial')
            monitor.snapshot_rows('A', [live])
            monitor.source_health('A', True)
            self.assertEqual(local.snapshot()['coverage'], 'complete')

    def test_public_hmac_authentication_without_raw_key_or_session_activation(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            keys = directory / 'rpc/keys'
            keys.mkdir(mode=0o700, parents=True)
            endpoint = directory / 'public.sock'
            launch = str(uuid.uuid4())
            secret = secrets.token_bytes(32)
            record = {'version': 1, 'launch_id': launch, 'key': secret.hex(), 'pid': os.getpid(), 'endpoint': str(endpoint)}
            (keys / launch).write_text(json.dumps(record))
            os.chmod(keys / launch, 0o600)
            server = socket.socket(socket.AF_UNIX)
            server.bind(str(endpoint))
            os.chmod(endpoint, 0o600)
            server.listen(1)
            local = bridge.Bridge(directory / 'state')
            monitor = observer.Observer(local, directory)
            complete = threading.Event()
            errors = []

            def serve():
                try:
                    connection, _ = server.accept()
                    with connection:
                        connection.settimeout(4)
                        stream = connection.makefile('rb')
                        protocol = json.loads(stream.readline())
                        client_nonce = protocol['auth']['client_nonce']
                        server_nonce = secrets.token_hex(32)
                        proof = hmac.new(secret, '\n'.join(['senpi-rpc-server', launch, client_nonce, server_nonce]).encode(), hashlib.sha256).hexdigest()
                        connection.sendall(json.dumps({'type': 'response', 'id': 'p', 'success': True, 'data': {'capabilities': ['session_status'], 'auth': {'launch_id': launch, 'server_nonce': server_nonce, 'server_proof': proof}}}).encode() + b'\n')
                        authentication = json.loads(stream.readline())
                        expected = hmac.new(secret, '\n'.join(['senpi-rpc-client', launch, server_nonce, client_nonce]).encode(), hashlib.sha256).hexdigest()
                        self.assertEqual(authentication['client_proof'], expected)
                        self.assertNotIn(secret.hex(), json.dumps(authentication))
                        connection.sendall(b'{"type":"response","id":"a","success":true,"data":{}}\n')
                        subscription = json.loads(stream.readline())
                        self.assertEqual(subscription['type'], 'subscribe_sessions')
                        connection.sendall(b'{"type":"response","id":"s","success":true,"data":{"subscriptionId":"pub","seq":0,"snapshot":[{"sessionId":"daemon-shared","status":"running"}]}}\n')
                        self.assertTrue(monitor.ready.wait(3))
                        rows = local.snapshot()['citizens']
                        self.assertEqual(len(rows), 1)
                        self.assertEqual(rows[0]['attribution'], 'shared')
                        self.assertFalse(rows[0]['can_terminate'])
                        monitor.stop.set()
                        heartbeat = json.loads(stream.readline())
                        connection.sendall(json.dumps({'type': 'response', 'id': heartbeat['id'], 'success': True, 'data': {}}).encode() + b'\n')
                except BaseException as error:
                    errors.append(error)
                finally:
                    complete.set()

            threading.Thread(target=serve, daemon=True).start()
            watch = threading.Thread(target=monitor.watch, args=(str(endpoint), 'host'), daemon=True)
            watch.start()
            self.assertTrue(complete.wait(8))
            monitor.stop.set()
            watch.join(timeout=3)
            server.close()
            if errors:
                raise errors[0]

    def test_tui_status_projection_and_observer_only_frames(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            endpoint = directory / 'tui.sock'
            secret = os.urandom(32)
            (directory / 'tui.sock.secret').write_bytes(secret)
            os.chmod(directory / 'tui.sock.secret', 0o600)
            server = socket.socket(socket.AF_UNIX)
            server.bind(str(endpoint))
            os.chmod(endpoint, 0o600)
            server.listen(1)
            local = bridge.Bridge(directory / 'state')
            monitor = observer.Observer(local, directory)
            complete = threading.Event()
            failure = []

            def serve():
                try:
                    connection, _ = server.accept()
                    with connection:
                        connection.settimeout(3)
                        self.assertEqual(connection.recv(32), secret)
                        stream = connection.makefile('rb')
                        protocol = json.loads(stream.readline())
                        self.assertEqual(protocol['type'], 'get_protocol_info')
                        self.assertTrue(protocol['observe'])
                        connection.sendall(json.dumps({'type': 'response', 'id': 'p', 'success': True, 'data': {'capabilities': ['session_status']}}).encode() + b'\n')
                        subscription = json.loads(stream.readline())
                        self.assertEqual(subscription['type'], 'subscribe_sessions')
                        self.assertTrue(subscription['observe'])
                        self.assertFalse(subscription['include_context'])
                        self.assertFalse(subscription['effects'])
                        self.assertEqual(subscription['mirror'], 'none')
                        payload = {'type': 'response', 'id': 's', 'success': True, 'data': {'subscriptionId': 'sub-1', 'seq': 0, 'snapshot': [{'sessionId': 'rpc-1', 'durableSessionId': 'native-1', 'status': 'running', 'cwd': '/secret-work', 'sessionPath': '/private-session', 'context': {'prompt': 'CANARY'}}]}}
                        connection.sendall(json.dumps(payload).encode() + b'\n')
                        heartbeat = json.loads(stream.readline())
                        self.assertEqual(heartbeat['type'], 'get_protocol_info')
                        connection.sendall(json.dumps({'type': 'response', 'id': 'h', 'success': True, 'data': {'capabilities': ['session_status']}}).encode() + b'\n')
                        # Receiving the next request proves the prior native snapshot was projected.
                        next_request = json.loads(stream.readline())
                        self.assertEqual(next_request['type'], 'get_protocol_info')
                        rows = local.snapshot()['citizens']
                        self.assertEqual(len(rows), 1)
                        self.assertEqual(rows[0]['state'], 'working')
                        self.assertEqual(rows[0]['attribution'], 'dedicated')
                        encoded = json.dumps(rows)
                        self.assertNotIn('CANARY', encoded)
                        self.assertNotIn('/private', encoded)
                        self.assertNotIn('/secret', encoded)
                        monitor.stop.set()
                        connection.sendall(json.dumps({'type': 'response', 'id': 'h', 'success': True, 'data': {}}).encode() + b'\n')
                        unsubscribe = json.loads(stream.readline())
                        self.assertEqual(unsubscribe['type'], 'unsubscribe_sessions')
                except BaseException as error:
                    failure.append(error)
                finally:
                    complete.set()

            threading.Thread(target=serve, daemon=True).start()
            watch = threading.Thread(target=monitor.watch, args=(str(endpoint), 'tui'), daemon=True)
            watch.start()
            self.assertTrue(complete.wait(8), 'Observer protocol did not finish')
            monitor.stop.set()
            watch.join(timeout=3)
            server.close()
            if failure:
                raise failure[0]


if __name__ == '__main__':
    unittest.main()
