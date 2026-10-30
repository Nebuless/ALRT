#!/usr/bin/env python3
"""Real Linux sampler -> bounded journal -> verified TLS payload privacy proof.

Only the TLS receiver and Telegram transport are fakes. The canary is actually
present in a live process's argv, environment and cwd before sampling starts.
"""
import http.server
import json
import os
from pathlib import Path
import secrets
import select
import signal
import ssl
import subprocess
import tempfile
import threading

ROOT = Path(__file__).resolve().parents[2]


def main():
    os.umask(0o077)
    with tempfile.TemporaryDirectory(prefix='nebuless-privacy-') as temporary:
        directory = Path(temporary)
        canary = 'PRIVATE_PATH_PROMPT_ENV_' + secrets.token_hex(16)
        cwd = directory / canary
        cwd.mkdir()
        canary_process = '''import signal, time, resource, os
resource.setrlimit(resource.RLIMIT_CPU, (10, 10))
def visible(signum, frame):
    memory = bytearray(64 * 1024 * 1024)
    deadline = time.monotonic() + 12
    while time.monotonic() < deadline:
        until = time.monotonic() + .02
        while time.monotonic() < until:
            pass
        time.sleep(.03)
signal.signal(signal.SIGUSR1, visible)
os.write(1, b'R')
time.sleep(60)
'''
        planted = subprocess.Popen(['python3', '-c', canary_process, canary], cwd=cwd,
                                   env={**os.environ, 'NEBULESS_PRIVATE_CANARY': canary}, stdout=subprocess.PIPE, start_new_session=True)
        collector = None
        server = None
        try:
            assert planted.stdout is not None
            with planted.stdout as readiness:
                assert select.select([readiness], [], [], 10)[0], 'Canary process did not signal startup readiness'
                assert os.read(readiness.fileno(), 1) == b'R' and planted.poll() is None, 'Canary process failed during startup'
            proc = Path('/proc') / str(planted.pid)
            assert canary.encode() in (proc / 'cmdline').read_bytes()
            assert canary.encode() in (proc / 'environ').read_bytes()
            assert canary in os.readlink(proc / 'cwd')
            binary = directory / 'collector'
            subprocess.run(['go', 'build', '-o', str(binary), './cmd/nebu-alrt'], cwd=ROOT / 'collector', check=True)
            subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-keyout', str(directory / 'key.pem'),
                            '-out', str(directory / 'cert.pem'), '-subj', '/CN=localhost', '-addext', 'subjectAltName=IP:127.0.0.1',
                            '-addext', 'basicConstraints=critical,CA:TRUE'], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            captured, reports = [], []
            enough = threading.Event()
            class Receiver(http.server.BaseHTTPRequestHandler):
                def log_message(self, format, *args):
                    pass
                def do_POST(self):
                    raw = self.rfile.read(int(self.headers['Content-Length']))
                    assert canary.encode() not in raw, 'Canary crossed TLS network boundary'
                    captured.append(raw)
                    body = json.loads(raw)
                    records = body.get('reports', []) + ([body['report']] if 'report' in body else [])
                    reports.extend(records)
                    if len(reports) >= 3 and any(item['identity']['pid'] == planted.pid for report in reports
                                                 for category in report['processes'].values() for item in category['items']):
                        enough.set()
                    result = {'accepted': [{'stream_id': r['stream_id'], 'sequence': r['sequence']} for r in records]} if records else {'nonce': 'n' * 64, 'expires_at': '2099-01-01T00:00:00Z'}
                    payload = json.dumps(result).encode()
                    self.send_response(200)
                    self.send_header('Content-Type', 'application/json')
                    self.send_header('Content-Length', str(len(payload)))
                    self.end_headers()
                    self.wfile.write(payload)
            server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Receiver)
            context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
            context.load_cert_chain(directory / 'cert.pem', directory / 'key.pem')
            server.socket = context.wrap_socket(server.socket, server_side=True)
            threading.Thread(target=server.serve_forever, daemon=True).start()
            configuration = {'machine_id': '22222222-2222-4222-8222-222222222222', 'scope': 'sandbox',
                             'telemetry_directory': str(directory / 'journal'), 'action_directory': str(directory / 'actions'),
                             'interval_seconds': 1, 'budget_bytes': 1024 * 1024, 'segment_bytes': 64 * 1024,
                             'disks': {'disk': str(cwd)}, 'interfaces': {'local': 'lo'},
                             'destinations': [{'id': 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'url': 'https://127.0.0.1:' + str(server.server_port), 'token': secrets.token_hex(32)}]}
            configuration_path = directory / 'config.json'
            configuration_path.write_text(json.dumps(configuration))
            # A short, capped workload makes the planted process observable in
            # top CPU/RSS; an omitted process would make privacy vacuous.
            planted.send_signal(signal.SIGUSR1)
            with (directory / 'collector.log').open('wb') as log:
                collector = subprocess.Popen([str(binary), 'run', '--config', str(configuration_path)],
                                             env={**os.environ, 'SSL_CERT_FILE': str(directory / 'cert.pem')}, stdout=log, stderr=log, start_new_session=True)
            assert enough.wait(30), 'Verified TLS receiver did not receive real collector samples'
            os.killpg(collector.pid, signal.SIGTERM)
            assert collector.wait(timeout=10) == 0
            for path in [directory / 'collector.log', *(directory / 'journal').rglob('*')]:
                if path.is_file():
                    assert canary.encode() not in path.read_bytes(), 'Canary reached journal or collector logs'
            assert reports and any(item['identity']['pid'] == planted.pid for report in reports
                                   for category in report['processes'].values() for item in category['items']), 'Canary process must actually appear in sampled process evidence'
            wire = directory / 'wire.json'
            wire.write_text(json.dumps({'canary': canary, 'reports': reports}))
            result = subprocess.run([str(ROOT / 'bin/php'), 'vendor/bin/pest', '--compact', str(ROOT / 'tests/acceptance/privacy.php')],
                                    cwd=ROOT / 'apps/hub', env={**os.environ, 'NEBULESS_ACCEPTANCE_WIRE': str(wire)}, capture_output=True, text=True)
            assert canary not in result.stdout + result.stderr, 'Canary reached hub CLI diagnostics'
            assert result.returncode == 0, result.stdout + result.stderr
            assert 'privacy-normal-sink' in result.stderr and 'privacy-emergency-sink' in result.stderr, 'Normal/emergency sink proof missing'
            print(json.dumps({'privacy_canary_primed': ['argv', 'environ', 'cwd'], 'real_samples': len(reports),
                              'boundaries_checked': ['journal', 'verified_TLS_payload', 'collector_log', 'hub_database', 'hub_log', 'hub_normal_and_emergency_stderr', 'rendered_owner_HTML', 'owner_API', 'fake_Telegram'], 'passed': True}))
        finally:
            for process in [collector, planted]:
                if process is not None and process.poll() is None:
                    os.killpg(process.pid, signal.SIGTERM)
                    process.wait(timeout=10)
            if server is not None:
                server.shutdown()
                server.server_close()


if __name__ == '__main__':
    main()
