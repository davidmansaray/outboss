import { expect, test } from '@playwright/test';
import { campaignTab, goToCampaign, signIn } from './helpers';

test.beforeEach(async ({ page }) => {
    await signIn(page);
});

test('campaign list shows sample campaigns and can create a draft', async ({
    page,
}) => {
    await page.goto('/campaigns');
    await expect(
        page.getByRole('heading', { name: 'Campaigns' }),
    ).toBeVisible();
    await expect(
        page.getByRole('link', { name: 'Workflow verification Draft' }),
    ).toBeVisible();
    await expect(page.getByText('Q3 Series A founders').first()).toBeVisible();

    await page.getByRole('button', { name: 'Drafts' }).click();
    await expect(
        page.getByRole('link', { name: 'Workflow verification Draft' }),
    ).toBeVisible();

    await page.getByRole('button', { name: 'All campaigns' }).click();
    await page.getByRole('button', { name: 'New campaign' }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.getByLabel('Campaign name').fill('User flow sample');
    await page.getByRole('button', { name: 'Create campaign' }).click();
    await expect(
        page.getByRole('heading', { name: 'User flow sample' }),
    ).toBeVisible();
    await expect(page.getByRole('main').getByText('Draft')).toBeVisible();
});

test('seeded campaign walks audience, sequence, and sending without sending mail', async ({
    page,
}) => {
    await goToCampaign(page, 'Workflow verification');
    await expect(page.getByText('Launch readiness')).toBeVisible();

    await campaignTab(page, 'audience').click();
    await expect(
        page.getByText('priya@northstar-labs.workflow.demo'),
    ).toBeVisible();
    await expect(
        page.locator('[data-test="add-people"]').first(),
    ).toBeVisible();

    await campaignTab(page, 'sequence').click();
    await expect(
        page.getByRole('heading', { name: 'What each person will receive' }),
    ).toBeVisible();
    await expect(page.locator('[data-test="sequence-subject"]')).toBeVisible();
    await expect(page.locator('[data-test="sequence-body"]')).toBeVisible();
    const sendTest = page.getByRole('button', { name: 'Send test' });
    if ((await sendTest.count()) > 0) {
        await expect(sendTest).toBeDisabled();
    }

    await campaignTab(page, 'sending').click();
    await expect(page.getByText('2 ready inboxes')).toBeVisible();
    await expect(
        page.getByRole('checkbox', { name: /David · Primary/ }),
    ).toBeChecked();
    await expect(
        page.getByRole('checkbox', { name: /Outboss · Founders/ }),
    ).toBeChecked();
});

test('operator can enrol a registry person and write a sequence on a new campaign', async ({
    page,
}) => {
    await page.goto('/campaigns');
    await page.getByRole('button', { name: 'New campaign' }).click();
    await page.getByLabel('Campaign name').fill('Enrolment walk');
    await page.getByRole('button', { name: 'Create campaign' }).click();
    await expect(
        page.getByRole('heading', { name: 'Enrolment walk' }),
    ).toBeVisible();

    await page.getByRole('button', { name: 'New objective' }).click();
    await page.locator('#composition-name').fill('Book a discovery call');
    await page.getByRole('button', { name: 'Create draft and select' }).click();
    await expect(page.getByLabel('Objective', { exact: true })).toContainText(
        'Book a discovery call',
    );

    await page.getByRole('button', { name: 'New offer' }).click();
    await page.locator('#composition-name').fill('30-minute working session');
    await page.getByRole('button', { name: 'Create draft and select' }).click();
    await expect(page.getByLabel('Offer', { exact: true })).toContainText(
        '30-minute working session',
    );

    await campaignTab(page, 'audience').click();
    await page.locator('[data-test="add-people"]').first().click();
    await expect(
        page.getByRole('heading', { name: 'Add people' }),
    ).toBeVisible();
    await page.getByLabel('Search people').fill('Dana Ruiz');
    await expect(
        page.getByText('dana@pinecone-freight.workflow.demo'),
    ).toBeVisible();
    await page.getByLabel('Select Dana Ruiz').check();
    await page.getByRole('button', { name: /Add 1 person/ }).click();
    await expect(page.getByText('Dana Ruiz')).toBeVisible();

    await campaignTab(page, 'sequence').click();
    await page
        .locator('[data-test="sequence-subject"]')
        .fill('A question for {{company}}');
    await page
        .locator('[data-test="sequence-body"]')
        .fill(
            'Hi {{first_name}}, would a short working session help {{company}}?',
        );
    await page.getByRole('button', { name: 'Save email' }).click();
    await expect(
        page
            .getByRole('main')
            .getByText(
                'Email saved. Your latest text is ready for preview and review.',
            ),
    ).toBeVisible();

    await campaignTab(page, 'sending').click();
    await expect(page.getByLabel('Messages per day')).toBeVisible();

    const review = page.getByRole('button', {
        name: /Review launch|Review exact messages|Continue setup/,
    });
    await expect(review.first()).toBeVisible();
    await review.first().click();
    await expect(page).not.toHaveURL(/\/login/);
    await expect(
        page
            .getByText(
                /Launch campaign|exact messages|What counts|Finish campaign setup|Objective/i,
            )
            .first(),
    ).toBeVisible();
});

test('escape closes the create-campaign dialog', async ({ page }) => {
    await page.goto('/campaigns');
    await page.getByRole('button', { name: 'New campaign' }).click();
    await expect(page.getByLabel('Campaign name')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByLabel('Campaign name')).toHaveCount(0);
});
