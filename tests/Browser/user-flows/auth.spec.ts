import { expect, test } from '@playwright/test';
import { DEMO_EMAIL, DEMO_PASSWORD, signIn } from './helpers';

test('failed login keeps the operator on the sign-in form', async ({
    page,
}) => {
    await page.goto('/login');
    await expect(
        page.getByRole('heading', { name: 'Welcome back' }),
    ).toBeVisible();
    await page.getByLabel('Email address').fill(DEMO_EMAIL);
    await page.locator('#password').fill('wrong-password');
    await page.locator('[data-test="login-button"]').click();
    await expect(page).toHaveURL(/\/login/);
    await expect(
        page.getByText('These credentials do not match our records.'),
    ).toBeVisible();
});

test('demo operator can sign in and reach the inbox', async ({ page }) => {
    await signIn(page);
    await expect(page).toHaveURL(/\/unibox/);
    await expect(
        page.getByRole('navigation', { name: 'Lead views' }),
    ).toBeVisible();
    await expect(
        page.getByRole('navigation', { name: 'Primary navigation' }),
    ).toBeVisible();
});

test('new registration creates an empty personal workspace', async ({
    page,
}) => {
    const stamp = Date.now();
    await page.goto('/register');
    await page.getByLabel('Name').fill('User Flow Operator');
    await page.getByLabel('Email address').fill(`flow-${stamp}@outboss.test`);
    await page.locator('#password').fill(DEMO_PASSWORD);
    await page.locator('#password_confirmation').fill(DEMO_PASSWORD);
    await page.locator('[data-test="register-user-button"]').click();
    await expect(page).not.toHaveURL(/\/(login|register)/);

    await page.goto('/unibox');
    await expect(page.getByText('Connect a mailbox')).toBeVisible();

    await page.goto('/campaigns');
    await expect(
        page.getByRole('heading', {
            name: 'Turn an audience into conversations',
        }),
    ).toBeVisible();

    await page.goto('/prospects');
    await expect(page.getByText('No people found')).toBeVisible();

    await page.goto('/settings/mailboxes');
    await expect(page.getByText('No inboxes connected yet.')).toBeVisible();
    await expect(
        page.getByRole('link', { name: 'Connect Gmail' }),
    ).toBeVisible();
});
