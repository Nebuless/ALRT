import { DatabaseSync } from 'node:sqlite';

const database = new DatabaseSync(':memory:');
try {
  const sqlite = database.prepare('select sqlite_version() as version').get().version;
  const [major, minor, patch] = sqlite.split('.').map(Number);
  const patched = major > 3 || (major === 3 && (minor > 51
    || (minor === 51 && patch >= 3) || (minor === 50 && patch >= 7) || (minor === 44 && patch >= 6)));
  if (!patched) throw new Error('Node SQLite requires the documented WAL fix.');
  console.log(JSON.stringify({ node: process.versions.node, sqlite, wal_fix: true }));
} finally {
  database.close();
}
