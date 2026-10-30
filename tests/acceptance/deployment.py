"""Release boundaries use real files/archives and PHP migrations; external manager/HTTPS are isolated."""
import importlib.util
import io
import hashlib
from email.message import Message
import datetime
import itertools
import json
import os
from pathlib import Path
import shutil
import sqlite3
import sys
import subprocess
import threading
import socket
import runpy
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from types import SimpleNamespace
import tarfile
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]

class Deployment(unittest.TestCase):
    def setUp(self):
        spec = importlib.util.spec_from_file_location('deployment', ROOT / 'deploy/deployment.py')
        assert spec is not None and spec.loader is not None
        self.d = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.d)
        # Existing synthetic HTTPS/heartbeat proofs isolate the external manager.
        manager_boundary = patch.object(self.d, 'service_running', return_value=True)
        manager_boundary.start(); self.addCleanup(manager_boundary.stop)
        stop_boundary = patch.object(self.d, 'quiesce')
        stop_boundary.start(); self.addCleanup(stop_boundary.stop)
        self.tmp = tempfile.TemporaryDirectory(prefix='nebuless-release-')
        self.addCleanup(self.tmp.cleanup)
        self.base = Path(self.tmp.name) / 'installation'
        self.d.initialize(self.base, {'origin': 'https://hub.example:8444', 'name': 'Alpha-Hub', 'role': 'standby', 'php': str(ROOT/'bin/php'), 'node': shutil.which('node'), 'manager': 'supervisor', 'auth_mode':'better-auth'})

    def test_native_file_sessions_do_not_share_the_telemetry_writer(self):
        env = self.d.read_json(self.base/'state/env.json')
        self.assertEqual(env['SESSION_DRIVER'], 'file')
        self.assertEqual((self.base/'state/storage/framework/sessions').stat().st_mode & 0o777, 0o700)

    def test_initialize_rejects_prepared_secret_paths_before_mutation(self):
        for name in ('env.json.new', 'runtime.json.new', 'hub.env', 'hub.sqlite'):
            with self.subTest(name=name), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                base = root/'installation'
                (base/'state').mkdir(parents=True, mode=0o700)
                outside = root/'canary'
                outside.write_text('unchanged')
                (base/'state'/name).symlink_to(outside)
                with self.assertRaises(ValueError):
                    self.d.initialize(base, {'origin':'https://audit.invalid','name':'Alpha-Hub','role':'standby','php':'/bin/false','node':'/bin/false'})
                self.assertEqual(outside.read_text(), 'unchanged')
                self.assertFalse((base/'state/runtime.json').exists())
                self.assertFalse((base/'state/env.json').exists())

    def test_initialize_rejects_unsafe_parent_before_creating_state(self):
        with tempfile.TemporaryDirectory() as directory:
            parent = Path(directory)/'shared'
            parent.mkdir(mode=0o777)
            parent.chmod(0o777)
            with self.assertRaises(ValueError):
                self.d.initialize(parent/'installation', {'origin':'https://audit.invalid','name':'Alpha-Hub','role':'standby','php':'/bin/false','node':'/bin/false'})
            self.assertFalse((parent/'installation').exists())

    def test_initialize_accepts_validated_runtime_only_skeleton(self):
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)/'nested/installation'
            (base/'runtime/bin').mkdir(parents=True, mode=0o700)
            base.chmod(0o700)
            (base/'runtime').chmod(0o700)
            runtime = base/'runtime/bin/php'
            runtime.write_text('synthetic runtime')
            self.d.initialize(base, {'origin':'https://audit.invalid','name':'Alpha-Hub','role':'standby','php':str(runtime),'node':'/bin/false'})
            self.assertEqual(runtime.read_text(), 'synthetic runtime')
            self.assertTrue((base/'state/env.json').is_file())

    def test_initialize_rejects_link_inside_preinstalled_runtime(self):
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)/'installation'
            (base/'runtime').mkdir(parents=True, mode=0o700)
            base.chmod(0o700)
            (base/'runtime/php').symlink_to('/bin/false')
            with self.assertRaises(ValueError):
                self.d.initialize(base, {'origin':'https://audit.invalid','name':'Alpha-Hub','role':'standby','php':'/bin/false','node':'/bin/false'})
            self.assertFalse((base/'state').exists())

    def test_json_atomic_write_never_follows_predictable_temporary_link(self):
        outside = Path(self.tmp.name)/'canary'
        outside.write_text('unchanged')
        path = self.base/'state/probe.json'
        path.with_name('probe.json.new').symlink_to(outside)
        self.d.write_json(path, {'safe':True})
        self.assertEqual(outside.read_text(), 'unchanged')
        self.assertEqual(self.d.read_json(path), {'safe':True})

    def test_archive_noncanonical_alias_is_rejected_before_extraction(self):
        for name in ('apps/hub/./file', 'apps//hub/file', './release.json'):
            with self.subTest(name=name):
                archive = Path(self.tmp.name)/'alias.tar.gz'
                with tarfile.open(archive, 'w:gz') as tar:
                    info = tarfile.TarInfo(name)
                    info.size = 1
                    tar.addfile(info, io.BytesIO(b'x'))
                with self.assertRaises(ValueError):
                    self.d.stage(self.base, archive)

    def artifact(self, version):
        archive = Path(self.tmp.name) / (version+'.tar.gz')
        self.d.artifact(ROOT, archive, version)
        return archive

    def test_release_state_and_failure_preserve_action_history(self):
        archive = self.artifact('a'*40)
        release = self.d.stage(self.base, archive)
        self.d.migrate(self.base, release)
        with sqlite3.connect(self.base/'state/hub.sqlite') as db:
            db.execute('CREATE TABLE release_proof(value TEXT)')
            db.execute("INSERT INTO release_proof VALUES ('persistent action history')")
        for path, content in [('auth.sqlite', b'auth secret state'), ('journal/record', b'black box'), ('actions/receipt', b'consumed')]:
            target = self.base/'state'/path
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(content)
        self.d.switch(self.base, release)
        with patch.dict(sys.modules, {'deployment':self.d}):
            runpy.run_path(str(release/'deploy/install'))['configure'](self.base)
        actual_run = subprocess.run
        manager_commands = []
        def manager_boundary(command, **kwargs):
            if command[0] in ('supervisord','supervisorctl','systemctl'):
                manager_commands.append(command)
                return subprocess.CompletedProcess(command,0)
            return actual_run(command, **kwargs)
        with patch.dict(sys.modules, {'deployment':self.d}), patch.object(self.d.subprocess,'run',side_effect=manager_boundary), patch.object(self.d, 'health', return_value={}):
            self.d.activate(self.base, release)
        self.assertIn('Private HTTPS ingress required', (self.base/'state/Caddyfile').read_text())
        candidate_archive = self.artifact('b'*40)
        with tarfile.open(candidate_archive) as tar:
            contents = {}
            for member in tar.getmembers():
                stream = tar.extractfile(member)
                assert stream is not None
                contents[member.name] = (member, stream.read())
        member,data = contents['deploy/install']
        data = data.replace(b'Private HTTPS ingress required',b'Candidate private HTTPS ingress required')
        contents['deploy/install'] = (member,data)
        member,raw_manifest = contents['release.json']
        manifest = json.loads(raw_manifest)
        manifest['files']['deploy/install'] = hashlib.sha256(data).hexdigest()
        contents['release.json'] = (member,json.dumps(manifest,sort_keys=True).encode())
        with tarfile.open(candidate_archive,'w:gz',compresslevel=1) as tar:
            for member,data in contents.values():
                member.size = len(data)
                tar.addfile(member,io.BytesIO(data))
        newer = self.d.stage(self.base, candidate_archive)
        def unhealthy(*args, **kwargs):
            self.assertIn('Candidate private HTTPS ingress required', (self.base/'state/Caddyfile').read_text())
            raise RuntimeError('unhealthy')
        with patch.dict(sys.modules, {'deployment':self.d}), patch.object(self.d.subprocess,'run',side_effect=manager_boundary), patch.object(self.d, 'health', side_effect=unhealthy):
            with self.assertRaises(RuntimeError): self.d.activate(self.base, newer)
        self.assertEqual((self.base/'current').resolve(), release)
        self.assertIn('Private HTTPS ingress required', (self.base/'state/Caddyfile').read_text())
        self.assertNotIn('Candidate', (self.base/'state/Caddyfile').read_text())
        self.assertEqual((self.base/'state/actions/receipt').read_bytes(), b'consumed')
        self.assertEqual((self.base/'state/auth.sqlite').read_bytes(), b'auth secret state')
        self.assertEqual((self.base/'state/journal/record').read_bytes(), b'black box')
        with sqlite3.connect(self.base/'state/hub.sqlite') as db:
            self.assertEqual(db.execute('SELECT value FROM release_proof').fetchone()[0], 'persistent action history')
        with patch.object(self.d, 'migrate', side_effect=RuntimeError('migration failed')):
            with self.assertRaises(RuntimeError): self.d.activate(self.base, newer)
        self.assertEqual((self.base/'current').resolve(), release)
        self.assertEqual(json.loads((self.base/'deployment.json').read_text())['status'], 'failed')
        with patch.dict(sys.modules, {'deployment':self.d}), patch.object(self.d.subprocess,'run',side_effect=manager_boundary), patch.object(self.d, 'health', return_value={}):
            self.d.activate(self.base, newer)
        self.assertEqual((self.base/'current').resolve(), newer)
        self.assertIn('Candidate private HTTPS ingress required', (self.base/'state/Caddyfile').read_text())
        self.assertTrue(any('restart' in command for command in manager_commands))
        self.assertFalse(any('nebu-alrt' in command for command in manager_commands))

    def test_refresh_updates_only_owned_user_units(self):
        config = self.d.read_json(self.base/'state/runtime.json')
        config['manager'] = 'systemd'
        self.d.write_json(self.base/'state/runtime.json',config)
        home = Path(self.tmp.name)/'home'
        previous = self.base/'releases'/('d'*40)
        candidate = self.base/'releases'/('e'*40)
        for release, marker in [(previous,'previous'),(candidate,'candidate')]:
            (release/'deploy/systemd-user').mkdir(parents=True)
            shutil.copyfile(ROOT/'deploy/install',release/'deploy/install')
            template = (ROOT/'deploy/systemd-user/nebuless.service').read_text()
            (release/'deploy/systemd-user/nebuless.service').write_text(template+'\n# '+marker+' definition\n')
        self.d.switch(self.base,previous)
        with patch.object(Path,'home',return_value=home), patch.dict(sys.modules,{'deployment':self.d}), patch.object(self.d.subprocess,'run') as manager:
            runpy.run_path(str(previous/'deploy/install'))['configure'](self.base)
            unit = home/'.config/systemd/user/nebuless-http.service'
            self.assertIn('# previous definition',unit.read_text())
            self.d.switch(self.base,candidate)
            self.d.refresh(self.base,previous)
            self.assertIn('# candidate definition',unit.read_text())
            self.d.switch(self.base,previous)
            self.d.refresh(self.base,candidate)
            self.assertIn('# previous definition',unit.read_text())
            unit.write_text('unrelated or drifted service')
            self.d.switch(self.base,candidate)
            manager.reset_mock()
            with self.assertRaises(ValueError): self.d.refresh(self.base,previous)
            self.assertEqual(unit.read_text(),'unrelated or drifted service')
            manager.assert_not_called()
            with self.assertRaises(ValueError): runpy.run_path(str(candidate/'deploy/install'))['configure'](self.base)
            self.assertEqual(unit.read_text(),'unrelated or drifted service')

    def test_artifact_immutable_no_runtime_secrets(self):
        subprocess.run([str(ROOT/'bin/php'),'vendor/bin/pest','tests/Feature/HubInstallTest.php'],cwd=ROOT/'apps/hub',check=True,stdout=subprocess.DEVNULL)
        archive = self.artifact('c'*40)
        with tarfile.open(archive) as tar:
            names = tar.getnames()
        self.assertFalse(any(p.startswith('contracts/fixtures/') for p in names), 'Source protocol fixtures must not ship')
        self.assertTrue('bin/nebuless-reboot-helper' in names, 'Immutable fixed helper is missing')
        self.assertFalse(any('.env' in Path(p).parts or p.endswith('.sqlite') or '/storage/' in p or '/.context/' in p for p in names))
        self.assertFalse(any(p.startswith('apps/hub/vendor/pestphp/pest/.temp/') for p in names), 'Mutable Pest results must not ship')
        release = self.d.stage(self.base, archive)
        self.assertFalse((release/'apps/hub/artisan').stat().st_mode & 0o222)
        self.assertTrue((release/'apps/hub/storage').is_symlink())
        code = r"""require $argv[1].'/apps/hub/vendor/autoload.php';
$app = require $argv[1].'/apps/hub/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), flags: JSON_THROW_ON_ERROR);
$machine = new App\Models\Machine;
$machine->id = $input->report->machine_id;
$report = (new App\Telemetry\ValidateReport)->validate($machine, $input->report);
$delivery = (new App\Telemetry\ValidateDelivery)->validate($input->delivery);
echo json_encode(['telemetry' => $report['machine_id'] === $input->report->machine_id, 'delivery' => $delivery['recording'] === 'recording'], JSON_THROW_ON_ERROR);
"""
        payload = {'report':json.loads((ROOT/'contracts/fixtures/telemetry/valid-sample.json').read_text()),'delivery':{'version':1,'recording':'recording','possible_loss_seconds':30,'unknown_coverage':False,'lost_ranges':[],'acknowledgements':[]}}
        validation = subprocess.run([*self.d.php_command(self.base),'-r',code,'--',str(release)],input=json.dumps(payload).encode(),env=self.d.environment(self.base,release),capture_output=True)
        self.assertEqual(validation.returncode,0,(validation.stderr+validation.stdout).decode()[-2000:])
        self.assertEqual(json.loads(validation.stdout),{'telemetry':True,'delivery':True})
        self.assertEqual((self.base/'state/env.json').stat().st_mode & 0o777, 0o600)
        # Rebuilding the same source produces identical bytes.
        # A different real focused suite mutates Pest's generated cache only.
        subprocess.run([str(ROOT/'bin/php'),'vendor/bin/pest','tests/Feature/HistoryRetentionTest.php'],cwd=ROOT/'apps/hub',check=True,stdout=subprocess.DEVNULL)
        copy = Path(self.tmp.name)/'copy.tar.gz'
        self.d.artifact(ROOT, copy, 'c'*40)
        self.assertEqual(archive.read_bytes(), copy.read_bytes())

    def test_auth_bridge_ignores_ambient_proxy_and_refuses_redirects(self):
        observed = {'proxy':0, 'direct':0, 'redirect':False}
        class Proxy(BaseHTTPRequestHandler):
            def do_POST(self):
                self.rfile.read(int(self.headers.get('Content-Length','0')))
                observed['proxy'] += 1
                self.send_response(200); self.end_headers(); self.wfile.write(b'{"session":null}')
            do_GET = do_POST
            def log_message(self,format,*args): pass
        proxy = ThreadingHTTPServer(('127.0.0.1',0),Proxy)
        class Bridge(BaseHTTPRequestHandler):
            def do_POST(self):
                self.rfile.read(int(self.headers.get('Content-Length','0')))
                observed['direct'] += 1
                if observed['redirect']:
                    self.send_response(302)
                    self.send_header('Location','http://127.0.0.1:'+str(proxy.server_port)+'/steal')
                    self.end_headers()
                else:
                    self.send_response(200); self.end_headers(); self.wfile.write(b'{"session":null}')
            def log_message(self,format,*args): pass
        bridge = ThreadingHTTPServer(('127.0.0.1',0),Bridge)
        for server in [proxy,bridge]:
            threading.Thread(target=server.serve_forever,daemon=True).start()
            self.addCleanup(server.server_close); self.addCleanup(server.shutdown)
        env = self.d.read_json(self.base/'state/env.json')
        env['AUTH_BRIDGE_URL'] = 'http://127.0.0.1:'+str(bridge.server_port)
        self.d.write_json(self.base/'state/env.json',env)
        with sqlite3.connect(self.base/'state/hub.sqlite') as db:
            db.execute('CREATE TABLE hub_heartbeats(service TEXT, observed_at TEXT)')
            now = datetime.datetime.now(datetime.timezone.utc).isoformat()
            db.executemany('INSERT INTO hub_heartbeats VALUES (?,?)',[('scheduler',now),('urgent_worker',now)])
        class Login(io.BytesIO):
            status = 200
            headers = Message()
        Login.headers.add_header('Set-Cookie','nebuless_session=opaque; Secure; HttpOnly; SameSite=Lax; Path=/')
        real_build = self.d.urllib.request.build_opener
        def build(*handlers):
            transport = real_build(*handlers)
            def open_request(address,*args,**kwargs):
                if isinstance(address,str) and address.startswith('https://hub.example:8444'):
                    return Login(b'<form action="https://hub.example:8444/login">')
                return transport.open(address,*args,**kwargs)
            return SimpleNamespace(open=open_request)
        proxy_url = 'http://127.0.0.1:'+str(proxy.server_port)
        with patch.dict(os.environ,{'http_proxy':proxy_url,'HTTP_PROXY':proxy_url,'no_proxy':'','NO_PROXY':''}), patch.object(self.d.urllib.request,'_opener',None), patch.object(self.d.urllib.request,'build_opener',side_effect=build), patch.object(self.d.time,'sleep'), patch.object(self.d.time,'monotonic',side_effect=itertools.count()):
            self.assertTrue(self.d.health(self.base)['auth'])
            self.assertEqual(observed['proxy'],0,'Private bridge request escaped through ambient proxy')
            self.assertGreater(observed['direct'],0)
            observed['redirect'] = True
            with self.assertRaises(RuntimeError): self.d.health(self.base)
            self.assertEqual(observed['proxy'],0,'Bridge credentials followed a redirect')

    def test_privileged_credential_only_reaches_collector_role(self):
        release = self.base/'releases'/('e'*40)
        with patch.dict(os.environ,{'NEBULESS_REBOOT_CREDENTIAL':'synthetic-private-canary'}):
            self.assertFalse('NEBULESS_REBOOT_CREDENTIAL' in self.d.environment(self.base,release), 'general environment inherited privileged credential')
            self.assertEqual(self.d.environment(self.base,release,service='collector')['NEBULESS_REBOOT_CREDENTIAL'],'synthetic-private-canary')
            for service in ['auth','fpm','http','scheduler','urgent','maintenance','proxy']:
                self.assertFalse('NEBULESS_REBOOT_CREDENTIAL' in self.d.environment(self.base,release,service=service), 'non-collector role inherited privileged credential')

    def test_native_credential_is_loaded_only_for_collector(self):
        release = self.base/'releases'/('e'*40)
        directory = self.base/'native-credentials';directory.mkdir()
        (directory/'nebuless-reboot').write_text('synthetic-encrypted-native-credential')
        with patch.dict(os.environ,{'CREDENTIALS_DIRECTORY':str(directory)}):
            os.environ.pop('NEBULESS_REBOOT_CREDENTIAL',None)
            self.assertEqual(self.d.environment(self.base,release,service='collector')['NEBULESS_REBOOT_CREDENTIAL'],'synthetic-encrypted-native-credential')
            for service in ['auth','fpm','http','scheduler','urgent','maintenance','proxy',None]:
                self.assertFalse('NEBULESS_REBOOT_CREDENTIAL' in self.d.environment(self.base,release,service=service))
            (directory/'nebuless-reboot').unlink()
            self.assertFalse('NEBULESS_REBOOT_CREDENTIAL' in self.d.environment(self.base,release,service='collector'), 'Missing optional native credential must leave observation usable')

    def test_runtime_probe_failure_prevents_migrations(self):
        release = self.d.stage(self.base, self.artifact('d'*40))
        config = self.d.read_json(self.base/'state/runtime.json')
        original_profile = config['php_ini']
        native_profile = self.base/'runtime/native-profile.ini'
        configured_profile = self.base/'runtime/configured-profile.ini'
        native_profile.write_text('memory_limit=137M\n')
        configured_profile.write_text('memory_limit=193M\ncurl.cainfo='+config['ca']+'\n')
        code = r"""require $argv[1].'/apps/hub/vendor/autoload.php';
$child = 'echo json_encode(["ini" => php_ini_loaded_file(), "memory" => ini_get("memory_limit"), "ca" => ini_get("curl.cainfo")]);';
$command = Illuminate\Console\Application::phpBinary().' -r '.Illuminate\Support\ProcessUtils::escapeArgument($child);
$process = Symfony\Component\Process\Process::fromShellCommandline($command);
$process->mustRun();
echo json_encode(['parent_ini' => php_ini_loaded_file(), 'child' => json_decode($process->getOutput(), true)]);
"""
        def native_child():
            result = subprocess.run([*self.d.php_command(self.base),'-r',code,'--',str(release)],env=self.d.environment(self.base,release),capture_output=True)
            self.assertEqual(result.returncode,0,(result.stdout+result.stderr).decode()[-2000:])
            return json.loads(result.stdout)
        with patch.dict(os.environ,{'PHPRC':str(native_profile)}):
            inherited = native_child()
            self.assertEqual(inherited['parent_ini'],str(native_profile))
            self.assertEqual(inherited['child']['ini'],str(native_profile))
            self.assertEqual(inherited['child']['memory'],'137M')
            config['php_ini'] = str(configured_profile)
            self.d.write_json(self.base/'state/runtime.json',config)
            configured = native_child()
            self.assertEqual(configured['parent_ini'],str(configured_profile))
            self.assertEqual(configured['child']['ini'],str(configured_profile),'Native PHP_BINARY descendant lost configured runtime profile')
            self.assertEqual(configured['child']['memory'],'193M')
            self.assertEqual(configured['child']['ca'],config['ca'])
        config['php_ini'] = original_profile
        self.d.write_json(self.base/'state/runtime.json',config)
        subprocess.run([*self.d.php_command(self.base),str(release/'tools/runtime-probe.php')],env=self.d.environment(self.base,release),check=True,stdout=subprocess.DEVNULL)
        config['node'] = '/bin/false'
        self.d.write_json(self.base/'state/runtime.json',config)
        with self.assertRaises(Exception): self.d.migrate(self.base,release)
        with sqlite3.connect(self.base/'state/hub.sqlite') as db:
            self.assertEqual(db.execute("SELECT count(*) FROM sqlite_master WHERE name='migrations'").fetchone()[0],0)

    def test_http_and_auth_cannot_hide_stopped_background_workers(self):
        class Response(io.BytesIO):
            status = 200
            headers = Message()
        Response.headers.add_header('Set-Cookie', 'nebuless_session=opaque; Secure; HttpOnly; SameSite=Lax; Path=/')
        login = lambda: Response(b'<form action="https://hub.example:8444/login">')
        auth = lambda: io.BytesIO(b'{"session":null}')
        with sqlite3.connect(self.base/'state/hub.sqlite') as db:
            db.execute('CREATE TABLE hub_heartbeats(service TEXT, observed_at TEXT)')
            db.executemany('INSERT INTO hub_heartbeats VALUES (?,?)', [('scheduler','2000-01-01T00:00:00Z'),('urgent_worker','2000-01-01T00:00:00Z')])
        with patch.object(self.d.urllib.request, 'build_opener') as opener, patch.object(self.d.time, 'sleep'), patch.object(self.d.time,'monotonic',side_effect=itertools.count()):
            opener.return_value.open.side_effect = lambda address,*a,**k: auth() if isinstance(address,self.d.urllib.request.Request) else login()
            with self.assertRaises(RuntimeError): self.d.health(self.base)
            now = datetime.datetime.now(datetime.timezone.utc).isoformat()
            with sqlite3.connect(self.base/'state/hub.sqlite') as db: db.execute('UPDATE hub_heartbeats SET observed_at=?',(now,))
            self.assertTrue(self.d.health(self.base,after=self.d.time.time()-1)['https'])
            with self.assertRaises(RuntimeError): self.d.health(self.base,after=self.d.time.time()+1)
        # A legitimate newly started schedule:work may await its minute boundary.
        with sqlite3.connect(self.base/'state/hub.sqlite') as db: db.execute("UPDATE hub_heartbeats SET observed_at='2000-01-01T00:00:00Z'")
        clock = [0.0]
        def wait_for_startup(seconds):
            clock[0] += seconds
            if clock[0] >= 14:
                stamp = datetime.datetime.now(datetime.timezone.utc).isoformat()
                with sqlite3.connect(self.base/'state/hub.sqlite') as db: db.execute('UPDATE hub_heartbeats SET observed_at=?',(stamp,))
        with patch.object(self.d.urllib.request,'build_opener') as opener, patch.object(self.d.time,'monotonic',side_effect=lambda:clock[0]), patch.object(self.d.time,'sleep',side_effect=wait_for_startup):
            opener.return_value.open.side_effect = lambda address,*a,**k: auth() if isinstance(address,self.d.urllib.request.Request) else login()
            self.assertTrue(self.d.health(self.base,after=self.d.time.time())['scheduler'])
            self.assertGreaterEqual(clock[0],14)

    def test_installed_caddy_serves_public_host_only_through_loopback_https_ingress(self):
        caddy = shutil.which('caddy')
        if not caddy:
            # CI builds the pinned upstream binary in this disposable test directory.
            tools = Path(self.tmp.name)/'tools';tools.mkdir()
            subprocess.run(['go','install','github.com/caddyserver/caddy/v2/cmd/caddy@v2.11.7'],env={**os.environ,'GOBIN':str(tools)},check=True,stdout=subprocess.DEVNULL)
            caddy = str(tools/'caddy')
        self.assertEqual(subprocess.check_output([caddy, 'version'], text=True).split()[0], 'v2.11.7')
        with socket.socket() as reservation:
            reservation.bind(('127.0.0.1',0)); port = reservation.getsockname()[1]
        config=self.d.read_json(self.base/'state/runtime.json');config.update({'caddy':caddy,'backend_port':port})
        self.d.write_json(self.base/'state/runtime.json',config)
        release=self.base/'releases'/('f'*40)
        public=release/'apps/hub/public';public.mkdir(parents=True)
        (public/'ingress-proof.txt').write_bytes(b'private HTTPS asset')
        templates=release/'deploy/supervisor';templates.mkdir(parents=True)
        shutil.copyfile(ROOT/'deploy/supervisor/program.conf',templates/'program.conf')
        self.d.switch(self.base,release)
        with patch.dict(sys.modules,{'deployment':self.d}):
            runpy.run_path(str(ROOT/'deploy/install'))['configure'](self.base)
        process=subprocess.Popen([caddy,'run','--config',str(self.base/'state/Caddyfile'),'--adapter','caddyfile'],stdout=subprocess.DEVNULL,stderr=subprocess.PIPE)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.1):pass
                    break
                except OSError:time.sleep(.05)
            def get(headers):
                request=self.d.urllib.request.Request('http://127.0.0.1:'+str(port)+'/ingress-proof.txt',headers=headers)
                try:
                    with self.d.urllib.request.build_opener(self.d.urllib.request.ProxyHandler({})).open(request,timeout=2) as response:return response.status,response.read()
                except self.d.urllib.error.HTTPError as response:return response.code,response.read()
            status,body=get({'Host':'hub.example:8444','X-Forwarded-Proto':'https'})
            self.assertEqual((status,body),(200,b'private HTTPS asset'))
            for headers in [{},{'Host':'hub.example:8444'},{'Host':'other.example:8444','X-Forwarded-Proto':'https'}]:self.assertEqual(get(headers)[0],400)
            adapted=json.loads(subprocess.check_output([caddy,'adapt','--config',str(self.base/'state/Caddyfile'),'--adapter','caddyfile'],stderr=subprocess.DEVNULL))
            servers=adapted['apps']['http']['servers']
            self.assertEqual([address for server in servers.values() for address in server['listen']],['127.0.0.1:'+str(port)])
        finally:
            process.terminate();process.wait(timeout=5)
            assert process.stderr is not None
            process.stderr.close()

    def test_archive_traversal_rejected(self):
        archive = Path(self.tmp.name)/'bad.tar.gz'
        with tarfile.open(archive, 'w:gz') as tar:
            info = tarfile.TarInfo('../state/env.json')
            tar.addfile(info)
        with self.assertRaises(ValueError): self.d.stage(self.base, archive)
        self.assertTrue((self.base/'state/env.json').exists())


class DeploymentReadiness(unittest.TestCase):
    def setUp(self):
        spec = importlib.util.spec_from_file_location('readiness_deployment', ROOT/'deploy/deployment.py')
        assert spec is not None and spec.loader is not None
        self.d = importlib.util.module_from_spec(spec); spec.loader.exec_module(self.d)
        self.tmp = tempfile.TemporaryDirectory(prefix='nebuless-readiness-'); self.addCleanup(self.tmp.cleanup)
        self.base = Path(self.tmp.name)/'hub'
        self.d.initialize(self.base, {'origin':'https://alpha.example', 'name':'Alpha-Hub', 'role':'standby', 'php':str(ROOT/'bin/php'), 'node':shutil.which('node'), 'manager':'supervisor', 'auth_mode':'better-auth'})
        self.prior, self.candidate = [self.base/'releases'/v for v in ('a'*40,'b'*40)]
        for release in (self.prior,self.candidate):
            release.mkdir(); self.d.write_json(release/'release.json',{'schema_epoch':1})
        self.d.switch(self.base,self.prior)
        self.collector = {'machine_id':'local-machine', 'destinations':[{'id':name,'url':'https://'+name+'.example','token':'private-'+name} for name in ('standby','primary','restricted')]}
        self.d.write_json(self.base/'state/collector.json',self.collector)
        with sqlite3.connect(self.base/'state/hub.sqlite') as db:
            db.execute('CREATE TABLE hub_identities(id INTEGER,hub_uuid TEXT)'); db.execute("INSERT INTO hub_identities VALUES (1,'standby')")

    def peer(self, hub='primary', age=0, scheduler=0, urgent: int | None=0):
        return {'hub_id':hub,'observed_at':(datetime.datetime.now(datetime.timezone.utc)-datetime.timedelta(seconds=age)).isoformat(),'heartbeats':{'scheduler':scheduler,'urgent_worker':urgent}}

    def test_fleet_lease_and_serial_matrix_are_shared_by_both_callers(self):
        workflow = (ROOT/'tests/fixtures/workflows/deploy-hosts.yml').read_text()
        self.assertRegex(workflow, r'(?m)^concurrency:\n  group: nebuless-observation-fleet\n  cancel-in-progress: false$')
        self.assertIn('max-parallel: 1',workflow); self.assertIn('fail-fast: false',workflow)
        for name in ('deploy.yml','reconcile-deployments.yml'):
            caller = (ROOT/'tests/fixtures/workflows'/name).read_text()
            self.assertIn('uses: ./.github/workflows/deploy-hosts.yml',caller)
            self.assertNotIn('nebuless-observation-fleet',caller)

    def test_guard_checks_authenticated_other_peer_identity_freshness_and_transport(self):
        proxy = 'http://127.0.0.1:18445'
        self.collector['destinations'][1]['proxy_url'] = proxy
        self.d.write_json(self.base/'state/collector.json',self.collector)
        seen = []
        class Reply(io.BytesIO): status=200
        body = [self.peer()]
        def opened(request, **kwargs):
            seen.append(request)
            return Reply(json.dumps(body[0]).encode())
        with patch.object(self.d.urllib.request,'build_opener') as build:
            build.return_value.open.side_effect = opened
            self.d.require_healthy_peer(self.base)
            self.assertEqual(seen[0].full_url,'https://primary.example/api/v1/collector/local-machine/hub-health')
            self.assertEqual(seen[0].get_header('Authorization'),'Bearer private-primary')
            handlers = build.call_args.args
            self.assertEqual(handlers[0].proxies,{'https':proxy})
            self.assertIsNone(handlers[1].redirect_request(None,None,None,None,None,None))
            for bad in (self.peer('standby'),self.peer(age=16),self.peer(age=-6),self.peer(scheduler=45),self.peer(urgent=None),self.peer(scheduler=True)):
                body[0] = bad
                with self.assertRaises(RuntimeError): self.d.require_healthy_peer(self.base)
            self.assertTrue(all('alpha.example' not in request.full_url for request in seen))
        self.d.write_json(self.base/'state/collector.json', {**self.collector,'destinations':self.collector['destinations'][:1]})
        with self.assertRaises(RuntimeError): self.d.require_healthy_peer(self.base)

    def activation_boundaries(self, migration=None, healthy=None, stopping=None):
        events=[]
        patches = [patch.object(self.d,'require_healthy_peer',side_effect=lambda base:events.append('peer')),
            patch.object(self.d,'quiesce',side_effect=lambda base:(events.append('stop'),stopping() if stopping else None)),
            patch.object(self.d,'migrate',side_effect=lambda *args:(events.append('migrate'),migration() if migration else None)),
            patch.object(self.d,'refresh',side_effect=lambda *args:events.append('refresh')),
            patch.object(self.d,'restart',side_effect=lambda *args:events.append('restart')),
            patch.object(self.d,'health',side_effect=lambda *args,**kwargs:(events.append('health'),healthy() if healthy else {'collector':True})[1])]
        for item in patches: item.start(); self.addCleanup(item.stop)
        return events

    def test_guard_failure_precedes_all_service_and_migration_mutation(self):
        events=self.activation_boundaries()
        with patch.object(self.d,'require_healthy_peer',side_effect=RuntimeError('no peer')):
            with self.assertRaises(RuntimeError): self.d.activate(self.base,self.candidate)
        self.assertEqual(events,[]); self.assertEqual((self.base/'current').resolve(),self.prior)

    def test_migration_failure_and_partial_stop_restore_and_verify_prior(self):
        for failure in ('migration','stop'):
            with self.subTest(failure=failure):
                def fail(): raise RuntimeError('injected')
                events=self.activation_boundaries(migration=fail if failure=='migration' else None, stopping=fail if failure=='stop' else None)
                with self.assertRaises(RuntimeError): self.d.activate(self.base,self.candidate)
                self.assertEqual(events[:2],['peer','stop'])
                self.assertEqual(events[-2:],['restart','health'])
                self.assertEqual((self.base/'current').resolve(),self.prior)
                state=self.d.read_json(self.base/'deployment.json')
                self.assertEqual(state['status'],'failed'); self.assertEqual(state['desired'],self.candidate.name)
                self.assertEqual(state['recovery']['status'],'healthy')
                patch.stopall()

    def test_candidate_and_failed_rollback_health_remain_failed(self):
        calls=[0]
        def fail(): calls[0]+=1; raise RuntimeError('unhealthy')
        events=self.activation_boundaries(healthy=fail)
        with self.assertRaises(RuntimeError): self.d.activate(self.base,self.candidate)
        self.assertEqual(calls[0],2)
        self.assertLess(events.index('stop'),events.index('migrate'))
        self.assertEqual((self.base/'current').resolve(),self.prior)
        state=self.d.read_json(self.base/'deployment.json')
        self.assertEqual(state['status'],'failed'); self.assertEqual(state['recovery']['status'],'failed')
        self.assertNotIn('health',state['recovery'])

    def test_failed_link_restoration_reports_actual_active_candidate(self):
        def unhealthy(): raise RuntimeError('candidate unhealthy')
        self.activation_boundaries(healthy=unhealthy)
        original_switch=self.d.switch
        def switch(base,release):
            if release == self.prior: raise OSError('link restore failed')
            original_switch(base,release)
        with patch.object(self.d,'switch',side_effect=switch):
            with self.assertRaises(RuntimeError): self.d.activate(self.base,self.candidate)
        state=self.d.read_json(self.base/'deployment.json')
        self.assertEqual((self.base/'current').resolve(),self.candidate)
        self.assertEqual(state['active'],self.candidate.name)
        self.assertEqual(state['status'],'failed'); self.assertEqual(state['recovery']['status'],'failed')

    def test_same_version_healthy_skips_peer_and_restart_unhealthy_requires_peer(self):
        # A no-op fixture must already have all native FPM sink settings.
        (self.base/'state/fpm.conf').write_text('error_log = /proc/self/fd/2\ncatch_workers_output = yes\nphp_admin_flag[log_errors] = on\nphp_admin_flag[display_errors] = off\nphp_admin_value[error_log] = /proc/self/fd/2\n')
        events=self.activation_boundaries()
        self.d.activate(self.base,self.prior); self.assertEqual(events,['health'])
        with patch.object(self.d,'health',side_effect=RuntimeError('unhealthy')), patch.object(self.d,'require_healthy_peer',side_effect=RuntimeError('no peer')) as guard:
            with self.assertRaises(RuntimeError): self.d.activate(self.base,self.prior)
            guard.assert_called_once()
        self.assertEqual(events,['health'])

    def test_initial_release_without_collector_remains_possible(self):
        (self.base/'current').unlink(); (self.base/'state/collector.json').unlink()
        events=self.activation_boundaries()
        self.d.activate(self.base,self.candidate)
        self.assertNotIn('peer',events); self.assertNotIn('stop',events)

    def test_quiescence_targets_only_owned_writers_for_both_managers(self):
        for manager in ('supervisor','systemd'):
            config=self.d.read_json(self.base/'state/runtime.json'); config['manager']=manager; self.d.write_json(self.base/'state/runtime.json',config)
            with patch.object(self.d.subprocess,'run',return_value=subprocess.CompletedProcess([],0,stdout='')) as run:
                self.d.quiesce(self.base)
            command=run.call_args.args[0]
            self.assertIn('stop',command)
            self.assertTrue(all(any('nebuless-'+service in item for item in command) for service in ('http','fpm','auth','scheduler','urgent','maintenance')))
            self.assertFalse(any('collector' in item or 'proxy' in item for item in command))

    def test_health_requires_running_maintenance_collector_and_fresh_local_contact(self):
        now=datetime.datetime.now(datetime.timezone.utc).isoformat()
        with sqlite3.connect(self.base/'state/hub.sqlite') as db:
            db.execute('CREATE TABLE hub_heartbeats(service TEXT,observed_at TEXT)')
            db.executemany('INSERT INTO hub_heartbeats VALUES (?,?)',[('scheduler',now),('urgent_worker',now)])
            db.execute('CREATE TABLE telemetry_contacts(machine_id TEXT,last_contact_at TEXT)')
            db.executemany('INSERT INTO telemetry_contacts VALUES (?,?)',[('local-machine',now),('other-machine',now)])
        class Reply(io.BytesIO):
            status=200; headers=Message()
        Reply.headers.add_header('Set-Cookie','nebuless_session=opaque; Secure; HttpOnly; SameSite=Lax; Path=/')
        def opened(address,**kwargs): return Reply(b'{"session":null}' if isinstance(address,self.d.urllib.request.Request) else b'https://alpha.example/login')
        def manager(command,**kwargs):
            service=command[-1].removesuffix('.service')
            return subprocess.CompletedProcess(command,0,stdout=service+' '+('STOPPED' if service in stopped else 'RUNNING')+' pid 123, uptime 0:01:00\n')
        stopped=set()
        with patch.object(self.d.urllib.request,'build_opener') as build, patch.object(self.d.subprocess,'run',side_effect=manager), patch.object(self.d.time,'sleep'), patch.object(self.d.time,'monotonic',side_effect=itertools.count()):
            build.return_value.open.side_effect=opened
            self.assertTrue(self.d.health(self.base)['maintenance'])
            for service in ('nebuless-maintenance','nebu-alrt'):
                stopped.add(service)
                with self.assertRaises(RuntimeError): self.d.health(self.base)
                stopped.clear()
            with sqlite3.connect(self.base/'state/hub.sqlite') as db: db.execute("UPDATE telemetry_contacts SET last_contact_at='2000-01-01T00:00:00Z' WHERE machine_id='local-machine'")
            with self.assertRaises(RuntimeError): self.d.health(self.base)
            with sqlite3.connect(self.base/'state/hub.sqlite') as db: db.execute('UPDATE telemetry_contacts SET last_contact_at=? WHERE machine_id=?',(now,'local-machine'))
            with self.assertRaises(RuntimeError): self.d.health(self.base,after=time.time()+1)

    def test_occupied_legacy_auth_requires_explicit_conversion_before_migration(self):
        with sqlite3.connect(self.base/'state/hub.sqlite') as db:
            db.execute('CREATE TABLE users(id INTEGER,password TEXT)'); db.execute("INSERT INTO users VALUES (1,'legacy-private')")
            db.execute('CREATE TABLE migrations(migration TEXT,batch INTEGER)')
        with patch.object(self.d.subprocess,'run') as runtime, patch.object(self.d,'artisan') as artisan:
            with self.assertRaisesRegex(ValueError,'auth_conversion_plan_required'): self.d.migrate(self.base,self.candidate)
            runtime.assert_not_called(); artisan.assert_not_called()
        with sqlite3.connect(self.base/'state/hub.sqlite') as db:
            self.assertEqual(db.execute('SELECT password FROM users').fetchone()[0],'legacy-private')
            db.execute("INSERT INTO migrations VALUES ('2026_10_15_000013_replace_owner_auth_provider',1)")
        with patch.object(self.d.subprocess,'run'), patch.object(self.d,'artisan') as artisan:
            self.d.migrate(self.base,self.candidate)
            artisan.assert_called_once()

    def test_owned_supervisor_process_faults_are_observed(self):
        supervisor = shutil.which('supervisord')
        if not supervisor: self.skipTest('Supervisor unavailable')
        config = self.base/'supervisor.conf'
        config.write_text('[unix_http_server]\nfile='+str(self.base/'run/supervisor.sock')+'\n[supervisord]\nnodaemon=true\nlogfile='+str(self.base/'state/logs/proof.log')+'\npidfile='+str(self.base/'run/proof.pid')+'\n[rpcinterface:supervisor]\nsupervisor.rpcinterface_factory=supervisor.rpcinterface:make_main_rpcinterface\n[supervisorctl]\nserverurl=unix://'+str(self.base/'run/supervisor.sock')+'\n'+''.join('[program:nebuless-'+name+']\ncommand=/bin/sleep 300\nstartsecs=0\nstdout_logfile=/dev/null\nstderr_logfile=/dev/null\n' for name in ('collector','maintenance')))
        process=subprocess.Popen([supervisor,'-n','-c',str(config)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        try:
            for _ in range(60):
                if (self.base/'run/supervisor.sock').exists() and self.d.service_running(self.base,'maintenance'): break
                time.sleep(.05)
            self.assertTrue(self.d.service_running(self.base,'maintenance'))
            self.assertTrue(self.d.service_running(self.base,'collector'))
            now=datetime.datetime.now(datetime.timezone.utc).isoformat()
            with sqlite3.connect(self.base/'state/hub.sqlite') as db:
                db.execute('CREATE TABLE hub_heartbeats(service TEXT,observed_at TEXT)')
                db.executemany('INSERT INTO hub_heartbeats VALUES (?,?)',[('scheduler',now),('urgent_worker',now)])
                db.execute('CREATE TABLE telemetry_contacts(machine_id TEXT,last_contact_at TEXT)')
                db.execute('INSERT INTO telemetry_contacts VALUES (?,?)',('local-machine',now))
            class Reply(io.BytesIO):
                status=200; headers=Message()
            Reply.headers.add_header('Set-Cookie','nebuless_session=opaque; Secure; HttpOnly; SameSite=Lax; Path=/')
            def opened(address,**kwargs): return Reply(b'{"session":null}' if isinstance(address,self.d.urllib.request.Request) else b'https://alpha.example/login')
            with patch.object(self.d.urllib.request,'build_opener') as build, patch.object(self.d.time,'sleep'), patch.object(self.d.time,'monotonic',side_effect=itertools.count(step=10)):
                build.return_value.open.side_effect=opened
                self.assertTrue(self.d.health(self.base)['collector'])
                for name in ('maintenance','collector'):
                    subprocess.run(self.d.service_command(self.base,'stop',[name]),check=True,stdout=subprocess.DEVNULL)
                    self.assertFalse(self.d.service_running(self.base,name))
                    with self.assertRaises(RuntimeError): self.d.health(self.base)
                    subprocess.run(self.d.service_command(self.base,'start',[name]),check=True,stdout=subprocess.DEVNULL)
        finally:
            process.terminate(); process.wait(timeout=10)

    def test_remote_release_bootstraps_verified_candidate_instead_of_prior_runner(self):
        archive=Path(self.tmp.name)/'remote.tar.gz'
        marker=self.base/'bootstrap-proof.json'
        (self.prior/'deploy').mkdir()
        prior_runner=self.prior/'deploy/release'
        prior_runner.write_text('#!/usr/bin/env python3\nfrom pathlib import Path\nPath('+repr(str(marker))+').write_text(\'["old-runner"]\')\n')
        prior_runner.chmod(0o700)
        code=('import json,sys\nfrom pathlib import Path\nPath(sys.argv[2],"bootstrap-proof.json").write_text(json.dumps(sys.argv[1:]))\n').encode()
        def package(valid):
            manifest={'version':'b'*40,'files':{'deploy/deployment.py':hashlib.sha256(code if valid else b'wrong').hexdigest()}}
            with tarfile.open(archive,'w:gz') as tar:
                for name,data in [('release.json',json.dumps(manifest).encode()),('deploy/deployment.py',code)]:
                    item=tarfile.TarInfo(name); item.size=len(data); tar.addfile(item,io.BytesIO(data))
        real_run=subprocess.run
        commands=[]
        def ssh(command,**kwargs):
            commands.append(command)
            if 'stdin' in kwargs:
                (self.base/('incoming-'+('b'*40)+'.tar.gz')).write_bytes(kwargs['stdin'].read())
                return subprocess.CompletedProcess(command,0)
            import shlex
            return real_run(['sh','-c',command[-1]],check=True,capture_output=True)
        env={'DEPLOY_TARGET':'deploy@example','DEPLOY_BASE':str(self.base),'DEPLOY_HOST_KEY':'synthetic-known-host','DEPLOY_SSH_KEY':'synthetic-private-key','EXPECTED_VERSION':'b'*40}
        with patch.dict(os.environ,env), patch.dict(sys.modules,{'deployment':self.d}), patch.object(sys,'argv',['remote-release',str(archive)]), patch.object(subprocess,'run',side_effect=ssh):
            package(True)
            runpy.run_path(str(ROOT/'deploy/remote-release'),run_name='__main__')
            self.assertEqual(json.loads(marker.read_text())[:2],['release',str(self.base)])
            self.assertTrue(all('StrictHostKeyChecking=yes' in command for command in commands))
            self.assertFalse(list(self.base.glob('.bootstrap-*')))
            marker.unlink(); package(False)
            os.environ['DEPLOY_SSH_KEY'] = env['DEPLOY_SSH_KEY']
            with self.assertRaises(subprocess.CalledProcessError): runpy.run_path(str(ROOT/'deploy/remote-release'),run_name='__main__')
            self.assertFalse(marker.exists())
            self.assertFalse(list(self.base.glob('.bootstrap-*')))

    def test_service_status_is_strict_for_supervisor_and_systemd(self):
        for manager,good,bad in [('supervisor','nebuless-maintenance RUNNING pid 123, uptime 0:00:01','nebuless-maintenance STARTING'),('systemd','active\n','inactive\n')]:
            config=self.d.read_json(self.base/'state/runtime.json'); config['manager']=manager; self.d.write_json(self.base/'state/runtime.json',config)
            with patch.object(self.d.subprocess,'run',return_value=subprocess.CompletedProcess([],0,stdout=good)):
                self.assertTrue(self.d.service_running(self.base,'maintenance'))
            with patch.object(self.d.subprocess,'run',return_value=subprocess.CompletedProcess([],0,stdout=bad)):
                self.assertFalse(self.d.service_running(self.base,'maintenance'))

class DiagnosticRouting(unittest.TestCase):

    def setUp(self):
        spec = importlib.util.spec_from_file_location('diagnostic_deployment', ROOT / 'deploy/deployment.py')
        assert spec is not None and spec.loader is not None
        assert spec is not None and spec.loader is not None
        self.d = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.d)
        self.tmp = tempfile.TemporaryDirectory(prefix='nebuless-diagnostics-')
        self.addCleanup(self.tmp.cleanup)
        self.base = Path(self.tmp.name) / 'hub'
        self.d.initialize(self.base, {'origin': 'https://alpha.example', 'name': 'Alpha-Hub', 'role': 'standby', 'php': str(ROOT / 'bin/php'), 'fpm': str(ROOT / 'tools/runtime/sbin/php-fpm'), 'node': shutil.which('node'), 'manager': 'supervisor'})

    def test_native_cli_routes_ini_override_and_emergency_to_stderr(self):
        ini = self.base / 'override.ini'
        side = self.base / 'unbounded.log'
        ini.write_text('log_errors=1\nerror_log=' + str(side) + '\ndisplay_errors=1\n')
        config = self.d.read_json(self.base / 'state/runtime.json')
        config['php_ini'] = str(ini)
        self.d.write_json(self.base / 'state/runtime.json', config)
        result = subprocess.run([*self.d.php_command(self.base), '-r', 'missing_prebootstrap_function();'], capture_output=True, text=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('missing_prebootstrap_function', result.stderr)
        self.assertEqual(result.stdout, '')
        self.assertFalse(side.exists())
        code = 'require ' + repr(str(ROOT / 'apps/hub/vendor/autoload.php')) + '; $app = new Illuminate\\Foundation\\Application(' + repr(str(ROOT / 'apps/hub')) + '); $app->useStoragePath(' + repr(str(self.base / 'state/storage')) + "); $app->instance('config', new Illuminate\\Config\\Repository(['logging'=>require " + repr(str(ROOT / 'apps/hub/config/logging.php')) + "])); $log = new Illuminate\\Log\\LogManager($app); $log->warning('native-normal'); $log->channel('invalid-channel')->warning('native-emergency');"
        code = code.replace('\\\\', '\\')
        result = subprocess.run([*self.d.php_command(self.base), '-r', code], capture_output=True, text=True, env={**os.environ, 'LOG_CHANNEL': 'stderr'})
        self.assertEqual(result.returncode, 0, result.stderr)
        for marker in ('native-normal', 'native-emergency', 'Unable to create configured logger'):
            self.assertIn(marker, result.stderr)
        self.assertFalse((self.base / 'state/storage/logs/laravel.log').exists())
        # A failing native sink may lose diagnostics but cannot create a fallback file.
        with open('/dev/full', 'wb') as failed_sink:
            result = subprocess.run([*self.d.php_command(self.base), '-r', code],
                                    stdout=subprocess.DEVNULL, stderr=failed_sink, timeout=10,
                                    env={**os.environ, 'LOG_CHANNEL': 'stderr'})
        self.assertIsInstance(result.returncode, int)
        self.assertFalse((self.base / 'state/storage/logs/laravel.log').exists())
        self.assertFalse(side.exists())

    def test_php_binary_descendant_preserves_native_error_sink(self):
        ini = self.base / 'descendant.ini'
        side = self.base / 'descendant-unbounded.log'
        ini.write_text('memory_limit=137M\nlog_errors=1\nerror_log=' + str(side) + '\ndisplay_errors=1\n')
        config = self.d.read_json(self.base / 'state/runtime.json')
        config['php_ini'] = str(ini)
        self.d.write_json(self.base / 'state/runtime.json', config)
        child = '$child = \'fwrite(STDOUT, ini_get("memory_limit")); missing_descendant_function();\'; passthru(escapeshellarg(PHP_BINARY)." -r ".escapeshellarg($child), $status); exit($status);'
        result = subprocess.run([*self.d.php_command(self.base), '-r', child],
                                env=self.d.environment(self.base, self.base / 'releases'), capture_output=True, text=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stdout, '137M')
        self.assertIn('missing_descendant_function', result.stderr)
        self.assertFalse(side.exists())

    def test_native_scan_paths_reject_links_and_override_files(self):
        release = self.base / 'releases'
        self.d.environment(self.base, release)
        directory = self.base / 'state/php-conf.d'
        profile = directory / 'zz-nebuless.ini'
        original = profile.read_bytes()
        target = self.base / 'untouched.ini'
        target.write_bytes(b'untouched')
        profile.unlink()
        profile.symlink_to(target)
        with self.assertRaises(ValueError):
            self.d.environment(self.base, release)
        self.assertEqual(target.read_bytes(), b'untouched')
        profile.unlink()
        self.d.write_private_bytes(profile, original)
        extra = directory / 'zzz-override.ini'
        extra.write_text('error_log=/tmp/forbidden')
        with self.assertRaises(ValueError):
            self.d.environment(self.base, release)
        extra.unlink()
        directory.chmod(0o755)
        with self.assertRaises(ValueError):
            self.d.environment(self.base, release)
        directory.chmod(0o700)

    def set_env(self, values):
        self.d.write_json(self.base / 'state/env.json', values)
        self.d.write_private_bytes(self.base / 'state/hub.env', ''.join((k + '=' + json.dumps(v) + '\n' for k, v in values.items())).encode())

    def test_dual_environment_inherited_drift_and_second_write_failure(self):
        env = self.d.read_json(self.base / 'state/env.json')
        env.update({'LOG_CHANNEL': 'single', 'UNRELATED': 'preserve'})
        self.set_env(env)
        paths = [self.base / 'state/env.json', self.base / 'state/hub.env']
        paths[0].write_bytes(b' ' + paths[0].read_bytes())
        original = {p: p.read_bytes() for p in paths}
        with patch.dict(os.environ, {'LOG_STACK': 'daily,single', 'LOG_DEPRECATIONS_CHANNEL': 'single'}):
            self.assertTrue(self.d.logging_transition(self.base)[1])
            calls = []
            real = self.d.write_private_bytes

            def second_fault(path, content):
                calls.append(path)
                if len(calls) == 2:
                    raise OSError('second write fault')
                real(path, content)
            with patch.object(self.d, 'write_private_bytes', side_effect=second_fault):
                with self.assertRaises(OSError):
                    self.d.change_logging(self.base)
            self.assertEqual({p: p.read_bytes() for p in paths}, original)
            self.d.change_logging(self.base)
            effective = self.d.environment(self.base, self.base / 'releases')
            for key in ('LOG_CHANNEL', 'LOG_STACK', 'LOG_DEPRECATIONS_CHANNEL'):
                self.assertEqual(effective[key], 'stderr')
            self.assertEqual(effective['UNRELATED'], 'preserve')
        self.assertFalse(list((self.base / 'state').glob('.*.new-*')))

    def test_unsupported_effective_logging_and_php_overrides_fail_before_mutation(self):
        originals = {p: p.read_bytes() for p in [self.base / 'state/env.json', self.base / 'state/hub.env']}
        for key, value in [('LOG_STACK', 'papertrail'), ('LOG_DEPRECATIONS_CHANNEL', 'slack'), ('LOG_STDERR_FORMATTER', 'custom'), ('PHP_VALUE', 'error_log='+str(self.base/'side')), ('PHP_INI_SCAN_DIR', str(self.base/'custom'))]:
            with self.subTest(key=key), patch.dict(os.environ, {key: value}):
                with self.assertRaises(ValueError):
                    self.d.logging_transition(self.base)
                self.assertEqual({p: p.read_bytes() for p in originals}, originals)

    def test_combined_auth_logging_and_same_release_drift_restore_true_bytes(self):
        prior = self.base / 'releases' / ('a' * 40)
        prior.mkdir()
        self.d.write_json(prior / 'release.json', {'schema_epoch': 1})
        self.d.switch(self.base, prior)
        env = self.d.read_json(self.base / 'state/env.json')
        env['LOG_CHANNEL'] = 'single'
        self.set_env(env)
        paths = [self.base / 'state/env.json', self.base / 'state/hub.env']
        paths[0].write_bytes(b'  ' + paths[0].read_bytes())
        originals = {p: p.read_bytes() for p in paths}
        canaries = {self.base / 'state' / p: b'preserved' for p in ('auth.sqlite', 'journal/record', 'actions/receipt', 'storage/framework/sessions/session')}
        for p, data in canaries.items():
            p.write_bytes(data)
        calls = []
        with patch.object(self.d, 'quiesce', side_effect=lambda base: calls.append('stop')), patch.object(self.d, 'migrate', side_effect=RuntimeError('migration fault')), patch.object(self.d, 'restart'), patch.object(self.d, 'health', return_value={}):
            with self.assertRaises(RuntimeError):
                self.d.activate(self.base, prior, auth_mode='better-auth')
        self.assertEqual(calls, ['stop'])
        self.assertEqual({p: p.read_bytes() for p in paths}, originals)
        self.assertEqual({p: p.read_bytes() for p in canaries}, canaries)
        with patch.object(self.d, 'quiesce') as stopped, patch.object(self.d, 'migrate'), patch.object(self.d, 'refresh'), patch.object(self.d, 'restart'), patch.object(self.d, 'health', return_value={}):
            self.d.activate(self.base, prior)
            stopped.assert_called_once()
        self.assertEqual(self.d.read_json(paths[0])['LOG_CHANNEL'], 'stderr')

    def test_private_paths_reject_symlink_hardlink_modes_and_mismatch(self):
        path = self.base / 'state/hub.env'
        original = path.read_bytes()
        for fault in ('symlink', 'hardlink', 'mode', 'mismatch', 'parent'):
            with self.subTest(fault=fault):
                other = self.base / 'other'
                if fault in ('symlink', 'hardlink'):
                    path.rename(other)
                    if fault == 'symlink':
                        path.symlink_to(other)
                    else:
                        os.link(other, path)
                elif fault == 'mode':
                    path.chmod(420)
                elif fault == 'parent':
                    (self.base / 'state').chmod(488)
                else:
                    path.write_bytes(b'BAD="mismatch"\n')
                with self.assertRaises(ValueError):
                    self.d.logging_transition(self.base)
                if other.exists():
                    path.unlink()
                    other.rename(path)
                path.write_bytes(original)
                path.chmod(0o600)
                (self.base / 'state').chmod(0o700)

    def test_legacy_disposition_is_explicit_quiesced_fixed_and_never_automatic(self):
        paths = [self.base / 'state/storage/logs/laravel.log', self.base / 'state/logs/fpm-error.log']
        for p in paths:
            p.write_bytes(b'disposable diagnostics')
        with self.assertRaises(ValueError):
            self.d.dispose_legacy_diagnostics(self.base)
        unknown = paths[0].with_name('laravel-rotated.log')
        unknown.write_bytes(b'unknown')
        with self.assertRaises(ValueError):
            self.d.dispose_legacy_diagnostics(self.base, quiesced=True)
        self.assertTrue(all((p.exists() for p in paths)))
        unknown.unlink()
        other = self.base / 'protected'
        other.write_bytes(b'canary')
        for fault in ('symlink', 'hardlink', 'parent'):
            paths[0].unlink()
            if fault == 'symlink':
                paths[0].symlink_to(other)
            elif fault == 'hardlink':
                os.link(other, paths[0])
            else:
                paths[0].write_bytes(b'diagnostic')
                paths[0].parent.chmod(0o750)
            with self.assertRaises(ValueError):
                self.d.dispose_legacy_diagnostics(self.base, quiesced=True)
            self.assertEqual(other.read_bytes(), b'canary')
            paths[0].parent.chmod(0o700)
        paths[0].write_bytes(b'diagnostic')
        real = Path.unlink

        def fail_second(path, *args, **kwargs):
            if path == paths[1]:
                raise OSError('unlink fault')
            return real(path, *args, **kwargs)
        with patch.object(Path, 'unlink', fail_second):
            with self.assertRaises(OSError):
                self.d.dispose_legacy_diagnostics(self.base, quiesced=True)
        self.assertFalse(paths[0].exists())
        self.assertTrue(paths[1].exists())
        self.d.dispose_legacy_diagnostics(self.base, quiesced=True)
        self.assertEqual(self.d.legacy_diagnostics(self.base), [])

    def test_real_fpm_service_captures_journal_socket_stderr(self):
        import selectors
        import struct
        release = self.base / 'releases' / ('d' * 40)
        (release / 'deploy/systemd-user').mkdir(parents=True)
        (release / 'apps/hub/public').mkdir(parents=True)
        for name in ('install', 'service', 'deployment.py'):
            shutil.copyfile(ROOT / 'deploy' / name, release / 'deploy' / name)
        shutil.copyfile(ROOT / 'deploy/systemd-user/nebuless.service', release / 'deploy/systemd-user/nebuless.service')
        self.d.switch(self.base, release)
        with socket.socket() as reservation:
            reservation.bind(('127.0.0.1', 0))
            port = reservation.getsockname()[1]
        config = self.d.read_json(self.base / 'state/runtime.json')
        config.update(manager='systemd', fpm_port=port, caddy='/unused-disposable')
        wrapper = self.base / 'fpm'
        wrapper.write_text('#!/bin/sh\nexec ' + str(ROOT / 'tools/runtime/sbin/php-fpm') + (' -R' if os.getuid() == 0 else '') + ' "$@"\n')
        wrapper.chmod(0o700)
        config['fpm'] = str(wrapper)
        self.d.write_json(self.base / 'state/runtime.json', config)
        with patch.dict(sys.modules, {'deployment': self.d}), patch.object(Path, 'home', return_value=self.base):
            runpy.run_path(str(ROOT / 'deploy/install'))['configure'](self.base)
        if os.getuid() == 0:
            fpm = self.base / 'state/fpm.conf'
            fpm.write_text(fpm.read_text().replace('user = nobody', 'user = root').replace('group = nogroup', 'group = root'))
        script = release / 'apps/hub/public/probe.php'
        script.write_text("<?php error_log('journal-worker-marker'); echo 'journal-response';")
        reader, writer = socket.socketpair()
        self.addCleanup(reader.close)
        selector = selectors.DefaultSelector()
        self.addCleanup(selector.close)
        selector.register(reader, selectors.EVENT_READ)
        process = subprocess.Popen([sys.executable, str(release / 'deploy/service'), str(self.base), 'fpm'], stdout=subprocess.DEVNULL, stderr=writer.fileno())
        writer.close()
        output = bytearray()

        def capture(marker):
            deadline = time.monotonic() + 10
            while marker not in output:
                self.assertTrue(selector.select(max(0, deadline - time.monotonic())), output.decode(errors='replace'))
                chunk = reader.recv(65536)
                self.assertTrue(chunk, output.decode(errors='replace'))
                output.extend(chunk)

        try:
            capture(b'ready to handle connections')
            self.assertIsNone(process.poll())

            def record(kind, payload):
                return struct.pack('!BBHHBB', 1, kind, 1, len(payload), 0, 0) + payload
            params = {'SCRIPT_FILENAME': str(script), 'REQUEST_METHOD': 'GET', 'SERVER_PROTOCOL': 'HTTP/1.1', 'CONTENT_LENGTH': '0'}
            encoded = b''
            for key, value in params.items():
                k, v = key.encode(), value.encode()
                encoded += bytes([len(k)]) + (bytes([len(v)]) if len(v) < 128 else struct.pack('!I', len(v) | 2147483648)) + k + v
            with socket.create_connection(('127.0.0.1', port), timeout=3) as connection:
                connection.sendall(record(1, struct.pack('!HB5x', 1, 0)) + record(4, encoded) + record(4, b'') + record(5, b''))
                response = bytearray()
                while chunk := connection.recv(65536):
                    response.extend(chunk)
            self.assertIn(b'journal-response', response)
            capture(b'journal-worker-marker')
            self.assertFalse((self.base / 'state/logs/fpm-error.log').exists())
        finally:
            if process.poll() is None:
                process.terminate()
            process.wait(timeout=5)

    def test_real_fpm_service_and_supervisor_rotation(self):
        import struct
        import xmlrpc.client
        supervisor = shutil.which('supervisord')
        assert supervisor is not None, 'Supervisor is a required local sink prerequisite'
        release = self.base / 'releases' / ('c' * 40)
        (release / 'deploy/supervisor').mkdir(parents=True)
        (release / 'apps/hub/public').mkdir(parents=True)
        for name in ('install', 'service', 'deployment.py'):
            shutil.copyfile(ROOT / 'deploy' / name, release / 'deploy' / name)
        shutil.copyfile(ROOT / 'deploy/supervisor/program.conf', release / 'deploy/supervisor/program.conf')
        self.d.switch(self.base, release)
        with socket.socket() as reservation:
            reservation.bind(('127.0.0.1', 0))
            port = reservation.getsockname()[1]
        config = self.d.read_json(self.base / 'state/runtime.json')
        config['fpm_port'] = port
        config['caddy'] = '/unused-disposable'
        side = self.base / 'unbounded.log'
        ini = self.base / 'override.ini'
        ini.write_text('log_errors=1\ndisplay_errors=1\nerror_log=' + str(side) + '\n')
        config['php_ini'] = str(ini)
        wrapper = self.base / 'fpm'
        wrapper.write_text('#!/bin/sh\nexec ' + str(ROOT / 'tools/runtime/sbin/php-fpm') + (' -R' if os.getuid() == 0 else '') + ' "$@"\n')
        wrapper.chmod(0o700)
        config['fpm'] = str(wrapper)
        self.d.write_json(self.base / 'state/runtime.json', config)
        with patch.dict(sys.modules, {'deployment': self.d}):
            runpy.run_path(str(ROOT / 'deploy/install'))['configure'](self.base)
        if os.getuid() == 0:
            fpm = self.base / 'state/fpm.conf'
            fpm.write_text(fpm.read_text().replace('user = nobody', 'user = root').replace('group = nogroup', 'group = root'))
        logging = 'require ' + repr(str(ROOT / 'apps/hub/vendor/autoload.php')) + '; $app = new Illuminate\\Foundation\\Application(' + repr(str(ROOT / 'apps/hub')) + '); $app->useStoragePath(' + repr(str(self.base / 'state/storage')) + "); $app->instance('config', new Illuminate\\Config\\Repository(['logging'=>require " + repr(str(ROOT / 'apps/hub/config/logging.php')) + '])); $log = new Illuminate\\Log\\LogManager($app); '
        script = release / 'apps/hub/public/probe.php'
        script.write_text('<?php ' + logging + "$log->warning('fpm-normal'); $log->channel('invalid')->warning('fpm-emergency'); trigger_error('fpm-worker-warning', E_USER_WARNING); echo 'fpm-complete';")
        bad = script.with_name('bad.php')
        bad.write_text('<?php invalid parse syntax;')
        fifo = self.base / 'commands'
        os.mkfifo(fifo, 0o600)
        artisan = release / 'apps/hub/artisan'
        artisan.write_text('<?php ' + logging + '$input=fopen(' + repr(str(fifo)) + ",'r+'); while (($line=fgets($input))!==false) { $n=(int)$line; for($i=0;$i<$n;$i++) { $log->warning('burst-'.str_repeat('x',32768)); } $log->warning('cli-complete-'.$n); }")
        rendered = (self.base / 'supervisor.conf').read_text()
        head = rendered.split('[program:')[0]
        template = (ROOT / 'deploy/supervisor/program.conf').read_text()
        programs = ''.join((template.replace('@SERVICE@', name).replace('@BASE@', str(self.base)).replace('@PYTHON@', sys.executable).replace('startsecs=2', 'startsecs=0') for name in ('fpm', 'scheduler')))
        (self.base / 'supervisor.conf').write_text(head + programs)
        out = self.base / 'manager-console'
        with out.open('wb') as console:
            manager = subprocess.Popen([supervisor, '-n', '-c', str(self.base / 'supervisor.conf')], stdout=console, stderr=console)

        def until(predicate):
            deadline = time.monotonic() + 15
            while time.monotonic() < deadline:
                self.assertIsNone(manager.poll(), out.read_text()[-1000:])
                try:
                    value = predicate()
                    if value:
                        return value
                except (OSError, xmlrpc.client.Fault):
                    pass
            self.fail('Disposable sink deadline exceeded: ' + ''.join((p.read_text()[-4000:] for p in (self.base / 'state/logs').glob('*.log'))))

        socket_path = self.base / 'run/supervisor.sock'

        class Transport(xmlrpc.client.Transport):

            def make_connection(self, host):
                import http.client
                if host == self._connection[0] and self._connection[1] is not None:
                    return self._connection[1]
                connection = http.client.HTTPConnection('localhost')
                self._connection = (host, connection)

                def connect():
                    connection.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
                    connection.sock.settimeout(2)
                    connection.sock.connect(str(socket_path))
                connection.connect = connect
                return connection
        rpc = xmlrpc.client.ServerProxy('http://localhost', transport=Transport())

        def fcgi(path):

            def record(kind, payload):
                return struct.pack('!BBHHBB', 1, kind, 1, len(payload), 0, 0) + payload
            params = {'SCRIPT_FILENAME': str(path), 'SCRIPT_NAME': '/' + path.name, 'REQUEST_METHOD': 'GET', 'REQUEST_URI': '/' + path.name, 'SERVER_PROTOCOL': 'HTTP/1.1', 'CONTENT_LENGTH': '0'}
            encoded = b''
            for key, value in params.items():
                k, v = (key.encode(), value.encode())

                def length(n):
                    return bytes([n]) if n < 128 else struct.pack('!I', n | 2147483648)
                encoded += length(len(k)) + length(len(v)) + k + v
            with socket.create_connection(('127.0.0.1', port), timeout=3) as connection:
                connection.sendall(record(1, struct.pack('!HB5x', 1, 0)) + record(4, encoded) + record(4, b'') + record(5, b''))
                result = b''
                while True:
                    chunk = connection.recv(65536)
                    if not chunk:
                        break
                    result += chunk
                return result
        try:
            def running(value):
                return isinstance(value, dict) and value.get('statename') == 'RUNNING'

            until(lambda: running(rpc.supervisor.getState()))
            until(lambda: all(running(rpc.supervisor.getProcessInfo('nebuless-' + name)) for name in ('fpm', 'scheduler')))
            until(lambda: b'fpm-complete' in fcgi(script))
            self.assertIn(b'500', fcgi(bad))
            fpm_log = self.base / 'state/logs/fpm.log'
            until(lambda: fpm_log.exists() and all((m in fpm_log.read_text() for m in ('fpm-normal', 'fpm-emergency', 'fpm-worker-warning', 'Parse error'))))
            log = self.base / 'state/logs/scheduler.log'
            descriptor = os.open(fifo, os.O_RDWR | os.O_NONBLOCK)
            try:
                os.write(descriptor, b'320\n')
                self.assertIn(b'fpm-complete', fcgi(script))
                # Supervisor writes then renames files during rotation. Observe
                # completion and both backups together, not an intermediate name.
                until(lambda: log.with_name(log.name + '.2').exists() and any(b'cli-complete-320' in path.read_bytes() for path in log.parent.glob('scheduler.log*')))
                sizes = {p.name: p.stat().st_size for p in log.parent.glob('scheduler.log*')}
                self.assertLessEqual(len(sizes), 3)
                self.assertTrue(all((size <= 2 * 1024 * 1024 + 65536 for size in sizes.values())), sizes)
                self.assertFalse(any((b'burst-' not in p.read_bytes() for p in [log.with_name(log.name + '.1'), log.with_name(log.name + '.2')])))
                before = {p: p.stat().st_size for p in log.parent.glob('scheduler.log*')}
                rpc.supervisor.getState()
                self.assertEqual({p: p.stat().st_size for p in before}, before)
                rpc.supervisor.clearProcessLogs('nebuless-scheduler')
                os.write(descriptor, b'1\n')
                until(lambda: b'cli-complete-1' in log.read_bytes())
                manager.send_signal(__import__('signal').SIGUSR2)
                os.write(descriptor, b'2\n')
                until(lambda: b'cli-complete-2' in log.read_bytes())
                self.assertIn(b'fpm-complete', fcgi(script))
            finally:
                os.close(descriptor)
            self.assertFalse(side.exists())
            self.assertFalse((self.base / 'state/storage/logs/laravel.log').exists())
            self.assertFalse((self.base / 'state/logs/fpm-error.log').exists())
            print(json.dumps({'real_sinks': ['service CLI', 'FPM master', 'FPM worker', 'Laravel normal', 'Laravel emergency', 'PHP pre-bootstrap'], 'rotation_bytes': sizes, 'nominal_bytes': 2097152, 'continued_after_reopen': True, 'classification': 'disposable Supervisor, not installed journal'}))
        finally:
            manager.terminate()
            manager.wait(timeout=15)
            rpc('close')()

    def test_environment_replace_and_rollback_failure_are_visible(self):
        before = {p: p.read_bytes() for p in (self.base / 'state/env.json', self.base / 'state/hub.env')}
        with patch.object(self.d.os, 'replace', side_effect=OSError('disk write fault')):
            with self.assertRaisesRegex(OSError, 'rollback failed'):
                self.d.write_environment(before, {'LOG_CHANNEL': 'stderr'})
        self.assertEqual({p: p.read_bytes() for p in before}, before)
        self.assertFalse(list((self.base / 'state').glob('.*.new-*')))

    def test_new_install_normalizes_inherited_file_channels(self):
        base = self.base.parent / 'new-install'
        with patch.dict(os.environ, {'LOG_STACK': 'daily,single', 'LOG_DEPRECATIONS_CHANNEL': 'single'}):
            self.d.initialize(base, self.d.read_json(self.base / 'state/runtime.json'))
            _, values = self.d.private_environment(base)
            for key in ('LOG_CHANNEL', 'LOG_STACK', 'LOG_DEPRECATIONS_CHANNEL'):
                self.assertEqual(values[key], 'stderr')

    def test_same_release_fpm_worker_drift_requires_quiescence(self):
        prior = self.base / 'releases' / ('a' * 40)
        prior.mkdir()
        self.d.write_json(prior / 'release.json', {'schema_epoch': 1})
        self.d.switch(self.base, prior)
        (self.base / 'state/fpm.conf').write_text('error_log = /proc/self/fd/2\ncatch_workers_output = no\n')
        with patch.object(self.d, 'quiesce') as stopped, patch.object(self.d, 'migrate'), patch.object(self.d, 'refresh'), patch.object(self.d, 'restart'), patch.object(self.d, 'health', return_value={}):
            self.d.activate(self.base, prior)
            stopped.assert_called_once()

    def test_real_release_entry_refuses_pending_legacy_disposition(self):
        version = 'd' * 40
        prior = self.base / 'releases' / version
        prior.mkdir()
        manifest = {'version': version, 'schema_epoch': 1, 'files': {}}
        self.d.write_json(prior / 'release.json', manifest)
        self.d.switch(self.base, prior)
        archive = self.base / 'candidate.tar.gz'
        with tarfile.open(archive, 'w:gz') as output:
            raw = json.dumps(manifest).encode()
            member = tarfile.TarInfo('release.json')
            member.size = len(raw)
            output.addfile(member, io.BytesIO(raw))
        legacy = self.base / 'state/storage/logs/laravel.log'
        legacy.write_bytes(b'preserve pending approval')
        before = {p: p.read_bytes() for p in (self.base / 'state/env.json', self.base / 'state/hub.env')}
        result = subprocess.run([str(ROOT / 'deploy/release'), str(self.base), str(archive)], capture_output=True, text=True, timeout=10)
        self.assertEqual(result.returncode, 1)
        self.assertIn('Deployment failed (ValueError)', result.stderr)
        self.assertEqual(legacy.read_bytes(), b'preserve pending approval')
        self.assertEqual({p: p.read_bytes() for p in before}, before)

    def test_defaults_are_native(self):
        self.assertEqual(self.d.read_json(self.base / 'state/env.json')['LOG_CHANNEL'], 'stderr')

if __name__ == '__main__': unittest.main()
