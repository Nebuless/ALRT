"""Observe enrolled OmO endpoints; never attach, activate or mirror a session."""
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import secrets
import socket
import stat
import threading
import uuid


def private_file(path, limit):
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        info = os.fstat(descriptor)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid() or info.st_mode & 0o077 or info.st_size > limit:
            raise ValueError('unsafe_observer_file')
        return os.read(descriptor, limit + 1)
    finally:
        os.close(descriptor)


def private_directory(path, exact=False):
    info = path.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.getuid() or info.st_mode & (0o077 if exact else 0o022):
        raise ValueError('unsafe_observer_directory')


class Observer:
    def __init__(self, bridge, agent_dir):
        self.bridge = bridge
        self.agent_dir = Path(agent_dir)
        self.stop = threading.Event()
        self.threads = {}
        self.ready = threading.Event()
        self.discovery_source = 'omo-root:' + self.bridge.opaque(str(self.agent_dir))
        self.health = {}
        self.health_lock = threading.Lock()
        self.discovery_truncated = False
        self.truncated = set()

    def reconcile(self, endpoints):
        with self.health_lock:
            self.health = {endpoint: self.health.get(endpoint, False) for endpoint, _kind in endpoints}
            self.truncated.intersection_update(self.health)
            self.bridge.source_health(self.discovery_source, bool(self.health) and all(self.health.values()) and not self.truncated and not self.discovery_truncated and len(endpoints) <= 16)

    def source_health(self, endpoint, healthy):
        with self.health_lock:
            if endpoint in self.health:
                self.health[endpoint] = healthy
            self.bridge.source_health(self.discovery_source, bool(self.health) and all(self.health.values()) and not self.truncated and not self.discovery_truncated and len(self.health) <= 16)

    def snapshot_rows(self, endpoint, rows):
        if not isinstance(rows, list):
            raise ValueError('invalid_observer_snapshot')
        with self.health_lock:
            self.truncated.discard(endpoint)
            if len(rows) > 128:
                self.truncated.add(endpoint)
        return sorted(rows, key=lambda row: row.get('status') in {'closed', 'unloaded'})[:128]

    def discover(self):
        endpoints = []
        root = self.agent_dir / 'rpc-host-daemon'
        if not root.is_dir():
            return endpoints
        directories = sorted(root.iterdir())
        self.discovery_truncated = len(directories) > 64
        for directory in directories[:64]:
            if not directory.is_dir() or not re.fullmatch('[a-f0-9]{16}', directory.name):
                continue
            try:
                meta = json.loads(private_file(directory / 'endpoint.json', 16384))
                endpoint = meta['socket']
                if not isinstance(endpoint, str) or not Path(endpoint).is_absolute():
                    continue
                sock = Path(endpoint).lstat()
                if not stat.S_ISSOCK(sock.st_mode) or sock.st_uid != os.getuid() or sock.st_mode & 0o077:
                    continue
                endpoints.append((endpoint, meta.get('endpoint_kind')))
            except (OSError, ValueError, KeyError, TypeError):
                continue
        return endpoints

    def run(self):
        while not self.stop.is_set():
            endpoints = self.discover()
            self.reconcile(endpoints)
            for endpoint in endpoints[:16]:
                if endpoint[0] not in self.threads or not self.threads[endpoint[0]].is_alive():
                    thread = threading.Thread(target=self.watch, args=endpoint, daemon=True)
                    self.threads[endpoint[0]] = thread
                    thread.start()
            self.stop.wait(5)

    def watch(self, endpoint, kind):
        source = self.bridge.opaque(endpoint)
        with self.health_lock:
            self.health.setdefault(endpoint, False)
        self.source_health(endpoint, False)
        try:
            with socket.socket(socket.AF_UNIX) as connection:
                connection.settimeout(3)
                connection.connect(endpoint)
                peer_pid, peer_uid, _ = __import__('struct').unpack('3i', connection.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, 12))
                if peer_uid != os.getuid():
                    raise PermissionError('wrong_observer_owner')
                if kind == 'tui':
                    secret = private_file(Path(endpoint + '.secret'), 32)
                    if len(secret) != 32:
                        raise ValueError('invalid_tui_secret')
                    connection.sendall(secret)
                stream = connection.makefile('rb', buffering=0)
                event_handler = None

                def send(frame):
                    connection.sendall(json.dumps(frame, separators=(',', ':')).encode() + b'\n')

                def receive():
                    raw = stream.readline(1048577)
                    if not raw or len(raw) > 1048576:
                        raise ValueError('observer_frame_unavailable')
                    value = json.loads(raw)
                    if not isinstance(value, dict):
                        raise ValueError('invalid_observer_frame')
                    return value

                def response(identity):
                    for _ in range(64):
                        frame = receive()
                        if frame.get('id') == identity and frame.get('type') == 'response':
                            if not frame.get('success'):
                                raise ValueError('observer_refused')
                            return frame.get('data', {})
                        if event_handler:
                            event_handler(frame)
                    raise ValueError('observer_response_missing')

                nonce = secrets.token_hex(32)
                send({'id': 'p', 'type': 'get_protocol_info', 'observe': True, 'auth': {'client_nonce': nonce}})
                protocol = response('p')
                pid = peer_pid
                if kind != 'tui':
                    private_directory(self.agent_dir)
                    private_directory(self.agent_dir / 'rpc')
                    private_directory(self.agent_dir / 'rpc/keys', exact=True)
                    auth = protocol.get('auth', {})
                    launch = str(uuid.UUID(auth['launch_id']))
                    key = json.loads(private_file(self.agent_dir / 'rpc/keys' / launch, 4096))
                    if key.get('version') != 1 or key.get('launch_id') != launch or key.get('endpoint') != endpoint or key.get('pid') != peer_pid or not re.fullmatch('[a-f0-9]{64}', key.get('key', '')):
                        raise ValueError('unproven_host')
                    server_nonce = auth['server_nonce']
                    proof = auth['server_proof']
                    if not re.fullmatch('[a-f0-9]{64}', server_nonce) or not re.fullmatch('[a-f0-9]{64}', proof):
                        raise ValueError('invalid_observer_proof')
                    secret = bytes.fromhex(key['key'])
                    expected = hmac.new(secret, '\n'.join(['senpi-rpc-server', launch, nonce, server_nonce]).encode(), hashlib.sha256).hexdigest()
                    if not hmac.compare_digest(expected, proof):
                        raise ValueError('unproven_host')
                    client = hmac.new(secret, '\n'.join(['senpi-rpc-client', launch, server_nonce, nonce]).encode(), hashlib.sha256).hexdigest()
                    send({'id': 'a', 'type': 'authenticate', 'client_proof': client})
                    response('a')
                    pid = key['pid']
                if 'session_status' not in protocol.get('capabilities', []):
                    raise ValueError('status_unsupported')
                send({'id': 's', 'type': 'subscribe_sessions', 'observe': True, 'sessions': 'all', 'include_workers': True, 'include_context': False, 'mirror': 'none', 'effects': False})
                subscription = response('s')
                rows = {}

                def project(row):
                    identity = row.get('durableSessionId') or row.get('sessionId')
                    state = {'running': 'working', 'compacting': 'working', 'waiting_input': 'waiting', 'idle': 'idle', 'closed': 'stopped', 'unloaded': 'stopped'}.get(row.get('status'), 'unknown')
                    if not isinstance(identity, str) or not identity or len(identity) > 128:
                        return
                    local = hashlib.sha256((source + ':' + identity).encode()).hexdigest()
                    try:
                        self.bridge.register({'session_id': local, 'parent_id': None, 'tool': 'omo', 'state': state}, pid, os.getuid(), shared=kind != 'tui')
                    except ValueError:
                        with self.health_lock:
                            self.truncated.add(endpoint)
                        return
                    opaque = self.bridge.opaque('omo:' + local)
                    with self.bridge.lock:
                        for handle, activity in list(rows.items()):
                            if self.bridge.opaque('omo:' + activity['session_id']) not in self.bridge.rows:
                                del rows[handle]
                    rows[row['sessionId']] = {'session_id': local, 'parent_id': None, 'tool': 'omo', 'state': state}

                for row in self.snapshot_rows(endpoint, subscription.get('snapshot', [])):
                    project(row)
                sequence = subscription['seq']
                self.source_health(endpoint, True)
                self.ready.set()

                def event(frame):
                    nonlocal sequence
                    if frame.get('type') == 'resync_required':
                        raise ValueError('observer_resync')
                    if frame.get('type') == 'session_status' and frame.get('subscriptionId') == subscription['subscriptionId']:
                        if frame.get('seq') != sequence + 1:
                            raise ValueError('observer_gap')
                        sequence += 1
                        project(frame)

                event_handler = event
                connection.settimeout(2)
                # select avoids corrupting a buffered reader by timing out midway through a line.
                import select
                while not self.stop.is_set():
                    readable, _, _ = select.select([connection], [], [], 2)
                    if readable:
                        frame = receive()
                        event(frame)
                    send({'id': 'h', 'type': 'get_protocol_info', 'observe': True})
                    response('h')
                    self.source_health(endpoint, True)
                    for activity in list(rows.values()):
                        if activity['state'] in {'stopped', 'failed'}:
                            continue
                        opaque = self.bridge.opaque('omo:' + activity['session_id'])
                        with self.bridge.lock:
                            if opaque not in self.bridge.rows:
                                continue
                        self.bridge.register(activity, pid, os.getuid(), shared=kind != 'tui')
                send({'id': 'u', 'type': 'unsubscribe_sessions', 'observe': True, 'subscriptionId': subscription['subscriptionId']})
        except (OSError, ValueError, KeyError, TypeError, PermissionError):
            self.source_health(endpoint, False)
