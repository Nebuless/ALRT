#!/usr/bin/env python3
"""Build immutable releases and switch code without replacing persistent authority."""
import argparse
import base64
import datetime
import sqlite3
import fcntl
import gzip
import hashlib
import http.cookies
import io
import json
import os
from pathlib import Path
import re
import runpy
import secrets
import shutil
import socket
import ssl
import stat
import subprocess
import sys
import tarfile
import tempfile
import time
import urllib.parse
import urllib.request

SERVICES = ('auth', 'fpm', 'http', 'urgent', 'maintenance', 'scheduler', 'collector')


def sha256_file(path):
    digest = hashlib.sha256()
    with open(path, 'rb') as file:
        while chunk := file.read(1024*1024):
            digest.update(chunk)
    return digest.hexdigest()


def write_json(path, value):
    path = Path(path)
    write_private_bytes(path, (json.dumps(value, indent=2)+'\n').encode())


def read_json(path):
    return json.loads(Path(path).read_text())


def write_private_bytes(path, content):
    descriptor, name = tempfile.mkstemp(prefix='.'+path.name+'.new-', dir=path.parent)
    temporary = Path(name)
    try:
        with os.fdopen(descriptor, 'wb') as file:
            file.write(content)
            file.flush()
            os.fsync(file.fileno())
        os.replace(temporary, path)
        descriptor = os.open(path.parent, os.O_RDONLY)
        try:
            os.fsync(descriptor)
        finally:
            os.close(descriptor)
    finally:
        temporary.unlink(missing_ok=True)


def private_environment(base):
    if base.resolve() != base:
        raise ValueError('Private environment parent invalid')
    for path in (base, base/'state'):
        info = path.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.getuid() or info.st_mode & 0o077:
            raise ValueError('Private environment parent invalid')
    paths = [base/'state/env.json', base/'state/hub.env']
    for path in paths:
        info = path.lstat()
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid() or info.st_nlink != 1 or stat.S_IMODE(info.st_mode) != 0o600:
            raise ValueError('Private environment permissions invalid')
    original = {path: path.read_bytes() for path in paths}
    env = json.loads(original[paths[0]])
    lines = [line.split('=', 1) for line in original[paths[1]].decode().splitlines() if line]
    if len(lines) != len({line[0] for line in lines}) or {key:json.loads(value) for key,value in lines} != env:
        raise ValueError('Private environment files disagree')
    if not isinstance(env, dict) or any(not isinstance(value, str) for value in env.values()):
        raise ValueError('Private environment values invalid')
    return original, env


def restore_environment(original):
    errors = []
    for path, content in reversed(list(original.items())):
        try:
            write_private_bytes(path, content)
        except OSError as error:
            errors.append(error)
    if errors:
        raise OSError('Private environment rollback failed') from errors[0]


def write_environment(original, changed):
    paths = list(original)
    try:
        write_private_bytes(paths[0], (json.dumps(changed, indent=2)+'\n').encode())
        write_private_bytes(paths[1], ''.join(key+'='+json.dumps(value)+'\n' for key,value in changed.items()).encode())
    except BaseException:
        restore_environment(original)
        raise


def logging_transition(base):
    original, env = private_environment(base)
    effective = {**os.environ, **env}
    for key in ('PHP_VALUE', 'PHP_ADMIN_VALUE', 'PHP_INI_SCAN_DIR'):
        if effective.get(key):
            raise ValueError('Unsupported PHP environment override')
    if effective.get('LOG_STDERR_FORMATTER'):
        raise ValueError('Unsupported stderr formatter')
    changed = dict(env)
    supported = {'single', 'daily', 'monthly', 'stderr', 'errorlog', 'null'}
    for key, default in (('LOG_CHANNEL','stderr'), ('LOG_STACK','stderr'), ('LOG_DEPRECATIONS_CHANNEL','null')):
        value = effective.get(key, default)
        channels = value.split(',') if key == 'LOG_STACK' else [value]
        if any(channel not in supported | ({'stack'} if key != 'LOG_STACK' else set()) for channel in channels):
            raise ValueError('Unsupported logging channel')
        target = 'null' if key == 'LOG_DEPRECATIONS_CHANNEL' and value == 'null' else 'stderr'
        if key == 'LOG_CHANNEL' or key in effective:
            changed[key] = target
    return original, changed if changed != env else None


def change_logging(base):
    original, changed = logging_transition(base)
    if changed is not None:
        write_environment(original, changed)
    return original



def change_authentication(base, mode):
    if mode not in ('tailscale', 'better-auth'): raise ValueError('Invalid authentication mode')
    original, env = private_environment(base)
    write_environment(original, {**env, 'NEBULESS_AUTH_MODE':mode})
    return original


def legacy_diagnostics(base):
    # Fixed paths only. Validation is read-only and completes before any unlink.
    if base.resolve() != base:
        raise ValueError('Legacy diagnostic parent invalid')
    for parent in (base, base/'state', base/'state/storage', base/'state/storage/logs', base/'state/logs'):
        info = parent.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.getuid() or info.st_mode & 0o077:
            raise ValueError('Legacy diagnostic parent invalid')
    allowed = {'laravel.log'}
    if any(path.name not in allowed for path in (base/'state/storage/logs').iterdir()):
        raise ValueError('Unexpected Laravel diagnostic file')
    if any(path.name.startswith('fpm-error') and path.name != 'fpm-error.log' for path in (base/'state/logs').iterdir()):
        raise ValueError('Unexpected FPM diagnostic file')
    result = []
    for path in (base/'state/storage/logs/laravel.log', base/'state/logs/fpm-error.log'):
        try:
            info = path.lstat()
        except FileNotFoundError:
            continue
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid() or info.st_nlink != 1 or info.st_mode & 0o077:
            raise ValueError('Legacy diagnostic file invalid')
        result.append((path, info.st_dev, info.st_ino))
    return result


def dispose_legacy_diagnostics(base, *, quiesced=False):
    # Fixture-rehearsed primitive, deliberately NOT called by install/release.
    # Installed deletion requires the separate, still-pending owner decision.
    if not quiesced:
        raise ValueError('Legacy diagnostic writers must be stopped')
    paths = legacy_diagnostics(base)
    for path, device, inode in paths:
        info = path.lstat()
        if (info.st_dev, info.st_ino) != (device, inode) or info.st_nlink != 1:
            raise ValueError('Legacy diagnostic file changed')
        path.unlink()


def initialize(base, config):
    base = Path(base).absolute()
    if (base/'state/env.json').exists():
        raise ValueError('Installation already initialized; use release for upgrades')
    origin = urllib.parse.urlparse(config['origin'])
    if origin.scheme != 'https' or not origin.hostname or origin.path or origin.username or origin.query or origin.fragment:
        raise ValueError('An HTTPS origin is required')
    if config['name'] not in ('Alpha-Hub', 'Beta-Restricted', 'Primary-Hub') or config['role'] != ('primary' if config['name'] == 'Primary-Hub' else 'standby'):
        raise ValueError('Invalid fleet identity')
    if config.get('auth_mode','tailscale') not in ('tailscale','better-auth'):
        raise ValueError('Invalid authentication mode')
    # Reject prepared trees before generating or writing any authority material.
    for parent in base.parents:
        if not parent.exists():
            continue
        info = parent.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid not in {0, os.getuid()} or (info.st_mode & 0o022 and not (info.st_uid == 0 and info.st_mode & stat.S_ISVTX)):
            raise ValueError('Unsafe installation parent')
    if base.is_symlink():
        raise ValueError('Unsafe installation directory')
    if base.exists():
        info = base.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.getuid() or info.st_mode & 0o077:
            raise ValueError('Unsafe installation directory')
        entries = list(base.iterdir())
        if entries and entries != [base/'runtime']:
            raise ValueError('Installation directory must be empty except for its private runtime')
        if entries and (base/'runtime').is_symlink():
            raise ValueError('Unsafe preinstalled runtime')
        for directory, subdirectories, files in os.walk(base/'runtime', followlinks=False):
            for path in [Path(directory), *[Path(directory)/name for name in subdirectories+files]]:
                item = path.lstat()
                if item.st_uid != os.getuid() or item.st_mode & 0o022 or not (stat.S_ISDIR(item.st_mode) or stat.S_ISREG(item.st_mode)):
                    raise ValueError('Unsafe preinstalled runtime')
    os.umask(0o077)
    for directory in ('releases', 'state/storage/framework/cache/data', 'state/storage/framework/sessions', 'state/storage/framework/views', 'state/storage/logs', 'state/cache', 'state/journal', 'state/actions', 'state/logs', 'run', 'runtime'):
        (base/directory).mkdir(parents=True, exist_ok=True, mode=0o700)
    config = {**{'backend_port': 18444, 'fpm_port': 19444, 'auth_port': 19445, 'proxy_port': 18445, 'proxy': False, 'php_ini': '', 'ca': '/etc/ssl/certs/ca-certificates.crt', 'ld_library_path': '', 'tailscale': '/usr/bin/tailscale', 'python': sys.executable}, **config}
    write_json(base/'state/runtime.json', config)
    env = {'APP_NAME': 'Nebuless', 'APP_ENV': 'production', 'APP_DEBUG': 'false', 'APP_KEY': 'base64:'+base64.b64encode(secrets.token_bytes(32)).decode(), 'APP_URL': config['origin'], 'LOG_CHANNEL': 'stderr', 'LOG_LEVEL': 'warning', 'DB_CONNECTION': 'sqlite', 'DB_DATABASE': str(base/'state/hub.sqlite'), 'SESSION_DRIVER': 'file', 'SESSION_SECURE_COOKIE': 'true', 'SESSION_HTTP_ONLY': 'true', 'SESSION_SAME_SITE': 'lax', 'SESSION_COOKIE': 'nebuless_session', 'CACHE_STORE': 'database', 'QUEUE_CONNECTION': 'database', 'AUTH_DATABASE': str(base/'state/auth.sqlite'), 'AUTH_SECRET': secrets.token_hex(32), 'AUTH_BRIDGE_KEY': secrets.token_hex(32), 'AUTH_ORIGIN': config['origin'], 'AUTH_NODE': config['node'], 'AUTH_PORT': str(config['auth_port']), 'AUTH_BRIDGE_URL': f"http://127.0.0.1:{config['auth_port']}", 'NEBULESS_HUB_NAME': config['name'], 'NEBULESS_HUB_ROLE': config['role'], 'NEBULESS_COLLECTOR_CONFIG': str(base/'state/collector.json')}
    env['NEBULESS_AUTH_MODE'] = config.get('auth_mode','tailscale')
    write_json(base/'state/env.json', env)
    write_private_bytes(base/'state/hub.env', ''.join(k+'='+json.dumps(v)+'\n' for k, v in env.items()).encode())
    with open(base/'state/hub.sqlite', 'xb'):
        pass
    change_logging(base)
    return base


def artifact(source, output, version):
    source, output = Path(source), Path(output)
    if not re.fullmatch('[a-f0-9]{40}', version): raise ValueError('Use an exact source revision')
    selected = ['contracts/telemetry-v1.schema.json', 'contracts/delivery-v1.schema.json', 'apps/hub/app', 'apps/hub/bootstrap/app.php', 'apps/hub/bootstrap/providers.php', 'apps/hub/config', 'apps/hub/database/migrations', 'apps/hub/resources', 'apps/hub/routes', 'apps/hub/vendor', 'apps/hub/public', 'apps/hub/artisan', 'apps/hub/composer.json', 'apps/hub/composer.lock', 'apps/auth/src', 'apps/auth/node_modules', 'apps/auth/package.json', 'apps/auth/package-lock.json', 'deploy', 'tools/runtime-probe.php', 'tools/node-runtime-probe.mjs', 'bin/php']
    files = []
    for name in selected:
        path = source/name
        if not path.exists(): raise ValueError('Missing build input '+name)
        files.extend([path] if path.is_file() else sorted(p for p in path.rglob('*') if p.is_file() and not p.is_symlink()))
    files = sorted(set(files))
    with tempfile.TemporaryDirectory(prefix='nebuless-build-') as temp:
        binaries = [Path(temp)/name for name in ('nebu-alrt','nebuless-reboot-helper')]
        for binary in binaries:
            subprocess.run(['go', 'build', '-buildvcs=false', '-trimpath', '-ldflags=-s -w -buildid=', '-o', str(binary), './cmd/'+binary.name], cwd=source/'collector', env={**os.environ, 'CGO_ENABLED': '0'}, check=True)
        manifest = {'version': version, 'schema_epoch': 1, 'files': {}}
        with open(output, 'wb') as raw, gzip.GzipFile(fileobj=raw, mode='wb', filename='', mtime=0, compresslevel=1) as zipped, tarfile.open(fileobj=zipped, mode='w') as tar:
            for path in [*files, *binaries]:
                name = 'bin/'+path.name if path in binaries else path.relative_to(source).as_posix()
                if name.startswith('apps/hub/vendor/pestphp/pest/.temp/'):
                    continue
                if any(part in ('.env', '.context', '__pycache__', '.git') for part in Path(name).parts) or name.endswith(('.sqlite', '.pyc')) or name == 'apps/hub/public/hot': continue
                data = path.read_bytes()
                info = tarfile.TarInfo(name)
                info.size, info.mode = len(data), 0o555 if os.access(path, os.X_OK) else 0o444
                tar.addfile(info, io.BytesIO(data))
                manifest['files'][name] = hashlib.sha256(data).hexdigest()
            data = json.dumps(manifest, sort_keys=True).encode()
            info = tarfile.TarInfo('release.json'); info.size, info.mode = len(data), 0o444
            tar.addfile(info, io.BytesIO(data))
    return manifest


def stage(base, archive):
    base = Path(base).absolute()
    with tarfile.open(archive, 'r:gz') as tar:
        members = tar.getmembers()
        names = [item.name for item in members]
        if len(names) != len(set(names)) or any(not item.isfile() or Path(item.name).is_absolute() or '..' in Path(item.name).parts or item.name != Path(item.name).as_posix() for item in members):
            raise ValueError('Invalid release archive')
        manifest_file = tar.extractfile('release.json')
        if manifest_file is None: raise ValueError('Missing release manifest')
        manifest = json.load(manifest_file)
        version = manifest['version']
        if not re.fullmatch('[a-f0-9]{40}', version) or manifest['schema_epoch'] != 1 or set(names) != set(manifest['files']) | {'release.json'}: raise ValueError('Invalid release manifest')
        destination = base/'releases'/version
        if destination.exists():
            if read_json(destination/'release.json') != manifest: raise ValueError('Immutable release differs')
            if any(hashlib.sha256((destination/name).read_bytes()).hexdigest() != digest for name,digest in manifest['files'].items()): raise ValueError('Installed immutable release failed verification')
            return destination
        temporary = Path(tempfile.mkdtemp(prefix='.stage-', dir=base/'releases'))
        try:
            for item in members:
                member = tar.extractfile(item)
                if member is None: raise ValueError('Invalid release member')
                data = member.read()
                if item.name != 'release.json' and hashlib.sha256(data).hexdigest() != manifest['files'][item.name]: raise ValueError('Release digest mismatch')
                path = temporary/item.name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(data); path.chmod(item.mode & 0o555)
            cache = base/'state/cache'/version
            cache.mkdir(mode=0o700, exist_ok=True)
            hub = temporary/'apps/hub'
            (hub/'storage').symlink_to(base/'state/storage')
            (hub/'bootstrap/cache').symlink_to(cache)
            (hub/'.env').symlink_to(base/'state/hub.env')
            for directory in sorted((p for p in temporary.rglob('*') if p.is_dir() and not p.is_symlink()), key=lambda p: len(p.parts), reverse=True): directory.chmod(0o555)
            temporary.chmod(0o555)
            os.rename(temporary, destination)
        except Exception:
            for directory, _, _ in os.walk(temporary): os.chmod(directory, 0o700)
            shutil.rmtree(temporary)
            raise
    return destination


def environment(base, release, service=None):
    config = read_json(base/'state/runtime.json')
    env = {**os.environ, **read_json(base/'state/env.json'), 'APP_BASE_PATH': str(release/'apps/hub')}
    env.pop('NEBULESS_REBOOT_CREDENTIAL', None)
    if service == 'collector' and 'NEBULESS_REBOOT_CREDENTIAL' in os.environ:
        env['NEBULESS_REBOOT_CREDENTIAL'] = os.environ['NEBULESS_REBOOT_CREDENTIAL']
    if service == 'collector' and os.environ.get('CREDENTIALS_DIRECTORY'):
        credential = Path(os.environ['CREDENTIALS_DIRECTORY'])/'nebuless-reboot'
        if credential.is_file():
            raw = credential.read_bytes()
            if len(raw) < 32 or len(raw) > 1024: raise ValueError('Native credential unavailable')
            env['NEBULESS_REBOOT_CREDENTIAL'] = raw.decode('utf-8')
    for key in ('PHP_INI_SCAN_DIR', 'PHP_VALUE', 'PHP_ADMIN_VALUE'):
        env.pop(key, None)
    if config['php_ini']: env['PHPRC'] = config['php_ini']
    # PHP_BINARY descendants inherit native scan settings, not parent -d options.
    directory = base/'state/php-conf.d'
    directory.mkdir(mode=0o700, exist_ok=True)
    info = directory.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.getuid() or info.st_mode & 0o077:
        raise ValueError('Private PHP scan directory invalid')
    profile = directory/'zz-nebuless.ini'
    if any(path != profile for path in directory.iterdir()):
        raise ValueError('Unexpected private PHP scan file')
    if profile.is_symlink() or profile.exists() and (not profile.is_file() or profile.stat().st_nlink != 1 or profile.stat().st_uid != os.getuid() or stat.S_IMODE(profile.stat().st_mode) != 0o600):
        raise ValueError('Private PHP scan profile invalid')
    content = b'log_errors=1\ndisplay_errors=0\nerror_log=/proc/self/fd/2\n'
    if not profile.exists() or profile.read_bytes() != content:
        write_private_bytes(profile, content)
    env['PHP_INI_SCAN_DIR'] = os.pathsep+str(directory)
    if config['ld_library_path']: env['LD_LIBRARY_PATH'] = config['ld_library_path']
    env['SSL_CERT_FILE'] = config['ca']
    return env


def php_command(base):
    config = read_json(base/'state/runtime.json')
    return [config['php'], *(['-c', config['php_ini']] if config['php_ini'] else []),
            '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'error_log=/proc/self/fd/2']


def artisan(base, release, args, capture=False):
    return subprocess.run([*php_command(base), str(release/'apps/hub/artisan'), *args], cwd=release/'apps/hub', env=environment(base, release), check=True, capture_output=capture, text=True)


def migrate(base, release):
    with sqlite3.connect('file:'+str(base/'state/hub.sqlite')+'?mode=ro', uri=True) as database:
        tables = {row[0] for row in database.execute("SELECT name FROM sqlite_master WHERE type='table'")}
        if 'users' in tables and database.execute('SELECT EXISTS(SELECT 1 FROM users)').fetchone()[0]:
            converted = 'migrations' in tables and database.execute('SELECT EXISTS(SELECT 1 FROM migrations WHERE migration=?)', ('2026_10_15_000013_replace_owner_auth_provider',)).fetchone()[0]
            if not converted: raise ValueError('auth_conversion_plan_required')
    config = read_json(base/'state/runtime.json')
    subprocess.run([*php_command(base), str(release/'tools/runtime-probe.php')], env=environment(base, release), check=True, stdout=subprocess.DEVNULL)
    subprocess.run([config['node'], str(release/'tools/node-runtime-probe.mjs')], env=environment(base, release), check=True, stdout=subprocess.DEVNULL)
    # Forward migrations only. Prior-code compatibility needs an explicit rehearsal;
    # schema_epoch alone cannot prove it (notably owner conversion 000013).
    artisan(base, release, ['migrate', '--force', '--no-interaction'])


def switch(base, target):
    link = base/'current.new'
    link.unlink(missing_ok=True)
    link.symlink_to(target)
    os.replace(link, base/'current')
    fd = os.open(base, os.O_RDONLY)
    try: os.fsync(fd)
    finally: os.close(fd)


def configured_services(base):
    config = read_json(base/'state/runtime.json')
    return [*SERVICES[:-1], *(['collector'] if (base/'state/collector.json').exists() else []), *(['proxy'] if config['proxy'] else [])]


def unit_definitions(base, release):
    config = read_json(base/'state/runtime.json')
    template = (release/'deploy/systemd-user/nebuless.service').read_text()
    return {name: template.replace('@SERVICE@',name).replace('@BASE@',str(base)).replace('@PYTHON@',config.get('python',sys.executable)) for name in configured_services(base)}


def refresh(base, previous=None):
    release = (base/'current').resolve()
    config = read_json(base/'state/runtime.json')
    if config['manager'] == 'systemd':
        desired = unit_definitions(base, release)
        prior = unit_definitions(base, previous) if previous else desired
        units = Path.home()/'.config/systemd/user'
        for name, content in desired.items():
            unit = units/('nebuless-'+name+'.service')
            if unit.exists() and unit.read_text() not in (content,prior[name]):
                raise ValueError('Refusing to replace a different unit')
        units.mkdir(parents=True,exist_ok=True)
        for name, content in desired.items():
            (units/('nebuless-'+name+'.service')).write_text(content)
    # Prior installers retain configure(base); render owned units before that call.
    installer = runpy.run_path(str(release/'deploy/install'))
    installer['start'](base, installer['configure'](base))


def service_command(base, operation, services):
    config = read_json(base/'state/runtime.json')
    if config['manager'] == 'supervisor':
        command = ['supervisorctl', '-c', str(base/'supervisor.conf'), operation, *['nebuless-'+s for s in services]]
    elif config['manager'] == 'systemd':
        command = ['systemctl', '--user', operation, *['nebuless-'+s+'.service' for s in services]]
    else: raise ValueError('Unsupported service manager')
    return command


def restart(base):
    subprocess.run(service_command(base, 'restart', configured_services(base)), check=True, stdout=subprocess.DEVNULL)


def quiesce(base):
    # Leave the collector journal/fanout and userspace CONNECT transport available.
    subprocess.run(service_command(base, 'stop', ['scheduler','urgent','maintenance','http','fpm','auth']), check=True, stdout=subprocess.DEVNULL)


def service_running(base, service):
    config = read_json(base/'state/runtime.json')
    operation = 'status' if config['manager'] == 'supervisor' else 'is-active'
    result = subprocess.run(service_command(base, operation, [service]), capture_output=True, text=True, timeout=5)
    if result.returncode != 0: return False
    fields = result.stdout.split()
    return (len(fields) >= 2 and fields[:2] == ['nebuless-'+service, 'RUNNING']) if config['manager'] == 'supervisor' else fields == ['active']


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs): return None


def require_healthy_peer(base):
    collector = read_json(base/'state/collector.json')
    config = read_json(base/'state/runtime.json')
    with sqlite3.connect('file:'+str(base/'state/hub.sqlite')+'?mode=ro', uri=True) as db:
        local_id = db.execute('SELECT hub_uuid FROM hub_identities WHERE id=1').fetchone()[0]
    destinations = collector['destinations']
    if len(destinations) != 3 or len({peer['id'] for peer in destinations}) != 3 or len({peer['url'] for peer in destinations}) != 3:
        raise RuntimeError('fleet_peer_configuration_invalid')
    if not any(peer['id'] == local_id and peer['url'] == config['origin'] for peer in destinations):
        raise RuntimeError('fleet_local_identity_mismatch')
    for peer in destinations:
        if peer['id'] == local_id or peer['url'] == config['origin']: continue
        try:
            origin = urllib.parse.urlsplit(peer['url'])
            if origin.scheme != 'https' or not origin.hostname or origin.username or origin.password or origin.query or origin.fragment or origin.path:
                raise ValueError('Peer origin invalid')
            proxy = peer.get('proxy_url')
            opener = urllib.request.build_opener(urllib.request.ProxyHandler({'https':proxy} if proxy else {}), NoRedirect(), urllib.request.HTTPSHandler(context=ssl.create_default_context(cafile=config['ca'])))
            machine = urllib.parse.quote(collector['machine_id'], safe='')
            request = urllib.request.Request(peer['url']+'/api/v1/collector/'+machine+'/hub-health', headers={'Authorization':'Bearer '+peer['token'], 'Accept':'application/json'})
            with opener.open(request, timeout=5) as response:
                if response.status != 200: raise ValueError('Peer unavailable')
                body = json.loads(response.read(64*1024))
            if body['hub_id'] != peer['id']: raise ValueError('Peer identity mismatch')
            observed = datetime.datetime.fromisoformat(body['observed_at'].replace('Z','+00:00'))
            age = (datetime.datetime.now(datetime.timezone.utc)-observed).total_seconds()
            if age < -5 or age > 15: raise ValueError('Peer observation stale')
            for service in ('scheduler','urgent_worker'):
                age = body['heartbeats'][service]
                if type(age) not in (int,float) or not 0 <= age < 45: raise ValueError('Peer heartbeat stale')
            return
        except Exception:
            continue  # A later enrolled peer can still preserve observation.
    raise RuntimeError('fleet_healthy_peer_unavailable')


def health(base, after=None):
    config = read_json(base/'state/runtime.json')
    env = read_json(base/'state/env.json')
    auth_mode = env.get('NEBULESS_AUTH_MODE','better-auth')
    if auth_mode not in ('tailscale','better-auth'): raise ValueError('Invalid authentication mode')
    proxy = f"http://127.0.0.1:{config['proxy_port']}" if config['proxy'] else ''
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({'https': proxy} if proxy else {}), NoRedirect())
    auth_opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
    last = None
    deadline = time.monotonic()+90
    while time.monotonic() < deadline:
        try:
            with opener.open(config['origin']+'/login', timeout=max(0.1,min(8,deadline-time.monotonic()))) as response:
                text = response.read(512*1024).decode()
                cookies = response.headers.get_all('Set-Cookie', [])
                expected = config['origin']+'/login'
                if response.status != 200 or expected not in text or not cookies: raise ValueError('HTTPS URL generation failed')
                if auth_mode == 'tailscale' and 'data-auth-mode="tailscale"' not in text: raise ValueError('Tailscale entry page unavailable')
                for header in cookies:
                    cookie = http.cookies.SimpleCookie(); cookie.load(header)
                    for value in cookie.values():
                        if not value['secure'] or value['domain'] or value['samesite'].lower() != 'lax': raise ValueError('Insecure or shared cookie')
                        if value.key == 'nebuless_session' and not value['httponly']: raise ValueError('Session must be HTTP-only')
            if auth_mode == 'better-auth':
                request = urllib.request.Request(env['AUTH_BRIDGE_URL']+'/session', data=b'{"cookies":{}}', headers={'Authorization': 'Bearer '+env['AUTH_BRIDGE_KEY'], 'Content-Type':'application/json'})
                with auth_opener.open(request, timeout=max(0.1,min(3,deadline-time.monotonic()))) as response:
                    if json.load(response).get('session', 'missing') is not None: raise ValueError('Auth probe failed')
            with sqlite3.connect('file:'+str(base/'state/hub.sqlite')+'?mode=ro', uri=True) as database:
                beats = dict(database.execute('SELECT service,observed_at FROM hub_heartbeats'))
            for service in ('scheduler','urgent_worker'):
                observed = datetime.datetime.fromisoformat(beats.get(service, '').replace('Z','+00:00'))
                age = (datetime.datetime.now(datetime.timezone.utc)-observed).total_seconds()
                if age < -5 or age >= 45 or (after is not None and observed.timestamp() < after): raise ValueError('Background heartbeat stale')
            if not service_running(base, 'maintenance'): raise ValueError('Maintenance worker unavailable')
            collector_config = base/'state/collector.json'
            collector_ready = None
            if collector_config.exists():
                if not service_running(base, 'collector'): raise ValueError('Collector unavailable')
                machine_id = read_json(collector_config)['machine_id']
                with sqlite3.connect('file:'+str(base/'state/hub.sqlite')+'?mode=ro', uri=True) as database:
                    row = database.execute('SELECT last_contact_at FROM telemetry_contacts WHERE machine_id=?', (machine_id,)).fetchone()
                observed = datetime.datetime.fromisoformat(row[0].replace('Z','+00:00'))
                age = (datetime.datetime.now(datetime.timezone.utc)-observed).total_seconds()
                if age < -5 or age > 30 or (after is not None and observed.timestamp() < after): raise ValueError('Local collector contact stale')
                collector_ready = True
            return {'https': True, 'cookies': 'secure host-only', 'urls': 'https', 'auth': True, 'scheduler': True, 'urgent_worker': True, 'maintenance': True, 'collector': collector_ready}
        except Exception as error:
            last = type(error).__name__
            time.sleep(min(1,max(0,deadline-time.monotonic())))
    raise RuntimeError('Private HTTPS/auth gate failed: '+str(last))


def activate(base, release, auth_mode=None):
    base, release = Path(base).absolute(), Path(release).absolute()
    previous = (base/'current').resolve() if (base/'current').exists() else None
    manifest = read_json(release/'release.json')
    if previous and read_json(previous/'release.json')['schema_epoch'] != manifest['schema_epoch']: raise ValueError('Schema-incompatible release requires an explicit migration plan')
    if auth_mode is not None and auth_mode not in ('tailscale','better-auth'): raise ValueError('Invalid authentication mode')
    original_environment, logging_changed = logging_transition(base)
    if legacy_diagnostics(base):
        raise ValueError('legacy_diagnostic_disposition_owner_pending')
    mode_changed = auth_mode is not None and read_json(base/'state/env.json').get('NEBULESS_AUTH_MODE','better-auth') != auth_mode
    fpm_config = base/'state/fpm.conf'
    fpm_sink = 'syslog' if read_json(base/'state/runtime.json')['manager'] == 'systemd' else '/proc/self/fd/2'
    required_fpm = {'error_log = '+fpm_sink, 'catch_workers_output = yes',
                    'php_admin_flag[log_errors] = on', 'php_admin_flag[display_errors] = off',
                    'php_admin_value[error_log] = /proc/self/fd/2'}
    sink_changed = not fpm_config.exists() or not required_fpm.issubset(fpm_config.read_text().splitlines())
    if previous == release and not mode_changed and logging_changed is None and not sink_changed:
        try:
            observed = health(base)
            write_json(base/'deployment.json', {'desired': release.name, 'active': release.name, 'status': 'healthy', 'observed_at': int(time.time()), 'health': observed})
            return
        except Exception:
            pass  # Reconcile a known release whose managed services are unhealthy.
    write_json(base/'deployment.json', {'desired': release.name, 'active': previous.name if previous else None, 'status': 'pending'})
    switched = quiesced = False
    try:
        if previous:
            if (base/'state/collector.json').exists(): require_healthy_peer(base)
            quiesced = True  # A failed multi-service stop may already have stopped some writers.
            quiesce(base)
        if mode_changed: change_authentication(base,auth_mode)
        if logging_changed is not None: change_logging(base)
        migrate(base, release)
        switch(base, release); switched = True
        refresh(base, previous)
        started = time.time()
        restart(base)
        observed = health(base, after=started)
        write_json(base/'deployment.json', {'desired': release.name, 'active': release.name, 'status': 'healthy', 'observed_at': int(time.time()), 'health': observed})
    except Exception:
        state = {'desired': release.name, 'active': release.name if switched else (previous.name if previous else None), 'status': 'failed', 'observed_at': int(time.time())}
        # Persist failure even if link restoration or recovery itself fails.
        write_json(base/'deployment.json', state)
        if previous and (switched or quiesced):
            try:
                if original_environment is not None:
                    restore_environment(original_environment)
                if switched:
                    switch(base, previous)
                    state['active'] = previous.name
                    refresh(base, release)
                started = time.time()
                restart(base)
                observed = health(base, after=started)
                state['recovery'] = {'status':'healthy', 'health':observed}
            except Exception as error:
                state['recovery'] = {'status':'failed', 'error':type(error).__name__}
            write_json(base/'deployment.json', state)
        raise


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest='command', required=True)
    build = commands.add_parser('artifact'); build.add_argument('output'); build.add_argument('--version', required=True); build.add_argument('--source', default=str(Path(__file__).resolve().parents[1]))
    init = commands.add_parser('initialize'); init.add_argument('base'); init.add_argument('config')
    release = commands.add_parser('release'); release.add_argument('base'); release.add_argument('archive'); release.add_argument('--auth-mode',choices=('tailscale','better-auth'))
    verify = commands.add_parser('health'); verify.add_argument('base')
    args = parser.parse_args()
    os.umask(0o077)
    if args.command == 'artifact':
        artifact(args.source, args.output, args.version)
        print(json.dumps({'artifact': args.output, 'sha256': sha256_file(args.output), 'version': args.version}))
    elif args.command == 'initialize': initialize(Path(args.base), read_json(args.config))
    elif args.command == 'release':
        base = Path(args.base).absolute()
        with open(base/'deploy.lock', 'a') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            activate(base, stage(base, args.archive), auth_mode=args.auth_mode)
        print(json.dumps(read_json(base/'deployment.json')))
    elif args.command == 'health': print(json.dumps(health(Path(args.base).absolute())))

if __name__ == '__main__':
    try: main()
    except Exception as error:
        print('Deployment failed ('+type(error).__name__+'). Inspect private local logs; existing persistent state was retained.', file=sys.stderr)
        sys.exit(1)
