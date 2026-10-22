import { expect, test } from '@playwright/test';
import { signIn } from './helpers';

test.beforeEach(async ({ page }) => {
    await signIn(page);
});

test('people registry lists sample contacts and can add a person', async ({
    page,
}) => {
    await page.goto('/prospects');
    await expect(page.getByRole('heading', { name: 'People' })).toBeVisible();
    await expect(
        page.getByText('priya@northstar-labs.workflow.demo'),
    ).toBeVisible();

    await page.getByPlaceholder('Search people…').fill('Owen Blake');
    await expect(page.getByText('Owen Blake').first()).toBeVisible();
    await expect(
        page.getByText('priya@northstar-labs.workflow.demo'),
    ).toHaveCount(0);

    await page.getByPlaceholder('Search people…').fill('');
    await page.locator('[data-test="add-person"]').click();
    await expect(
        page.getByRole('heading', { name: 'Add someone to the registry' }),
    ).toBeVisible();
    await page.getByLabel('Email').fill('river@user-flow-labs.demo');
    await page.getByLabel('Name').fill('River Quinn');
    await page.getByLabel('Job title').fill('Founder');
    await page.locator('[data-test="submit-person"]').click();
    await expect(page.getByText('River Quinn')).toBeVisible();
});

test('accounts registry can add a company and keep it independent of campaigns', async ({
    page,
}) => {
    await page.goto('/accounts');
    await expect(page.getByRole('heading', { name: 'Accounts' })).toBeVisible();
    await expect(page.getByText('northstar-labs.workflow.demo')).toBeVisible();

    await page.locator('[data-test="add-account"]').click();
    await expect(
        page.getByRole('heading', { name: 'Add a company' }),
    ).toBeVisible();
    await page.getByLabel('Name').fill('User Flow Labs');
    await page.getByLabel('Website').fill('https://user-flow-labs.demo');
    await page.locator('[data-test="submit-account"]').click();
    await expect(page.getByText('User Flow Labs')).toBeVisible();
});

test('CSV import previews new, invalid, and suppressed rows before commit', async ({
    page,
}) => {
    await page.goto('/prospects');
    await page.locator('[data-test="import-people"]').click();
    await expect(
        page.getByRole('heading', { name: 'Add people from a CSV' }),
    ).toBeVisible();

    await page
        .getByLabel('Paste CSV')
        .fill(
            [
                'email,name,company',
                'maya-flow@user-flow.demo,Maya Flow,Flow Labs',
                'priya@northstar-labs.workflow.demo,Priya Shah,Northstar Labs',
                'not-an-email,Bad Row,Nope',
                'lea@lumen-health.workflow.demo,Lea Okonkwo,Lumen Health',
            ].join('\n'),
        );
    await page.locator('[data-test="continue-person-import"]').click();
    await expect(
        page.getByRole('heading', { name: 'Match your columns' }),
    ).toBeVisible();
    await expect(page.locator('#person-import-map-email')).toHaveValue('email');
    await page.locator('[data-test="preview-person-import"]').click();
    await expect(
        page.getByRole('heading', { name: 'Review import' }),
    ).toBeVisible();
    await expect(
        page.locator('[data-test="person-import-bucket-new"]'),
    ).toContainText('1');
    await expect(
        page.locator('[data-test="person-import-bucket-invalid"]'),
    ).toContainText('1');
    await expect(
        page.locator('[data-test="person-import-bucket-suppressed"]'),
    ).toContainText('1');

    await page.locator('[data-test="commit-person-import"]').click();
    await expect(page.getByText('Maya Flow')).toBeVisible();
});

test('escape closes the add-person dialog and returns focus to the page', async ({
    page,
}) => {
    await page.goto('/prospects');
    await page.locator('[data-test="add-person"]').click();
    await expect(
        page.getByRole('dialog', { name: /Add someone/ }),
    ).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog', { name: /Add someone/ })).toHaveCount(
        0,
    );
});
