import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { randomBytes, createHmac } from 'node:crypto';
import { openAuth, provisionOwner, assertSqliteVersion } from '../src/auth.mjs';
import { startBridge } from '../src/bridge.mjs';

function totp(uri) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  const secret = new URL(uri).searchParams.get('secret');
  let bits = '';
  for (const char of secret.toUpperCase()) bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
  const key = Buffer.from((bits.match(/.{8}/g) ?? []).map(byte => parseInt(byte, 2)));
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const hash = createHmac('sha1', key).update(counter).digest();
  return ((hash.readUInt32BE(hash.at(-1) & 15) & 0x7fffffff) % 1000000).toString().padStart(6, '0');
}

async function fixture(t) {
  const directory = await mkdtemp(join(tmpdir(), 'nebuless-auth-'));
  const config = { databasePath: join(directory, 'auth.sqlite'), secret: randomBytes(48).toString('hex'), origin: 'https://hub.example.test' };
  const bridgeKey = randomBytes(32).toString('hex');
  const engine = await openAuth(config);
  const server = await startBridge(engine, { key: bridgeKey, port: 0 });
  t.after(async () => { await new Promise(resolve => server.close(resolve)); engine.database.close(); await rm(directory, { recursive: true, force: true }); });
  let cookies = {};
  const request = async (operation, body = {}, key = bridgeKey) => {
    const response = await fetch(`http://127.0.0.1:${server.address().port}/${operation}`, { method: 'POST', headers: { 'content-type': 'application/json', authorization: `Bearer ${key}` }, body: JSON.stringify({ cookies, ...body }) });
    const data = await response.json();
    if (response.ok && data.cookies) cookies = data.cookies;
    return { status: response.status, ...data };
  };
  return { config, engine, request, getCookies: () => cookies, setCookies: value => { cookies = value; } };
}

test('real Better Auth password, confirmed TOTP, one-use recovery and password plus TOTP step-up', async t => {
  const f = await fixture(t);
  const owner = await provisionOwner(f.config, 'owner@example.test', 'correct-password');
  assert.equal(owner.provider, 'better-auth');
  await assert.rejects(provisionOwner(f.config, 'other@example.test', 'correct-password'), /already/);
  assert.equal((await f.request('sign-up')).status, 404);
  assert.equal((await f.request('two-factor/disable')).status, 404);
  assert.equal((await f.request('session', {}, 'wrong-key')).status, 401);
  assert.equal((await f.request('sign-in', { email: owner.email, password: 'wrong-password' })).status, 422);
  assert.equal((await f.request('sign-in', { email: owner.email, password: 'correct-password' })).status, 200);
  assert.equal((await f.request('session')).session.enrolled, false);
  const enrollment = await f.request('enroll', { password: 'correct-password' });
  assert.match(enrollment.totpURI, /^otpauth:/);
  assert.equal((await f.request('session')).session.enrolled, false);
  assert.equal((await f.request('totp', { code: totp(enrollment.totpURI) })).status, 200);
  const firstSession = (await f.request('session')).session;
  assert.equal(firstSession.enrolled, true);
  assert.equal(firstSession.subject, owner.subject);
  assert.equal((await f.request('enroll', { password: 'correct-password' })).status, 403);
  assert.equal((await f.request('confirm', { password: 'correct-password', code: enrollment.backupCodes[0] })).status, 422);
  assert.equal((await f.request('confirm', { password: 'wrong-password', code: totp(enrollment.totpURI) })).status, 422);
  assert.equal((await f.request('confirm', { password: 'correct-password', code: totp(enrollment.totpURI) })).confirmed, true);
  await f.request('sign-out');
  assert.equal((await f.request('session')).session, null);
  assert.equal((await f.request('sign-in', { email: owner.email, password: 'correct-password' })).twoFactorRedirect, true);
  assert.equal((await f.request('session')).session, null);
  assert.equal((await f.request('backup', { code: enrollment.backupCodes[0] })).status, 200);
  assert.equal((await f.request('session')).session.enrolled, true);
  assert.equal((await f.request('confirm', { password: 'correct-password', code: enrollment.backupCodes[1] })).status, 422);
  await assert.rejects(provisionOwner(f.config, owner.email, 'short', true));
  assert.equal((await f.request('session')).session.subject, owner.subject);
  const oldCookies = f.getCookies();
  const replacement = await provisionOwner(f.config, owner.email, 'replacement-password', true);
  assert.notEqual(replacement.subject, owner.subject);
  f.setCookies(oldCookies);
  assert.equal((await f.request('session')).session, null);
  assert.equal((await f.request('sign-in', { email: owner.email, password: 'correct-password' })).status, 422);
  assert.equal((await f.request('sign-in', { email: owner.email, password: 'replacement-password' })).status, 200);
  assert.equal((await f.request('session')).session.enrolled, false);
});

test('two hubs keep independent credentials, signing secrets and sessions during primary outage', async t => {
  const primary = await fixture(t);
  const standby = await fixture(t);
  const first = await provisionOwner(primary.config, 'owner@example.test', 'first-password');
  const second = await provisionOwner(standby.config, 'owner@example.test', 'second-password');
  assert.notEqual(first.subject, second.subject);
  await primary.request('sign-in', { email: first.email, password: 'first-password' });
  standby.setCookies(primary.getCookies());
  assert.equal((await standby.request('session')).session, null);
  assert.equal((await standby.request('sign-in', { email: second.email, password: 'first-password' })).status, 422);
  assert.equal((await standby.request('sign-in', { email: second.email, password: 'second-password' })).status, 200);
  const enrollment = await standby.request('enroll', { password: 'second-password' });
  await standby.request('totp', { code: totp(enrollment.totpURI) });
  assert.equal((await standby.request('session')).session.enrolled, true);
});

test('embedded SQLite must contain the WAL reset fix', () => {
  for (const version of ['3.53.4', '3.51.3', '3.50.7', '3.44.6']) assert.doesNotThrow(() => assertSqliteVersion(version));
  for (const version of ['3.51.2', '3.50.6', '3.45.2', '3.44.5', 'invalid']) assert.throws(() => assertSqliteVersion(version));
});
