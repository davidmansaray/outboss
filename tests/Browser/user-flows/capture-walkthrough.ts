import { mkdirSync } from 'node:fs';
import { test } from '@playwright/test';
import { campaignTab, signIn } from './helpers';

const outDir = process.env.OUTBOSS_CAPTURE_DIR ?? 'output/playwright';

test('capture operator setup walkthrough screens', async ({ page }) => {
    mkdirSync(outDir, { recursive: true });
    await signIn(page);
    await page.screenshot({
        path: `${outDir}/user_flow_inbox.png`,
        fullPage: true,
    });

    await page.goto('/campaigns');
    await page.screenshot({
        path: `${outDir}/user_flow_campaigns.png`,
        fullPage: true,
    });

    await page.getByRole('link', { name: 'Workflow verification Draft' }).click();
    await page.screenshot({
        path: `${outDir}/user_flow_campaign_purpose.png`,
        fullPage: true,
    });

    await campaignTab(page, 'audience').click();
    await page.screenshot({
        path: `${outDir}/user_flow_campaign_audience.png`,
        fullPage: true,
    });

    await campaignTab(page, 'sequence').click();
    await page.screenshot({
        path: `${outDir}/user_flow_campaign_sequence.png`,
        fullPage: true,
    });

    await campaignTab(page, 'sending').click();
    await page.screenshot({
        path: `${outDir}/user_flow_campaign_sending_collapsed.png`,
        fullPage: true,
    });
    await page.getByText(/Selected inboxes:/).click();
    await page.screenshot({
        path: `${outDir}/user_flow_campaign_sending_expanded.png`,
        fullPage: true,
    });

    await page.goto('/prospects');
    await page.screenshot({
        path: `${outDir}/user_flow_people.png`,
        fullPage: true,
    });

    await page.goto('/accounts');
    await page.screenshot({
        path: `${outDir}/user_flow_accounts.png`,
        fullPage: true,
    });

    await page.goto('/settings/mailboxes');
    await page.screenshot({
        path: `${outDir}/user_flow_mailboxes.png`,
        fullPage: true,
    });
});
