import { mkdirSync } from 'node:fs';
import { test, expect } from './live-fixture';

declare global {
    interface Window { orbitMutations: { gauges: number; processes: number }; }
}

const restrictedHost = '33333333-3333-4333-8333-333333333333';
const standby = '22222222-2222-4222-8222-222222222222';

test('compact header keeps one navigation path and fits enlarged phone text', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('#host-list option')).toHaveCount(3);
    const header = page.locator('.orbit-header');
    await expect(header.getByRole('navigation', { name: 'Main navigation' }).getByRole('link')).toHaveCount(3);
    await expect(header.getByRole('heading', { name: 'Orbit', exact: true })).toBeVisible();
    expect(await header.locator('.brand-mark').evaluate((image: HTMLImageElement) => image.complete && image.naturalWidth > 0)).toBe(true);
    await expect(page.locator('#agents-link,.brand-caption,#sector-title')).toHaveCount(0);
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    for (const link of await header.getByRole('link').all()) {
        const fit = await link.evaluate(node => { const r = node.getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth + 1 && r.height >= 44 && node.scrollWidth <= node.clientWidth; });
        expect(fit).toBe(true);
    }
});

test('compact header fits enlarged text across intermediate breakpoint widths', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('#host-list option')).toHaveCount(3);
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    for (const width of [900, 901, 1000, 1100]) {
        await page.setViewportSize({ width, height: 1000 });
        const fit = await page.locator('.orbit-header').evaluate(node => [...node.querySelectorAll('a,button,.identity,.session')].every(child => {
            const r = child.getBoundingClientRect();
            return r.left >= 0 && r.right <= innerWidth + 1;
        }));
        expect(fit).toBe(true);
    }
});

test('pending host action updates preserve keyboard destination and recover when removed', async ({ page }) => {
    let state = 'queued';
    await page.route('**/api/v1/fleet', async route => {
        const response = await route.fetch();
        const data = await response.json();
        const host = data.hosts.find(host => host.id === standby);
        host.actions.latest = state ? { id: 'synthetic-action', state } : null;
        await route.fulfill({ response, json: data });
    });
    await page.goto('/');
    const link = page.locator('#pending-host-actions a');
    await expect(link).toHaveCount(1);
    await link.focus();
    state = 'cancel_window';
    await page.locator('#retry').evaluate((button: HTMLButtonElement) => button.click());
    await expect(page.locator('#retry')).toBeEnabled();
    await expect(link).toBeFocused();
    state = '';
    await page.locator('#retry').evaluate((button: HTMLButtonElement) => button.click());
    await expect(page.locator('#retry')).toBeEnabled();
    await expect(page.locator('.system-chooser summary')).toBeFocused();
});

test('host context follows automatic selection and browser history, while process disclosures survive polling', async ({ page }) => {
    await page.goto('/orbit');
    await expect(page.locator('#host-list option')).toHaveCount(3);
    const initial = new URL(page.url()).searchParams.get('host');
    await expect(page.getByLabel('Main navigation').getByRole('link', { name: 'Citizens', exact: true })).toHaveAttribute('href', '/citizens?host=' + initial);
    await page.locator('.system-chooser summary').click();
    await page.locator('#host-list').selectOption(restrictedHost);
    await expect(page.locator('#process-body')).toContainText('worker');
    const identity = page.locator('#process-body details').first();
    await identity.locator('summary').click();
    await identity.locator('summary').focus();
    const refreshed = page.waitForResponse(response => response.url().includes('/history?') && response.status() === 200);
    await page.locator('#retry').evaluate((button: HTMLButtonElement) => button.click());
    await refreshed;
    await expect(page.locator('#retry')).toBeEnabled();
    await expect(identity).toHaveAttribute('open', '');
    await expect(identity.locator('summary')).toBeFocused();
    await page.locator('#host-list').selectOption(standby);
    await page.goBack();
    await expect(page.locator('#dock-title')).toHaveText('Beta-Restricted');
    await expect(page.getByLabel('Main navigation').getByRole('link', { name: 'Citizens', exact: true })).toHaveAttribute('href', '/citizens?host=' + restrictedHost);
    await expect(page.locator('#access-recovery')).toHaveAttribute('href', '/orbit?host=' + restrictedHost);
    expect((await page.locator('#pause-constellation').boundingBox())!.height).toBeGreaterThanOrEqual(44);
});

test('selection settles when reduced motion changes mid-flight', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    await page.goto('/orbit');
    await expect(page.locator('#host-list option')).toHaveCount(3);
    const planet = page.locator(`.planet[data-host="${restrictedHost}"]`);
    await planet.click();
    await planet.locator('.system-body').evaluate(node => {
        const animation = node.getAnimations()[0];
        if (!animation) throw new Error('Expected zoom animation');
        animation.currentTime = 100;
        animation.playbackRate = 0.001;
    });
    await page.evaluate(() => {
        (window as any).motionChanged = new Promise(resolve => matchMedia('(prefers-reduced-motion: reduce)').addEventListener('change', resolve, { once: true }));
    });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.evaluate(() => (window as any).motionChanged);
    expect(await planet.locator('.system-body').evaluate(node => node.getAnimations().filter(animation => animation.playState === 'running').length)).toBe(0);
    await expect(planet).toHaveAttribute('aria-pressed', 'true');
    await page.locator('#return-to-sky').click();
    await expect(planet).toBeFocused();
    await expect(page.locator('#sky')).not.toHaveClass(/close-up/);
});

test('pause blocks new selection zoom and hidden state settles an in-flight zoom', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    await page.goto('/orbit');
    await expect(page.locator('#host-list option')).toHaveCount(3);
    const planet = page.locator(`.planet[data-host="${restrictedHost}"]`);
    await page.locator('#pause-constellation').click();
    await planet.click();
    expect(await planet.locator('.system-body').evaluate(node => node.getAnimations().length)).toBe(0);
    await page.locator('#return-to-sky').click();
    await page.locator('#pause-constellation').click();
    await planet.click();
    await planet.locator('.system-body').evaluate(node => {
        const animation = node.getAnimations()[0];
        if (!animation) throw new Error('Expected zoom animation');
        animation.currentTime = 100;
        animation.playbackRate = 0.001;
    });
    await page.evaluate(() => {
        Object.defineProperty(document, 'hidden', { configurable: true, get: () => true });
        document.dispatchEvent(new Event('visibilitychange'));
    });
    expect(await planet.locator('.system-body').evaluate(node => node.getAnimations().length)).toBe(0);
});

test('Orbit uses authenticated fleet evidence and native data controls', async ({ page }) => {
    await page.goto('/orbit');
    await page.locator('.system-chooser').evaluate((chooser: HTMLDetailsElement) => { chooser.open = true; });
    await expect(page.getByRole('heading', { name: 'Orbit', exact: true })).toBeVisible();
    await expect(page.locator('#host-list option')).toHaveCount(3);
    const selectedEvidence = page.waitForResponse(response => response.url().includes(`/api/v1/hosts/${restrictedHost}/history?`) && response.status() === 200);
    await page.locator('#host-list').selectOption(restrictedHost);
    await selectedEvidence;
    await expect(page.locator('#dock-title')).toHaveText('Beta-Restricted');
    await expect(page.locator('#process-body')).toContainText('worker');
    if (process.env.ORBIT_SCREENSHOTS) {
        mkdirSync(process.env.ORBIT_SCREENSHOTS, { recursive: true });
        await page.screenshot({ path: process.env.ORBIT_SCREENSHOTS + '/' + test.info().project.name + '-orbit-live.png', fullPage: true });
    }
    await page.locator('#process-filter').fill('no matching sentinel');
    await expect(page.locator('#process-empty')).toBeVisible();
    await page.locator('#reset-process').click();
    await expect(page.locator('#process-body')).toContainText('worker');
    await page.locator('#process-category').selectOption('top_rss');
    await page.locator('#process-sort').selectOption('name');
    const history = page.waitForResponse(r => r.url().includes('/history?') && r.status() === 200);
    await page.getByRole('button', { name: '30 min', exact: true }).click();
    await history;
    await expect(page.locator('#heat-ribbon')).toHaveAttribute('data-range', '30');
    await expect(page.locator('#heat-title')).toContainText(/CPU|history/i);
    await page.locator('#host-search').fill('absent sentinel');
    await expect(page.locator('#fleet-empty')).toBeVisible();
    await page.locator('#reset-hosts').click();
    await page.locator('#host-list').selectOption(standby);
    await expect(page.locator('#dock-state')).toContainText(/offline|silent/i);
    await expect(page).toHaveURL(new RegExp('host=' + standby));
    expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
    expect(await page.evaluate(() => Object.keys(localStorage).length)).toBe(0);
    expect(await page.evaluate(() => Object.keys(sessionStorage).length)).toBe(0);
});

test('Orbit handles lost access by clearing private process and host data', async ({ page }) => {
    await page.goto('/orbit');
    await page.locator('.system-chooser').evaluate((chooser: HTMLDetailsElement) => { chooser.open = true; });
    await expect(page.locator('#host-list option')).toHaveCount(3);
    await page.locator('#host-list').selectOption(restrictedHost);
    await expect(page.locator('#process-body')).toContainText('worker');
    await page.route('**/api/v1/**', route => route.fulfill({ status: 401, contentType: 'application/json', body: '{"message":"Unauthenticated."}' }));
    await page.locator('#retry').click();
    await expect(page.locator('#connection')).toContainText(/sign in|access|authentication/i);
    await expect(page.locator('#process-body')).toBeEmpty();
    await expect(page.locator('#node-card')).toBeHidden();
    await expect(page.locator('#node-card-title')).toBeEmpty();
    await expect(page.locator('#sky .planet')).toHaveCount(0);
    await expect(page.locator('#host-list')).toBeDisabled();
    await expect(page.locator('#signal-list')).toBeEmpty();
});

test('Orbit preserves honest last-success evidence after refresh failure', async ({ page }) => {
    await page.goto('/orbit');
    await page.locator('.system-chooser').evaluate((chooser: HTMLDetailsElement) => { chooser.open = true; });
    await expect(page.locator('#host-list option')).toHaveCount(3);
    await page.locator('#host-list').selectOption(restrictedHost);
    await expect(page.locator('#process-body')).toContainText('worker');
    await page.route('**/api/v1/**', route => route.abort('failed'));
    await page.locator('#retry').click();
    await expect(page.locator('#connection')).toContainText(/fail|offline|disconnect|unavailable/i);
    await expect(page.locator('#dock-title')).toHaveText('Beta-Restricted');
    await expect(page.locator('#state-note')).toContainText(/last|old|recorded|stale|failed|disconnect/i);
});


test('Orbit returns incident focus after a completed refresh while the dialog is open', async ({ page }) => {
    await page.goto('/orbit');
    await page.locator('.system-chooser').evaluate((chooser: HTMLDetailsElement) => { chooser.open = true; });
    await expect(page.locator('#host-list option')).toHaveCount(3);
    await page.locator('#host-list').selectOption('22222222-2222-4222-8222-222222222222');
    await expect(page.locator('#signal-list [data-incident]').first()).toBeVisible();
    const incident = await page.locator('#signal-list [data-incident]').first().getAttribute('data-incident');
    const trigger = page.locator('#signal-list [data-incident]').filter({ has: page.locator('strong') }).first();
    await trigger.click();
    await expect(page.locator('#signal-drawer')).toBeVisible();
    const refreshed = page.waitForResponse(r => r.url().endsWith('/incidents') && r.status() === 200);
    await page.locator('#retry').evaluate((button: HTMLButtonElement) => button.click());
    await refreshed;
    await expect(page.locator('#retry')).toBeEnabled();
    await page.locator('#close-drawer').click();
    await expect(page.locator('#signal-list [data-incident="' + incident + '"]')).toBeFocused();
});


test('Orbit clears an open incident dialog when access is forbidden', async ({ page }) => {
    await page.goto('/orbit');
    await page.locator('.system-chooser').evaluate((chooser: HTMLDetailsElement) => { chooser.open = true; });
    await expect(page.locator('#host-list option')).toHaveCount(3);
    await page.locator('#host-list').selectOption(standby);
    await page.locator('#signal-list [data-incident]').first().click();
    await expect(page.locator('#signal-drawer')).toBeVisible();
    await page.route('**/api/v1/**', route => route.fulfill({ status: 403, contentType: 'application/json', body: '{"message":"Forbidden."}' }));
    const denied = page.waitForResponse(r => r.url().endsWith('/fleet') && r.status() === 403);
    await page.locator('#retry').evaluate((button: HTMLButtonElement) => button.click());
    await denied;
    await expect(page.locator('#signal-drawer')).not.toBeVisible();
    await expect(page.locator('#drawer-evidence')).toBeEmpty();
    await expect(page.locator('#process-body')).toBeEmpty();
    await expect(page.locator('#node-card')).toBeHidden();
    await expect(page.locator('#node-card-title')).toBeEmpty();
    await expect(page.locator('#sky .planet')).toHaveCount(0);
});

test('Orbit does not adopt a delayed history response for an obsolete host', async ({ page }) => {
    await page.goto('/orbit');
    await page.locator('.system-chooser').evaluate((chooser: HTMLDetailsElement) => { chooser.open = true; });
    await expect(page.locator('#host-list option')).toHaveCount(3);
    await page.locator('#host-list').selectOption(restrictedHost);
    await expect(page.locator('#process-body')).toContainText('worker');
    let release!: () => void;
    let entered!: () => void;
    const held = new Promise<void>(resolve => { release = resolve; });
    const started = new Promise<void>(resolve => { entered = resolve; });
    await page.route('**/hosts/' + beta-restricted + '/history?**', async route => {
        entered();
        await held;
        try { await route.continue(); } catch (error) { if (!route.request().failure()) throw error; }
    });
    await page.getByRole('button', { name: '30 min', exact: true }).click();
    await started;
    await page.locator('#host-list').selectOption(standby);
    release();
    await expect(page.locator('#dock-title')).toHaveText('Alpha-Hub');
    await expect(page).toHaveURL(new RegExp('host=' + standby));
    await expect(page.locator('#heat-ribbon')).toHaveAttribute('data-range', '30');
    await expect(page.locator('#connection')).toHaveAttribute('data-state', 'connected');
});


test('Orbit keeps unchanged gauge and process nodes while ages advance and expire', async ({ page }) => {
    await page.clock.install({ time: new Date('2026-10-21T12:00:00Z') });
    await page.clock.pauseAt(new Date('2026-10-21T12:00:01Z'));
    await page.route('**/api/v1/fleet', async route => {
        const response = await route.fetch();
        const body = await response.json();
        const host = body.hosts.find((item: { id: string }) => item.id === restrictedHost);
        host.contact_age_seconds = 0;
        host.contact_at = host.observed_at;
        host.observation.state = 'healthy';
        host.metrics.cpu_percent = { status: 'available', value: 42 };
        host.sample_age_seconds = 27;
        host.sampled_at = new Date(Date.parse(host.observed_at) - 27000).toISOString();
        await route.fulfill({ response, json: body });
    });
    await page.goto('/orbit?host=' + restrictedHost);
    await page.locator('.system-chooser').evaluate((chooser: HTMLDetailsElement) => { chooser.open = true; });
    await expect(page.locator('#process-body')).toContainText('worker');
    await expect(page.locator('#retry')).toBeEnabled();
    await expect(page.locator('#gauges .cpu .gauge-value')).toHaveText('42');
    const gauge = await page.locator('#gauges figure').first().elementHandle();
    const row = await page.locator('#process-body tr').first().elementHandle();
    expect(gauge).not.toBeNull();
    expect(row).not.toBeNull();
    if (!gauge || !row) throw new Error('Expected rendered telemetry nodes');
    const age = await page.locator('#sample-age').textContent();
    await page.evaluate(() => {
        const counts = { gauges: 0, processes: 0 };
        for (const [id, key] of [['gauges', 'gauges'], ['process-body', 'processes']] as const) {
            new MutationObserver(records => { counts[key] += records.length; })
                .observe(document.getElementById(id) ?? document.body, { childList: true, subtree: true });
        }
        window.orbitMutations = counts;
    });
    const cdp = await page.context().newCDPSession(page);
    await cdp.send('Performance.enable');
    const before = await cdp.send('Performance.getMetrics');
    await page.clock.runFor(2000);
    const after = await cdp.send('Performance.getMetrics');
    const taskDuration = (metrics: typeof before.metrics) => {
        const metric = metrics.find(metric => metric.name === 'TaskDuration');
        if (!metric) throw new Error('Chrome TaskDuration metric unavailable');
        return metric.value;
    };
    console.log(JSON.stringify({ viewport: test.info().project.name, controlledTicks: 2,
        mainThreadTaskMilliseconds: (taskDuration(after.metrics) - taskDuration(before.metrics)) * 1000,
        mutations: await page.evaluate(() => window.orbitMutations) }));
    expect(await page.evaluate(() => window.orbitMutations)).toEqual({ gauges: 0, processes: 0 });
    expect(await gauge.evaluate(node => node.isConnected)).toBe(true);
    expect(await row.evaluate(node => node.isConnected)).toBe(true);
    expect(await page.locator('#sample-age').textContent()).not.toBe(age);
    await page.clock.runFor(2000);
    await expect(page.locator('#gauges .cpu .gauge-value')).toHaveText('N/A');
    expect(await row.evaluate(node => node.isConnected)).toBe(true);
    await page.route('**/api/v1/fleet', async route => {
        const response = await route.fetch();
        const body = await response.json();
        const host = body.hosts.find((item: { id: string }) => item.id === restrictedHost);
        host.sample_age_seconds = 14399;
        host.sampled_at = new Date(Date.parse(host.observed_at) - 14399000).toISOString();
        await route.fulfill({ response, json: body });
    });
    await page.locator('#retry').click();
    await expect(page.locator('#retry')).toBeEnabled();
    await expect(page.locator('#process-body')).toContainText('worker');
    await page.clock.runFor(2000);
    await expect(page.locator('#process-body')).toBeEmpty();
    await cdp.detach();
});


test('Orbit refresh reuses fleet host evidence without a detail request', async ({ page }) => {
    await page.goto('/orbit?host=' + restrictedHost);
    await page.locator('.system-chooser').evaluate((chooser: HTMLDetailsElement) => { chooser.open = true; });
    await expect(page.locator('#retry')).toBeEnabled();
    await expect(page.locator('#process-body')).toContainText('worker');
    const requests: string[] = [];
    let bytes = 0;
    page.on('request', request => { if (request.url().includes('/api/v1/')) requests.push(new URL(request.url()).pathname); });
    const responses: Promise<void>[] = [];
    page.on('response', response => {
        if (response.url().includes('/api/v1/')) responses.push(response.body().then(body => { bytes += body.length; }));
    });
    await page.locator('#retry').click();
    await expect(page.locator('#retry')).toBeEnabled();
    await Promise.all(responses);
    const detailResponse = await page.request.get('/api/v1/hosts/' + restrictedHost);
    const avoidedDetailBytes = (await detailResponse.body()).length;
    expect(requests.filter(path => path === '/api/v1/hosts/' + restrictedHost)).toHaveLength(0);
    expect(requests).toHaveLength(3);
    console.log(JSON.stringify({ viewport: test.info().project.name, refreshRequests: requests.length, responseBodyBytes: bytes, avoidedDetailBytes }));
});


test('Orbit motion follows selection and keeps incident dismissal immediate with reduced motion', async ({ page }) => {
    for (const motion of ['no-preference', 'reduce'] as const) {
        await page.emulateMedia({ reducedMotion: motion });
        await page.goto('/orbit?host=' + standby);
    await page.locator('.system-chooser').evaluate((chooser: HTMLDetailsElement) => { chooser.open = true; });
        const trigger = page.locator('#signal-list [data-incident]').first();
        await expect(trigger).toBeVisible();
        expect(await page.locator('.planet[aria-pressed=true] .system-star').evaluate(el => getComputedStyle(el).transform), 'Stationary system star transform').toBe('none');
        await trigger.focus();
        const arrival = await trigger.evaluate((button: HTMLButtonElement) => {
            button.click();
            const drawer = document.querySelector<HTMLDialogElement>('#signal-drawer');
            if (!drawer) throw new Error('Expected incident dialog');
            const animations = drawer.getAnimations().filter(animation => (animation.effect as KeyframeEffect | null)?.target === drawer);
            for (const animation of animations) {
                animation.pause();
                animation.currentTime = 50;
            }
            return { name: getComputedStyle(drawer).animationName, transform: getComputedStyle(drawer).transform,
                frames: animations.flatMap(animation => (animation.effect as KeyframeEffect).getKeyframes()) };
        });
        expect(arrival.name, 'Drawer motion for ' + motion + ': ' + JSON.stringify(arrival)).toBe(motion === 'reduce' ? 'signal-settle' : 'signal-arrival');
        expect(arrival.frames.length).toBeGreaterThan(0);
        if (motion === 'reduce') {
            expect(arrival.transform, 'Reduced-motion drawer must not move').toBe('none');
            expect(arrival.frames.every(frame => frame.transform === undefined), 'Reduced-motion keyframes must not translate').toBe(true);
        } else expect(arrival.frames.some(frame => String(frame.transform).includes('24px')), 'Drawer starts at its right-hand edge: ' + JSON.stringify(arrival.frames)).toBe(true);
        await expect(page.locator('#signal-drawer')).toBeVisible();
        if (process.env.ORBIT_SCREENSHOTS) {
            await page.screenshot({ path: process.env.ORBIT_SCREENSHOTS + '/' + test.info().project.name + '-drawer-' + motion + '.png', animations: 'allow' });
        }
        await page.locator('#signal-drawer').evaluate(drawer => {
            (window as Window & { orbitDialogClosed?: Promise<void> }).orbitDialogClosed = new Promise(resolve => drawer.addEventListener('close', () => resolve(), { once: true }));
        });
        await page.locator('#close-drawer').click();
        await page.evaluate(() => (window as Window & { orbitDialogClosed?: Promise<void> }).orbitDialogClosed);
        await expect(page.locator('#signal-drawer')).not.toBeVisible();
        await expect(trigger, 'Close button restores incident focus for ' + motion).toBeFocused();
        await trigger.press('Enter');
        await expect(page.locator('#signal-drawer')).toBeVisible();
        await page.locator('#signal-drawer').evaluate(drawer => {
            (window as Window & { orbitDialogClosed?: Promise<void> }).orbitDialogClosed = new Promise(resolve => drawer.addEventListener('close', () => resolve(), { once: true }));
        });
        await page.keyboard.press('Escape');
        await page.evaluate(() => (window as Window & { orbitDialogClosed?: Promise<void> }).orbitDialogClosed);
        await expect(page.locator('#signal-drawer')).not.toBeVisible();
        await expect(trigger, 'Escape restores incident focus for ' + motion).toBeFocused();
        expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
    }
});


test('Orbit stars stay fixed while independent fresh resource rings pulse, pause and expire', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    await page.clock.install({ time: new Date('2026-10-22T20:00:00Z') });
    await page.clock.pauseAt(new Date('2026-10-22T20:00:01Z'));
    await page.route('**/api/v1/fleet', async route => {
        const response = await route.fetch();
        const body = await response.json();
        const host = body.hosts.find((item: { id: string }) => item.id === restrictedHost);
        host.sample_age_seconds = host.contact_age_seconds = 0;
        host.sampled_at = host.contact_at = host.observed_at;
        host.observation.state = 'healthy';
        const available = (value: number) => ({ status: 'available', value });
        host.metrics.cpu_percent = available(95);
        host.metrics.memory = { used_bytes: available(92), total_bytes: available(100) };
        host.metrics.disks = [{ used_bytes: available(94), total_bytes: available(100), label: 'system' }];
        await route.fulfill({ response, json: body });
    });
    await page.goto('/orbit?host=' + restrictedHost);
    await page.locator('.system-chooser').evaluate((chooser: HTMLDetailsElement) => { chooser.open = true; });
    const sky = page.locator('#sky'), system = page.locator('.planet[data-host="' + beta-restricted + '"]');
    await expect(system.locator('.resource-ring.high')).toHaveCount(3);
    await expect(page.locator('#retry')).toBeEnabled();
    await sky.scrollIntoViewIfNeeded();
    await expect(sky).toHaveClass(/in-view/);
    const revolution = await system.locator('.ring-plane').evaluateAll(planes => planes.map(plane => {
        const animation = plane.getAnimations()[0];
        if (!animation) throw new Error('Expected resource ring revolution');
        animation.pause(); animation.currentTime = 0;
        const first = getComputedStyle(plane).transform;
        animation.currentTime = 2700;
        const next = getComputedStyle(plane).transform;
        animation.play(); return first !== next;
    }));
    expect(revolution).toEqual([true, true, true]);
    await expect(system.locator('.cpu')).toHaveAttribute('data-state', 'high');
    await expect(system).toHaveAccessibleName(/CPU 95% high, RAM 92% high, Storage 94% high/);
    await expect(page.locator('.sky-art,.constellation-lines,.orb-motion')).toHaveCount(0);
    const geometry = await system.evaluate(button => {
        const star = button.querySelector('.system-star'), ring = button.querySelector('.resource-ring.high');
        if (!star || !ring) throw new Error('Expected system and high ring');
        const animation = ring.getAnimations()[0];
        if (!animation) throw new Error('Expected resource pulse');
        const before = { button: button.getBoundingClientRect().toJSON(), star: star.getBoundingClientRect().toJSON() };
        animation.pause(); animation.currentTime = 0;
        const first = getComputedStyle(ring).opacity;
        animation.currentTime = 1200;
        const second = getComputedStyle(ring).opacity;
        const after = { button: button.getBoundingClientRect().toJSON(), star: star.getBoundingClientRect().toJSON() };
        animation.play(); return { before, after, first, second };
    });
    expect(geometry.before).toEqual(geometry.after);
    expect(geometry.first).not.toBe(geometry.second);
    if (process.env.ORBIT_SCREENSHOTS) await page.screenshot({ path: process.env.ORBIT_SCREENSHOTS + '/' + test.info().project.name + '-high-rings.png', fullPage: true });
    await page.getByRole('button', { name: 'Pause motion', exact: true }).click();
    expect(await system.locator('.cpu').evaluate(el => getComputedStyle(el).animationName), 'Resource ring pulse must be disabled').toBe('none');
    await expect(system.locator('.cpu')).toHaveAttribute('data-state', 'high');
    await expect(system.locator('.cpu .ring-plane')).toHaveCSS('animation-play-state', 'paused');
    await page.getByRole('button', { name: 'Resume motion', exact: true }).click();
    await page.emulateMedia({ reducedMotion: 'reduce' });
    expect(await system.locator('.cpu').evaluate(el => getComputedStyle(el).animationName), 'Resource ring pulse must be disabled').toBe('none');
    await expect(system.locator('.cpu .ring-plane')).toHaveCSS('animation-name', 'none');
    // Verify steady reduced rendering from a new document as well as dynamic cancellation.
    await page.reload();
    await expect(system.locator('.resource-ring.high')).toHaveCount(3);
    await expect(system.locator('.cpu')).toHaveCSS('opacity', '1');
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    await page.route('**/api/v1/**', route => route.abort('failed'));
    await page.clock.runFor(31000);
    await expect(system.locator('.resource-ring.high')).toHaveCount(0);
    await expect(system.locator('.resource-ring[data-state=unavailable]')).toHaveCount(3);
    await expect(system).toHaveAccessibleName(/CPU unavailable, RAM unavailable, Storage unavailable/);
    expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
});


test('Orbit zoom keeps the planet and resource rings centered throughout selection', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    await page.goto('/orbit');
    await page.locator('.system-chooser').evaluate((chooser: HTMLDetailsElement) => { chooser.open = true; });
    await expect(page.locator('#host-list option')).toHaveCount(3);
    const system = page.locator('.planet').first();
    await system.scrollIntoViewIfNeeded();
    const frames = await system.evaluate((button: HTMLButtonElement) => {
        button.click();
        const body = button.querySelector('.system-body');
        const animation = body?.getAnimations()[0];
        const graphic = button.querySelector<SVGSVGElement>('.system-star');
        const sphere = button.querySelector('.planet-sphere');
        if (!animation || !graphic || !sphere) throw new Error('Expected shared planetary zoom');
        animation.pause();
        return [0, 325, 650].map(time => {
            animation.currentTime = time;
            const center = graphic.createSVGPoint();
            center.x = center.y = 56;
            const matrix = graphic.getScreenCTM();
            if (!matrix) throw new Error('Expected rendered ring geometry');
            const ring = center.matrixTransform(matrix), planet = sphere.getBoundingClientRect();
            return { time, dx: Math.abs(planet.x + planet.width / 2 - ring.x), dy: Math.abs(planet.y + planet.height / 2 - ring.y) };
        });
    });
    for (const frame of frames) {
        expect(frame.dx, 'Shared horizontal center at ' + frame.time + 'ms').toBeLessThan(.75);
        expect(frame.dy, 'Shared vertical center at ' + frame.time + 'ms').toBeLessThan(.75);
    }
    await expect(page.locator('#sky')).toHaveClass(/close-up/);
    await expect(system.locator('.planet-state,.resource-summary')).toHaveCount(0);
    await page.getByRole('button', { name: 'All systems', exact: true }).click();
    await expect(page.locator('#sky')).not.toHaveClass(/close-up/);
    await expect(system).toBeFocused();
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await system.press('Enter');
    await expect(page.locator('#sky')).toHaveClass(/close-up/);
    expect(await system.locator('.system-body').evaluate(body => body.getAnimations().length)).toBe(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
});


test('Orbit opens on the sky and reveals a truthful selected metric card with instruments below', async ({ page }) => {
    await page.goto('/orbit');
    await expect(page.locator('#host-list option')).toHaveCount(3);
    await expect(page.locator('#node-card')).toBeHidden();
    const initial = await page.evaluate(() => ({
        stage: document.querySelector('.constellation-stage')!.getBoundingClientRect().toJSON(),
        metrics: document.querySelector('#instruments')!.getBoundingClientRect().toJSON(),
        legend: document.querySelector('.sky-legend')!.getBoundingClientRect().toJSON(),
        viewport: innerHeight,
    }));
    expect(initial.metrics.top).toBeGreaterThanOrEqual(initial.viewport);
    expect(initial.stage.height).toBeGreaterThan(initial.viewport * .8);
    expect(initial.legend.bottom).toBeLessThanOrEqual(initial.stage.bottom);
    await page.locator('.planet[data-host="' + beta-restricted + '"]').click();
    const phone = (page.viewportSize()?.width ?? 0) <= 480;
    if (phone) await expect(page.locator('#node-card')).toBeHidden();
    else await expect(page.locator('#node-card')).toBeVisible();
    await expect(page.locator('#node-card-title')).toHaveText('Beta-Restricted');
    const selected = await page.evaluate(() => ({
        card: document.querySelector('#node-card')!.getBoundingClientRect().toJSON(),
        planet: document.querySelector('.is-focused .system-body')!.getBoundingClientRect().toJSON(),
        sky: document.querySelector('#sky')!.getBoundingClientRect().toJSON(),
    }));
    if ((page.viewportSize()?.width ?? 0) > 850) expect(selected.card.left).toBeGreaterThan(selected.planet.right);
    else if (!phone) expect(selected.card.top).toBeGreaterThanOrEqual(selected.sky.bottom);
    await page.locator(phone ? '.metrics-link' : '#node-card a').click();
    await expect(page.locator('#dock-title')).toBeInViewport();
    await page.locator('#return-to-sky').click();
    await expect(page.locator('#node-card')).toBeHidden();
    expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
});


for (const focus of ['planet', 'return']) {
    test(`Orbit preserves keyboard focus when aging hides the focused ${focus}`, async ({ page }) => {
        await page.clock.install({ time: new Date('2026-10-26T12:00:00Z') });
        await page.clock.pauseAt(new Date('2026-10-26T12:00:01Z'));
        await page.route('**/api/v1/fleet', async route => {
            const response = await route.fetch();
            const body = await response.json();
            const host = body.hosts.find((item: { id: string }) => item.id === restrictedHost);
            host.sample_age_seconds = host.contact_age_seconds = 0;
            host.sampled_at = host.contact_at = host.observed_at;
            host.observation.state = 'healthy';
            await route.fulfill({ response, json: body });
        });
        await page.goto('/orbit?host=' + restrictedHost);
        await expect(page.locator('#retry')).toBeEnabled();
        await page.locator('.system-chooser summary').click();
        await page.locator('#state-filter').selectOption('fresh');
        const system = page.locator('.planet[data-host="' + beta-restricted + '"]');
        await system.focus();
        await system.press('Enter');
        await expect(page.locator('#sky')).toHaveClass(/close-up/);
        if (focus === 'return') await page.locator('#return-to-sky').focus();
        await page.route('**/api/v1/**', route => route.abort('failed'));
        await page.clock.runFor(31000);
        await expect(system).toBeHidden();
        await expect(page.locator('#return-to-sky')).toBeHidden();
        await expect(page.locator('.system-chooser summary')).toBeFocused();
    });
}


test('Orbit held nodes stay transparent in overview and close-up', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto('/orbit');
    await expect(page.locator('#host-list option')).toHaveCount(3);
    const system = page.locator('.planet[data-host="' + beta-restricted + '"]');
    for (const closeUp of [false, true]) {
        if (closeUp) await system.click();
        await system.scrollIntoViewIfNeeded();
        const bounds = await system.boundingBox();
        if (!bounds) throw new Error('Expected visible planet');
        await page.mouse.move(bounds.x + bounds.width / 2, bounds.y + bounds.height / 2);
        await page.mouse.down();
        try {
            expect(await system.evaluate(button => button.matches(':active'))).toBe(true);
            await expect(system).toHaveCSS('background-color', 'rgba(0, 0, 0, 0)');
            await expect(system).toHaveCSS('border-top-color', 'rgba(0, 0, 0, 0)');
            await expect(system).toHaveCSS('color', 'rgb(245, 239, 255)');
        } finally {
            await page.mouse.up();
        }
    }
});

test('Orbit touch release keeps the button transparent during planet zoom', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    await page.goto('/orbit');
    await expect(page.locator('#retry')).toBeEnabled();
    const system = page.locator('.planet[data-host="' + beta-restricted + '"]');
    await system.scrollIntoViewIfNeeded();
    const bounds = await system.boundingBox();
    if (!bounds) throw new Error('Expected visible planet');
    const touch = await page.context().newCDPSession(page);
    try {
        await touch.send('Emulation.setTouchEmulationEnabled', { enabled: true });
        await touch.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: bounds.x + bounds.width / 2, y: bounds.y + bounds.height / 2 }] });
        await touch.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
        await expect(page.locator('#sky')).toHaveClass(/close-up/);
        const frames = await system.evaluate(button => {
            const animation = button.querySelector('.system-body')?.getAnimations()[0];
            if (!animation) throw new Error('Expected planetary zoom');
            animation.pause();
            return [0, 80, 325, 650].map(time => {
                animation.currentTime = time;
                return { time, background: getComputedStyle(button).backgroundColor, border: getComputedStyle(button).borderTopColor };
            });
        });
        for (const frame of frames) {
            expect(frame.background, 'Background at ' + frame.time + 'ms').toBe('rgba(0, 0, 0, 0)');
            expect(frame.border, 'Border at ' + frame.time + 'ms').toBe('rgba(0, 0, 0, 0)');
        }
    } finally {
        await touch.detach();
    }
});
