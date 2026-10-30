import { test as base, expect } from '@playwright/test';
import { statSync } from 'node:fs';

if (process.env.BROWSER_RELEASE_GATE === '1') {
    for (const name of ['BROWSER_STORAGE_STATE', 'BROWSER_REBOOT_FIXTURE']) {
        const path = process.env[name];
        if (!path || !statSync(path).isFile() || (statSync(path).mode & 0o077) !== 0) {
            throw new Error(`Release browser gate requires private ${name}.`);
        }
    }
}

// Provision and authenticate the isolated browser runtime with the normal local
// owner setup. Keep this session file outside the repository.
export const test = base.extend({ storageState: process.env.BROWSER_STORAGE_STATE });
export { expect };

test.beforeEach(async () => {
    test.skip(!process.env.BROWSER_STORAGE_STATE, 'Requires an authenticated isolated fleet runtime and private BROWSER_STORAGE_STATE.');
});
