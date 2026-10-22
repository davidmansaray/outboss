import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: 'tests/Browser/user-flows',
    testIgnore: ['**/capture-walkthrough.ts'],
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    workers: 1,
    timeout: 60_000,
    expect: {
        timeout: 10_000,
    },
    use: {
        baseURL: 'http://127.0.0.1:8124',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    webServer: {
        command: 'bash tests/Browser/user-flows-server.sh',
        url: 'http://127.0.0.1:8124/login',
        reuseExistingServer: !process.env.CI,
        timeout: 180_000,
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
