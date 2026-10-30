import { test, expect } from './live-fixture';

test('modern offline installation replaces retired cached fallback', async ({ page }) => {
    await page.goto('/');
    await page.evaluate(async () => {
        await navigator.serviceWorker.ready;
        const old = await caches.open('nebuless-static-v1');
        await old.put('/offline', new Response('<h1>Retired frontend</h1>'));
        const registration = await navigator.serviceWorker.getRegistration();
        await registration?.unregister();
    });
    await page.reload();
    await expect(page.locator('#host-list option')).toHaveCount(3);
    await page.evaluate(async () => { await navigator.serviceWorker.ready; });
    expect(await page.evaluate(async () => (await caches.keys()).includes('nebuless-static-v1'))).toBe(false);
    expect(await page.evaluate(async () => (await (await caches.open('nebuless-static-v2')).match('/offline'))?.text())).not.toContain('Retired frontend');
});

for (const entry of ['/', '/orbit', '/citizens']) {
    test(`direct ${entry} entry registers only the static offline fallback`, async ({ page, context }) => {
        await page.goto(entry);
        await page.evaluate(async () => { await navigator.serviceWorker.ready; });
        await page.reload();
        const cached = await page.evaluate(async () => (await Promise.all((await caches.keys()).map(async key => (await (await caches.open(key)).keys()).map(request => new URL(request.url).pathname)))).flat());
        expect(cached).toContain('/offline');
        expect(cached.every(path => path === '/offline' || path.startsWith('/build/assets/'))).toBe(true);
        await context.setOffline(true);
        await page.reload();
        await expect(page.getByRole('heading', { name: 'You are offline.' })).toBeVisible();
        await expect(page.locator('#sky .planet,.citizen-card')).toHaveCount(0);
    });
}

test('a failed poll retains evidence, ages it and replaces a cached healthy verdict with unknown', async ({ page }) => {
    const time = new Date('2035-01-01T00:00:00Z');
    await page.clock.install({ time });
    await page.clock.pauseAt(new Date(time.getTime() + 1000));
    await page.goto('/hosts/33333333-3333-4333-8333-333333333333');
    const processes = page.locator('.process-list').first();
    await expect(processes).toContainText('worker');
    const evidence = await processes.innerText();
    await expect(page.locator('[data-current-verdict]')).not.toHaveText('Current status is unknown.');
    await page.route('**/livewire-*/update', (route) => route.abort('failed'));
    const failed = page.waitForEvent('requestfailed', {
        predicate: request => request.url().includes('/livewire-') && request.method() === 'POST',
    });
    await page.clock.runFor(10010);
    await failed;
    await expect(page.locator('[data-connection]')).toContainText('Refresh failed');
    await expect(page.locator('[data-current-verdict]')).toHaveText('Current status is unknown.');
    await page.clock.runFor(1100);
    await expect(page.locator('[data-current-verdict]')).toHaveText('Current status is unknown.');
    expect(await processes.innerText()).toBe(evidence);
    await expect(page.locator('[data-screen-age]')).not.toHaveText('0 s ago');
    await page.unroute('**/livewire-*/update');
    const recovered = page.waitForResponse(response => response.url().includes('/livewire-') && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Try again' }).click();
    await page.clock.runFor(10);
    await recovered;
    await page.clock.resume();
    await expect(page.locator('[data-connection]')).not.toContainText('Refresh failed');
    await expect(page.locator('[data-current-verdict]')).not.toHaveText('Current status is unknown.');
    await expect(page.locator('[data-screen-age]')).toHaveText('0 s ago');
});

test('offline resume and worker updates cache only static assets and never replay a POST', async ({ page, context }) => {
    const time = new Date('2035-01-01T00:00:00Z');
    await page.clock.install({ time });
    await page.clock.pauseAt(new Date(time.getTime() + 1000));
    await page.goto('/');
    await expect(page.locator('#connection')).toHaveAttribute('data-state', 'connected');
    await page.evaluate(async () => {
        const registration = await navigator.serviceWorker.ready;
        if (!navigator.serviceWorker.controller) {
            await new Promise<void>((resolve) => {
                if (registration.active) resolve();
                else navigator.serviceWorker.addEventListener('controllerchange', () => resolve(), { once: true });
            });
        }
    });
    await page.reload();
    await expect(page.locator('#connection')).toHaveAttribute('data-state', 'connected');
    await page.evaluate(async () => {
        await fetch('/api/v1/fleet');
        await fetch('/login');
        await (await navigator.serviceWorker.ready).update();
    });
    const cached = await page.evaluate(async () => {
        const requests = await Promise.all((await caches.keys()).map(async (key) => (await (await caches.open(key)).keys()).map((request) => new URL(request.url).pathname)));
        return requests.flat();
    });
    expect(cached).toContain('/offline');
    expect(cached.every((path) => path === '/offline' || path.startsWith('/build/assets/'))).toBeTruthy();
    await context.setOffline(true);
    await expect(page.locator('#connection')).toContainText(/offline/i);
    await expect(page.locator('#state-note')).toContainText(/last|old|recorded|stale|failed|disconnect/i);
    const failed = await page.evaluate(async () => {
        try { await fetch('/owner/action-confirmation', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' }); return false; } catch { return true; }
    });
    expect(failed).toBeTruthy();
    let actionPosts = 0;
    page.on('request', (request) => { if (request.method() === 'POST' && request.url().includes('/owner/')) actionPosts++; });
    await page.reload();
    await expect(page.getByRole('heading', { name: 'You are offline.' })).toBeVisible();
    await expect(page.locator('#sky, [data-live-screen]')).toHaveCount(0);
    await context.setOffline(false);
    await page.getByRole('link', { name: 'Try Orbit again' }).click();
    await expect(page.locator('#retry')).toBeEnabled();
    await expect(page.locator('#connection')).toHaveAttribute('data-state', 'connected');
    const refreshed = page.waitForResponse(response => new URL(response.url()).pathname === '/api/v1/fleet');
    await page.clock.runFor(11000);
    await refreshed;
    expect(actionPosts).toBe(0);
});

for (const status of [401, 403]) {
    test(`host denied poll ${status} clears private DOM and stops retries`, async ({ page }) => {
        const time = new Date('2035-01-01T00:00:00Z');
        await page.clock.install({ time });
        await page.clock.pauseAt(new Date(time.getTime() + 1000));
        const host = '/hosts/33333333-3333-4333-8333-333333333333';
        await page.goto(host);
        await expect(page.locator('.process-list').first()).toContainText('worker');
        await expect(page.locator('[wire\\:snapshot]')).toHaveCount(1);
        let requests = 0;
        await page.route('**/livewire-*/update', async route => {
            requests++;
            await route.fulfill({ status, contentType: 'application/json', body: '{}' });
        });
        const denied = page.waitForResponse(response => response.url().includes('/livewire-') && response.status() === status);
        await page.clock.runFor(10010);
        await denied;
        const recovery = page.getByRole('link', { name: 'Restore access' });
        await expect(recovery).toBeVisible();
        await expect(recovery).toHaveAttribute('href', host);
        await expect(page.locator('body')).toHaveAttribute('data-connection-state', 'denied');
        await expect(page.locator('[data-live-screen], [wire\\:snapshot], .process-list, .incident-list, dialog, [data-reboot-mutation]')).toHaveCount(0);
        expect(await page.locator('body').innerHTML()).not.toContain('worker');
        expect(await page.evaluate(() => (window as any).Livewire.all().length)).toBe(0);
        await expect(recovery).toBeFocused();
        await page.evaluate(() => {
            window.dispatchEvent(new Event('online'));
            window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
            document.dispatchEvent(new Event('visibilitychange'));
        });
        await page.clock.runFor(31000);
        expect(requests).toBe(1);
        await page.unroute('**/livewire-*/update');
        await recovery.click();
        await expect(page.locator('.process-list').first()).toContainText('worker');
    });
}

test('late successful host morph cannot restore private evidence after a separate denial', async ({ page }) => {
    await page.addInitScript(() => {
        let runtime: any;
        Object.defineProperty(window, 'Livewire', { configurable: true, get: () => runtime, set: value => {
                runtime = value;
                const hook = runtime.hook.bind(runtime);
                runtime.hook = (name: string, callback: (request: any) => void) => hook(name, name === 'request' ? (request: any) => {
                    const fail = request.fail;
                    request.fail = (handler: any) => { (window as any).__denyHost = handler; fail(handler); };
                    callback(request);
                } : callback);
        } });
    });
    const time = new Date('2035-01-01T00:00:00Z');
    await page.clock.install({ time });
    await page.clock.pauseAt(new Date(time.getTime() + 1000));
    await page.goto('/hosts/33333333-3333-4333-8333-333333333333');
    await expect(page.locator('.process-list').first()).toContainText('worker');
    let ready!: () => void, release!: () => void;
    const held = new Promise<void>(resolve => { ready = resolve; });
    const resumed = new Promise<void>(resolve => { release = resolve; });
    await page.route('**/livewire-*/update', async route => {
        const response = await route.fetch();
        ready();
        await resumed;
        await route.fulfill({ response });
    });
    const complete = page.waitForResponse(response => response.url().includes('/livewire-'));
    await page.clock.runFor(10010);
    await held;
    await page.evaluate(() => { (window as any).__denyHost({ status: 401, preventDefault() {} }); });
    release();
    await complete;
    await expect(page.getByRole('link', { name: 'Restore access' })).toBeVisible();
    await expect(page.locator('[wire\\:snapshot], .process-list')).toHaveCount(0);
    expect(await page.locator('body').innerHTML()).not.toContain('worker');
});
