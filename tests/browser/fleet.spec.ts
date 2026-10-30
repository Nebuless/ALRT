import { test, expect } from './live-fixture';

test('owner destinations connect without text loss or a false host current-page marker', async ({ page }) => {
    await page.goto('/');
    for (const destination of ['Citizens', 'Reporting', 'Orbit']) {
        await page.getByRole('navigation', { name: 'Main navigation', exact: true }).getByRole('link', { name: new RegExp('^' + destination) }).click();
        await expect(page.getByRole('navigation', { name: 'Main navigation', exact: true }).locator('[aria-current="page"]')).toContainText(destination);
    }
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    for (const link of await page.getByLabel('Main navigation').getByRole('link').all()) {
        await expect(link).toBeVisible();
        expect(await link.evaluate(node => node.scrollWidth <= node.clientWidth)).toBe(true);
    }
    await expect(page.locator('#host-instruments')).toBeVisible();
    await page.locator('#host-instruments').click();
    await expect(page.getByLabel('Main navigation').locator('[aria-current]')).toHaveCount(0);
});

// Start browser promises in an awaited evaluation before advancing the paused
// clock, then join their exact result after the request/morph event.
declare global {
    interface Window {
        retentionRefresh: Promise<unknown>;
        retentionMorph: Promise<void>;
    }
}

test('direct root Orbit, every host and Reporting share received evidence at phone and desktop widths', async ({ page }) => {
    const response = await page.request.get('/api/v1/fleet');
    expect(response.ok()).toBeTruthy();
    const fleet = await response.json();
    expect(fleet.hosts.length).toBeGreaterThan(0);
    const root = await page.goto('/');
    expect(root?.status()).toBe(200);
    await expect(page.getByRole('heading', { name: 'Orbit', exact: true })).toBeVisible();
    await expect(page.locator('#sky .planet')).toHaveCount(fleet.hosts.length);
    await expect(page.locator('#host-list option')).toHaveCount(fleet.hosts.length);
    await expect(page.locator('#connection')).toHaveAttribute('data-state', 'connected');
    await expect(page.getByLabel('Main navigation').getByRole('link')).toHaveText(['Orbit', 'Citizens', 'Reporting']);
    await expect(page.locator('#verdict, .lens, .ledger-row, .constellation, a[href^="/design"], a[href="/satellites"]')).toHaveCount(0);
    await page.locator('.system-chooser summary').click();
    for (const host of fleet.hosts) {
        await page.locator('#host-list').selectOption(host.id);
        await expect(page.locator('#dock-title')).toHaveText(host.name);
        await expect(page.locator('#host-instruments')).toHaveAttribute('href', `/hosts/${host.id}`);
        await page.locator('#host-instruments').click();
        await expect(page.getByRole('heading', { name: host.name, exact: true })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Processes', exact: true })).toBeVisible();
        const reboot = host.actions;
        const pending = reboot.latest && ['queued', 'cancel_window', 'awaiting_primary', 'authorized', 'attempted'].includes(reboot.latest.state);
        const action = page.getByRole('button', { name: 'Queue reboot', exact: true });
        if (!reboot.capability.available || pending) await expect(action).toBeDisabled();
        else await expect(action).toBeEnabled();
        await expect(page.getByText(`Records still on ${host.name} appear after replay.`, { exact: false })).toBeVisible();
        for (const category of ['Top by CPU', 'Top by memory', 'Orphans', 'Zombies', '10 longest running']) {
            await expect(page.locator('summary').filter({ hasText: category })).toHaveCount(1);
        }
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
        await page.getByRole('link', { name: /Back to orbit/i }).click();
        await page.locator('.system-chooser summary').click();
    }
    await page.getByLabel('Main navigation').getByRole('link', { name: 'Reporting', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Reporting paths' })).toBeVisible();
    const paths = (await (await page.request.get('/api/v1/reporting-paths')).json()).data;
    await expect(page.locator('.reporting-list li')).toHaveCount(paths.length);
    for (const [index, path] of paths.entries()) {
        const row = page.locator('.reporting-list li').nth(index);
        await expect(row).toContainText(path.source_name);
        await expect(row).toContainText(path.target_name);
        await expect(row).toContainText(path.state);
        await expect(row).toContainText(path.provenance === 'direct_receipt' ? 'Direct receipt' : path.provenance === 'collector_claim' ? 'Collector claim' : 'Unknown');
    }
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
    await page.getByLabel('Main navigation').scrollIntoViewIfNeeded();
    const nav = await page.getByLabel('Main navigation').boundingBox();
    expect(nav!.y + nav!.height).toBeLessThanOrEqual(page.viewportSize()!.height);
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    for (const link of await page.getByLabel('Main navigation').getByRole('link').all()) {
        await expect(link).toBeVisible();
        const fit = await link.evaluate((element) => {
            const box = element.getBoundingClientRect();
            const count = element.querySelector('.attention-count')?.getBoundingClientRect();
            return {
                contentFits: element.scrollWidth <= element.clientWidth,
                countFits: !count || (count.left >= box.left && count.right <= box.right && count.top >= box.top && count.bottom <= box.bottom),
            };
        });
        expect(fit.contentFits).toBeTruthy();
        expect(fit.countFits).toBeTruthy();
    }
});

test('historical process selection uses the retained sample id and survives a refresh', async ({ page }) => {
    const fleet = await (await page.request.get('/api/v1/fleet')).json();
    const host = fleet.hosts.find((item: { name: string }) => item.name === 'Alpha-Hub') ?? fleet.hosts[0];
    const history = await (await page.request.get(`/api/v1/hosts/${host.id}/history?limit=1`)).json();
    expect(history.data.length).toBeGreaterThan(0);
    const id = history.data[0].id;
    await page.goto(`/hosts/${host.id}`);
    await page.getByLabel('Inspect received sample').selectOption(String(id));
    await expect(page.getByText(`Historical snapshot #${id}`, { exact: false })).toBeVisible();
    await expect(page).toHaveURL(new RegExp(`sample=${id}`));
    const snapshot = (await (await page.request.get(`/api/v1/hosts/${host.id}/history/snapshots/${id}`)).json()).data;
    for (const process of snapshot.processes.top_cpu.items) {
        await expect(page.locator('.process-list').first().getByText(process.name, { exact: true })).toBeVisible();
    }
    await page.reload();
    await expect(page.getByLabel('Inspect received sample')).toHaveValue(String(id));
    await expect(page.getByText(`Historical snapshot #${id}`, { exact: false })).toBeVisible();
});

test('visible polling preserves focus and scroll, pauses while hidden and refreshes on resume', async ({ page }) => {
    await retentionClock(page);
    await page.goto('/hosts/33333333-3333-4333-8333-333333333333');
    await page.evaluate(() => Promise.all([...document.images].map(image => image.decode())));
    const selector = page.getByLabel('Inspect received sample');
    await selector.focus();
    const scroll = await page.evaluate(() => window.scrollY);
    const armMorph = () => page.evaluate(() => {
        const root = document.querySelector('[data-live-screen]')!;
        window.retentionMorph = new Promise<void>((resolve) => {
            const unhook = (window as any).Livewire.hook('morphed', ({ component }: { component: { id: string } }) => {
                if (component.id !== root.getAttribute('wire:id')) return;
                unhook();
                resolve();
            });
        });
    });
    await armMorph();
    const update = page.waitForResponse((response) => response.url().includes('/livewire-') && response.request().method() === 'POST');
    await page.clock.runFor(10010);
    await update;
    await page.clock.resume();
    await page.evaluate(() => window.retentionMorph);
    await expect(selector).toBeFocused();
    expect(Math.abs(await page.evaluate(() => window.scrollY) - scroll)).toBeLessThan(3);

    // Hold a real poll response after the request starts, then move the viewport
    // while it is pending. Completion must preserve this newer user position.
    let release!: () => void;
    let intercepted!: () => void;
    const held = new Promise<void>((resolve) => { release = resolve; });
    const started = new Promise<void>((resolve) => { intercepted = resolve; });
    await page.route('**/livewire-*/update', async (route) => {
        const response = await route.fetch();
        intercepted();
        await held;
        await route.fulfill({ response });
    }, { times: 1 });
    await armMorph();
    const completed = page.waitForResponse((response) => response.url().includes('/livewire-') && response.request().method() === 'POST');
    await page.evaluate(() => {
        const root = document.querySelector('[data-live-screen]')!;
        (window as any).Livewire.find(root.getAttribute('wire:id')).$refresh();
    });
    await page.clock.runFor(10);
    await started;
    await page.evaluate(() => window.scrollBy(0, -300));
    expect(await page.evaluate(() => window.scrollY)).toBeLessThan(scroll - 100);
    const moved = await page.evaluate(() => window.scrollY);
    release();
    await completed;
    await page.clock.resume();
    await page.evaluate(() => window.retentionMorph);
    await expect(page.locator('body')).toHaveAttribute('data-connection-state', 'connected');
    await expect(selector).toBeFocused();
    expect(Math.abs(await page.evaluate(() => window.scrollY) - moved)).toBeLessThan(3);

    let requests = 0;
    page.on('request', (request) => { if (request.url().includes('/livewire-') && request.method() === 'POST') requests++; });
    await page.evaluate(() => {
        Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' });
        document.dispatchEvent(new Event('visibilitychange'));
    });
    const hiddenCount = requests;
    await page.clock.fastForward(11000);
    expect(requests).toBe(hiddenCount);
    await armMorph();
    const resumed = page.waitForRequest(request => request.url().includes('/livewire-') && request.method() === 'POST');
    await page.evaluate(() => {
        Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' });
        document.dispatchEvent(new Event('visibilitychange'));
    });
    await page.clock.runFor(10);
    await resumed;
    expect(requests).toBeGreaterThan(hiddenCount);
    await page.clock.resume();
    await page.evaluate(() => window.retentionMorph);
    await expect(selector).toBeFocused();
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
    await page.goto('/hosts/22222222-2222-4222-8222-222222222222');
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
        const response = await route.fetch();
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
