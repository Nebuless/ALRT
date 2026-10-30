import { createServer } from 'node:http';
import { createHash, timingSafeEqual } from 'node:crypto';

const operations = new Set(['session', 'sign-in', 'sign-out', 'enroll', 'totp', 'backup', 'confirm']);
class AuthenticationRejected extends Error {}
const digest = value => createHash('sha256').update(value).digest();

function cookieHeader(cookies) {
  if (!cookies || typeof cookies !== 'object' || Array.isArray(cookies) || Object.keys(cookies).length > 16) throw new Error('Invalid cookie jar.');
  return Object.entries(cookies).map(([name, value]) => {
    if (!/^[A-Za-z0-9_.-]+$/.test(name) || typeof value !== 'string' || !/^[\x21-\x3a\x3c-\x7e]*$/.test(value) || value.length > 4096) throw new Error('Invalid cookie jar.');
    return `${name}=${value}`;
  }).join('; ');
}

function applyCookies(jar, headers) {
  for (const cookie of headers.getSetCookie()) {
    const pair = cookie.split(';', 1)[0];
    const equals = pair.indexOf('=');
    const name = pair.slice(0, equals);
    const value = pair.slice(equals + 1);
    if (/;\s*max-age=0(?:;|$)/i.test(cookie) || value === '') delete jar[name];
    else jar[name] = value;
  }
}

export async function startBridge({ auth, config }, { key, port = 19445 }) {
  if (typeof key !== 'string' || key.length < 32) throw new Error('A private bridge key is required.');
  const server = createServer(async (request, response) => {
    const send = (status, data) => { response.writeHead(status, { 'content-type': 'application/json', 'cache-control': 'no-store' }); response.end(JSON.stringify(data)); };
    if (!timingSafeEqual(digest(request.headers.authorization ?? ''), digest(`Bearer ${key}`))) return send(401, { message: 'Unauthenticated.' });
    const operation = request.url?.slice(1);
    if (request.method !== 'POST' || !operations.has(operation)) return send(404, { message: 'Not found.' });
    try {
      let raw = '';
      for await (const chunk of request) {
        raw += chunk;
        if (Buffer.byteLength(raw) > 16384) return send(413, { message: 'Request too large.' });
      }
      const body = JSON.parse(raw);
      const cookies = { ...(body.cookies ?? {}) };
      const headers = new Headers({ cookie: cookieHeader(cookies), origin: config.origin });
      const call = async (method, input = {}) => {
        const result = await auth.api[method]({ ...input, headers, asResponse: true });
        applyCookies(cookies, result.headers);
        headers.set('cookie', cookieHeader(cookies));
        const data = await result.json();
        if (!result.ok) {
          if (result.status < 500) throw new AuthenticationRejected();
          throw new Error('Auth service failure.');
        }
        return data;
      };
      const session = () => call('getSession', { query: { disableCookieCache: true } });
      let data = {};
      if (operation === 'session') {
        const current = await session();
        data.session = current ? { provider: 'better-auth', subject: current.user.id, id: current.session.id, email: current.user.email, enrolled: current.user.twoFactorEnabled === true } : null;
      } else if (operation === 'sign-in') {
        const result = await call('signInEmail', { body: { email: body.email, password: body.password, rememberMe: false } });
        data.twoFactorRedirect = result.twoFactorRedirect === true;
      } else if (operation === 'sign-out') await call('signOut', { body: {} });
      else if (operation === 'enroll') {
        const current = await session();
        if (!current || current.user.twoFactorEnabled) return send(403, { message: 'Enrollment unavailable.' });
        const result = await call('enableTwoFactor', { body: { password: body.password, method: 'totp' } });
        data = { totpURI: result.totpURI, backupCodes: result.backupCodes };
      } else if (operation === 'totp') await call('verifyTOTP', { body: { code: body.code, trustDevice: false } });
      else if (operation === 'backup') await call('verifyBackupCode', { body: { code: body.code, trustDevice: false } });
      else if (operation === 'confirm') {
        const current = await session();
        if (!current?.user.twoFactorEnabled || !/^\d{6}$/.test(body.code ?? '')) throw new AuthenticationRejected();
        await call('verifyPassword', { body: { password: body.password } });
        await call('verifyTOTP', { body: { code: body.code, trustDevice: false } });
        const after = await session();
        if (after?.session.id !== current.session.id || after?.user.id !== current.user.id) throw new AuthenticationRejected();
        data = { confirmed: true, sessionId: current.session.id, subject: current.user.id };
      }
      return send(200, { ...data, cookies });
    } catch (error) {
      if (error instanceof AuthenticationRejected) return send(422, { message: 'Authentication rejected.' });
      if (error instanceof SyntaxError) return send(400, { message: 'Invalid request.' });
      return send(503, { message: 'Authentication unavailable.' });
    }
  });
  server.requestTimeout = 10000;
  server.headersTimeout = 10000;
  server.maxRequestsPerSocket = 100;
  await new Promise((resolve, reject) => { server.once('error', reject); server.listen(port, '127.0.0.1', resolve); });
  return server;
}
