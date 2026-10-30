#!/usr/bin/env python3
"""Required Tailscale browser gate against an HTTPS emulated-Serve fixture.

This fixture does not use Tailscale or claim to verify real tailnet identity.
Caddy strips client identity headers and supplies a synthetic identity only to
requests carrying a private, randomly generated fixture credential.
"""
import base64
import grp
import json
import os
from pathlib import Path
import pwd
import secrets
import signal
import ssl
import sqlite3
import subprocess
import sys
import tempfile
import time
import urllib.request

from runtime import ROOT, caddy_binary, interrupted, port, stop


def main():
    if len(sys.argv) != 1:
        raise RuntimeError('bin/tailscale-browser-check runs the complete desktop and phone gate without filters.')
    os.umask(0o077)
    processes = []
    phase = 'setup'
    with tempfile.TemporaryDirectory(prefix='nebuless-browser-', dir='/tmp') as scratch:
        directory = Path(scratch)
        directory.chmod(0o700)
        env = {key: os.environ[key] for key in ('PATH', 'HOME', 'LANG', 'PLAYWRIGHT_BROWSERS_PATH', 'BROWSER_TAILSCALE_SCREENSHOTS') if key in os.environ}
        https_port, fpm_port = port(), port()
        origin = f'https://127.0.0.1:{https_port}'
        credential = secrets.token_hex(32)
        env.update({
            'APP_ENV': 'testing', 'APP_DEBUG': 'false', 'APP_URL': origin,
            'APP_KEY': 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode(),
            'NEBULESS_AUTH_MODE': 'tailscale',
            'NEBULESS_TAILSCALE_BINARY': str(directory / 'fixture-whois'),
            'DB_CONNECTION': 'sqlite', 'DB_DATABASE': str(directory / 'hub.sqlite'),
            'SESSION_DRIVER': 'file', 'SESSION_SECURE_COOKIE': 'true', 'SESSION_COOKIE': 'nebuless_tailscale_browser',
            'CACHE_STORE': 'database', 'LOG_CHANNEL': 'stderr', 'MAIL_MAILER': 'array',
            'LARAVEL_STORAGE_PATH': str(directory / 'storage'), 'VIEW_COMPILED_PATH': str(directory / 'storage/framework/views'),
            'APP_CONFIG_CACHE': str(directory / 'config.php'), 'APP_ROUTES_CACHE': str(directory / 'routes.php'),
            'APP_EVENTS_CACHE': str(directory / 'events.php'),
            'AUTH_DATABASE': str(directory / 'auth.sqlite'),
            'NEBULESS_HUB_NAME': 'Primary-Hub', 'NEBULESS_HUB_ROLE': 'primary',
            'NEBULESS_ACTION_KEY_ID': 'synthetic-browser-only', 'NEBULESS_ACTION_PRIVATE_KEY_FILE': str(directory / 'signing.pem'),
            'LD_LIBRARY_PATH': str(ROOT / 'tools/runtime/lib'), 'BROWSER_SCRATCH': scratch,
            'BROWSER_BASE_URL': origin, 'BROWSER_TAILSCALE_GATE': '1', 'BROWSER_RELEASE_GATE': '1',
            'BROWSER_TAILSCALE_CREDENTIAL': credential,
            'BROWSER_OUTPUT_DIR': str(directory / 'test-results'), 'PLAYWRIGHT_JSON_OUTPUT_NAME': str(directory / 'report.json'),
            'PW_EXPERIMENTAL_SERVICE_WORKER_NETWORK_EVENTS': '1',
            'XDG_CONFIG_HOME': str(directory / 'config'), 'XDG_DATA_HOME': str(directory / 'data'),
            'TMPDIR': str(directory / 'tmp'),
        })
        for child in ('storage/framework/views', 'storage/framework/sessions', 'storage/framework/cache/data', 'storage/logs', 'tmp'):
            (directory / child).mkdir(parents=True, exist_ok=True)
        (directory / 'hub.sqlite').touch(mode=0o600)
        # Disposable daemon stand-in: only this fixture source is recognized.
        (directory / 'fixture-whois').write_text('#!/usr/bin/env python3\nimport json,sys\n'
            'if sys.argv[1:] != ["whois", "--json", "100.80.0.2"]: sys.exit(1)\n'
            'print(json.dumps({"Node":{"StableID":"nBrowserDevice","Tags":["tag:phone"],"Addresses":["100.80.0.2/32"]}}))\n')
        (directory / 'fixture-whois').chmod(0o700)
        php = str(ROOT / 'bin/php')
        artisan = [php, str(ROOT / 'apps/hub/artisan')]
        with (directory / 'runtime.log').open('w') as log:
            def launch(command):
                process = subprocess.Popen(command, cwd=ROOT, env=env, stdin=subprocess.DEVNULL,
                                           stdout=log, stderr=log, start_new_session=True)
                processes.append(process)
                return process

            def run(command, timeout=120):
                process = launch(command)
                process.wait(timeout=timeout)
                if process.returncode:
                    raise RuntimeError(f'{phase} failed (private logs removed during cleanup).')

            try:
                caddy = caddy_binary(env, log, processes)
                phase = 'migration and synthetic fleet seed'
                run(artisan + ['migrate', '--force'])
                run(artisan + ['fleet:configure-hub', '--id=11111111-1111-4111-8111-111111111111', '--name=Primary-Hub', '--role=primary'])
                run([php, '-r', '$k=openssl_pkey_new(["private_key_type"=>OPENSSL_KEYTYPE_ED25519]);openssl_pkey_export($k,$pem);file_put_contents(getenv("NEBULESS_ACTION_PRIVATE_KEY_FILE"),$pem);'])
                run([php, str(ROOT / 'tests/browser/seed.php')])
                phase = 'private loopback HTTPS certificate'
                run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
                     '-keyout', str(directory / 'tls.key'), '-out', str(directory / 'tls.crt'),
                     '-subj', '/CN=127.0.0.1', '-addext', 'subjectAltName=IP:127.0.0.1'])
                user = pwd.getpwuid(os.getuid()).pw_name
                group = grp.getgrgid(os.getgid()).gr_name
                (directory / 'fpm.conf').write_text(f'[global]\ndaemonize=no\nerror_log={scratch}/fpm.log\n[app]\nlisten=127.0.0.1:{fpm_port}\nuser={user}\ngroup={group}\npm=static\npm.max_children=2\nclear_env=no\ncatch_workers_output=yes\n')
                # The private request header exists only in this disposable edge.
                # No credential or identity injection hook is added to the app.
                (directory / 'Caddyfile').write_text(f'''{{
 admin off
 auto_https off
}}
{origin} {{
 bind 127.0.0.1
 tls {scratch}/tls.crt {scratch}/tls.key
 root * {ROOT}/apps/hub/public
 @approved header X-Nebuless-Fixture-Access {credential}
 @device {{
  header X-Nebuless-Fixture-Access {credential}
  header X-Nebuless-Fixture-Device true
 }}
 route {{
  request_header -X-Forwarded-For
  request_header -Tailscale-User-Login
  request_header -Tailscale-User-Name
  request_header @approved Tailscale-User-Login browser-owner@example.test
  request_header @device -Tailscale-User-Login
  request_header @device X-Forwarded-For 100.80.0.2
  request_header -X-Nebuless-Fixture-Access
  php_fastcgi 127.0.0.1:{fpm_port} {{
   env NEBULESS_TAILSCALE_INGRESS 1
   env NEBULESS_TAILSCALE_SOURCE {{http.request.header.X-Forwarded-For}}
   env HTTPS on
   env SERVER_PORT {https_port}
  }}
  file_server
 }}
}}
''')
                phase = 'HTTPS runtime readiness'
                runtime = [launch([str(ROOT / 'tools/runtime/sbin/php-fpm'), '--allow-to-run-as-root', '-y', str(directory / 'fpm.conf')]),
                           launch([caddy, 'run', '--config', str(directory / 'Caddyfile'), '--adapter', 'caddyfile'])]
                trust = ssl.create_default_context(cafile=str(directory / 'tls.crt'))
                deadline = time.monotonic() + 30
                while True:
                    if any(process.poll() is not None for process in runtime):
                        raise RuntimeError('Emulated-Serve runtime exited before readiness.')
                    try:
                        with urllib.request.urlopen(origin + '/login', context=trust, timeout=1) as response:
                            if response.status == 200:
                                break
                    except OSError:
                        if time.monotonic() >= deadline:
                            raise RuntimeError('Emulated-Serve readiness timed out.') from None
                        time.sleep(.1)
                phase = 'desktop/phone Tailscale browser tests'
                suite = launch(['npm', '--prefix', 'tests/browser', 'test', '--', '--project=desktop', '--project=phone', '--workers=1', '--retries=0', '--reporter=json'])
                suite.wait(timeout=600)
                report = json.loads((directory / 'report.json').read_text())
                stats = report['stats']
                print(f"Emulated-Serve browser gate: {stats['expected']} passed, {stats['unexpected']} failed, {stats['skipped']} skipped, {stats['flaky']} flaky.", flush=True)

                def failures(suites):
                    for item in suites:
                        for spec in item.get('specs', []):
                            for test in spec['tests']:
                                if test['status'] != 'expected':
                                    print(f"{test['projectName']}: {spec['title']}: {test['status']}", flush=True)
                                    for result in test['results']:
                                        for error in result.get('errors', []):
                                            print(error.get('message', ''), flush=True)
                        failures(item.get('suites', []))
                failures(report['suites'])
                if suite.returncode or not stats['expected'] or stats['unexpected'] or stats['skipped'] or stats['flaky'] or report.get('errors'):
                    raise RuntimeError('Required Tailscale browser gate failed.')
                with sqlite3.connect(f'file:{directory / "hub.sqlite"}?mode=ro', uri=True) as database:
                    actions = database.execute('SELECT state FROM reboot_actions').fetchall()
                    proofs = database.execute('SELECT consumed_at FROM action_confirmation_proofs').fetchall()
                    users = database.execute('SELECT auth_provider, owner_slot FROM users').fetchall()
                if actions != [('canceled',), ('canceled',)] or len(proofs) != 2 or any(row[0] is None for row in proofs) or users != [('tailscale', None), ('tailscale', None)]:
                    raise RuntimeError('Expected exactly one canceled synthetic action and consumed proof per project, with no provisioned owner.')
                print('Private fixture: two canceled actions, two consumed proofs, no password/TOTP owner provisioned.', flush=True)
            finally:
                for process in reversed(processes):
                    stop(process)
    print('Owned emulated-Serve/browser children stopped; private scratch removed.', flush=True)


if __name__ == '__main__':
    signal.signal(signal.SIGTERM, interrupted)
    signal.signal(signal.SIGINT, interrupted)
    try:
        main()
    except (Exception, KeyboardInterrupt) as error:
        print(str(error) or 'Tailscale browser gate interrupted.', file=sys.stderr)
        print('Owned emulated-Serve/browser children stopped; private scratch removed.', file=sys.stderr)
        sys.exit(1)
