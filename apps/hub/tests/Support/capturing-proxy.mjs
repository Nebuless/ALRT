import { createServer } from 'node:http';
import { writeFileSync } from 'node:fs';
process.umask(0o077);
let count = 0;
const server = createServer(async (request, response) => {
  for await (const chunk of request) { /* Drain without recording credentials or bodies. */ }
  writeFileSync(process.env.CAPTURE_FILE, JSON.stringify({ requests: ++count }), { mode: 0o600 });
  response.writeHead(503, { 'content-type': 'application/json' });
  response.end('{"message":"captured by synthetic test proxy"}');
});
server.listen(0, '127.0.0.1', () => process.stdout.write(JSON.stringify({ ready: true, port: server.address().port }) + '\n'));
process.once('SIGTERM', () => server.close(() => process.exit(0)));
