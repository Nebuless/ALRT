import json
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[2]

class ConnectProxy(unittest.TestCase):
    def test_only_configured_connect_and_unchanged_tunnel(self):
        with tempfile.TemporaryDirectory() as temp:
            temp = Path(temp)
            fake = temp/'tailscale'
            fake.write_text('#!/usr/bin/env python3\nimport os\nos.execvp("cat",["cat"])\n'); fake.chmod(0o700)
            with socket.socket() as reservation:
                reservation.bind(('127.0.0.1',0)); port = reservation.getsockname()[1]
            config = temp/'config.json'
            config.write_text(json.dumps({'proxy_port':port,'tailscale':str(fake),'proxy_origins':['https://hub.example:8444']}))
            process = subprocess.Popen([sys.executable,str(ROOT/'deploy/connect-proxy.py'),str(config)],stdout=subprocess.DEVNULL,stderr=subprocess.PIPE)
            try:
                for _ in range(50):
                    try:
                        with socket.create_connection(('127.0.0.1',port),timeout=.2): pass
                        break
                    except OSError: time.sleep(.05)
                for target in ['hub.example:443','other.example:8444','127.0.0.1:22']:
                    with socket.create_connection(('127.0.0.1',port),timeout=2) as client:
                        client.sendall(('CONNECT '+target+' HTTP/1.1\r\n\r\n').encode())
                        self.assertIn(b'403',client.recv(4096))
                with socket.create_connection(('127.0.0.1',port),timeout=2) as client:
                    client.sendall(b'CONNECT hub.example:8444 HTTP/1.1\r\n\r\n')
                    self.assertIn(b'200',client.recv(4096))
                    client.sendall(b'opaque TLS bytes\x00\xff')
                    self.assertEqual(client.recv(4096),b'opaque TLS bytes\x00\xff')
            finally:
                process.terminate(); process.wait(timeout=5)
                process.stderr.close()

if __name__=='__main__': unittest.main()
