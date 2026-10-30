"""Explicit local enrollment; protected paths are fixed and never reset or recovered here."""
import base64
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import subprocess
import tempfile
import uuid
from deployment import read_json, write_json

HELPER = Path('/usr/local/libexec/nebuless-reboot-helper')
POLICY = Path('/etc/nebuless/reboot-policy.json')
LEDGER = Path('/var/lib/nebuless-reboot/consumed.json')
LOCK = Path('/var/lib/nebuless-reboot/lock')
SUDOERS = Path('/etc/sudoers.d/nebuless-reboot')


def exclusive(path, data, mode=0o600):
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
    with os.fdopen(descriptor, 'wb') as file:
        file.write(data); file.flush(); os.fsync(file.fileno())


def sync_directory(path):
    descriptor = os.open(path, os.O_RDONLY)
    try: os.fsync(descriptor)
    finally: os.close(descriptor)


def validate(policy):
    required = {'machine_id','machine_name','issuer','key_id','public_key','credential_hash','collector_uid'}
    if set(policy) != required or policy['machine_name'] not in ('Alpha-Hub','Beta-Restricted') or type(policy['collector_uid']) is not int or policy['collector_uid'] <= 0:
        raise ValueError('Nonzero target collector UID and fixed fleet target required')
    for name in ('machine_id','issuer'): uuid.UUID(policy[name])
    if len(base64.b64decode(policy['public_key'],validate=True)) != 32 or not re.fullmatch('[a-f0-9]{64}',policy['credential_hash']) or not re.fullmatch('[A-Za-z0-9_-]{1,64}',policy['key_id']): raise ValueError('Invalid pinned policy')


def protected_directory(path):
    if path.is_symlink(): raise ValueError('Protected directory cannot be a symlink')
    if not path.exists():
        if not path.parent.is_dir() or path.parent.stat().st_uid != 0 or path.parent.stat().st_mode & 0o022: raise ValueError('Protected parent required')
        path.mkdir(mode=0o700)
    info = path.stat()
    if not path.is_dir() or info.st_uid != 0 or info.st_mode & 0o022: raise ValueError('Root-owned protected directory required')


def validate_installed_target(base, policy):
    validate(policy)
    config = read_json(base/'state/runtime.json')
    collector = read_json(base/'state/collector.json')
    if config['name'] != policy['machine_name'] or config['role'] != 'standby' or config['manager'] != 'systemd' or collector['machine_id'] != policy['machine_id'] or collector['primary_hub_id'] != policy['issuer'] or not any(destination['id'] == policy['issuer'] for destination in collector['destinations']):
        raise ValueError('Request does not match installed target and pinned primary')
    for path in (base,base/'state',base/'state/runtime.json',base/'state/collector.json'):
        if path.is_symlink() or path.stat().st_uid != policy['collector_uid']:
            raise ValueError('Request does not match installed service owner')


def provision(policy, binary):
    if os.geteuid() != 0: raise PermissionError('Run only from the trusted owner root provisioning terminal')
    validate(policy)
    paths = (HELPER,POLICY,LEDGER,LOCK,SUDOERS)
    if any(path.exists() or path.is_symlink() for path in paths): raise ValueError('Existing helper identity/history is never replaced; use explicit recovery')
    result = subprocess.run(['/usr/bin/sudo','--version'],check=True,capture_output=True,text=True,env={'PATH':'/usr/sbin:/usr/bin:/sbin:/bin'})
    if not result.stdout.startswith('Sudo version '): raise ValueError('A compatible real sudo launcher is required; run0 wrappers are not enrolled')
    username = pwd.getpwuid(policy['collector_uid']).pw_name
    if not re.fullmatch('[A-Za-z0-9_.-]+',username): raise ValueError('Invalid collector account')
    grant = f'{username} ALL=(root) NOPASSWD: /usr/local/libexec/nebuless-reboot-helper ""\n'
    for directory in {path.parent for path in paths}: protected_directory(directory)
    temporary = None
    try:
        descriptor, name = tempfile.mkstemp(prefix='.nebuless-validate-',dir=SUDOERS.parent)
        temporary = Path(name)
        with os.fdopen(descriptor,'w') as file: file.write(grant)
        visudo = '/usr/sbin/visudo' if Path('/usr/sbin/visudo').is_file() else '/usr/bin/visudo'
        subprocess.run([visudo,'-cf',str(temporary)],check=True,capture_output=True,env={'PATH':'/usr/sbin:/usr/bin:/sbin:/bin'})
        exclusive(HELPER,Path(binary).read_bytes(),0o755)
        exclusive(POLICY,json.dumps(policy).encode())
        exclusive(LEDGER,b'{"version":1,"consumed":{}}\n')
        exclusive(LOCK,b'')
        exclusive(SUDOERS,grant.encode(),0o440)
        for directory in {path.parent for path in paths}: sync_directory(directory)
    finally:
        if temporary is not None: temporary.unlink(missing_ok=True)


def prepare_target(base, public, credential):
    config = read_json(base/'state/runtime.json')
    collector = read_json(base/'state/collector.json')
    if os.geteuid() == 0 or config['name'] not in ('Alpha-Hub','Beta-Restricted') or config['manager'] != 'systemd': raise ValueError('Run as the target collector user on its native user manager')
    if set(public) != {'issuer','key_id','public_key'} or collector['primary_hub_id'] != public['issuer']: raise ValueError('Pinned primary identity mismatch')
    if len(credential.encode()) < 32 or len(credential.encode()) > 1024 or '\x00' in credential or '\n' in credential or '\r' in credential: raise ValueError('Use a private credential of 32–1024 bytes')
    config_home = Path(os.environ.get('XDG_CONFIG_HOME') or Path.home()/'.config')
    runtime_home = Path(os.environ.get('XDG_RUNTIME_DIR') or '/run/user/'+str(os.geteuid()))
    if not config_home.is_absolute() or not runtime_home.is_absolute(): raise ValueError('Native credential directories must be absolute')
    encrypted, request = config_home/'credstore.encrypted/nebuless-reboot', base/'state/reboot-enrollment.json'
    dropin = config_home/'systemd/user/nebuless-collector.service.d/credential.conf'
    native_ids = [directory/store/'nebuless-reboot' for directory in (config_home,runtime_home) for store in ('credstore','credstore.encrypted')]
    if any(path.exists() or path.is_symlink() for path in (*native_ids,base/'state/reboot.cred',request,dropin)): raise ValueError('Existing enrollment is preserved; explicit local rotation/recovery required')
    policy = {'machine_id':collector['machine_id'],'machine_name':config['name'],'collector_uid':os.geteuid(),'credential_hash':hashlib.sha256(credential.encode()).hexdigest(),**public}
    validate(policy)
    encrypted.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
    subprocess.run(['/usr/bin/systemd-creds','--user','--no-ask-password','--with-key=host','--name=nebuless-reboot','encrypt','-',str(encrypted)],input=credential.encode(),check=True,capture_output=True,env={name:value for name,value in os.environ.items() if name != 'NEBULESS_REBOOT_CREDENTIAL'})
    encrypted.chmod(0o600)
    sync_directory(encrypted.parent)
    write_json(request,policy)
    dropin.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
    exclusive(dropin,b'[Service]\nImportCredential=nebuless-reboot\n')
    sync_directory(dropin.parent)
    return request
