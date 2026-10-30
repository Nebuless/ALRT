import { chromium } from '../browser/node_modules/playwright-core/index.mjs';

const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
try {
    const context = await browser.newContext({ storageState: process.env.BROWSER_STORAGE_STATE });
    const page = await context.newPage();
    await page.goto(process.env.BROWSER_BASE_URL + '/citizens');
    const card = page.locator('.citizen-card').first();
    await card.waitFor({ state: 'visible' });
    await card.getByRole('button').click();
    await page.locator('#request-terminate').click();
    await page.locator('#confirm-terminate').click();
    try {
        await page.getByText(/Action terminated\./, { exact: false }).waitFor({ state: 'visible', timeout: 15000 });
    } catch (error) {
        const response = await page.request.get(process.env.BROWSER_BASE_URL + '/api/v1/citizens');
        const roster = await response.json();
        console.error(JSON.stringify({ actionStatus: await page.locator('#action-status').textContent(), reason: await page.locator('#terminate-reason').textContent(), rows: roster.hosts.flatMap(host => host.citizens.map(row => ({ state: row.state, can_terminate: row.can_terminate, action: row.action }))) }));
        throw error;
    }
    await context.close();
} finally {
    await browser.close();
}
