#!/usr/bin/env python3
"""Owned, isolated U12 reference runs. Invoke inside the documented 2 CPU/4 GiB cgroup.

HTTP timing includes Laravel parsing/validation, bearer auth or a real enrolled
Better Auth owner session, Caddy, the installed three-child FPM pool and verified
loopback HTTPS. The CLI server remains available only for boundary diagnosis.
No production routes, credentials, databases or services are modified.
"""
import argparse
import base64
import concurrent.futures
import hashlib
import hmac
import http.cookiejar
import html
import json
import math
import os
from pathlib import Path
import re
import secrets
import signal
import socket
import ssl
import sqlite3
import struct
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
GIB = 1024**3
# Keep direct invocation and the context runner's file-based import compatible.
sys.path.insert(0, str(Path(__file__).resolve().parent))
from reference_envelope import SeparateLoadGenerator, cgroup, topology, cpus


def port():
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        return sock.getsockname()[1]


def p95(values):
    return sorted(values)[math.ceil(len(values) * .95) - 1]


def stamp(offset=0):
    from datetime import datetime, timezone, timedelta
    return (datetime.now(timezone.utc) + timedelta(seconds=offset)).replace(microsecond=0).isoformat()


def totp(secret):
    digest = hmac.new(base64.b32decode(secret + '=' * (-len(secret) % 8)), struct.pack('>Q', int(time.time()) // 30), 'sha1').digest()
    offset = digest[-1] & 15
    return str((struct.unpack('>I', digest[offset:offset+4])[0] & 0x7fffffff) % 1000000).zfill(6)


class Browser:
    profiles = []

    def __init__(self, origin, profile=False, cafile=None):
        self.origin = origin
        self.profile = profile
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(self.cookies),
                                                   urllib.request.HTTPSHandler(context=ssl.create_default_context(cafile=cafile)))
        self.csrf = None

    def request(self, path, data=None, token=None, form=False):
        headers = {'Accept': 'text/html' if form else 'application/json'}
        if token:
            headers['Authorization'] = 'Bearer ' + token
        if data is not None:
            headers['Content-Type'] = 'application/x-www-form-urlencoded' if form else 'application/json'
            if form:
                data = {**data, '_token': self.csrf}
            elif self.csrf:
                headers['X-CSRF-TOKEN'] = self.csrf
            data = urllib.parse.urlencode(data).encode() if form else json.dumps(data).encode()
        if self.profile:
            probe = secrets.randbits(53)
            headers.update({'X-Nebuless-Probe-Id': str(probe), 'X-Nebuless-Probe-Sent': str(time.time())})
        started = time.monotonic()
        cpu_started = time.thread_time()
        with self.opener.open(urllib.request.Request(self.origin + path, data=data, headers=headers), timeout=30) as response:
            raw = response.read()
            elapsed = (time.monotonic() - started) * 1000
            if self.profile:
                metrics = {'probe_id': probe, 'operation': 'host' if re.fullmatch(r'/api/v1/hosts/[a-f0-9-]+', path.split('?', 1)[0]) else path.split('?', 1)[0].rsplit('/', 1)[-1] if path.split('?', 1)[0].rsplit('/', 1)[-1] in ['history', 'reports', 'contact', 'challenge', 'login', 'enroll', 'confirm', 'logout', 'fleet'] else 'other',
                           'request_ms': elapsed, 'receive_cpu_ms': (time.thread_time() - cpu_started) * 1000,
                           'response_bytes': len(raw)}
                Browser.profiles.append(metrics)
            if form:
                text = raw.decode()
                csrf = re.search(r'name="_token" value="([^"]+)"', text) or re.search(r'name="csrf-token" content="([^"]+)"', text)
                if csrf:
                    self.csrf = html.unescape(csrf[1])
                return text, elapsed
            decoded = time.thread_time()
            result = json.loads(raw)
            if self.profile:
                metrics['json_decode_cpu_ms'] = (time.thread_time() - decoded) * 1000
            return result, elapsed


def seed_copy(source, target):
    # SQLite's backup API includes committed WAL state. The source is read-only.
    with sqlite3.connect(source.as_uri() + '?mode=ro', uri=True) as before:
        assert before.execute('SELECT COUNT(*) FROM users').fetchone()[0] == 0
        assert before.execute('SELECT COUNT(*) FROM hub_identities').fetchone()[0] == 0
        identities = before.execute("SELECT machine_id,COUNT(*),MIN(sequence),MAX(sequence),MIN(sampled_at),MAX(sampled_at) FROM telemetry_samples WHERE stream_id='load-history' GROUP BY machine_id ORDER BY machine_id").fetchall()
        assert len(identities) == 3 and all(row[1:4] == (60480, 1, 60480) for row in identities), 'Retained seven-day seed identity mismatch'
        with sqlite3.connect(target) as after:
            before.backup(after)
    with sqlite3.connect(target) as after:
        assert after.execute("SELECT machine_id,COUNT(*),MIN(sequence),MAX(sequence),MIN(sampled_at),MAX(sampled_at) FROM telemetry_samples WHERE stream_id='load-history' GROUP BY machine_id ORDER BY machine_id").fetchall() == identities
    return {'source': str(source), 'copy': str(target), 'seed_identities': identities, 'seed_records': 181440, 'database_bytes': target.stat().st_size, 'copy_method': 'SQLite read-only source backup API'}


def fpm_servers(args, env, directory, http_port):
    certificate = directory / 'https.pem'
    key = directory / 'https-key.pem'
    subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
                    '-keyout', str(key), '-out', str(certificate), '-subj', '/CN=localhost',
                    '-addext', 'subjectAltName=IP:127.0.0.1', '-addext', 'basicConstraints=critical,CA:TRUE'],
                   check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    fpm_port = port()
    fpm = directory / 'fpm.conf'
    # Match the installed pool. Every path and process belongs to this fixture.
    fpm.write_text(f'''[global]
pid = {directory}/fpm.pid
error_log = {directory}/fpm-error.log
daemonize = no
[nebuless]
listen = 127.0.0.1:{fpm_port}
listen.allowed_clients = 127.0.0.1
pm = ondemand
pm.max_children = 3
pm.process_idle_timeout = 15s
catch_workers_output = yes
clear_env = no
chdir = {ROOT}/apps/hub
''' + ('user = root\ngroup = root\n' if os.getuid() == 0 else ''))
    document_root = ROOT / 'apps/hub/public'
    if args.profile:
        document_root = directory / 'public'
        document_root.mkdir()
        (document_root / 'index.php').symlink_to(ROOT / 'tests/acceptance/reference_profile.php')
    caddy = directory / 'Caddyfile'
    caddy.write_text(f'''{{
    admin off
    auto_https off
    log default {{
        output stderr
        level ERROR
    }}
}}
https://127.0.0.1:{http_port} {{
    bind 127.0.0.1
    tls {json.dumps(str(certificate))} {json.dumps(str(key))}
    root * {json.dumps(str(document_root))}
    php_fastcgi 127.0.0.1:{fpm_port} {{
        env HTTPS on
        env SERVER_PORT {http_port}
        env HTTP_HOST 127.0.0.1:{http_port}
    }}
    file_server
}}
''')
    # Caddy's optional local state must not touch the account's shared state.
    env.update(XDG_DATA_HOME=str(directory / 'caddy-data'), XDG_CONFIG_HOME=str(directory / 'caddy-config'))
    env['LD_LIBRARY_PATH'] = str(args.fpm.parent.parent / 'lib') + (':' + env['LD_LIBRARY_PATH'] if env.get('LD_LIBRARY_PATH') else '')
    profile = ['-c', env['PHPRC']] if env.get('PHPRC') else []
    commands = [([str(args.fpm), *profile, *(['-R'] if os.getuid() == 0 else []), '-F', '-y', str(fpm)], 'fpm.log'),
                ([str(args.caddy), 'run', '--config', str(caddy), '--adapter', 'caddyfile'], 'caddy.log')]
    return commands, certificate


def http_run(args, directory, processes, envelope=None):
    seed = seed_copy(args.seed.resolve(), directory / 'hub.sqlite')
    http_port, auth_port = port(), port()
    origin = ('https' if args.server == 'fpm' else 'http') + '://127.0.0.1:' + str(http_port)
    env = {**os.environ, 'APP_ENV': 'production', 'APP_DEBUG': 'false', 'APP_URL': origin,
           'APP_KEY': 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode(),
           'SESSION_DRIVER': 'file', 'DB_CONNECTION': 'sqlite', 'DB_DATABASE': str(directory / 'hub.sqlite'),
           'DB_URL': '', 'AUTH_DATABASE': str(directory / 'auth.sqlite'), 'AUTH_SECRET': secrets.token_hex(48),
           'AUTH_BRIDGE_KEY': secrets.token_hex(32), 'AUTH_ORIGIN': origin,
           'AUTH_BRIDGE_URL': 'http://127.0.0.1:' + str(auth_port), 'AUTH_PORT': str(auth_port),
           'LARAVEL_STORAGE_PATH': str(directory / 'storage'), 'APP_CONFIG_CACHE': str(directory / 'config.php'),
           'APP_ROUTES_CACHE': str(directory / 'routes.php'), 'SESSION_SECURE_COOKIE': 'true' if args.server == 'fpm' else 'false',
           'CACHE_STORE': 'database', 'LOG_CHANNEL': 'single', 'PHP_CLI_SERVER_WORKERS': '4'}
    if args.profile:
        env['NEBULESS_REFERENCE_PROFILE'] = str(directory / 'server-profile.jsonl')
    for name in ['framework/sessions', 'framework/views', 'framework/cache/data', 'logs']:
        (directory / 'storage' / name).mkdir(parents=True)
    (directory / '.env').touch()
    email, password = 'reference@example.test', secrets.token_urlsafe(32)
    owner = json.loads(subprocess.check_output(['node', str(ROOT / 'apps/auth/src/owner.mjs')], env=env,
                       input=json.dumps({'email': email, 'password': password}).encode(), stderr=subprocess.DEVNULL))
    subprocess.run([str(ROOT / 'bin/php'), str(ROOT / 'tests/acceptance/reference_setup.php')], env=env,
                   input=json.dumps({**owner, 'email': email, 'directory': str(directory)}).encode(), check=True, stdout=subprocess.DEVNULL)
    commands = [(['node', str(ROOT / 'apps/auth/src/server.mjs')], 'auth.log')]
    certificate = None
    if args.server == 'fpm':
        web_commands, certificate = fpm_servers(args, env, directory, http_port)
        commands.extend(web_commands)
    else:
        commands.append(([str(ROOT / 'bin/php'), '-S', '127.0.0.1:' + str(http_port), '-t', str(ROOT / 'apps/hub/public'),
                          str(ROOT / ('tests/acceptance/reference_profile.php' if args.profile else 'apps/hub/public/index.php'))], 'http.log'))
    for command, log in commands:
        with (directory / log).open('wb') as output:
            processes.append(subprocess.Popen(command, env=env, stdout=output, stderr=output, start_new_session=True))
    if envelope:
        envelope.start_generator(processes)
    http_started, generator_cpu_started = time.monotonic(), time.process_time()
    browser = Browser(origin, args.profile, certificate)
    deadline = time.monotonic() + 30
    while True:
        try:
            browser.request('/login', form=True)
            break
        except (OSError, urllib.error.URLError):
            if time.monotonic() > deadline:
                raise RuntimeError('Isolated HTTP fixture failed readiness; inspect private logs') from None
            time.sleep(.1)
    browser.request('/login', {'email': email, 'password': password}, form=True)
    body, _ = browser.request('/owner/enroll', {'password': password}, form=True)
    secret = re.search(r'Setup key: <code>([^<]+)', body)
    assert secret, 'Actual owner enrollment did not expose setup to its private session'
    browser.request('/owner/enroll/confirm', {'code': totp(secret[1])}, form=True)
    browser.request('/', form=True)
    # Prove a new login through password + TOTP, rather than reusing enrollment.
    browser.request('/logout', {}, form=True)
    browser.request('/login', {'email': email, 'password': password}, form=True)
    browser.request('/two-factor-challenge', {'code': totp(secret[1])}, form=True)
    fleet, _ = browser.request('/api/v1/fleet')
    assert fleet, 'Authenticated fleet response missing'
    machines = json.loads((directory / 'machines.json').read_text())
    template = json.loads((ROOT / 'contracts/fixtures/telemetry/valid-sample.json').read_text())
    run = secrets.token_hex(4)
    from datetime import datetime, timedelta
    payload_bytes = []
    history_points = []

    def report(machine, n, stream, offset=0):
        value = json.loads(json.dumps(template))
        value.update(machine_id=machine['id'], sequence=n, stream_id=stream, sampled_at=stamp(offset))
        items = []
        for index in range(10):
            process = json.loads(json.dumps(template['processes']['top_cpu']['items'][0]))
            process['identity'].update(pid=200 + index, start_ticks=1000 + index)
            process['name'] = 'worker-' + str(index)
            items.append(process)
        for category in ['top_cpu', 'top_rss', 'longest_running']:
            value['processes'][category].update(items=items[:3 if n % 3 == 0 else 10], total_observed=600, completeness='partial', reason='category_limit')
        payload_bytes.append(len(json.dumps(value).encode()))
        return value

    ready = __import__('threading').Barrier(4)
    stop = __import__('threading').Event()

    def backlog(machine):
        client = Browser(origin, args.profile, certificate)
        ready.wait(timeout=20)
        latencies, batch_bytes, records = [], [], 0
        # Keep backlog active for the entire foreground sample window.
        while not stop.is_set() or records < args.backlog:
            reports = []
            while len(reports) < 128:
                candidate = report(machine, records + len(reports) + 1, 'http-backlog-' + run, -10800)
                if len(json.dumps({'reports': reports + [candidate]}).encode()) > 262144:
                    break
                reports.append(candidate)
            ack, elapsed = client.request('/api/v1/collector/' + machine['id'] + '/reports', {'reports': reports}, machine['token'])
            assert [item['sequence'] for item in ack['accepted']] == [item['sequence'] for item in reports]
            records += len(reports)
            latencies.append(elapsed)
            batch_bytes.append(len(json.dumps({'reports': reports}).encode()))
        return {'records': records, 'batches': len(latencies), 'batch_p95_ms': p95(latencies), 'max_batch_bytes': max(batch_bytes)}

    live, challenge, history, hosts = [], [], [], []
    machine = next(item for item in machines if item['name'] == 'Alpha-Hub')
    with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
        tasks = [pool.submit(backlog, item) for item in machines]
        ready.wait(timeout=20)
        try:
            for n in range(1, args.samples + 1):
                nonce, elapsed = browser.request('/api/v1/collector/' + machine['id'] + '/challenge', {}, machine['token'])
                challenge.append(elapsed)
                ack, elapsed = browser.request('/api/v1/collector/' + machine['id'] + '/contact', {'nonce': nonce['nonce'], 'report': report(machine, n, 'http-current-' + run)}, machine['token'])
                assert ack['accepted'][0]['sequence'] == n
                live.append(elapsed)
                # Current interval, not the immutable seven-day seed's now-expired endpoint.
                history_to = datetime.fromisoformat(stamp()).replace(microsecond=0)
                history_query = urllib.parse.urlencode({'from': (history_to - timedelta(hours=4) + timedelta(seconds=30)).isoformat(), 'to': history_to.isoformat(), 'resolution': 'raw'})
                timeline, elapsed = browser.request('/api/v1/hosts/' + machine['id'] + '/history?' + history_query)
                assert any(point['stream_id'] == 'http-current-' + run and point['sequence'] == n for point in timeline['points']), 'History must contain the current committed identity within four hours'
                history_points.append(len(timeline['points']))
                history.append(elapsed)
                host, elapsed = browser.request('/api/v1/hosts/' + machine['id'])
                projected = host['data']
                assert projected['id'] == machine['id'] and projected['provenance']['stream_id'] == 'http-current-' + run and projected['provenance']['sequence'] == n
                assert projected['metrics']['cpu_percent']['status'] == 'available' and projected['processes']['top_cpu']['items'], 'Authenticated host response must contain usable current data'
                hosts.append(elapsed)
        finally:
            stop.set()
        backlog_results = [task.result() for task in tasks]
    with sqlite3.connect(directory / 'hub.sqlite') as db:
        accepted = db.execute("SELECT COUNT(*) FROM telemetry_accepted WHERE stream_id IN (?,?)", ('http-current-' + run, 'http-backlog-' + run)).fetchone()[0]
    expected = args.samples + sum(item['records'] for item in backlog_results)
    assert accepted == expected, 'HTTP accepted identity count mismatch'
    if args.profile:
        (directory / 'client-profile.json').write_text(json.dumps(Browser.profiles, indent=2) + '\n')
    envelope_evidence = envelope.evidence() if envelope else {
        'mode': 'shared_server_and_load_generator', 'generator_cpu_seconds_http': time.process_time() - generator_cpu_started,
        'http_wall_seconds': time.monotonic() - http_started,
        'scope': 'Python load generation/TLS/response decoding and all app servers share the same 2CPU/4GiB cgroup.'}
    return {'resource_envelope': envelope_evidence,
            'diagnostic_profile': args.profile, 'server_profile': str(directory / 'server-profile.jsonl') if args.profile else None,
            'client_profile': str(directory / 'client-profile.json') if args.profile else None,
            'boundary': ('verified loopback HTTPS + Caddy + PHP-FPM three-child installed pool' if args.server == 'fpm' else 'loopback HTTP + PHP CLI server with four children plus parent') + '; full validation/bearer auth + independent Better Auth owner; retained seven-day synthetic DB',
            'server': args.server, 'session_driver': env['SESSION_DRIVER'], 'server_binaries_sha256': {str(path): hashlib.sha256(path.read_bytes()).hexdigest() for path in [args.fpm, args.caddy]} if args.server == 'fpm' else None,
            'seed': seed, 'history_min_points': min(history_points), 'payload_bytes': {'min': min(payload_bytes), 'max': max(payload_bytes)}, 'samples': args.samples, 'concurrent_backlog_workers': 3, 'accepted_expected': expected, 'accepted_actual': accepted,
            'owner_password_totp_login': True, 'challenge_p95_ms': p95(challenge), 'live_ingest_p95_ms': p95(live),
            'live_with_challenge_p95_ms': p95([a+b for a,b in zip(challenge, live)]), 'history_http_p95_ms': p95(history),
            'host_http_p95_ms': p95(hosts), 'history_samples': len(history), 'host_samples': len(hosts),
            'latency_targets': {'comparison': '<', 'p95_ms': 1000,
                                'required_metrics': ['live_ingest_p95_ms', 'host_http_p95_ms', 'history_http_p95_ms'],
                                'reported_only_metrics': ['challenge_p95_ms', 'live_with_challenge_p95_ms'],
                                'contract': 'U8 scenario 5; docs/operations/history.md live-contact and read budgets'},
            'backlog': backlog_results, 'budgets_pass': max(p95(live), p95(history), p95(hosts)) < 1000}


def collector_run(args, directory, processes):
    binary = directory / 'nebu-alrt'
    subprocess.run(['go', 'build', '-o', str(binary), './cmd/nebu-alrt'], cwd=ROOT / 'collector', check=True)
    config = {'machine_id': '22222222-2222-4222-8222-222222222222', 'scope': 'host',
              'telemetry_directory': str(directory / 'journal'), 'action_directory': str(directory / 'actions'),
              'interval_seconds': 10, 'budget_bytes': 512 * 1024, 'segment_bytes': 32 * 1024,
              'disks': {'root': '/'}, 'interfaces': {'loopback': 'lo'},
              'destinations': [{'id': 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'url': 'https://127.0.0.1:1', 'token': secrets.token_hex(32)}]}
    path = directory / 'collector.json'
    path.write_text(json.dumps(config))
    path.chmod(0o600)
    with (directory / 'collector.log').open('wb') as log:
        process = subprocess.Popen([str(binary), 'run', '--config', str(path)], stdout=log, stderr=log, start_new_session=True)
    processes.append(process)
    time.sleep(10)  # Exclude startup; one full sampling cadence before steady interval.
    def stats():
        fields = Path('/proc/' + str(process.pid) + '/stat').read_text().rsplit(')', 1)[1].split()
        return (int(fields[11]) + int(fields[12])) / os.sysconf('SC_CLK_TCK'), int(Path('/proc/' + str(process.pid) + '/statm').read_text().split()[1]) * os.sysconf('SC_PAGE_SIZE')
    cpu_start, _ = stats()
    started, rss, journal = time.monotonic(), [], []
    while time.monotonic() - started < args.seconds:
        assert process.poll() is None, 'Collector exited during measurement'
        cpu, memory = stats()
        rss.append(memory)
        journal.append(sum(p.stat().st_size for p in (directory / 'journal').rglob('*') if p.is_file()))
        time.sleep(.25)
    elapsed = time.monotonic() - started
    cpu_percent = (cpu - cpu_start) / elapsed * 100
    result = {'boundary': 'actual collector on reference host; 10-second cadence; one unreachable HTTPS destination; no synthetic starvation',
              'steady_seconds': elapsed, 'steady_max_rss_bytes': max(rss), 'cpu_percent_one_core': cpu_percent,
              'journal_max_bytes': max(journal), 'journal_budget_bytes': 512 * 1024,
              'budgets_pass': max(rss) <= 32 * 1024**2 and cpu_percent < 1 and max(journal) <= 512 * 1024}
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--mode', choices=['http', 'check', 'collector'], required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--work-parent', type=Path, required=True)
    parser.add_argument('--seed', type=Path)
    parser.add_argument('--server', choices=['cli', 'fpm'], default='fpm')
    parser.add_argument('--fpm', type=Path)
    parser.add_argument('--caddy', type=Path)
    parser.add_argument('--samples', type=int, default=36)
    parser.add_argument('--backlog', type=int, default=360)
    parser.add_argument('--seconds', type=int, default=120)
    parser.add_argument('--profile', action='store_true', help='Fixture-only kernel/query/auth/CPU profile; retains full workload and limits')
    parser.add_argument('--separate-load-generator', action='store_true', help='Require own delegated 3CPU/5GiB outer unit; app server gets 2CPU/4GiB, generator gets separate 1CPU/1GiB')
    parser.add_argument('--server-cpus', help='Exactly two logical CPUs on distinct physical cores within the delegated outer unit')
    parser.add_argument('--client-cpu', type=int, help='One separate physical core for the bounded Python load generator')
    parser.add_argument('--allow-unconstrained-self-check', action='store_true')
    args = parser.parse_args()
    os.umask(0o077)
    assert args.output.is_absolute() and not args.output.exists(), 'Choose a fresh absolute evidence path'
    assert args.work_parent.is_dir() and args.work_parent.is_absolute()
    assert args.samples >= 2 and args.seconds >= 1 and args.backlog >= 1
    if args.mode == 'http':
        assert args.seed and args.seed.is_file(), 'HTTP mode requires the retained synthetic seed'
        if args.server == 'fpm':
            assert all(path and path.is_absolute() and os.access(path, os.X_OK) for path in [args.fpm, args.caddy]), 'Use compatible existing absolute FPM/Caddy executable paths'
    envelope = None
    if args.separate_load_generator:
        assert args.mode == 'http' and args.server == 'fpm' and not args.allow_unconstrained_self_check, 'Resource separation is only for constrained FPM HTTP reference runs'
        assert args.server_cpus is not None and args.client_cpu is not None, 'Explicit server and generator CPUs required'
        envelope = SeparateLoadGenerator(args.server_cpus, args.client_cpu)
    else:
        assert args.server_cpus is None and args.client_cpu is None, 'CPU selection flags require --separate-load-generator'
    before = cgroup(envelope.server if envelope else None)
    assert before['reference_enforced'] or args.allow_unconstrained_self_check, 'Actual 2 CPU/4 GiB/no-swap cgroup required'
    directory = Path(tempfile.mkdtemp(prefix='nebuless-reference-', dir=args.work_parent))
    started, processes = time.monotonic(), []
    result = {'mode': args.mode, 'directory': str(directory), 'cgroup_before': before,
              'resource_envelope_mode': 'separate_load_generator' if envelope else 'shared_server_and_load_generator',
              'resource_envelope': {'mode': 'separate_load_generator', 'initial': envelope.initial, 'ancestors': envelope.ancestors,
                                    'topology': envelope.topology} if envelope else {'mode': 'shared_server_and_load_generator'},
              'server_topology': topology(cpus(before['cpuset.cpus.effective'] or '')),
              'source_revision': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip(),
              'source_diff_sha256': hashlib.sha256(subprocess.check_output(['git', 'diff', 'HEAD'], cwd=ROOT)).hexdigest(),
              'runtime': subprocess.check_output([str(ROOT / 'bin/php'), '-v'], text=True).splitlines()[0],
              'node': subprocess.check_output(['node', '--version'], text=True).strip(),
              'go': subprocess.check_output(['go', 'version'], text=True).strip()}
    owned = ['tests/acceptance/reference.py', 'tests/acceptance/reference_setup.php', 'tests/acceptance/reference_profile.php', 'tests/acceptance/reference_envelope.py']
    files = set(subprocess.check_output(['git', 'ls-files'], cwd=ROOT, text=True).splitlines() + owned)
    result['source_files_sha256'] = {name: hashlib.sha256((ROOT / name).read_bytes()).hexdigest() for name in sorted(files) if (ROOT / name).is_file()}
    php_binary = subprocess.check_output([str(ROOT / 'bin/php'), '-r', 'echo PHP_BINARY;'], text=True)
    result['php_binary'] = php_binary
    result['php_binary_sha256'] = hashlib.sha256(Path(php_binary).read_bytes()).hexdigest()
    try:
        if args.mode == 'http':
            result.update(http_run(args, directory, processes, envelope))
            result['latency_targets_pass'] = result['budgets_pass']
            result['budgets_pass'] = result['budgets_pass'] and args.server == 'fpm' and not args.profile and before['reference_enforced']
        elif args.mode == 'collector':
            result.update(collector_run(args, directory, processes))
        else:
            with (directory / 'check.log').open('wb') as log:
                check = subprocess.run([str(ROOT / 'bin/check')], cwd=ROOT, stdout=log, stderr=log)
            result.update(exit_code=check.returncode, check_log=str(directory / 'check.log'), warm_dependency_caches=True,
                          budgets_pass=check.returncode == 0 and time.monotonic() - started <= 480)
    except BaseException as error:
        # Never serialize HTTP request bodies or secret-bearing exception text.
        result.update(budgets_pass=False, failure_type=type(error).__name__)
        raise
    finally:
        for process in reversed(processes):
            try:
                os.killpg(process.pid, signal.SIGTERM)
                process.wait(timeout=5)
            except ProcessLookupError:
                pass
            except subprocess.TimeoutExpired:
                os.killpg(process.pid, signal.SIGKILL)
                process.wait()
        result.update(elapsed_seconds=time.monotonic() - started, cgroup_after=cgroup(envelope.server if envelope else None))
        args.output.write_text(json.dumps(result, indent=2) + '\n')
        print(json.dumps({'output': str(args.output), 'budgets_pass': result.get('budgets_pass'), 'reference_enforced': before['reference_enforced']}))
    return 0 if result['budgets_pass'] else 1


if __name__ == '__main__':
    sys.exit(main())
