#!/usr/bin/env python3
"""Real Python bridge / Go exchange / Laravel acceptance in browser fixture only."""
import ctypes
import http.client
import importlib.util
import json
import os
from pathlib import Path
import signal
import socket
import subprocess
import sys
import threading
import time


def main():
    root = Path(__file__).resolve().parents[2]
    scratch = Path(os.environ['BROWSER_SCRATCH'])
    if scratch.parent != Path('/tmp') or not scratch.name.startswith('nebuless-browser-'):
        raise ValueError('disposable_fixture_required')
    sys.path.insert(0, str(root / 'deploy'))
    from citizens import Bridge, Handler, Server

    bridge = Bridge(scratch / 'citizens-state')
    server = Server(str(scratch / 'citizens.sock'), Handler)
    server.bridge = bridge
    server.collector_uid = os.getuid()
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    child = subprocess.Popen(['/usr/bin/sleep', '120'])
    stop = threading.Event()
    bridge.register({'session_id': 'wire-synthetic-child', 'parent_id': None, 'tool': 'pi', 'state': 'working'}, child.pid, os.getuid())

    def heartbeat():
        while not stop.wait(2):
            if child.poll() is not None:
                return
            bridge.register({'session_id': 'wire-synthetic-child', 'parent_id': None, 'tool': 'pi', 'state': 'working'}, child.pid, os.getuid())

    threading.Thread(target=heartbeat, daemon=True).start()
    php = "require getcwd().'/apps/hub/vendor/autoload.php';$a=require getcwd().'/apps/hub/bootstrap/app.php';$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();$m=App\\Models\\Machine::findOrFail('33333333-3333-4333-8333-333333333333');echo json_encode(['machine'=>$m->id,'token'=>$m->rotateToken(),'boot'=>trim(file_get_contents('/proc/sys/kernel/random/boot_id'))]);"
    identity = json.loads(subprocess.check_output([str(root / 'bin/php'), '-r', php], cwd=root, env=os.environ))
    config = {'socket': str(scratch / 'citizens.sock'), 'url': os.environ['BROWSER_BASE_URL'], **identity}
    config_path = scratch / 'citizens-wire.json'
    config_path.write_text(json.dumps(config))
    os.chmod(config_path, 0o600)
    ready = threading.Event()
    client = scratch / 'citizens-wire-client'
    subprocess.run(['go', 'build', '-o', str(client), './cmd/citizens-wire-test'], cwd=root / 'collector', check=True)
    agent = subprocess.Popen([str(client), str(config_path)], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    def output():
        for line in agent.stdout:
            if line.strip() == 'CITIZENS_WIRE_ACCEPTED':
                ready.set()

    threading.Thread(target=output, daemon=True).start()
    try:
        if not ready.wait(15):
            raise RuntimeError('citizens_exchange_not_ready')
        result = subprocess.run(['node', str(root / 'tests/acceptance/citizens_wire.mjs')], cwd=root, env=os.environ, capture_output=True, text=True, timeout=30)
        if result.returncode:
            raise RuntimeError('citizens_owner_action_failed:' + result.stderr[-2200:] + ':child_exit=' + str(child.poll()))
        child.wait(timeout=10)
        if child.returncode != -signal.SIGKILL:
            raise RuntimeError('citizens_child_not_killed')
        print('Real bridge/Go/Laravel/owner action terminated only disposable child; result verified.', flush=True)
    finally:
        stop.set()
        agent.terminate()
        agent.wait(timeout=5)
        if child.poll() is None:
            child.terminate()
            child.wait(timeout=5)
        server.shutdown()
        server.server_close()
        thread.join(timeout=3)


if __name__ == '__main__':
    main()
