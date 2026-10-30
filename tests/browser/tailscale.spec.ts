import { expect, test } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

// Start browser promises in an awaited evaluation before advancing the paused
// clock, then join their exact result after the request/morph event.
declare global {
    interface Window {
        retentionRefresh: Promise<unknown>;
        retentionMorph: Promise<void>;
    }
}

// Only the disposable HTTPS emulated-Serve edge understands this credential.
// It is never a production application parameter or a Tailscale credential.
const credential = process.env.BROWSER_TAILSCALE_CREDENTIAL;
if (!credential) throw new Error('The Tailscale browser fixture requires its private emulated-Serve credential.');
test.use({ extraHTTPHeaders: { 'X-Nebuless-Fixture-Access': credential } });
const standby = '22222222-2222-4222-8222-222222222222';

test('verified access opens the fleet directly without enrollment or a sign-in form', async ({ page }) => {
    const response = await page.goto('/');
    expect(response!.status()).toBe(200);
    expect(new URL(page.url()).pathname).toBe('/');
    await expect(page.getByRole('heading', { name: 'Orbit', exact: true })).toBeVisible();
    await expect(page.getByLabel('Main navigation')).toBeVisible();
    await expect(page.locator('#sky .planet')).toHaveCount(3);
    await expect(page.locator('#host-list option')).toHaveCount(3);
    await expect(page.locator('#connection')).toHaveAttribute('data-state', 'connected');
    await expect(page.locator('#verdict, .lens, .ledger-row, a[href="/satellites"], a[href^="/design"]')).toHaveCount(0);
    await expect(page.locator('input[type="password"], input[autocomplete="one-time-code"]')).toHaveCount(0);
    const fleet = await page.request.get('/api/v1/fleet');
    expect(fleet.status()).toBe(200);
    expect((await fleet.json()).hosts).toHaveLength(3);
});

test('access status identifies the connected person and fits phone text enlargement', async ({ page }, testInfo) => {
    const response = await page.goto('/login');
    expect(response!.status()).toBe(200);
    await expect(page.locator('body')).toHaveAttribute('data-auth-mode', 'tailscale');
    await expect(page.getByRole('heading', { name: "You're connected." })).toBeVisible();
    await expect(page.getByText('Connected as browser-owner@example.test.', { exact: true })).toBeVisible();
    await expect(page.locator('form, input')).toHaveCount(0);
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', new URL('/login', process.env.BROWSER_BASE_URL).href);
    expect(response!.headers()['cache-control']).toContain('no-store');
    if (process.env.BROWSER_TAILSCALE_SCREENSHOTS) {
        mkdirSync(process.env.BROWSER_TAILSCALE_SCREENSHOTS, { recursive: true, mode: 0o700 });
        await page.screenshot({ path: join(process.env.BROWSER_TAILSCALE_SCREENSHOTS, `${testInfo.project.name}-tailscale-access.png`), fullPage: true });
    }
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
    await expect(page.getByRole('link', { name: 'Open fleet' })).toBeVisible();
    await page.getByRole('link', { name: 'Open fleet' }).click();
    await expect(page.getByRole('heading', { name: 'Orbit', exact: true })).toBeVisible();
});

test('reboot needs an explicit confirmation click and queues once without password or TOTP', async ({ page }) => {
    await page.goto(`/hosts/${standby}`);
    const before = await (await page.request.get(`/api/v1/hosts/${standby}/actions`)).json();
    const trigger = page.getByRole('button', { name: 'Queue reboot', exact: true });
    await expect(trigger).toBeEnabled();
    await trigger.click();
    const dialog = page.getByRole('alertdialog', { name: 'Confirm reboot' });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Keep as is' })).toBeFocused();
    await expect(dialog.locator('input')).toHaveCount(0);
    const unopened = await (await page.request.get(`/api/v1/hosts/${standby}/actions`)).json();
    expect(unopened.latest?.id ?? null).toBe(before.latest?.id ?? null);
    await dialog.getByRole('button', { name: 'Keep as is' }).click();
    await expect(dialog).not.toBeVisible();
    await expect(trigger).toBeFocused();
    await trigger.click();
    await dialog.getByRole('button', { name: 'Confirm and queue' }).click();
    await expect(dialog).not.toBeVisible();
    await expect(page.locator('[data-reboot-state="queued"]')).toContainText('Waiting for the next check-in');
    await expect(trigger).toBeDisabled();
    const queued = await (await page.request.get(`/api/v1/hosts/${standby}/actions`)).json();
    expect(queued.latest.state).toBe('queued');
    expect(queued.latest.id).not.toBe(before.latest?.id);
    await page.reload();
    const stableResponse = await page.request.get(`/api/v1/hosts/${standby}/actions`);
    expect(stableResponse.status(), await stableResponse.text()).toBe(200);
    const stable = await stableResponse.json();
    expect(stable.latest.id).toBe(queued.latest.id);
    await page.getByRole('button', { name: 'Cancel reboot', exact: true }).click();
    await expect(page.locator('[data-reboot-state="canceled"]')).toContainText('Reboot canceled');
    const canceled = await (await page.request.get(`/api/v1/hosts/${standby}/actions`)).json();
    expect(canceled.latest.id).toBe(queued.latest.id);
    expect(canceled.latest.state).toBe('canceled');
});

test('losing verified identity denies Livewire and API access despite the existing session cookie', async ({ page, context, browser }) => {
    await page.goto('/hosts/33333333-3333-4333-8333-333333333333');
    await expect(page.locator('.process-list').first()).toContainText('worker');
    const cookie = (await context.cookies()).find((item) => item.name === 'nebuless_tailscale_browser');
    expect(cookie).toBeDefined();
    const replay = await browser.newContext({ ignoreHTTPSErrors: true, extraHTTPHeaders: {} });
    try {
        await replay.addCookies([cookie!]);
        expect((await replay.cookies()).find((item) => item.name === cookie!.name)?.value).toBe(cookie!.value);
        const denied = await replay.request.get(new URL('/api/v1/fleet', process.env.BROWSER_BASE_URL).href);
        expect(denied.status()).toBe(401);
    } finally {
        await replay.close();
    }
    await context.setExtraHTTPHeaders({});
    const update = page.waitForResponse((response) => response.url().includes('/livewire-') && response.request().method() === 'POST');
    await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    const denied = await update;
    expect(denied.status()).toBe(302);
    expect(denied.headers().location).toBe(new URL('/login', process.env.BROWSER_BASE_URL).href);
    const api = await page.request.get('/api/v1/fleet', { headers: { Accept: 'application/json' } });
    expect(api.status()).toBe(401);
    await expect(page).toHaveURL(new URL('/login', process.env.BROWSER_BASE_URL).href);
    await expect(page.getByRole('heading', { name: 'Connect Tailscale' })).toBeVisible();
    await expect(page.locator('#sky, [data-live-screen], form, input')).toHaveCount(0);
});

test('a client-supplied Tailscale identity is stripped without fixture edge approval', async ({ page, context }) => {
    await context.setExtraHTTPHeaders({
        'Tailscale-User-Login': 'browser-owner@example.test',
        'Tailscale-User-Name': 'Forged owner',
    });
    await page.goto('/');
    await expect(page).toHaveURL(new URL('/login', process.env.BROWSER_BASE_URL).href);
    await expect(page.getByRole('heading', { name: 'Connect Tailscale' })).toBeVisible();
    await expect(page.getByText('Connected as browser-owner@example.test.', { exact: true })).toHaveCount(0);
    expect((await page.request.get('/api/v1/fleet', { headers: { Accept: 'application/json' } })).status()).toBe(401);
});

test('a tagged phone opens the fleet through the preserved Serve source and loses access when it disappears', async ({ page, context }) => {
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    const fleetLoaded = page.waitForResponse(response => response.url().endsWith('/api/v1/fleet') && response.status() === 200);
    await context.setExtraHTTPHeaders({
        'X-Nebuless-Fixture-Access': credential!,
        'X-Nebuless-Fixture-Device': 'true',
        'X-Forwarded-For': '100.80.0.99',
        'Tailscale-User-Login': 'forged-person@example.test',
    });
    const response = await page.goto('/');
    await fleetLoaded;
    expect(response!.status()).toBe(200);
    await expect(page.getByRole('heading', { name: 'Orbit', exact: true })).toBeVisible();
    await expect(page.locator('#sky .planet')).toHaveCount(3).catch(async error => {
        throw new Error(JSON.stringify({ errors, connection: await page.locator('#connection-note').textContent(), state: await page.locator('#connection').getAttribute('data-state') }) + String(error));
    });
    expect((await page.request.get('/api/v1/fleet')).status()).toBe(200);
    await page.goto('/login');
    await expect(page.getByText('Connected as device nBrowserDevice.', { exact: true })).toBeVisible();
    await context.setExtraHTTPHeaders({
        'X-Forwarded-For': '100.80.0.2',
        'NEBULESS_TAILSCALE_SOURCE': '100.80.0.2',
    });
    expect((await page.request.get('/api/v1/fleet')).status()).toBe(401);
    await page.goto('/');
    await expect(page.getByRole('heading', { name: 'Connect Tailscale' })).toBeVisible();
});

// B1: deterministic clock/event checks against the disposable real hub. The
// clock's performance timeline starts at zero, including navigation requestStart.
async function retentionClock(page: import('@playwright/test').Page) {
    const time = new Date('2035-01-01T00:00:00Z');
    await page.clock.install({ time });
    await page.clock.pauseAt(new Date(time.getTime() + 1000));
    await page.addInitScript(() => {
        const entries = performance.getEntriesByType.bind(performance);
        performance.getEntriesByType = (type: string) => type === 'navigation'
            ? [{ requestStart: 0 } as PerformanceNavigationTiming] : entries(type);
    });
}

for (const selected of [false, true]) {
    test(`B1 offline retention expires ${selected ? 'selected snapshot' : 'current evidence'} inclusively without trusting the phone wall clock`, async ({ page }, testInfo) => {
        await retentionClock(page);
        const host = '33333333-3333-4333-8333-333333333333';
        const history = (await (await page.request.get(`/api/v1/hosts/${host}/history?limit=1`)).json()).data;
        expect(history).toHaveLength(1);
        const id = history[0].id;
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await page.goto(`/hosts/${host}${selected ? `?sample=${id}` : ''}`);
        const selector = page.getByLabel('Inspect received sample');
        const processEvidence = page.locator('[data-expired-text^="Selected snapshot"], [data-expired-text^="Processes and coverage"]');
        const vitals = page.locator('[data-expired-text="Vitals unavailable (expired)."]');
        const graph = page.locator('[data-expired-text="History unavailable (expired)."]');
        await expect(vitals.locator('dd').first()).not.toHaveText('Unavailable');
        await expect(processEvidence.locator('.process-list').first()).toContainText('worker');
        await expect(graph.locator('svg title')).not.toHaveCount(0);
        const actionText = await page.locator('#capabilities .capability-row').first().innerText();
        const incidentText = await page.locator('.incident-list').innerText();
        await selector.focus();
        const scroll = await page.evaluate(() => window.scrollY);
        await page.route('**/livewire-*/update', route => route.abort('failed'));
        const lifetime = Number(await vitals.getAttribute('data-evidence-lifetime')) * 1000;
        expect(lifetime).toBeGreaterThan(0);
        await page.clock.setSystemTime(new Date('2020-01-01T00:00:00Z'));
        await page.clock.fastForward(Math.max(0, lifetime - await page.evaluate(() => performance.now())));
        await expect(vitals.locator('dd').first()).toBeVisible(); // sample == C remains eligible
        await page.clock.runFor(1);
        await expect(vitals).toHaveText('Vitals unavailable (expired).');
        await expect(graph.locator('svg, title')).toHaveCount(0);
        await expect(processEvidence.locator('.process-list, summary')).toHaveCount(0);
        await expect(page.locator('[data-expired-text="Collector capabilities and coverage unavailable (expired)."]')).toHaveText('Collector capabilities and coverage unavailable (expired).');
        await expect(selector.locator(`option[value="${id}"]`)).toHaveText(`Selected #${id} unavailable (expired).`);
        await expect(selector).toHaveValue(selected ? String(id) : '');
        if (selected) await expect(page).toHaveURL(new RegExp(`sample=${id}`));
        await expect(selector).toBeFocused();
        expect(Math.abs(await page.evaluate(() => window.scrollY) - scroll)).toBeLessThan(3);
        await expect(page.locator('.evidence-note').first()).toContainText('4 h');
        expect(await page.locator('#capabilities .capability-row').first().innerText()).toBe(actionText);
        expect(await page.locator('.incident-list').innerText()).toBe(incidentText);
        await expect(page.getByRole('button', { name: 'Queue reboot', exact: true })).toBeDisabled();
        const failedRequest = page.waitForEvent('requestfailed', {
            predicate: request => request.url().includes('/livewire-') && request.method() === 'POST',
        });
        await page.evaluate(() => {
            const root = document.querySelector('[data-live-screen]');
            window.retentionRefresh = (window as any).Livewire.find(root?.getAttribute('wire:id')).$refresh().then(
                () => ({ succeeded: true }),
                (failure: unknown) => ({ succeeded: false, failure }),
            );
        });
        await page.clock.runFor(10);
        expect((await failedRequest).failure()?.errorText).toBe('net::ERR_FAILED');
        expect(await page.evaluate(() => window.retentionRefresh)).toEqual({
            succeeded: false,
            failure: { status: null, body: null, json: null, errors: null },
        });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        expect(errors).toEqual([]);
        await page.screenshot({ path: `../../.context/backlog-finish/retention-browser-${testInfo.project.name}-${process.env.BROWSER_TAILSCALE_GATE ? 'tailscale' : 'owner'}-${selected ? 'selected' : 'current'}-expired.png`, fullPage: true });
    });
}

test('B1 host history and cached culprit expire while every refresh fails', async ({ page }) => {
    await retentionClock(page);
    await page.goto(`/hosts/${standby}`);
    const detail = page.locator('[data-expired-text="Observation evidence unavailable (expired)."]');
    await expect(detail).toContainText('worker');
    const graphs = page.locator('[data-evidence-lifetime]:has(.cpu-line)');
    expect(await graphs.count()).toBe(1);
    await page.route('**/livewire-*/update', (route) => route.abort('failed'));
    const lifetime = Math.max(...await graphs.evaluateAll((elements) => elements.map((element) => Number((element as HTMLElement).dataset.evidenceLifetime) * 1000)));
    await page.clock.setSystemTime(new Date('2010-01-01T00:00:00Z'));
    await page.clock.fastForward(lifetime + 1);
    await expect(page.locator('.cpu-line')).toHaveCount(0);
    await expect(page.locator('[data-evidence-expired] svg')).toHaveCount(0);
    await expect(detail).toHaveText('Observation evidence unavailable (expired).');
    await expect(page.locator('[data-current-verdict]')).toHaveText('Current status is unknown.');
    await page.clock.fastForward(10000);
    await expect(detail).not.toContainText('worker');
    await expect(page.getByRole('heading', { name: 'Alpha-Hub', exact: true })).toBeVisible();
    await expect(page.getByLabel('Main navigation')).toBeVisible();
    const cached = await page.evaluate(async () => (await Promise.all((await caches.keys()).map(async (key) => (await (await caches.open(key)).keys()).map((request) => new URL(request.url).pathname)))).flat());
    expect(cached.every((path) => path === '/offline' || path.startsWith('/build/assets/'))).toBe(true);
});

for (const restore of ['visibility', 'bfcache']) {
    test(`B1 ${restore} hides observations synchronously before a pending failed resume refresh`, async ({ page }) => {
        await retentionClock(page);
        const host = '33333333-3333-4333-8333-333333333333';
        const history = (await (await page.request.get(`/api/v1/hosts/${host}/history?limit=1`)).json()).data;
        expect(history).toHaveLength(1);
        const id = String(history[0].id);
        await page.goto(`/hosts/${host}?sample=${id}`);
        await expect(page.locator('.process-list').first()).toContainText('worker');
        const selector = page.getByLabel('Inspect received sample');
        await selector.focus();
        let release!: () => void;
        let intercepted!: () => void;
        const held = new Promise<void>((resolve) => { release = resolve; });
        const started = new Promise<void>((resolve) => { intercepted = resolve; });
        await page.route('**/livewire-*/update', async (route) => { intercepted(); await held; await route.abort('failed'); });
        if (restore === 'visibility') {
            await page.evaluate(() => {
                Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' });
                document.dispatchEvent(new Event('visibilitychange'));
            });
            await page.clock.fastForward(14400001);
        }
        const synchronous = await page.evaluate((kind) => {
            if (kind === 'visibility') {
                Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' });
                document.dispatchEvent(new Event('visibilitychange'));
            } else window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
            return document.querySelectorAll('.process-list, svg title, .vitals dd').length;
        }, restore);
        expect(synchronous).toBe(0);
        await page.clock.runFor(10);
        await started;
        await expect(page.locator('[data-current-verdict]')).not.toContainText('worker');
        await expect(selector).toHaveValue(id);
        await expect(selector).toBeFocused();
        release();
        await expect(page.locator('[data-connection]')).toContainText('Refresh failed');
        await expect(page.locator('.process-list, svg title')).toHaveCount(0);
    });
}

test('B1 a delayed successful Livewire morph cannot restore evidence expired in transit', async ({ page }) => {
    await retentionClock(page);
    const host = '33333333-3333-4333-8333-333333333333';
    const history = (await (await page.request.get(`/api/v1/hosts/${host}/history?limit=1`)).json()).data;
    expect(history).toHaveLength(1);
    const id = String(history[0].id);
    await page.goto(`/hosts/${host}?sample=${id}`);
    await expect(page.locator('.process-list').first()).toContainText('worker');
    let release!: () => void;
    let intercepted!: () => void;
    const held = new Promise<void>((resolve) => { release = resolve; });
    const started = new Promise<void>((resolve) => { intercepted = resolve; });
    await page.route('**/livewire-*/update', async (route) => {
        // APIRequestContext does not generate Chromium's HTTPS Fetch Metadata.
        // Preserve this same-origin browser POST's provenance for Laravel CSRF.
        const response = await route.fetch({
            headers: { ...await route.request().allHeaders(), 'sec-fetch-site': 'same-origin' },
        });
        expect(response.status()).toBe(200);
        const payload = await response.json();
        expect(payload.components).toHaveLength(1);
        expect(payload.components[0].effects.html).toContain('class="process-list"');
        expect(payload.components[0].effects.html).toContain('worker');
        intercepted();
        await held;
        await route.fulfill({ response });
    }, { times: 1 });
    await page.evaluate(() => {
        const root = document.querySelector('[data-live-screen]')!;
        window.retentionMorph = new Promise<void>((resolve) => {
            const unhook = (window as any).Livewire.hook('morphed', ({ component }: { component: { id: string } }) => {
                if (component.id !== root.getAttribute('wire:id')) return;
                unhook();
                resolve();
            });
        });
        window.retentionRefresh = (window as any).Livewire.find(root.getAttribute('wire:id')).$refresh();
    });
    await page.clock.runFor(10);
    await started;
    await page.clock.fastForward(14400001);
    await page.route('**/livewire-*/update', (route) => route.abort('failed'));
    release();
    await page.clock.resume();
    await page.evaluate(() => Promise.all([window.retentionMorph, window.retentionRefresh]));
    await expect(page.locator('.process-list, svg title, .vitals dd')).toHaveCount(0);
    await expect(page.locator('[data-current-verdict]')).toHaveText('Current status is unknown.');
    await page.clock.runFor(1000);
    await expect(page.locator('[data-current-verdict]')).toHaveText('Current status is unknown.');
    await expect(page.getByLabel('Inspect received sample')).toHaveValue(id);
    await expect(page).toHaveURL(new RegExp(`sample=${id}`));
});


test('Orbit uses verified Tailscale owner APIs and clears evidence when identity is lost', async ({ page, context }) => {
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    const fleetLoaded = page.waitForResponse(response => response.url().endsWith('/api/v1/fleet') && response.status() === 200);
    const response = await page.goto('/orbit');
    await fleetLoaded;
    expect(response!.status()).toBe(200);
    expect(response!.headers()['cache-control']).toContain('no-store');
    await expect(page.locator('#host-list option')).toHaveCount(3).catch(async error => {
        throw new Error(JSON.stringify({ errors, connection: await page.locator('#connection-note').textContent(), state: await page.locator('#connection').getAttribute('data-state') }) + String(error));
    });
    await page.locator('.system-chooser summary').click();
    await page.locator('#host-list').selectOption('33333333-3333-4333-8333-333333333333');
    await expect(page.locator('#process-body')).toContainText('worker');
    await context.setExtraHTTPHeaders({});
    const denied = page.waitForResponse(r => r.url().endsWith('/api/v1/fleet') && r.status() === 401);
    await page.locator('#retry').click();
    await denied;
    await expect(page.locator('#process-body')).toBeEmpty();
    await expect(page.locator('#sky .planet')).toHaveCount(0);
    await expect(page.locator('#signal-list')).toBeEmpty();
    expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
});
