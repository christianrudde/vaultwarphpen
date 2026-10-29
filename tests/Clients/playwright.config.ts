import { defineConfig } from '@playwright/test';

// .github/scripts/clients.sh starts the servers and sets VW_CLIENTS_URL and VW_CLIENTS_SSO_URL.
if (!process.env.VW_CLIENTS_URL || !process.env.VW_CLIENTS_SSO_URL) {
    throw new Error('VW_CLIENTS_URL or VW_CLIENTS_SSO_URL is not set; run the tests with .github/scripts/clients.sh');
}

export default defineConfig({
    testDir: '.',
    testMatch: '*.spec.ts',
    forbidOnly: !!process.env.CI,
    // The tests of a file build on each other, in its accounts.
    workers: 1,
    retries: 0,
    timeout: 5 * 60 * 1000,
    expect: { timeout: 20_000 },
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL: process.env.VW_CLIENTS_URL,
        browserName: 'chromium',
        locale: 'en-US',
        timezoneId: 'UTC',
        // The certificate's authority is one of clients.sh's own.
        ignoreHTTPSErrors: true,
        actionTimeout: 20_000,
        navigationTimeout: 30_000,
    },
});
