import { test, expect } from './live-fixture';
import type { Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';

const NOW = '2026-10-28T12:00:00Z';
const machine = '11111111-1111-4111-8111-111111111111';
const identity = 'a'.repeat(64);
const revision = 'b'.repeat(64);
const runtime = 'c'.repeat(64);
const endpoint = '**/api/v1/citizens';
const terminateEndpoint = '**/api/v1/citizens/*/*/terminate';

function citizen(overrides = {}) {
    return { id: identity, parent_id: null, tool: 'omo', state: 'working', observed_at: NOW, runtime_id: runtime, attribution: 'dedicated', process: { boot_id: '22222222-2222-4222-8222-222222222222', pid: 1234, start_ticks: 100 }, cpu_percent: 125, rss_bytes: 33554432, can_terminate: true, revision, ...overrides };
}
function snapshot(overrides = {}) {
    return { observed_at: NOW, primary: true, hosts: [{ id: machine, name: 'Synthetic station', coverage: 'complete', last_contact_at: NOW, citizens: [citizen(), citizen({ id: 'd'.repeat(64), tool: 'pi', state: 'waiting', attribution: 'shared', can_terminate: false }), citizen({ id: 'e'.repeat(64), tool: 'pi', state: 'idle', attribution: 'shared', can_terminate: false })] }], ...overrides };
}
async function open(page: Page, data = snapshot()) {
    await page.clock.install({ time: new Date(NOW) });
    await page.clock.pauseAt(new Date(Date.parse(NOW) + 1000));
    await page.route(endpoint, route => route.fulfill({ json: data }));
    await page.goto('/citizens');
    await expect(page.locator('#inventory-count')).toHaveText('3 of 3 citizens');
}
async function refresh(page: Page) {
    const response = page.waitForResponse(response => response.url().endsWith('/api/v1/citizens'));
    await page.locator('#refresh-citizens').evaluate((button: HTMLButtonElement) => button.click());
    await response;
    await expect(page.locator('#refresh-citizens')).toBeEnabled();
}

test('owner shell keeps assets local, filters reset, shared resources counted once, layouts fit', async ({ page }) => {
    const external: string[] = [], errors: string[] = [];
    page.on('request', request => { if (new URL(request.url()).origin !== new URL(page.url()).origin && page.url() !== 'about:blank') external.push(request.url()); });
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    await open(page);
    await expect(page.getByRole('heading', { name: 'Citizens', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Return to Orbit' })).toHaveAttribute('href', '/orbit');
    await expect(page.locator('.citizen-card')).toHaveCount(3);
    if (process.env.CITIZENS_SCREENSHOTS) {
        mkdirSync(process.env.CITIZENS_SCREENSHOTS, { recursive: true, mode: 0o700 });
        await page.screenshot({ path: process.env.CITIZENS_SCREENSHOTS + '/' + test.info().project.name + '-citizens.png', fullPage: true });
    }
    await expect(page.locator('.citizen-card svg')).toHaveCount(3);
    await expect(page.locator('[data-runtime]')).toHaveCount(1);
    await expect(page.locator('[data-runtime]')).toContainText('2 citizens');
    await expect(page.locator('[data-runtime] dd').first()).toHaveText('125%');
    await expect(page.locator('.citizen-card[data-citizen$="' + identity + '"]')).toContainText('125%');
    await expect(page.locator('.citizen-card').first()).toContainText('Shared runtime');
    await page.locator('#filter-disclosure summary').click();
    await page.locator('#citizen-tool').selectOption('pi');
    await expect(page.locator('.citizen-card:visible')).toHaveCount(2);
    await page.locator('#citizen-state').selectOption('waiting');
    await expect(page.locator('.citizen-card:visible')).toHaveCount(1);
    await page.locator('#citizen-search').fill('absent citizen');
    await expect(page.locator('#citizen-empty')).toBeVisible();
    await page.locator('#reset-citizens').click();
    await expect(page.locator('.citizen-card:visible')).toHaveCount(3);
    for (const width of [320, 390, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
        const layout = await page.evaluate(() => ({ width: innerWidth, documentWidth: document.documentElement.scrollWidth, oversized: [...document.querySelectorAll('body *')].filter(node => node.getBoundingClientRect().right > innerWidth + 1).map(node => ({ id: node.id, tag: node.tagName, class: node.className, right: node.getBoundingClientRect().right })) }));
        expect(layout.documentWidth, JSON.stringify(layout)).toBeLessThanOrEqual(layout.width + 1);
        const targets = await page.locator('button:visible, input:visible, select:visible, summary:visible, .return-orbit').evaluateAll(nodes => nodes.every(node => node.getBoundingClientRect().height >= 44));
        expect(targets).toBe(true);
    }
    expect(await page.evaluate(() => Object.keys(localStorage).length + Object.keys(sessionStorage).length)).toBe(0);
    expect(external).toEqual([]); expect(errors).toEqual([]);
});

test('current activity leads on phones while stopped evidence remains in disclosed history', async ({ page }) => {
    const data = snapshot();
    data.hosts[0].citizens.unshift(citizen({ id: 'f'.repeat(64), state: 'stopped', can_terminate: false, cpu_percent: null, rss_bytes: null }));
    await page.clock.install({ time: new Date(NOW) });
    await page.clock.pauseAt(new Date(Date.parse(NOW) + 1000));
    await page.route(endpoint, route => route.fulfill({ json: data }));
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/citizens');
    await expect(page.locator('#inventory-count')).toHaveText('4 of 4 citizens');
    await expect(page.locator('#citizen-list .citizen-card')).toHaveCount(3);
    await expect(page.locator('#citizen-list .native-state strong').first()).toHaveText('waiting');
    await expect(page.locator('#count-working')).toHaveText('1');
    await expect(page.locator('#count-waiting')).toHaveText('1');
    await expect(page.locator('#count-idle')).toHaveText('1');
    await expect(page.locator('#filter-disclosure')).not.toHaveAttribute('open');
    await expect(page.locator('#recent-history')).not.toHaveAttribute('open');
    const first = await page.locator('#citizen-list .citizen-card').first().boundingBox();
    expect(first!.y + 150).toBeLessThan(844);
    await page.locator('#recent-history summary').click();
    await expect(page.locator('#citizen-history .citizen-card')).toBeVisible();
    await page.getByRole('button', { name: 'View agent OmO ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff on Synthetic station', exact: true }).click();
    await expect(page.locator('#request-terminate')).toBeDisabled();
    await expect(page.locator('.technical-details')).not.toHaveAttribute('open');
    await page.locator('.technical-details summary').click();
    await expect(page.locator('#detail-evidence')).toContainText('PID');
    await page.keyboard.press('Escape');
    await page.locator('#filter-disclosure summary').click();
    await page.locator('#citizen-state').selectOption('stopped');
    await expect(page.locator('#recent-history')).toHaveAttribute('open', '');
    await expect(page.locator('#citizen-list .citizen-card:visible')).toHaveCount(0);
    await expect(page.locator('#history-count')).toHaveText('(1)');
    await page.locator('#recent-history summary').click();
    await page.clock.runFor(1000);
    await refresh(page);
    await expect(page.locator('#recent-history')).not.toHaveAttribute('open');
    await page.locator('#recent-history summary').focus();
    data.hosts[0].citizens = data.hosts[0].citizens.filter(row => row.state !== 'stopped');
    await refresh(page);
    await expect(page.locator('#recent-history')).toBeHidden();
    await expect(page.locator('#citizen-search')).toBeFocused();
});

test('stale activity counts and filters are honest and moving a focused agent to history keeps focus usable', async ({ page }) => {
    let data = snapshot();
    await open(page);
    await page.route(endpoint, route => route.fulfill({ json: data }));
    const trigger = page.getByRole('button', { name: 'View agent OmO aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa on Synthetic station', exact: true });
    await trigger.focus();
    data.hosts[0].citizens[0].state = 'stopped';
    data.hosts[0].citizens[0].can_terminate = false;
    await refresh(page);
    await expect(page.locator('#recent-history summary')).toBeFocused();
    await page.locator('#recent-history summary').click();
    await trigger.click();
    await expect(page.locator('#request-terminate')).toBeDisabled();
    await page.locator('#close-detail').click();
    await expect(trigger).toBeFocused();
    await page.locator('#filter-disclosure summary').click();
    data = snapshot();
    data.hosts[0].citizens[0].observed_at = '2026-10-28T11:59:40Z';
    await refresh(page);
    await expect(page.locator('#count-working')).toHaveText('0');
    await expect(page.locator('#count-unknown')).toHaveText('1');
    await page.locator('#citizen-state').selectOption('working');
    await expect(page.locator('#citizen-list .citizen-card:visible')).toHaveCount(0);
    await page.locator('#citizen-state').selectOption('unknown');
    await expect(page.locator('#citizen-list .citizen-card:visible')).toHaveCount(1);
});

test('agent detail and destructive confirmation fit enlarged phone text with full process evidence accessible', async ({ page }) => {
    await open(page);
    await page.setViewportSize({ width: 320, height: 844 });
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    await page.getByRole('button', { name: 'View agent OmO aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa on Synthetic station', exact: true }).click();
    await expect(page.locator('#detail-readings')).toContainText('125%');
    await page.locator('.technical-details summary').click();
    await expect(page.locator('#detail-evidence')).toContainText('PID');
    await page.locator('#request-terminate').click();
    await expect(page.locator('#cancel-terminate')).toBeFocused();
    await expect(page.locator('#confirmation-copy')).toContainText('PID 1234');
    const dialogLayout = await page.locator('#citizen-detail').evaluate(node => ({ width: node.clientWidth, scroll: node.scrollWidth, oversized: [...node.querySelectorAll('*')].filter(child => child.getBoundingClientRect().right > node.getBoundingClientRect().right).map(child => ({ id: child.id, tag: child.tagName, right: child.getBoundingClientRect().right })) }));
    expect(dialogLayout.scroll, JSON.stringify(dialogLayout)).toBeLessThanOrEqual(dialogLayout.width + 1);
    expect(await page.locator('#citizen-detail button:visible, #citizen-detail summary:visible').evaluateAll(nodes => nodes.every(node => node.getBoundingClientRect().height >= 44))).toBe(true);
    await page.keyboard.press('Escape');
    await expect(page.locator('#request-terminate')).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('button', { name: 'View agent OmO aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa on Synthetic station', exact: true })).toBeFocused();
});

test('poll keeps identical card and focused control, dialog returns focus after refresh', async ({ page }) => {
    await open(page);
    const trigger = page.getByRole('button', { name: 'View agent OmO aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa on Synthetic station', exact: true });
    await trigger.focus();
    const original = await trigger.elementHandle();
    const polled = page.waitForResponse(response => response.url().endsWith('/api/v1/citizens'));
    await page.clock.runFor(2000); await polled;
    await expect(trigger).toBeFocused();
    expect(await original!.evaluate(node => node.isConnected && node === document.activeElement)).toBe(true);
    await page.keyboard.press('Enter');
    await expect(page.locator('#close-detail')).toBeFocused();
    await refresh(page);
    await expect(page.locator('#close-detail')).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(trigger).toBeFocused();
});

test('confirmed force kill captures exact identity and waits for reported result', async ({ page }) => {
    let data = snapshot();
    await open(page, data);
    await page.route(endpoint, route => route.fulfill({ json: data }));
    let captured: { body: unknown; csrf: string | undefined; path: string } | undefined;
    await page.route(terminateEndpoint, async route => {
        captured = { body: route.request().postDataJSON(), csrf: route.request().headers()['x-csrf-token'], path: new URL(route.request().url()).pathname };
        await route.fulfill({ status: 201, json: { action: { id: '33333333-3333-4333-8333-333333333333', status: 'requested' } } });
    });
    await page.getByRole('button', { name: 'View agent OmO aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa on Synthetic station', exact: true }).click();
    await page.locator('#request-terminate').click();
    await expect(page.locator('#confirmation-copy')).toContainText('Synthetic station');
    await expect(page.locator('#confirmation-copy')).toContainText('OmO aaaaaaaa');
    await expect(page.locator('#confirmation-copy')).toContainText('PID 1234');
    await expect(page.locator('#cancel-terminate')).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(page.locator('#kill-confirmation')).toBeHidden();
    await expect(page.locator('#request-terminate')).toBeFocused();
    await page.locator('#request-terminate').click();
    const posted = page.waitForResponse(response => response.request().method() === 'POST' && response.url().endsWith('/terminate'));
    await page.locator('#confirm-terminate').click(); await posted;
    await expect(page.locator('#action-status')).toContainText('Action requested');
    await expect(page.locator('#request-terminate')).toBeDisabled();
    expect(captured!.body).toEqual({ revision, confirmation: true });
    expect(captured!.path).toBe(`/api/v1/citizens/${machine}/${identity}/terminate`);
    expect(captured!.csrf).toBe(await page.locator('meta[name="csrf-token"]').getAttribute('content'));
    await expect(page.locator('.citizen-card[data-citizen$="' + identity + '"] .native-state strong')).toHaveText('working');
    data = snapshot();
    Object.assign(data.hosts[0].citizens[0], { action: { id: '33333333-3333-4333-8333-333333333333', status: 'terminated' }, state: 'stopped' });
    await refresh(page);
    await expect(page.locator('#action-status')).toContainText('Action terminated');
    await expect(page.locator('.citizen-card[data-citizen$="' + identity + '"] .native-state strong')).toHaveText('stopped');
});

test('shared standby unsupported and missing identity refuse controls', async ({ page }) => {
    await open(page);
    await page.getByRole('button', { name: 'View agent Pi dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd on Synthetic station', exact: true }).click();
    await expect(page.locator('#request-terminate')).toBeDisabled();
    await expect(page.locator('#terminate-reason')).toContainText('Shared runtime');
    await page.locator('#close-detail').click();
    await page.getByRole('button', { name: 'View agent OmO aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa on Synthetic station', exact: true }).click();
    for (const [data, expected] of [
        [snapshot({ primary: false }), 'Standby'],
        [snapshot({ hosts: [{ ...snapshot().hosts[0], citizens: [citizen({ can_terminate: false })] }] }), 'capability'],
        [snapshot({ hosts: [{ ...snapshot().hosts[0], citizens: [citizen({ process: null })] }] }), 'identity'],
    ] as const) {
        await page.route(endpoint, route => route.fulfill({ json: data })); await refresh(page);
        await expect(page.locator('#request-terminate')).toBeDisabled();
        await expect(page.locator('#terminate-reason')).toContainText(expected);
    }
});

test('source expiry invalidates confirmation between refreshes without inventing zero', async ({ page }) => {
    await open(page);
    await page.getByRole('button', { name: 'View agent OmO aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa on Synthetic station', exact: true }).click();
    await page.locator('#request-terminate').click();
    let release!: () => void, entered!: () => void;
    const held = new Promise<void>(resolve => { release = resolve; });
    const started = new Promise<void>(resolve => { entered = resolve; });
    await page.route(endpoint, async route => { entered(); await held; await route.fulfill({ json: snapshot() }).catch(() => {}); });
    const progressing = page.clock.runFor(11000);
    await started; await progressing;
    await expect(page.locator('#kill-confirmation')).toBeHidden();
    await expect(page.locator('#request-terminate')).toBeDisabled();
    await expect(page.locator('.citizen-card[data-citizen$="' + identity + '"] dd').first()).toHaveText('Unavailable');
    await expect(page.locator('#close-detail')).toBeFocused();
    release();
});

test('offline pauses reads and reconnect revalidates before enabling actions', async ({ page, context }) => {
    await open(page);
    await page.getByRole('button', { name: 'View agent OmO aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa on Synthetic station', exact: true }).click();
    await context.setOffline(true);
    await expect(page.locator('#connection-note')).toContainText('Offline');
    await expect(page.locator('#request-terminate')).toBeDisabled();
    let calls = 0;
    await page.route(endpoint, route => { calls++; return route.fulfill({ json: snapshot() }); });
    await page.clock.runFor(12000); expect(calls).toBe(0);
    const revalidated = page.waitForResponse(response => response.url().endsWith('/api/v1/citizens'));
    await context.setOffline(false); await revalidated;
    await expect(page.locator('#request-terminate')).toBeEnabled();
});

test('failed refresh retains marked evidence and owner loss clears every private surface', async ({ page }) => {
    await open(page);
    await page.getByRole('button', { name: 'View agent OmO aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa on Synthetic station', exact: true }).click();
    await page.route(endpoint, route => route.fulfill({ status: 503, json: {} })); await refresh(page);
    await expect(page.locator('#connection-note')).toContainText('Refresh failed');
    await expect(page.locator('.citizen-card')).toHaveCount(3);
    await expect(page.locator('.citizen-card[data-citizen$="' + identity + '"] dd').first()).toHaveText('Unavailable');
    await expect(page.locator('#request-terminate')).toBeDisabled();
    for (const status of [401, 403]) {
        if (status === 403) {
            await page.route(endpoint, route => route.fulfill({ json: snapshot() })); await page.reload();
            await page.getByRole('button', { name: 'View agent OmO aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa on Synthetic station', exact: true }).click();
        }
        await page.route(endpoint, route => route.fulfill({ status, json: {} }));
        const lost = page.waitForResponse(response => response.status() === status && response.url().endsWith('/api/v1/citizens'));
        await page.locator('#refresh-citizens').evaluate((button: HTMLButtonElement) => button.click()); await lost;
        await expect(page.locator('#citizen-detail')).not.toBeVisible();
        for (const id of ['citizen-list', 'shared-runtimes', 'host-coverage', 'detail-title', 'detail-evidence', 'detail-avatar', 'confirmation-copy']) await expect(page.locator('#' + id)).toBeEmpty();
        await expect(page.locator('#citizen-host option')).toHaveCount(1);
        await expect(page.locator('#refresh-citizens')).toBeDisabled();
    }
});

test('POST denial redacts private detail and uncertain transport never auto retries', async ({ page }) => {
    await open(page);
    await page.route(terminateEndpoint, route => route.abort('failed'));
    await page.getByRole('button', { name: 'View agent OmO aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa on Synthetic station', exact: true }).click();
    await page.locator('#request-terminate').click(); await page.locator('#confirm-terminate').click();
    await expect(page.locator('#action-status')).toContainText('Action unknown');
    await expect(page.locator('#request-terminate')).toBeDisabled();
    await page.reload();
    await page.route(terminateEndpoint, route => route.fulfill({ status: 403, json: {} }));
    await page.getByRole('button', { name: 'View agent OmO aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa on Synthetic station', exact: true }).click();
    await page.locator('#request-terminate').click(); await page.locator('#confirm-terminate').click();
    await expect(page.locator('#citizen-detail')).not.toBeVisible();
    await expect(page.locator('#citizen-list')).toBeEmpty();
});

test('hidden page pauses polling and visible resume revalidates current identity', async ({ page }) => {
    await open(page);
    await page.getByRole('button', { name: 'View agent OmO aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa on Synthetic station', exact: true }).click();
    await page.locator('#request-terminate').click();
    await page.evaluate(() => {
        Object.defineProperty(document, 'hidden', { configurable: true, get: () => true });
        document.dispatchEvent(new Event('visibilitychange'));
    });
    await expect(page.locator('#kill-confirmation')).toBeHidden();
    await expect(page.locator('#request-terminate')).toBeDisabled();
    let calls = 0;
    await page.route(endpoint, route => { calls++; return route.fulfill({ json: snapshot() }); });
    await page.clock.runFor(12000); expect(calls).toBe(0);
    const resumed = page.waitForResponse(response => response.url().endsWith('/api/v1/citizens'));
    await page.evaluate(() => {
        delete (document as unknown as { hidden?: boolean }).hidden;
        document.dispatchEvent(new Event('visibilitychange'));
    });
    await resumed;
    await expect(page.locator('#request-terminate')).toBeEnabled();
});

test('new revision cancels confirmation and explicit request rejection remains visible', async ({ page }) => {
    await open(page);
    await page.getByRole('button', { name: 'View agent OmO aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa on Synthetic station', exact: true }).click();
    await page.locator('#request-terminate').click();
    const data = snapshot(); data.hosts[0].citizens[0].revision = 'f'.repeat(64);
    await page.route(endpoint, route => route.fulfill({ json: data })); await refresh(page);
    await expect(page.locator('#kill-confirmation')).toBeHidden();
    await expect(page.locator('#close-detail')).toBeFocused();
    await page.route(terminateEndpoint, route => route.fulfill({ status: 409, json: {} }));
    await page.locator('#request-terminate').click(); await page.locator('#confirm-terminate').click();
    await expect(page.locator('#action-status')).toContainText('HTTP 409');
    await expect(page.locator('.citizen-card[data-citizen$="' + identity + '"] .native-state strong')).toHaveText('working');
});
