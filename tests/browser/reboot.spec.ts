import { readFileSync, statSync } from 'node:fs';
import { createHmac } from 'node:crypto';
import { test, expect } from './live-fixture';

// This file is created privately for the synthetic browser owner. Production
// credentials are never needed. Run with one worker against the isolated hub.
const fixturePath = process.env.BROWSER_REBOOT_FIXTURE;
const fixture = fixturePath ? JSON.parse(readFileSync(fixturePath, 'utf8')) as { machine_id: string; password: string; totp_secret: string } : undefined;
if (fixturePath && (statSync(fixturePath).mode & 0o077) !== 0) throw new Error('Browser reboot fixture must be private.');

test.beforeEach(() => test.skip(!fixture, 'Requires private BROWSER_REBOOT_FIXTURE and fake capability enrollment.'));

function totp(secret: string): string {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    const bits = [...secret.toUpperCase().replace(/=+$/, '')].map((value) => alphabet.indexOf(value).toString(2).padStart(5, '0')).join('');
    const key = Buffer.from(bits.match(/.{8}/g)!.map((byte) => parseInt(byte, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
    const digest = createHmac('sha1', key).update(counter).digest();
    const offset = digest[digest.length - 1] & 15;
    return String((digest.readUInt32BE(offset) & 0x7fffffff) % 1000000).padStart(6, '0');
}

test('real confirmation queues one action and cancel survives Orbit navigation', async ({ page }) => {
    await page.goto(`/hosts/${fixture!.machine_id}`);
    const trigger = page.getByRole('button', { name: 'Queue reboot', exact: true });
    await expect(trigger).toBeEnabled();
    await trigger.focus();
    await page.keyboard.press('Enter');
    const dialog = page.getByRole('alertdialog', { name: 'Confirm reboot' });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Keep as is' })).toBeFocused();
    await page.keyboard.press('Shift+Tab');
    expect(await page.evaluate(() => document.querySelector('#confirm-reboot')!.contains(document.activeElement))).toBe(true);
    await page.keyboard.press('Escape');
    await expect(dialog).not.toBeVisible();
    await expect(trigger).toBeFocused();
    await trigger.click();
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    const labelsFit = await dialog.locator('label, p').evaluateAll((labels) => labels.every((label) => {
        const box = label.getBoundingClientRect();
        const range = document.createRange();
        range.selectNodeContents(label);
        return [...range.getClientRects()].every((text) => text.left >= box.left - 1 && text.right <= box.right + 1);
    }));
    expect(labelsFit).toBe(true);
    await dialog.getByLabel('Password', { exact: true }).fill(fixture!.password);
    await dialog.getByLabel('Authenticator code').fill(totp(fixture!.totp_secret));
    await dialog.getByRole('button', { name: 'Confirm and queue' }).click();
    await expect(dialog).not.toBeVisible();
    const queued = page.locator('[data-reboot-state="queued"]');
    await expect(queued).toContainText('Waiting for the next check-in');
    await expect(queued).toBeFocused();
    const statusFits = await queued.locator('p').evaluateAll((paragraphs) => paragraphs.every((paragraph) => {
        const box = paragraph.getBoundingClientRect();
        const range = document.createRange();
        range.selectNodeContents(paragraph);
        return [...range.getClientRects()].every((text) => text.left >= box.left - 1 && text.right <= box.right + 1);
    }));
    expect(statusFits).toBe(true);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
    const result = await (await page.request.get(`/api/v1/hosts/${fixture!.machine_id}/actions`)).json();
    expect(result.latest.state).toBe('queued');
    const confirmation = await page.request.post(`/api/v1/hosts/${fixture!.machine_id}/reboot`, { data: { proof: 'invalid' } });
    expect([419, 422]).toContain(confirmation.status());
    await page.getByRole('link', { name: /Back to orbit/i }).click();
    await expect(page.getByRole('heading', { name: 'Orbit', exact: true })).toBeVisible();
    await expect(page.locator('#sky .planet')).toHaveCount(3);
    await expect(page.locator('#verdict, .lens, .ledger-row')).toHaveCount(0);
    await expect(page.locator('#host-instruments')).toHaveAttribute('href', `/hosts/${fixture!.machine_id}`);
    await page.locator('#host-instruments').click();
    await expect(queued).toContainText('Waiting for the next check-in');
    const pending = await (await page.request.get(`/api/v1/hosts/${fixture!.machine_id}/actions`)).json();
    expect(pending.latest.id).toBe(result.latest.id);
    expect(pending.latest.state).toBe('queued');
    await page.getByRole('button', { name: 'Cancel reboot', exact: true }).click();
    await expect(page.locator('[data-reboot-state="canceled"]')).toContainText('Reboot canceled');
    const canceled = await (await page.request.get(`/api/v1/hosts/${fixture!.machine_id}/actions`)).json();
    expect(canceled.latest.id).toBe(result.latest.id);
    expect(canceled.latest.state).toBe('canceled');
});

test('offline state disables confirmation and queues no action in the worker', async ({ page, context }) => {
    await page.goto(`/hosts/${fixture!.machine_id}`);
    const trigger = page.getByRole('button', { name: 'Queue reboot', exact: true });
    await expect(trigger).toBeEnabled();
    await trigger.click();
    await context.setOffline(true);
    await expect(page.getByRole('button', { name: 'Confirm and queue' })).toBeDisabled();
    await expect(trigger).toBeDisabled();
    await expect(page.locator('[data-connection]')).toContainText('Offline');
    await context.setOffline(false);
    await page.keyboard.press('Escape');
    await expect(trigger).toBeEnabled();
});
