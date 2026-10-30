#!/usr/bin/env python3
"""Required browser release gate using a disposable, private local fleet."""
import base64
import json
import os
from pathlib import Path
import grp
import pwd
import secrets
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import time
import urllib.request


ROOT = Path(__file__).resolve().parents[2]


def port():
    with socket.socket() as reservation:
        reservation.bind(('127.0.0.1', 0))
        return reservation.getsockname()[1]


def stop(process):
    # Include descendants (FPM workers and Chromium), even if the parent exited.
    try:
        os.killpg(process.pid, signal.SIGTERM)
    except ProcessLookupError:
        pass
    try:
        process.wait(timeout=5)
    except subprocess.TimeoutExpired:
        pass
    try:
        os.killpg(process.pid, signal.SIGKILL)
    except ProcessLookupError:
        pass
    process.wait()


def caddy_binary(env, log, processes):
    caddy = shutil.which('caddy', path=env['PATH'])
    if not caddy:
        cache = Path(env['HOME']) / '.cache/nebuless-browser/caddy-2.11.7'
        cache.mkdir(parents=True, exist_ok=True)
        caddy = str(cache / 'caddy')
        if not Path(caddy).is_file():
            build_env = dict(env, GOBIN=str(cache))
            process = subprocess.Popen(['go', 'install', 'github.com/caddyserver/caddy/v2/cmd/caddy@v2.11.7'], cwd=ROOT,
                                       env=build_env, stdout=log, stderr=log, start_new_session=True)
            processes.append(process)
            process.wait(timeout=600)
            if process.returncode:
                raise RuntimeError('Pinned Caddy setup failed.')
    if subprocess.check_output([caddy, 'version'], text=True).split()[0] != 'v2.11.7':
        raise RuntimeError('Browser fixture requires pinned Caddy v2.11.7')
    return caddy


def main():
    if len(sys.argv) != 1:
        raise RuntimeError('bin/browser-check runs the complete desktop and phone gate without filters.')
    os.umask(0o077)
    processes = []
    phase = 'setup'
    with tempfile.TemporaryDirectory(prefix='nebuless-browser-', dir='/tmp') as scratch:
        directory = Path(scratch)
        directory.chmod(0o700)
        # Do not inherit a production app/database/auth/transport environment.
        env = {key: os.environ[key] for key in ('PATH', 'HOME', 'LANG', 'PLAYWRIGHT_BROWSERS_PATH', 'ORBIT_SCREENSHOTS', 'CITIZENS_SCREENSHOTS') if key in os.environ}
        http_port, auth_port, fpm_port = port(), port(), port()
        origin = f'http://127.0.0.1:{http_port}'
        env.update({
            'APP_ENV': 'testing', 'APP_DEBUG': 'false', 'APP_URL': origin,
            'APP_KEY': 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode(),
            'DB_CONNECTION': 'sqlite', 'DB_DATABASE': str(directory / 'hub.sqlite'),
            'SESSION_DRIVER': 'file', 'SESSION_SECURE_COOKIE': 'false', 'SESSION_COOKIE': 'nebuless_browser',
            'CACHE_STORE': 'database', 'LOG_CHANNEL': 'stderr', 'MAIL_MAILER': 'array',
            'LARAVEL_STORAGE_PATH': str(directory / 'storage'), 'VIEW_COMPILED_PATH': str(directory / 'storage/framework/views'),
            'APP_CONFIG_CACHE': str(directory / 'config.php'), 'APP_ROUTES_CACHE': str(directory / 'routes.php'),
            'APP_EVENTS_CACHE': str(directory / 'events.php'),
            'AUTH_DATABASE': str(directory / 'auth.sqlite'), 'AUTH_SECRET': secrets.token_hex(48),
            'AUTH_ORIGIN': origin, 'AUTH_BRIDGE_KEY': secrets.token_hex(32),
            'AUTH_BRIDGE_URL': f'http://127.0.0.1:{auth_port}', 'AUTH_PORT': str(auth_port),
            'NEBULESS_HUB_NAME': 'Primary-Hub', 'NEBULESS_HUB_ROLE': 'primary',
            'NEBULESS_AUTH_MODE': 'better-auth',
            'NEBULESS_ACTION_KEY_ID': 'synthetic-browser-only', 'NEBULESS_ACTION_PRIVATE_KEY_FILE': str(directory / 'signing.pem'),
            'LD_LIBRARY_PATH': str(ROOT / 'tools/runtime/lib'), 'BROWSER_SCRATCH': scratch,
            'BROWSER_BASE_URL': origin, 'BROWSER_STORAGE_STATE': str(directory / 'browser-state.json'),
            'BROWSER_REBOOT_FIXTURE': str(directory / 'reboot.json'), 'BROWSER_RELEASE_GATE': '1',
            'BROWSER_OUTPUT_DIR': str(directory / 'test-results'), 'PLAYWRIGHT_JSON_OUTPUT_NAME': str(directory / 'report.json'),
            'PW_EXPERIMENTAL_SERVICE_WORKER_NETWORK_EVENTS': '1',
            'XDG_CONFIG_HOME': str(directory / 'config'), 'XDG_DATA_HOME': str(directory / 'data'),
            'TMPDIR': str(directory / 'tmp'),
        })
        for child in ('storage/framework/views', 'storage/framework/sessions', 'storage/framework/cache/data', 'storage/logs', 'tmp'):
            (directory / child).mkdir(parents=True, exist_ok=True)
        (directory / 'hub.sqlite').touch(mode=0o600)
        php = str(ROOT / 'bin/php')
        artisan = [php, str(ROOT / 'apps/hub/artisan')]
        with (directory / 'runtime.log').open('w') as log:
            def launch(command, *, data=None):
                process = subprocess.Popen(command, cwd=ROOT, env=env, stdin=subprocess.PIPE if data is not None else subprocess.DEVNULL,
                                           stdout=log, stderr=log, start_new_session=True)
                processes.append(process)
                return process

            def run(command, *, data=None, timeout=120):
                process = launch(command, data=data)
                process.communicate(data.encode() if data is not None else None, timeout=timeout)
                if process.returncode:
                    raise RuntimeError(f'{phase} failed (private logs removed during cleanup).')

            try:
                caddy = caddy_binary(env, log, processes)
                phase = 'migration/provisioning'
                run(artisan + ['migrate', '--force'])
                password = secrets.token_urlsafe(24)
                email = 'browser-owner@example.test'
                run(artisan + ['fleet:provision-owner', email, '--hub-name=Primary-Hub', '--role=primary'], data=password + '\n' + password + '\n')
                run([php, '-r', '$k=openssl_pkey_new(["private_key_type"=>OPENSSL_KEYTYPE_ED25519]);openssl_pkey_export($k,$pem);file_put_contents(getenv("NEBULESS_ACTION_PRIVATE_KEY_FILE"),$pem);'])
                run([php, str(ROOT / 'tests/browser/seed.php')])
                user = pwd.getpwuid(os.getuid()).pw_name
                group = grp.getgrgid(os.getgid()).gr_name
                (directory / 'fpm.conf').write_text(f'[global]\ndaemonize=no\nerror_log={scratch}/fpm.log\n[app]\nlisten=127.0.0.1:{fpm_port}\nuser={user}\ngroup={group}\npm=static\npm.max_children=2\nclear_env=no\ncatch_workers_output=yes\n')
                (directory / 'Caddyfile').write_text('{\n admin off\n auto_https off\n}\n' + origin + ' {\n bind 127.0.0.1\n root * ' + str(ROOT / 'apps/hub/public') + f'\n php_fastcgi 127.0.0.1:{fpm_port}\n file_server\n}}\n')
                phase = 'runtime readiness'
                runtime = [launch(['node', str(ROOT / 'apps/auth/src/server.mjs')]),
                           launch([str(ROOT / 'tools/runtime/sbin/php-fpm'), '--allow-to-run-as-root', '-y', str(directory / 'fpm.conf')]),
                           launch([caddy, 'run', '--config', str(directory / 'Caddyfile'), '--adapter', 'caddyfile'])]
                deadline = time.monotonic() + 30
                while True:
                    if any(process.poll() is not None for process in runtime):
                        raise RuntimeError('Browser runtime exited before readiness.')
                    try:
                        with socket.create_connection(('127.0.0.1', auth_port), timeout=.2):
                            pass
                        with urllib.request.urlopen(origin + '/login', timeout=1) as response:
                            if response.status == 200:
                                break
                    except OSError:
                        if time.monotonic() >= deadline:
                            raise RuntimeError('Browser runtime readiness timed out.') from None
                        time.sleep(.1)
                phase = 'normal owner password/TOTP enrollment'
                run(['node', str(ROOT / 'tests/browser/enroll.mjs')], data=json.dumps({'email': email, 'password': password}))
                for name in ('BROWSER_STORAGE_STATE', 'BROWSER_REBOOT_FIXTURE'):
                    if Path(env[name]).stat().st_mode & 0o077:
                        raise RuntimeError('Browser prerequisite is not private.')
                phase = 'desktop/phone browser tests'
                if os.environ.get('CITIZENS_WIRE_ACCEPTANCE') == '1':
                    phase = 'Citizens real bridge/collector/action acceptance'
                    wire = subprocess.run([sys.executable, str(ROOT / 'tests/acceptance/citizens_wire.py')], cwd=ROOT, env=env, capture_output=True, text=True, timeout=90)
                    if wire.returncode:
                        raise RuntimeError(phase+' failed: '+wire.stderr[-2200:])
                    print('Citizens real bridge/collector/action acceptance passed.', flush=True)
                    return
                suite = launch(['npm', '--prefix', 'tests/browser', 'test', '--', '--project=desktop', '--project=phone', '--workers=1', '--retries=0', '--reporter=json'])
                suite.wait(timeout=600)
                report = json.loads((directory / 'report.json').read_text())
                stats = report['stats']
                print(f"Browser gate: {stats['expected']} passed, {stats['unexpected']} failed, {stats['skipped']} skipped, {stats['flaky']} flaky.", flush=True)

                def captured_output(suites):
                    for item in suites:
                        for spec in item.get('specs', []):
                            for case in spec['tests']:
                                for result in case.get('results', []):
                                    for output in result.get('stdout', []):
                                        print(output.get('text', ''), end='', flush=True)
                        captured_output(item.get('suites', []))
                captured_output(report['suites'])

                def failures(suites):
                    for item in suites:
                        for spec in item.get('specs', []):
                            for test in spec['tests']:
                                if test['status'] != 'expected':
                                    print(f"{test['projectName']}: {spec['title']}: {test['status']}", flush=True)
                                    for result in test['results']:
                                        for error in result.get('errors', []):
                                            for line in error.get('message', '').splitlines():
                                                print(line, flush=True)
                        failures(item.get('suites', []))
                failures(report['suites'])
                if suite.returncode or not stats['expected'] or stats['unexpected'] or stats['skipped'] or stats['flaky'] or report.get('errors'):
                    raise RuntimeError('Required browser gate failed.')
            finally:
                for process in reversed(processes):
                    stop(process)
    print('Owned runtime/browser children stopped; private scratch removed.', flush=True)


def interrupted(*_):
    raise KeyboardInterrupt


if __name__ == '__main__':
    signal.signal(signal.SIGTERM, interrupted)
    signal.signal(signal.SIGINT, interrupted)
    try:
        main()
    except (Exception, KeyboardInterrupt) as error:
        print(str(error) or 'Browser gate interrupted.', file=sys.stderr)
        print('Owned runtime/browser children stopped; private scratch removed.', file=sys.stderr)
        sys.exit(1)
