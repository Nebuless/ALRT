import { openAuth, environmentConfig } from './auth.mjs';
import { startBridge } from './bridge.mjs';

process.umask(0o077);
try {
  const engine = await openAuth(environmentConfig());
  const server = await startBridge(engine, { key: process.env.AUTH_BRIDGE_KEY, port: Number(process.env.AUTH_PORT ?? 19445) });
  process.stdout.write(JSON.stringify({ ready: true, port: server.address().port }) + '\n');
  for (const signal of ['SIGTERM', 'SIGINT']) process.once(signal, () => server.close(() => { engine.database.close(); process.exit(0); }));
} catch {
  process.stderr.write('Nebuless auth could not start. Check local auth configuration.\n');
  process.exitCode = 1;
}
