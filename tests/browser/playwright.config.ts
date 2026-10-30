import { defineConfig, devices } from '@playwright/test';

// Playwright 1.56 otherwise applies offline emulation only to the page, leaving
// the service worker online. Exercise offline navigation at both network layers.
process.env.PW_EXPERIMENTAL_SERVICE_WORKER_NETWORK_EVENTS ??= '1';

export default defineConfig({
    testDir: '.',
    testMatch: process.env.BROWSER_TAILSCALE_GATE === '1' ? 'tailscale.spec.ts' : '**/*.spec.ts',
    testIgnore: process.env.BROWSER_TAILSCALE_GATE === '1' ? [] : ['**/tailscale.spec.ts'],
    outputDir: process.env.BROWSER_OUTPUT_DIR ?? 'test-results',
    fullyParallel: true,
    use: {
        baseURL: process.env.BROWSER_BASE_URL ?? 'http://127.0.0.1:8000',
        // Only the emulated-Serve fixture has a private, self-signed loopback certificate.
        ignoreHTTPSErrors: process.env.BROWSER_TAILSCALE_GATE === '1',
        trace: process.env.BROWSER_RELEASE_GATE === '1' ? 'off' : 'retain-on-failure',
        screenshot: process.env.BROWSER_RELEASE_GATE === '1' ? 'off' : 'only-on-failure',
    },
    projects: [
        { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 1000 } } },
        { name: 'phone', use: { ...devices['iPhone SE'], defaultBrowserType: 'chromium', viewport: { width: 320, height: 740 } } },
    ],
});
