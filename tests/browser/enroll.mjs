import { chromium } from '@playwright/test';
import { readFileSync, writeFileSync } from 'node:fs';
import { createHmac } from 'node:crypto';

// These credentials are generated for this invocation and arrive over stdin.
const state = JSON.parse(readFileSync(0, 'utf8'));
const browser = await chromium.launch();
try {
    const page = await browser.newPage();
    await page.goto(`${process.env.BROWSER_BASE_URL}/login`);
    await page.getByLabel('Email').fill(state.email);
    await page.getByLabel('Password', { exact: true }).fill(state.password);
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await page.waitForURL('**/owner/enroll');
    await page.getByLabel('Password', { exact: true }).fill(state.password);
    await page.getByRole('button', { name: 'Start enrollment' }).click();
    const uri = await page.getByRole('link', { name: 'Open authenticator', exact: true }).getAttribute('href');
    const secret = new URL(uri).searchParams.get('secret');
    const bits = [...secret.toUpperCase()].map((value) => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'.indexOf(value).toString(2).padStart(5, '0')).join('');
    const key = Buffer.from(bits.match(/.{8}/g).map((byte) => parseInt(byte, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
    const digest = createHmac('sha1', key).update(counter).digest();
    const code = String((digest.readUInt32BE(digest.at(-1) & 15) & 0x7fffffff) % 1000000).padStart(6, '0');
    await page.getByLabel('Authenticator code').fill(code);
    await page.getByRole('button', { name: 'Confirm enrollment' }).click();
    await page.getByRole('heading', { name: 'Recovery codes' }).waitFor();
    await page.context().storageState({ path: process.env.BROWSER_STORAGE_STATE });
    writeFileSync(process.env.BROWSER_REBOOT_FIXTURE, JSON.stringify({ machine_id: '22222222-2222-4222-8222-222222222222', password: state.password, totp_secret: secret }), { mode: 0o600 });
    const response = await page.request.get(`${process.env.BROWSER_BASE_URL}/api/v1/fleet`);
    if (!response.ok()) throw new Error('Synthetic owner is not authenticated.');
    const fleet = await response.json();
    if (fleet.hosts.length !== 3 || !fleet.hosts.find((host) => host.name === 'Alpha-Hub')?.actions.capability.available) {
        throw new Error('Synthetic fleet/reboot prerequisites are incomplete.');
    }
} finally {
    await browser.close();
}
