import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

async function signIn(page: Page) {
    await page.goto('/login');
    await page.getByLabel('Email address').fill('david@outboss.test');
    await page.locator('#password').fill('password');
    await page.locator('[data-test="login-button"]').click();
    await expect(page).not.toHaveURL(/\/login/);
}

test('owner can safely edit and inspect the GTM profile across overlays', async ({
    page,
}) => {
    await signIn(page);
    await page.goto('/offers');
    await page.goto('/settings/gtm-profile');

    await expect(
        page.getByRole('heading', { name: 'GTM profile' }),
    ).toBeVisible();
    await expect(page.locator('.gtm-knowledge-row')).toHaveCount(5);
    await expect(
        page.locator('.gtm-knowledge-row').filter({ hasText: 'Offer' }),
    ).toHaveCount(0);
    await expect(
        page.locator('[data-test="gtm-research-state"]'),
    ).toContainText('Add a website when you are ready');

    await page.getByRole('button', { name: 'Edit profile' }).click();
    await expect(page).toHaveURL(/panel=edit&section=company/);
    await expect(page.locator('[data-test="gtm-editor-drawer"]')).toBeVisible();

    const website = page.getByRole('textbox', { name: 'Company Website' });
    await website.fill('http://127.0.0.1');
    await page.getByRole('button', { name: 'Done' }).click();
    await expect(page.locator('[data-test="gtm-save-error"]')).toBeVisible();
    await expect(website).toBeFocused();
    await page.getByRole('button', { name: 'Discard' }).click();

    await page
        .getByRole('textbox', { name: 'Category' })
        .fill('Evidence-led outbound');
    await expect(page.getByText('Saved just now')).toBeVisible();
    await page.getByRole('button', { name: 'Done' }).click();
    await expect(page.locator('[data-test="gtm-editor-drawer"]')).toBeHidden();
    await expect(page).not.toHaveURL(/panel=/);

    // Explicit overlay close consumes the entry it pushed. The very next
    // Back must leave the GTM base page instead of landing on a duplicate URL.
    await page.goBack();
    await expect(page).toHaveURL(/\/offers$/);
    await page.goto('/settings/gtm-profile');

    const sourceRequests: string[] = [];
    page.on('request', (request) => {
        if (request.url().includes('/settings/gtm-profile/sources')) {
            sourceRequests.push(request.url());
        }
    });
    await page.getByRole('button', { name: '0 sources' }).click();
    await expect(
        page.locator('[data-test="gtm-sources-drawer"]'),
    ).toBeVisible();
    await expect.poll(() => sourceRequests.length).toBe(1);
    await page.keyboard.press('Escape');
    await expect(page.locator('[data-test="gtm-sources-drawer"]')).toBeHidden();

    await page.getByRole('button', { name: 'More actions' }).click();
    await page.getByRole('menuitem', { name: 'View history' }).click();
    await expect(
        page.locator('[data-test="gtm-history-drawer"]'),
    ).toContainText('Version 2');
    await page.keyboard.press('Escape');

    await page.locator('.gtm-knowledge-row[data-section="buyers"]').click();
    await expect(page).toHaveURL(/panel=edit&section=buyers/);
    await page.goBack();
    await expect(page.locator('[data-test="gtm-editor-drawer"]')).toBeHidden();

    await page.emulateMedia({ reducedMotion: 'reduce' });
    await expect
        .poll(() =>
            page
                .locator('.gtm-row-arrow')
                .first()
                .evaluate((element) =>
                    Number.parseFloat(
                        getComputedStyle(element).transitionDuration,
                    ),
                ),
        )
        .toBeLessThanOrEqual(0.001);

    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.locator('.ops-side-nav section')).toHaveCount(4);
    await page.locator('.gtm-knowledge-row[data-section="company"]').click();
    const drawerBox = await page
        .locator('[data-test="gtm-editor-drawer"]')
        .boundingBox();
    expect(drawerBox?.width).toBeLessThanOrEqual(390);
});
