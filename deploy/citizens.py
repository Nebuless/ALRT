#!/usr/bin/env python3
"""Private per-user Citizens metadata bridge. No transcript or command parsing."""
import argparse
import ctypes
import datetime
import fcntl
import hashlib
from http.server import BaseHTTPRequestHandler
import json
import os
from pathlib import Path
import pwd
import signal
import select
import socket
import socketserver
import stat
import struct
import tempfile
import threading
import time
import uuid


def timestamp(value):
    return datetime.datetime.fromtimestamp(value, datetime.timezone.utc).isoformat().replace('+00:00', 'Z')


def instant(value):
    if not isinstance(value, str) or len(value) > 40:
        raise ValueError('invalid_time')
    result = datetime.datetime.fromisoformat(value.replace('Z', '+00:00'))
    if result.tzinfo is None:
        raise ValueError('invalid_time')
    return result.timestamp()


def atomic_json(path, value):
    fd, temporary = tempfile.mkstemp(prefix='.citizens-', dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as output:
            os.fchmod(output.fileno(), 0o600)
            json.dump(value, output, separators=(',', ':'), allow_nan=False)
            output.flush()
            os.fsync(output.fileno())
        os.replace(temporary, path)
        directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        Path(temporary).unlink(missing_ok=True)


def process_identity(pid):
    if type(pid) is not int or pid <= 1:
        raise ValueError('invalid_process')
    root = Path('/proc') / str(pid)
    fields = (root / 'stat').read_text().rsplit(')', 1)[1].split()
    return {'pid': pid, 'start_ticks': int(fields[19]), 'uid': root.stat().st_uid,
            'ticks': int(fields[11]) + int(fields[12]), 'rss': max(0, int(fields[21])) * os.sysconf('SC_PAGE_SIZE')}


def process_fd(pid):
    # pidfd_open is syscall 434 on the supported Linux amd64/arm64 hosts.
    library = ctypes.CDLL(None, use_errno=True)
    descriptor = library.syscall(434, pid, 0)
    if descriptor < 0:
        error = ctypes.get_errno()
        raise OSError(error, 'process_handle_unavailable')
    return descriptor


def send_kill(descriptor):
    library = ctypes.CDLL(None, use_errno=True)
    if library.syscall(424, descriptor, signal.SIGKILL, 0, 0) < 0:
        raise OSError(ctypes.get_errno(), 'signal_failed')


def socket_access(endpoint, collector_uid):
    if collector_uid == os.getuid():
        os.chmod(endpoint, 0o600)
        return
    group = pwd.getpwuid(collector_uid).pw_gid
    if os.getuid() != 0 and group not in {os.getgid(), *os.getgroups()}:
        raise PermissionError('collector_group_not_enrolled')
    for parent in endpoint.parents:
        info = parent.stat()
        can_traverse = bool(info.st_mode & stat.S_IXOTH) or (info.st_gid == group and bool(info.st_mode & stat.S_IXGRP)) or (info.st_uid == collector_uid and bool(info.st_mode & stat.S_IXUSR))
        if not can_traverse:
            raise PermissionError('collector_parent_not_traversable')
    os.chown(endpoint, -1, group)
    os.chmod(endpoint, 0o660)


class Bridge:
    def __init__(self, directory, clock=time.time, control_allowed=True):
        self.directory = Path(directory)
        self.directory.mkdir(mode=0o700, parents=True, exist_ok=True)
        info = self.directory.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.getuid() or info.st_mode & 0o077:
            raise PermissionError('unsafe_state')
        self.clock = clock
        self.control_allowed = control_allowed
        self.id = str(uuid.uuid4())
        self.boot = Path('/proc/sys/kernel/random/boot_id').read_text().strip()
        self.sequence = 0
        self.rows = {}
        self.samples = {}
        self.sources = {}
        self.admission_drops = {}
        self.lock = threading.RLock()
        self.receipt_path = self.directory / 'receipts.json'
        marker = self.directory / 'initialized'
        if not marker.exists() and not self.receipt_path.exists():
            atomic_json(self.receipt_path, {})
            atomic_json(marker, {'version': 1})
        try:
            info = self.receipt_path.lstat()
            if not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid() or info.st_mode & 0o077 or info.st_size > 2 << 20:
                raise ValueError('unsafe_receipts')
            self.receipts = json.loads(self.receipt_path.read_text())
            allowed = {'terminated', 'already_exited', 'identity_changed', 'permission_denied', 'unsupported', 'expired', 'unknown'}
            if not isinstance(self.receipts, dict) or len(self.receipts) > 10000 or any(str(uuid.UUID(k)) != k or v not in allowed for k, v in self.receipts.items()):
                raise ValueError('invalid_receipts')
        except (OSError, json.JSONDecodeError, ValueError) as error:
            raise ValueError('protected_state_unavailable') from error
        try:
            descriptor = process_fd(os.getpid())
            os.close(descriptor)
            self.pidfd = True
        except OSError:
            self.pidfd = False
        self.coverage = 'complete'

    def source_health(self, source, healthy):
        with self.lock:
            self.sources[source] = healthy

    def current_coverage(self):
        return 'partial' if not all(self.sources.values()) or self.admission_drops else self.coverage

    def opaque(self, value):
        return hashlib.sha256((self.boot + ':' + value).encode()).hexdigest()

    def register(self, event, pid, uid, shared=False):
        if not isinstance(event, dict) or set(event) != {'session_id', 'parent_id', 'tool', 'state'}:
            raise ValueError('invalid_event')
        session = event['session_id']
        parent = event['parent_id']
        if not isinstance(session, str) or not 1 <= len(session) <= 128 or any(ord(c) < 32 for c in session):
            raise ValueError('invalid_session')
        if parent is not None and (not isinstance(parent, str) or not 1 <= len(parent) <= 128):
            raise ValueError('invalid_parent')
        if event['tool'] not in {'pi', 'omo'} or event['state'] not in {'working', 'waiting', 'idle', 'stopped', 'failed', 'unknown'}:
            raise ValueError('invalid_state')
        identity = process_identity(pid)
        if identity['uid'] != uid or uid != os.getuid():
            raise PermissionError('wrong_process_owner')
        citizen = self.opaque(event['tool'] + ':' + session)
        with self.lock:
            if citizen not in self.rows and len(self.rows) >= 128:
                stopped = [row for row in self.rows.values() if row['state'] in {'stopped', 'failed'}]
                if stopped:
                    oldest = min(stopped, key=lambda row: row['at'])
                    del self.rows[oldest['id']]
                else:
                    self.admission_drops[citizen] = self.clock()
                    raise ValueError('inventory_full')
            self.admission_drops.pop(citizen, None)
            prior = self.rows.get(citizen, {})
            self.rows[citizen] = {'id': citizen, 'parent_id': None if parent is None else self.opaque(event['tool'] + ':' + parent),
                                  'tool': event['tool'], 'state': event['state'], 'at': self.clock(), 'identity': identity,
                                  'sample': prior.get('sample'), 'shared': prior.get('shared', False) or shared}
        return {'accepted': True}

    def snapshot(self):
        with self.lock:
            now = self.clock()
            self.rows = {key: row for key, row in self.rows.items() if now - row['at'] <= 14400}
            self.admission_drops = {key: at for key, at in self.admission_drops.items() if now - at <= 10}
            counts = {}
            for row in self.rows.values():
                key = (row['identity']['pid'], row['identity']['start_ticks'])
                if row['state'] not in {'stopped', 'failed'}:
                    counts[key] = counts.get(key, 0) + 1
            output = []
            measured = {}
            for row in self.rows.values():
                saved = row['identity']
                runtime = (saved['pid'], saved['start_ticks'])
                current = None
                try:
                    found = process_identity(saved['pid'])
                    if found['start_ticks'] == saved['start_ticks'] and found['uid'] == saved['uid']:
                        current = found
                except (OSError, ValueError, IndexError):
                    pass
                fresh = 0 <= now - row['at'] <= 10 and current is not None
                state = row['state'] if fresh or row['state'] in {'stopped', 'failed'} else ('stopped' if current is None else 'unknown')
                if current is None and not row.get('ended_at'):
                    row['ended_at'] = now
                    row['state'] = 'stopped'
                shared = row['shared'] or counts.get((saved['pid'], saved['start_ticks']), 0) > 1
                cpu = None
                if fresh:
                    if runtime not in measured:
                        prior = self.samples.get(runtime)
                        if prior and now > prior[0] and current['ticks'] >= prior[1]:
                            cpu = (current['ticks'] - prior[1]) / os.sysconf('SC_CLK_TCK') / (now - prior[0]) * 100
                        self.samples[runtime] = (now, current['ticks'])
                        measured[runtime] = cpu
                    cpu = measured[runtime]
                output.append({'id': row['id'], 'parent_id': row['parent_id'], 'tool': row['tool'], 'state': state,
                               'observed_at': timestamp(row['at']), 'runtime_id': self.opaque(str(saved['pid']) + ':' + str(saved['start_ticks'])),
                               'attribution': 'shared' if shared else 'dedicated',
                               'process': {'boot_id': self.boot, 'pid': saved['pid'], 'start_ticks': saved['start_ticks']},
                               'cpu_percent': cpu, 'rss_bytes': current['rss'] if fresh else None,
                               'can_terminate': self.control_allowed and fresh and state not in {'stopped', 'failed', 'unknown'} and not shared and self.pidfd and saved['pid'] != os.getpid()})
            self.sequence += 1
            live = {(row['identity']['pid'], row['identity']['start_ticks']) for row in self.rows.values()}
            self.samples = {key: value for key, value in self.samples.items() if key in live}
            return {'boot_id': self.boot, 'bridge_id': self.id, 'sequence': self.sequence, 'sampled_at': timestamp(now),
                    'coverage': self.current_coverage(), 'citizens': output}

    def commands(self, commands):
        if not isinstance(commands, list) or len(commands) > 64:
            raise ValueError('invalid_commands')
        results = []
        with self.lock:
            for command in commands:
                expected = {'id', 'citizen_id', 'boot_id', 'bridge_id', 'pid', 'start_ticks', 'expires_at'}
                if not isinstance(command, dict) or set(command) != expected or str(uuid.UUID(command['id'])) != command['id']:
                    raise ValueError('invalid_command')
                action = command['id']
                if action in self.receipts:
                    results.append({'id': action, 'status': self.receipts[action]})
                    continue
                if len(self.receipts) >= 10000:
                    raise ValueError('receipt_capacity')
                status = self.execute(command)
                self.receipts[action] = status
                atomic_json(self.receipt_path, self.receipts)
                results.append({'id': action, 'status': status})
        return results

    def execute(self, command):
        if not self.control_allowed:
            return 'unsupported'
        now = self.clock()
        expires = instant(command['expires_at'])
        if not now < expires <= now + 5:
            return 'expired'
        row = self.rows.get(command['citizen_id'])
        if command['bridge_id'] != self.id or command['boot_id'] != self.boot or row is None:
            return 'identity_changed'
        saved = row['identity']
        if type(command['pid']) is not int or type(command['start_ticks']) is not int or (command['pid'], command['start_ticks']) != (saved['pid'], saved['start_ticks']):
            return 'identity_changed'
        if not 0 <= now - row['at'] <= 10 or row['state'] in {'stopped', 'failed', 'unknown'}:
            return 'expired'
        if row['shared'] or sum(item['identity']['pid'] == saved['pid'] and item['identity']['start_ticks'] == saved['start_ticks'] and item['state'] not in {'stopped', 'failed'} for item in self.rows.values()) > 1 or saved['pid'] == os.getpid() or not self.pidfd:
            return 'unsupported'
        try:
            descriptor = process_fd(saved['pid'])
        except OSError as error:
            return 'already_exited' if error.errno == 3 else 'unsupported'
        try:
            try:
                current = process_identity(saved['pid'])
            except (OSError, ValueError, IndexError):
                return 'already_exited'
            if current['uid'] != os.getuid():
                return 'permission_denied'
            if current['start_ticks'] != saved['start_ticks']:
                return 'identity_changed'
            # Fence before signaling. A crash or uncertain result can never retry the action.
            self.receipts[command['id']] = 'unknown'
            atomic_json(self.receipt_path, self.receipts)
            try:
                send_kill(descriptor)
                poll = select.poll()
                poll.register(descriptor, select.POLLIN)
                return 'terminated' if poll.poll(1000) else 'unknown'
            except OSError as error:
                return 'already_exited' if error.errno == 3 else 'permission_denied' if error.errno in {1, 13} else 'unknown'
        finally:
            os.close(descriptor)


class Server(socketserver.ThreadingMixIn, socketserver.UnixStreamServer):
    daemon_threads = True
    block_on_close = False

    def process_request(self, request, address):
        if self.peer_credentials[request.fileno()][1] not in {os.getuid(), self.collector_uid} or not self.slots.acquire(blocking=False):
            self.peer_credentials.pop(request.fileno(), None)
            self.shutdown_request(request)
            return
        try:
            super().process_request(request, address)
        except BaseException:
            self.peer_credentials.pop(request.fileno(), None)
            self.slots.release()
            raise

    def get_request(self):
        request, address = super().get_request()
        descriptor = request.fileno()
        request.settimeout(5)
        request.settimeout(5)
        try:
            peer_pid, peer_uid, _ = struct.unpack('3i', request.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, 12))
            self.peer_credentials[descriptor] = (peer_pid, peer_uid)
            return request, address
        except (OSError, PermissionError, struct.error):
            self.shutdown_request(request)
            self.peer_credentials.pop(descriptor, None)
            raise

    def server_activate(self):
        self.slots = threading.BoundedSemaphore(16)
        self.peer_credentials = {}
        super().server_activate()

    def process_request_thread(self, request, address):
        descriptor = request.fileno()
        try:
            super().process_request_thread(request, address)
        finally:
            self.peer_credentials.pop(descriptor, None)
            self.slots.release()


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_GET(self):
        self.handle_request()

    def do_POST(self):
        self.handle_request()

    def handle_request(self):
        self.connection.settimeout(5)
        pid, uid = self.server.peer_credentials.pop(self.connection.fileno())
        try:
            length = int(self.headers.get('Content-Length', '0'))
            if not 0 <= length <= 262144:
                raise ValueError('body_limit')
            if self.command == 'POST' and self.path == '/events' and uid == os.getuid():
                data = self.server.bridge.register(json.loads(self.rfile.read(length)), pid, uid)
            elif uid == self.server.collector_uid and self.command == 'GET' and self.path == '/snapshot':
                data = self.server.bridge.snapshot()
            elif self.server.bridge.control_allowed and uid == self.server.collector_uid and self.command == 'POST' and self.path == '/commands':
                body = json.loads(self.rfile.read(length))
                if not isinstance(body, dict) or set(body) != {'commands'}:
                    raise ValueError('invalid_commands')
                data = {'results': self.server.bridge.commands(body['commands'])}
            else:
                raise PermissionError('forbidden')
            encoded = json.dumps(data, separators=(',', ':'), allow_nan=False).encode()
            self.send_response(200)
        except (OSError, ValueError, TypeError, KeyError, OverflowError):
            encoded = b'{"error":"bridge_unavailable"}'
            self.send_response(503)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(encoded)))
        self.end_headers()
        self.wfile.write(encoded)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--socket', required=True)
    parser.add_argument('--state', required=True)
    parser.add_argument('--collector-uid', type=int, default=os.getuid())
    parser.add_argument('--agent-dir', action='append', default=[])
    args = parser.parse_args()
    os.umask(0o077)
    directory = Path(args.state)
    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    state_info = directory.lstat()
    if not stat.S_ISDIR(state_info.st_mode) or state_info.st_uid != os.getuid() or state_info.st_mode & 0o077:
        raise PermissionError('unsafe_state')
    lock_fd = os.open(directory / 'bridge.lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    lock = os.fdopen(lock_fd, 'a')
    fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
    bridge = Bridge(directory, control_allowed=args.collector_uid == os.getuid())
    from citizens_omo import Observer
    observers = [Observer(bridge, directory) for directory in args.agent_dir]
    for observer in observers:
        threading.Thread(target=observer.run, daemon=True).start()
    endpoint = Path(args.socket)
    endpoint.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    parent = endpoint.parent.lstat()
    if not stat.S_ISDIR(parent.st_mode) or parent.st_uid not in {os.getuid(), args.collector_uid} or parent.st_mode & 0o022:
        raise PermissionError('unsafe_socket_parent')
    if endpoint.exists():
        info = endpoint.lstat()
        if not stat.S_ISSOCK(info.st_mode) or info.st_uid != os.getuid():
            raise ValueError('unsafe_socket')
        with socket.socket(socket.AF_UNIX) as probe:
            probe.settimeout(1)
            try:
                probe.connect(str(endpoint))
            except ConnectionRefusedError:
                endpoint.unlink()
            else:
                raise ValueError('socket_in_use')
    with Server(str(endpoint), Handler) as server:
        server.bridge = bridge
        server.collector_uid = args.collector_uid
        socket_access(endpoint, args.collector_uid)
        print('CITIZENS_BRIDGE_READY', flush=True)
        try:
            server.serve_forever(poll_interval=0.25)
        finally:
            for observer in observers:
                observer.stop.set()
            endpoint.unlink(missing_ok=True)


if __name__ == '__main__':
    main()
