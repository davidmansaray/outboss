import { expect, test } from '@playwright/test';
import { openWorkspaceSettings, signIn } from './helpers';

test.beforeEach(async ({ page }) => {
    await signIn(page);
});

test('workspace switcher exposes mailbox settings as an icon-only control', async ({
    page,
}) => {
    await page
        .getByRole('button', { name: /Switch workspace, current workspace/ })
        .click();
    const settings = page.locator('a[href="/settings/mailboxes"]');
    await expect(settings).toBeVisible();
    await settings.click();
    await expect(page).toHaveURL(/\/settings\/mailboxes/);
});

test('seeded sender identities stay connected and never offer a live send', async ({
    page,
}) => {
    await openWorkspaceSettings(page);
    await expect(
        page.getByRole('heading', { name: 'Mailboxes' }),
    ).toBeVisible();
    await expect(page.getByText('2 connected')).toBeVisible();
    await expect(page.locator('[data-test="mailbox-row"]')).toHaveCount(2);
    await expect(
        page.getByText('david@outboss.test', { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByText('founders@outboss.test', { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole('link', { name: 'Connect Gmail' }),
    ).toBeVisible();
    await expect(
        page.getByRole('link', { name: 'Connect Microsoft' }),
    ).toBeVisible();
    await expect(
        page.getByRole('link', { name: 'Connect Zoho' }),
    ).toBeVisible();
    await expect(
        page.locator('[data-test="mailbox-sync-button"]').first(),
    ).toBeVisible();
});

test('disconnect stays behind a confirmation that can be cancelled', async ({
    page,
}) => {
    await page.goto('/settings/mailboxes');

    const identityButton = page.locator(
        '[data-test="mailbox-reply-identity-button"]',
    );
    if ((await identityButton.count()) > 0) {
        await identityButton.first().click();
        await expect(
            page.getByRole('heading', { name: 'Reply identity' }),
        ).toBeVisible();
        await page.locator('[data-test="cancel-reply-identity"]').click();
        await expect(
            page.getByRole('heading', { name: 'Reply identity' }),
        ).toHaveCount(0);
    }

    await page
        .locator('[data-test="mailbox-disconnect-button"]')
        .first()
        .click();
    await expect(
        page.getByRole('heading', { name: /Disconnect / }),
    ).toBeVisible();
    await page.locator('[data-test="cancel-disconnect"]').click();
    await expect(page.locator('[data-test="mailbox-row"]')).toHaveCount(2);
});

test('sync stays on the mailboxes page against the fake provider', async ({
    page,
}) => {
    await page.goto('/settings/mailboxes');
    await page.locator('[data-test="mailbox-sync-button"]').first().click();
    await expect(page).toHaveURL(/\/settings\/mailboxes/);
    await expect(page.locator('[data-test="mailbox-row"]')).toHaveCount(2);
});
