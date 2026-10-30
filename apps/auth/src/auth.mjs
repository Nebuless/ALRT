import { DatabaseSync } from 'node:sqlite';
import { chmodSync } from 'node:fs';
import { isAbsolute } from 'node:path';
import { betterAuth } from 'better-auth';
import { twoFactor } from 'better-auth/plugins';
import { runWithTransaction, getCurrentAdapter } from '@better-auth/core/context';

export function environmentConfig() {
  return { databasePath: process.env.AUTH_DATABASE, secret: process.env.AUTH_SECRET, origin: process.env.AUTH_ORIGIN };
}

export function assertSqliteVersion(version) {
  const [major, minor, patch] = version.split('.').map(Number);
  if (!(major > 3 || (major === 3 && (minor > 51 || (minor === 51 && patch >= 3) || (minor === 50 && patch >= 7) || (minor === 44 && patch >= 6))))) throw new Error('SQLite WAL reset fix is required.');
}

export async function openAuth(config, { bootstrap = false } = {}) {
  if (!isAbsolute(config.databasePath ?? '') || !config.secret || config.secret.length < 32) throw new Error('Private auth database path and secret are required.');
  const origin = new URL(config.origin);
  if (origin.origin !== config.origin || !['https:', 'http:'].includes(origin.protocol) || (origin.protocol === 'http:' && !['localhost', '127.0.0.1'].includes(origin.hostname))) throw new Error('Use the HTTPS hub origin.');
  const database = new DatabaseSync(config.databasePath);
  assertSqliteVersion(database.prepare('SELECT sqlite_version() AS version').get().version);
  chmodSync(config.databasePath, 0o600);
  database.exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000; PRAGMA synchronous=FULL;');
  if (database.prepare('PRAGMA journal_mode').get().journal_mode !== 'wal') { database.close(); throw new Error('A writable local WAL database is required.'); }
  const auth = betterAuth({
    appName: 'Nebuless Cloud',
    baseURL: config.origin,
    secret: config.secret,
    database,
    emailAndPassword: { enabled: true, disableSignUp: !bootstrap, autoSignIn: false, minPasswordLength: 12 },
    session: { cookieCache: { enabled: false }, expiresIn: 86400, updateAge: 3600 },
    plugins: [twoFactor({ issuer: `Nebuless · ${origin.host}` })],
    logger: { disabled: true },
  });
  await (await auth.$context).runMigrations();
  return { auth, database, config };
}

/** This function is only called by the trusted local CLI, never by the bridge. */
export async function provisionOwner(config, email, password, recover = false) {
  const engine = await openAuth(config, { bootstrap: true });
  const { auth, database } = engine;
  try {
    const context = await auth.$context;
    return await runWithTransaction(context.adapter, async () => {
      const adapter = await getCurrentAdapter(context.adapter);
      const owners = await adapter.findMany({ model: 'user', limit: 2 });
      if (owners.length && !recover) throw new Error('An owner already exists.');
      if (recover) {
        for (const model of ['session', 'account', 'twoFactor', 'verification', 'user']) await adapter.deleteMany({ model, where: [] });
      }
      const result = await auth.api.signUpEmail({ body: { email, password, name: 'Owner' } });
      return { provider: 'better-auth', subject: result.user.id, email: result.user.email };
    });
  } finally {
    database.close();
  }
}
