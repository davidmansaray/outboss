import { expect, test } from '@playwright/test';
import { signIn } from './helpers';

test.beforeEach(async ({ page }) => {
    await signIn(page);
});

test('unified inbox shows sample threads and opens a conversation', async ({
    page,
}) => {
    await page.goto('/unibox?folder=all_open');
    await expect(
        page.getByRole('navigation', { name: 'Lead views' }),
    ).toBeVisible();
    await expect(page.locator('button.thread').first()).toBeVisible();

    const stripe = page.getByText('Quick question about Stripe hiring');
    if (await stripe.count()) {
        await stripe.first().click();
        await expect(
            page.getByText(/Stripe|Maya|hiring/i).first(),
        ).toBeVisible();
    } else {
        await page.locator('button.thread').first().click();
        await expect(
            page.locator('.thread-scroll, .continuous-thread'),
        ).toBeVisible();
    }
});

test('suppressions list seeded addresses and keep remove behind confirm', async ({
    page,
}) => {
    await page.goto('/settings/suppressions');
    await expect(
        page.getByRole('heading', { name: 'Suppressions' }),
    ).toBeVisible();
    await expect(
        page.getByText('lea@lumen-health.workflow.demo'),
    ).toBeVisible();
    await expect(
        page.getByText('spam@atlas-robotics.workflow.demo'),
    ).toBeVisible();

    await page.getByRole('button', { name: 'Remove' }).first().click();
    await expect(
        page.locator('[data-test="confirm-remove-suppression"]'),
    ).toBeVisible();
    await page.locator('[data-test="cancel-remove-suppression"]').click();
    await expect(
        page.getByText('lea@lumen-health.workflow.demo'),
    ).toBeVisible();
});

test('exports offer the workspace CSV files without sending mail', async ({
    page,
}) => {
    await page.goto('/settings/exports');
    await expect(page.getByRole('heading', { name: 'Exports' })).toBeVisible();
    await expect(page.locator('[data-test="export-accounts"]')).toBeVisible();
    await expect(page.locator('[data-test="export-people"]')).toBeVisible();
    await expect(
        page.locator('[data-test="export-enrollments"]'),
    ).toBeVisible();
    await expect(
        page.locator('[data-test="export-suppressions"]'),
    ).toBeVisible();
    await expect(
        page.locator('[data-test="export-campaign-outcomes"]'),
    ).toBeVisible();

    const downloadPromise = page.waitForEvent('download');
    await page.locator('[data-test="export-accounts"]').click();
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toMatch(/accounts\.csv$/);
});

test('primary navigation reaches every operator surface used in setup', async ({
    page,
}) => {
    await page.goto('/unibox');
    const nav = page.getByRole('navigation', { name: 'Primary navigation' });
    await nav.getByRole('link', { name: 'Outreach' }).click();
    await expect(page).toHaveURL(/\/campaigns/);
    await nav.getByRole('link', { name: 'People' }).click();
    await expect(page).toHaveURL(/\/prospects/);
    await nav.getByRole('link', { name: 'Accounts' }).click();
    await expect(page).toHaveURL(/\/accounts/);
    await nav.getByRole('link', { name: 'Inbox' }).click();
    await expect(page).toHaveURL(/\/unibox/);

    await page.goto('/settings/mailboxes');
    await expect(
        page.getByRole('heading', { name: 'Mailboxes' }),
    ).toBeVisible();
    await page.getByRole('link', { name: 'Suppressions' }).click();
    await expect(page).toHaveURL(/\/settings\/suppressions/);
    await page.getByRole('link', { name: 'Exports' }).click();
    await expect(page).toHaveURL(/\/settings\/exports/);
});
