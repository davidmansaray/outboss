import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: 'tests/Browser',
    testMatch: 'odg-s2-single-candidate-intelligence.spec.ts',
    fullyParallel: false,
    forbidOnly: true,
    retries: 0,
    workers: 1,
    use: {
        baseURL: 'http://127.0.0.1:8012',
        trace: 'retain-on-failure',
    },
    webServer: {
        command: 'bash tests/Browser/s2-server.sh',
        url: 'http://127.0.0.1:8012/login',
        reuseExistingServer: false,
        timeout: 120_000,
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
