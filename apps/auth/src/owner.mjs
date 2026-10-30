import { provisionOwner, environmentConfig } from './auth.mjs';

process.umask(0o077);
try {
  let input = '';
  for await (const chunk of process.stdin) {
    input += chunk;
    if (Buffer.byteLength(input) > 16384) throw new Error('Input too large.');
  }
  const body = JSON.parse(input);
  const owner = await provisionOwner(environmentConfig(), body.email, body.password, body.recover === true);
  process.stdout.write(JSON.stringify(owner) + '\n');
} catch {
  process.stderr.write('Local owner provisioning failed. Check configuration and whether an owner already exists.\n');
  process.exitCode = 1;
}
