"""Private authentication transitions preserve the installed code and authority."""
import importlib.util
import datetime
from email.message import Message
import io
import json
from pathlib import Path
import runpy
import shutil
import sqlite3
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]


class TailscaleAuthentication(unittest.TestCase):
    def setUp(self):
        spec = importlib.util.spec_from_file_location('deployment', ROOT/'deploy/deployment.py')
        self.d = importlib.util.module_from_spec(spec); spec.loader.exec_module(self.d)
        self.tmp = tempfile.TemporaryDirectory(prefix='nebuless-tailnet-release-'); self.addCleanup(self.tmp.cleanup)
        self.base = Path(self.tmp.name)/'hub'
        self.config = {'origin':'https://hub.example:8444','name':'Alpha-Hub','role':'standby','php':'/checked/php','node':'/checked/node','fpm':'/checked/fpm','caddy':'/checked/caddy','manager':'supervisor','auth_mode':'better-auth'}
        self.d.initialize(self.base,self.config)
        self.prior,self.candidate=[self.base/'releases'/v for v in ('a'*40,'b'*40)]
        for release in (self.prior,self.candidate):
            release.mkdir();self.d.write_json(release/'release.json',{'schema_epoch':1})
            (release/'deploy/supervisor').mkdir(parents=True)
            shutil.copyfile(ROOT/'deploy/supervisor/program.conf',release/'deploy/supervisor/program.conf')
        self.d.switch(self.base,self.prior)

    def environments(self):
        paths=[self.base/'state/env.json',self.base/'state/hub.env']
        return {p.name:p.read_bytes() for p in paths}

    def test_new_private_installations_use_tailscale_without_owner_provisioning(self):
        config={k:v for k,v in self.config.items() if k!='auth_mode'}
        base=Path(self.tmp.name)/'new-private'
        self.d.initialize(base,config)
        self.assertEqual(self.d.read_json(base/'state/env.json')['NEBULESS_AUTH_MODE'],'tailscale')
        self.assertEqual((base/'state/env.json').stat().st_mode & 0o777,0o600)

    def test_edge_marker_is_a_server_parameter_only_in_the_owned_loopback_handler(self):
        with patch.dict('sys.modules',{'deployment':self.d}):
            runpy.run_path(str(ROOT/'deploy/install'))['configure'](self.base)
        text=(self.base/'state/Caddyfile').read_text()
        self.assertIn('bind 127.0.0.1',text)
        self.assertIn('env NEBULESS_TAILSCALE_INGRESS 1',text)
        self.assertIn('env NEBULESS_TAILSCALE_SOURCE {http.request.header.X-Forwarded-For}',text)
        self.assertNotIn('header_up NEBULESS_TAILSCALE_INGRESS',text)

    def test_requested_transition_changes_only_mode_after_quiescence(self):
        old=self.d.read_json(self.base/'state/env.json');events=[]
        def health(*args,**kwargs):
            env=self.d.read_json(self.base/'state/env.json')
            self.assertEqual(env,{**old,'NEBULESS_AUTH_MODE':'tailscale'})
            self.assertEqual(events,['stop','migrate','refresh','restart'])
            return {'auth':'tailscale'}
        with patch.object(self.d,'quiesce',side_effect=lambda *_:events.append('stop')),patch.object(self.d,'migrate',side_effect=lambda *_:events.append('migrate')),patch.object(self.d,'refresh',side_effect=lambda *_:events.append('refresh')),patch.object(self.d,'restart',side_effect=lambda *_:events.append('restart')),patch.object(self.d,'health',side_effect=health):
            self.d.activate(self.base,self.candidate,auth_mode='tailscale')
        self.assertEqual((self.base/'current').resolve(),self.candidate)
        dotenv=dict(line.split('=',1) for line in (self.base/'state/hub.env').read_text().splitlines())
        self.assertEqual({k:json.loads(v) for k,v in dotenv.items()},{**old,'NEBULESS_AUTH_MODE':'tailscale'})

    def test_failed_candidate_restores_both_original_environments_before_prior_health(self):
        original=self.environments();calls=[]
        def health(*args,**kwargs):
            calls.append((self.base/'current').resolve().name)
            if len(calls)==1:raise RuntimeError('candidate failure')
            self.assertEqual(self.environments(),original)
            self.assertEqual((self.base/'current').resolve(),self.prior)
            return {'auth':True}
        with patch.object(self.d,'quiesce'),patch.object(self.d,'migrate'),patch.object(self.d,'refresh'),patch.object(self.d,'restart'),patch.object(self.d,'health',side_effect=health):
            with self.assertRaisesRegex(RuntimeError,'candidate failure'):
                self.d.activate(self.base,self.candidate,auth_mode='tailscale')
        self.assertEqual(self.environments(),original)
        state=self.d.read_json(self.base/'deployment.json')
        self.assertEqual(state['status'],'failed');self.assertEqual(state['recovery']['status'],'healthy')

    def test_partial_environment_write_restores_exact_bytes_and_prior_health(self):
        original=self.environments();write=self.d.write_private_bytes
        for after_write in (False,True):
            with self.subTest(failed_after_dotenv_write=after_write):
                failed=False
                def interrupted(path,content):
                    nonlocal failed
                    if path.name=='hub.env' and not failed:
                        failed=True
                        self.assertNotEqual(self.environments()['env.json'],original['env.json'])
                        if after_write:write(path,content)
                        raise OSError('injected environment write failure')
                    write(path,content)
                def prior_health(*args,**kwargs):
                    self.assertEqual(self.environments(),original)
                    self.assertEqual((self.base/'current').resolve(),self.prior)
                    for name in original:
                        self.assertEqual((self.base/'state'/name).stat().st_mode & 0o777,0o600)
                    return {'auth':True}
                with patch.object(self.d,'write_private_bytes',side_effect=interrupted),patch.object(self.d,'quiesce'),patch.object(self.d,'migrate') as migrate,patch.object(self.d,'refresh'),patch.object(self.d,'restart') as restart,patch.object(self.d,'health',side_effect=prior_health) as health:
                    with self.assertRaisesRegex(OSError,'injected environment write failure'):
                        self.d.activate(self.base,self.candidate,auth_mode='tailscale')
                self.assertTrue(failed);migrate.assert_not_called();restart.assert_called_once();health.assert_called_once()
                self.assertEqual(self.environments(),original)
                state=self.d.read_json(self.base/'deployment.json')
                self.assertEqual(state['status'],'failed');self.assertEqual(state['recovery']['status'],'healthy')

    def test_unrequested_upgrade_preserves_the_existing_authentication_choice(self):
        original=self.environments()
        with patch.object(self.d,'quiesce'),patch.object(self.d,'migrate'),patch.object(self.d,'refresh'),patch.object(self.d,'restart'),patch.object(self.d,'health',return_value={}):
            self.d.activate(self.base,self.candidate)
        self.assertEqual(self.environments(),original)

    def test_private_readiness_uses_the_tailnet_page_without_provider_rpc(self):
        self.d.change_authentication(self.base,'tailscale')
        with sqlite3.connect(self.base/'state/hub.sqlite') as db:
            db.execute('CREATE TABLE hub_heartbeats(service TEXT,observed_at TEXT)')
            at=datetime.datetime.now(datetime.timezone.utc).isoformat()
            db.executemany('INSERT INTO hub_heartbeats VALUES (?,?)',[('scheduler',at),('urgent_worker',at)])
        class Reply(io.BytesIO):
            status=200;headers=Message()
        Reply.headers.add_header('Set-Cookie','nebuless_session=opaque; Secure; HttpOnly; SameSite=Lax; Path=/')
        def opened(address,**kwargs):
            self.assertIsInstance(address,str,'Private readiness must not call the Better Auth RPC')
            return Reply(b'<main data-auth-mode="tailscale"><a href="https://hub.example:8444/login">Access</a></main>')
        with patch.object(self.d.urllib.request,'build_opener') as build,patch.object(self.d,'service_running',return_value=True):
            build.return_value.open.side_effect=opened
            self.assertTrue(self.d.health(self.base)['auth'])
            self.assertEqual(build.return_value.open.call_count,1)

    def test_invalid_mode_cannot_fall_back_to_application_authentication(self):
        original=self.environments()
        with self.assertRaises(ValueError):self.d.change_authentication(self.base,'public')
        self.assertEqual(self.environments(),original)


if __name__=='__main__':unittest.main()
