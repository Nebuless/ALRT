import base64
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'deploy'))

class RebootProvisioning(unittest.TestCase):
    def setUp(self):
        spec=importlib.util.spec_from_file_location('setup',ROOT/'deploy/reboot_setup.py')
        self.module=importlib.util.module_from_spec(spec);spec.loader.exec_module(self.module)
        self.temporary=tempfile.TemporaryDirectory();self.addCleanup(self.temporary.cleanup)
        self.base=Path(self.temporary.name)
        self.request={'machine_id':'11111111-1111-4111-8111-111111111111','machine_name':'Alpha-Hub','issuer':'22222222-2222-4222-8222-222222222222','key_id':'primary-install','public_key':base64.b64encode(bytes(32)).decode(),'credential_hash':'a'*64,'collector_uid':65534}

    def test_new_protected_history_is_exclusive_and_never_reset(self):
        paths={name:self.base/name for name in ['HELPER','POLICY','LEDGER','LOCK','SUDOERS']}
        binary=self.base/'source-helper';binary.write_bytes(b'reviewed helper bytes')
        with patch.multiple(self.module,**paths),patch.object(self.module.os,'geteuid',return_value=0),patch.object(self.module,'protected_directory'),patch.object(self.module.subprocess,'run',return_value=subprocess.CompletedProcess([],0,stdout='Sudo version 1.9.17')):
            self.module.provision(self.request,binary)
            self.assertEqual(json.loads(paths['LEDGER'].read_text()),{'version':1,'consumed':{}})
            self.assertEqual(paths['POLICY'].stat().st_mode & 0o777,0o600)
            paths['LEDGER'].write_text('{"version":1,"consumed":{"already-attempted":true}}')
            prior=paths['LEDGER'].read_bytes()
            with self.assertRaises(ValueError):self.module.provision(self.request,binary)
            self.assertEqual(paths['LEDGER'].read_bytes(),prior)
            self.assertIn('/usr/local/libexec/nebuless-reboot-helper ""',paths['SUDOERS'].read_text())

    def installed(self):
        (self.base/'state').mkdir()
        policy={**self.request,'collector_uid':os.geteuid() or 65534}
        config={'name':'Alpha-Hub','role':'standby','manager':'systemd'}
        collector={'machine_id':policy['machine_id'],'primary_hub_id':policy['issuer'],'destinations':[{'id':policy['issuer']}]}
        for name,data in [('runtime.json',config),('collector.json',collector)]:
            (self.base/'state'/name).write_text(json.dumps(data))
        if os.geteuid()==0:
            for path in [self.base,self.base/'state',*list((self.base/'state').iterdir())]:os.chown(path,65534,65534)
        return policy

    def test_request_is_bound_to_installed_target_uid_and_primary(self):
        policy=self.installed()
        self.module.validate_installed_target(self.base,policy)
        for key,value in [('machine_name','Beta-Restricted'),('machine_id','33333333-3333-4333-8333-333333333333'),('issuer','44444444-4444-4444-8444-444444444444'),('collector_uid',policy['collector_uid']+1)]:
            with self.assertRaises(ValueError):self.module.validate_installed_target(self.base,{**policy,key:value})

    def test_native_encryption_receives_stdin_without_credential_environment(self):
        policy=self.installed()
        canary='synthetic-local-reboot-credential-at-least32bytes'
        def encrypt(command,**options):
            self.assertFalse('NEBULESS_REBOOT_CREDENTIAL' in options['env'])
            self.assertEqual(options['input'],canary.encode())
            Path(command[-1]).write_bytes(b'encrypted-native-ciphertext')
            return subprocess.CompletedProcess(command,0)
        home=self.base/'home';home.mkdir()
        config_home=home/'owner-custom-config'
        native=config_home/'credstore.encrypted';native.mkdir(parents=True)
        unrelated=native/'unrelated-owner-credential';unrelated.write_bytes(b'unrelated encrypted credential')
        public={key:policy[key] for key in ['issuer','key_id','public_key']}
        with patch.object(self.module.os,'geteuid',return_value=policy['collector_uid']),patch.object(self.module.Path,'home',return_value=home),patch.object(self.module.subprocess,'run',side_effect=encrypt),patch.dict(os.environ,{'NEBULESS_REBOOT_CREDENTIAL':canary,'XDG_CONFIG_HOME':str(config_home),'XDG_RUNTIME_DIR':str(home/'run')}):
            request=self.module.prepare_target(self.base,public,canary)
        self.assertEqual(json.loads(request.read_text())['credential_hash'],__import__('hashlib').sha256(canary.encode()).hexdigest())
        for file in self.base.rglob('*'):
            if file.is_file():self.assertFalse(canary.encode() in file.read_bytes(),'Raw credential persisted')
        self.assertEqual((native/'nebuless-reboot').read_bytes(),b'encrypted-native-ciphertext')
        self.assertEqual((native/'nebuless-reboot').stat().st_mode & 0o777,0o600)
        self.assertEqual(unrelated.read_bytes(),b'unrelated encrypted credential')
        self.assertEqual((config_home/'systemd/user/nebuless-collector.service.d/credential.conf').read_text(),'[Service]\nImportCredential=nebuless-reboot\n')
        with patch.object(self.module.os,'geteuid',return_value=policy['collector_uid']),patch.object(self.module.Path,'home',return_value=home),patch.object(self.module.subprocess,'run') as encryption,patch.dict(os.environ,{'XDG_CONFIG_HOME':str(config_home),'XDG_RUNTIME_DIR':str(home/'run')}):
            with self.assertRaises(ValueError):self.module.prepare_target(self.base,public,canary)
            encryption.assert_not_called()
        self.assertEqual(unrelated.read_bytes(),b'unrelated encrypted credential')

    def test_existing_native_id_refuses_before_enrollment_mutation(self):
        policy=self.installed()
        home=self.base/'home';home.mkdir()
        native=home/'.config/credstore';native.mkdir(parents=True)
        existing=native/'nebuless-reboot';existing.write_bytes(b'owner-existing-native-id')
        public={key:policy[key] for key in ['issuer','key_id','public_key']}
        with patch.object(self.module.os,'geteuid',return_value=policy['collector_uid']),patch.object(self.module.Path,'home',return_value=home),patch.object(self.module.subprocess,'run') as encryption,patch.dict(os.environ,{'XDG_CONFIG_HOME':str(home/'.config'),'XDG_RUNTIME_DIR':str(home/'run')}):
            with self.assertRaises(ValueError):self.module.prepare_target(self.base,public,'synthetic-private-credential-of-at-least32bytes')
            encryption.assert_not_called()
        self.assertEqual(existing.read_bytes(),b'owner-existing-native-id')
        self.assertFalse((self.base/'state/reboot-enrollment.json').exists())

    def test_nonstandard_sudo_refuses_before_any_protected_mutation(self):
        paths={name:self.base/name for name in ['HELPER','POLICY','LEDGER','LOCK','SUDOERS']}
        binary=self.base/'source-helper';binary.write_bytes(b'helper')
        with patch.multiple(self.module,**paths),patch.object(self.module.os,'geteuid',return_value=0),patch.object(self.module.subprocess,'run',return_value=subprocess.CompletedProcess([],0,stdout='run0-sudo (run0-wrappers)')):
            with self.assertRaises(ValueError):self.module.provision(self.request,binary)
        self.assertFalse(any(path.exists() for path in paths.values()))

if __name__=='__main__':unittest.main()
