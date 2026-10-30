import { test, expect } from './live-fixture';

const standby = '22222222-2222-4222-8222-222222222222';
const restrictedHost = '33333333-3333-4333-8333-333333333333';
const primary = '44444444-4444-4444-8444-444444444444';

for (const path of ['/', '/orbit', '/citizens', '/reporting', `/hosts/${standby}`, `/hosts/${restrictedHost}`, `/hosts/${primary}`]) {
    test(`${path}: modern owner page remains usable at 200% text size`, async ({ page }) => {
        const response = await page.goto(path);
        expect(response?.status()).toBe(200);
        if (path === '/' || path === '/orbit') {
            await expect(page.locator('#sky .planet')).toHaveCount(3);
            await expect(page.locator('#connection')).toHaveAttribute('data-state', 'connected');
        }
        await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await expect(page.locator('.lens, .ledger-row, .constellation, a[href^="/design"], a[href="/satellites"]')).toHaveCount(0);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
        const nav = page.getByRole('navigation', { name: 'Main navigation', exact: true });
        await expect(nav.getByRole('link')).toHaveText(['Orbit', 'Citizens', 'Reporting']);
        for (const link of await nav.getByRole('link').all()) {
            await link.scrollIntoViewIfNeeded();
            await expect(link).toBeVisible();
            expect(await link.evaluate(node => node.scrollWidth <= node.clientWidth)).toBe(true);
        }
        await nav.getByRole('link', { name: 'Reporting', exact: true }).press('Enter');
        await expect(page).toHaveURL(/\/reporting$/);
        await expect(page.getByLabel('Main navigation').getByRole('link', { name: 'Reporting', exact: true })).toHaveAttribute('aria-current', 'page');
        await expect(page.getByRole('heading', { name: 'Reporting paths', exact: true })).toBeVisible();
    });
}

test('retired graph redirects to Reporting and design fixture routes no longer exist', async ({ page }) => {
    await page.goto('/satellites');
    await expect(page).toHaveURL(/\/reporting$/);
    await expect(page.getByRole('heading', { name: 'Reporting paths', exact: true })).toBeVisible();
    for (const path of ['/design', '/design/satellites', '/design/hosts/standby']) {
        expect((await page.request.get(path)).status()).toBe(404);
    }
});

test('root planets and host list offer keyboard navigation to native host instruments', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto('/');
    await expect(page.locator('#host-list option')).toHaveCount(3);
    const planet = page.locator(`#sky .planet[data-host="${restrictedHost}"]`);
    await planet.focus();
    await expect(planet).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(planet).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('#dock-title')).toHaveText('Beta-Restricted');
    await page.locator('#return-to-sky').press('Enter');
    await expect(planet).toBeFocused();
    await page.locator('.system-chooser summary').press('Enter');
    await page.locator('#host-list').focus();
    await expect(page.locator('#host-list')).toBeFocused();
    await page.locator('#host-list').selectOption(standby);
    await expect(page.locator('#dock-title')).toHaveText('Alpha-Hub');
    await page.locator('#host-instruments').press('Enter');
    await expect(page).toHaveURL(new RegExp(`/hosts/${standby}$`));
    await expect(page.getByRole('heading', { name: 'Alpha-Hub', exact: true })).toBeVisible();
    await page.getByRole('link', { name: /Back to orbit/i }).press('Enter');
    await expect(page.locator('#dock-title')).toHaveText('Alpha-Hub');
});

test('native confirmation traps focus, dismisses with Escape and restores its trigger without queueing', async ({ page }) => {
    const before = await (await page.request.get(`/api/v1/hosts/${standby}/actions`)).json();
    await page.goto(`/hosts/${standby}`);
    const trigger = page.getByRole('button', { name: 'Queue reboot', exact: true });
    await expect(trigger).toBeEnabled();
    await trigger.focus();
    await page.keyboard.press('Enter');
    const dialog = page.getByRole('alertdialog', { name: 'Confirm reboot' });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Keep as is' })).toBeFocused();
    await page.keyboard.press('Shift+Tab');
    expect(await dialog.evaluate(node => node.contains(document.activeElement))).toBe(true);
    await page.keyboard.press('Tab');
    expect(await dialog.evaluate(node => node.contains(document.activeElement))).toBe(true);
    await page.keyboard.press('Escape');
    await expect(dialog).not.toBeVisible();
    await expect(trigger).toBeFocused();
    await trigger.press('Enter');
    await dialog.getByRole('button', { name: 'Keep as is' }).press('Enter');
    await expect(dialog).not.toBeVisible();
    await expect(trigger).toBeFocused();
    const after = await (await page.request.get(`/api/v1/hosts/${standby}/actions`)).json();
    expect(after.latest?.id ?? null).toBe(before.latest?.id ?? null);
});

test('missing evidence and capability remain unavailable rather than measured zero', async ({ page }) => {
    const host = (await (await page.request.get(`/api/v1/hosts/${primary}`)).json()).data;
    expect(host.actions.capability.available).toBe(false);
    await page.goto(`/hosts/${primary}`);
    await expect(page.getByRole('button', { name: 'Queue reboot', exact: true })).toBeDisabled();
    await expect(page.locator('#capabilities .capability-row').first()).toContainText(host.actions.capability.reason);
    await expect(page.locator('.process-rows li')).toHaveCount(0);
    await page.goto(`/?host=${primary}`);
    await expect(page.locator('#dock-title')).toHaveText('Primary-Hub');
    await expect(page.locator('#gauges .gauge-value').first()).toHaveText('N/A');
    await expect(page.locator('#process-body')).toBeEmpty();
    await expect(page.locator('#process-empty')).toBeVisible();
});

test('partial process coverage retains supplied evidence and reports missing coverage', async ({ page }) => {
    await page.route('**/api/v1/fleet', async route => {
        const response = await route.fetch();
        const body = await response.json();
        const host = body.hosts.find((item: { id: string }) => item.id === restrictedHost);
        host.coverage.processes = { ...host.coverage.processes, status: 'partial', sampled: 83, discovered: 96, reason: 'permission_denied' };
        host.processes.top_cpu = { ...host.processes.top_cpu, completeness: 'partial', reason: 'permission_denied' };
        await route.fulfill({ response, json: body });
    });
    await page.goto(`/?host=${restrictedHost}`);
    await expect(page.locator('#process-body')).toContainText('worker');
    await expect(page.locator('#snapshot-note')).toContainText('83 sampled / 96 discovered');
    await expect(page.locator('#snapshot-note')).toContainText(/partial/i);
    await expect(page.locator('#snapshot-note')).toContainText(/permission denied/i);
});

test('reduced motion preserves silent and unknown system information without moving stars', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    const fleet = await (await page.request.get('/api/v1/fleet')).json();
    await page.route('**/api/v1/fleet', route => route.fulfill({ json: fleet }));
    await page.goto('/');
    await expect(page.locator('#sky .planet')).toHaveCount(3);
    for (const id of [standby, restrictedHost, primary]) {
        const planet = page.locator(`#sky .planet[data-host="${id}"]`);
        await planet.press('Enter');
        await expect(planet).toHaveAttribute('aria-pressed', 'true');
        expect(await planet.locator('.system-body').evaluate(node => node.getAnimations().length)).toBe(0);
        expect(await planet.locator('.system-star').evaluate(node => getComputedStyle(node).transform)).toBe('none');
        const host = fleet.hosts.find((item: { id: string }) => item.id === id);
        await expect(page.locator('#state-note')).toContainText(host.observation.sentence);
        await expect(page.locator('#dock-state')).toContainText(host.observation.state.replaceAll('_', ' '));
        await page.locator('#return-to-sky').press('Enter');
        await expect(planet).toBeFocused();
    }
});
