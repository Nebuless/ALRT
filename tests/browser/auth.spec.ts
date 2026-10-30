import { test, expect } from './live-fixture';

test('alternate access form remains readable and invalid credentials expose associated generic feedback', async ({ browser, baseURL }) => {
    const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] }, viewport: { width: 320, height: 844 } });
    try {
        const page = await context.newPage();
        await page.goto('/two-factor-challenge');
        await expect(page).toHaveURL(new URL('/login', baseURL).href);
        const email = page.getByLabel('Email', { exact: true });
        const password = page.getByLabel('Password', { exact: true });
        await expect(email).toHaveAttribute('autocomplete', 'username');
        await expect(password).toHaveAttribute('autocomplete', 'current-password');
        await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
        for (const field of [email, password, page.getByRole('button', { name: 'Continue', exact: true })]) {
            const size = await field.boundingBox();
            expect(size!.height).toBeGreaterThanOrEqual(44);
            expect(size!.x + size!.width).toBeLessThanOrEqual(321);
        }
        await email.fill('unknown@example.test');
        await password.fill('invalid-password');
        await page.getByRole('button', { name: 'Continue', exact: true }).click();
        await expect(page.getByRole('alert')).toBeVisible();
        await expect(email).toHaveAttribute('aria-invalid', 'true');
        await expect(email).toHaveAttribute('aria-describedby', 'auth-errors');
        await expect(password).toHaveValue('');
        await expect(email).toBeFocused();
    } finally { await context.close(); }
});
