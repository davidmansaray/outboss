import { expect, type Page } from '@playwright/test';

export const DEMO_EMAIL = 'david@outboss.test';
export const DEMO_PASSWORD = 'password';

export async function signIn(
    page: Page,
    email = DEMO_EMAIL,
    password = DEMO_PASSWORD,
): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.locator('#password').fill(password);
    await page.locator('[data-test="login-button"]').click();
    await expect(page).not.toHaveURL(/\/login/);
}

export async function openWorkspaceSettings(page: Page): Promise<void> {
    await page.goto('/settings/mailboxes');
    await expect(
        page.getByRole('heading', { name: 'Mailboxes' }),
    ).toBeVisible();
}

export async function goToCampaign(page: Page, name: string): Promise<void> {
    await page.goto('/campaigns');
    await page
        .getByRole('link', { name: new RegExp(name) })
        .first()
        .click();
    await expect(page.getByRole('heading', { name })).toBeVisible();
}

export function campaignTab(
    page: Page,
    name: 'overview' | 'audience' | 'sequence' | 'sending',
) {
    return page.locator(`[data-test="campaign-tab-${name}"]`);
}
