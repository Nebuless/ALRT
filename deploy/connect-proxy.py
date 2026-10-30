#!/usr/bin/env python3
"""Loopback CONNECT transport for the three configured private HTTPS hubs."""
import asyncio
import contextlib
import json
import sys
from urllib.parse import urlparse

async def serve(config):
    allowed = {urlparse(origin).netloc: (urlparse(origin).hostname, str(urlparse(origin).port or 443)) for origin in config['proxy_origins']}
    slots = asyncio.Semaphore(24)
    async def client(reader, writer):
        process = None
        tasks = []
        try:
            async with slots:
                raw = await asyncio.wait_for(reader.readuntil(b'\r\n\r\n'), 5)
                if len(raw) > 8192: raise ValueError()
                method, target, version = raw.split(b'\r\n', 1)[0].decode('ascii').split(' ')
                if method != 'CONNECT' or version != 'HTTP/1.1' or target not in allowed:
                    writer.write(b'HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\n\r\n'); await writer.drain(); return
                host, port = allowed[target]
                process = await asyncio.create_subprocess_exec(config['tailscale'], 'nc', host, port, stdin=asyncio.subprocess.PIPE, stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.DEVNULL)
                writer.write(b'HTTP/1.1 200 Connection Established\r\n\r\n'); await writer.drain()
                async def copy(source, destination):
                    while True:
                        data = await source.read(65536)
                        if not data: return
                        destination.write(data); await destination.drain()
                tasks = [asyncio.create_task(copy(reader, process.stdin)), asyncio.create_task(copy(process.stdout, writer))]
                await asyncio.wait(tasks, timeout=30, return_when=asyncio.FIRST_COMPLETED)
        except (Exception, asyncio.CancelledError):
            pass  # Never log request headers, destination credentials or TLS payloads.
        finally:
            for task in tasks: task.cancel()
            if tasks: await asyncio.gather(*tasks, return_exceptions=True)
            if process:
                if process.returncode is None:
                    with contextlib.suppress(ProcessLookupError): process.terminate()
                await process.wait()
            writer.close()
            with contextlib.suppress(Exception): await writer.wait_closed()
    server = await asyncio.start_server(client, '127.0.0.1', config['proxy_port'], limit=8192)
    async with server: await server.serve_forever()

if __name__ == '__main__':
    asyncio.run(serve(json.load(open(sys.argv[1]))))
