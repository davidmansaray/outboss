import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * This journey is run against the opt-in local fake harness. Start the
 * harness with APP_ENV=testing, QUEUE_CONNECTION=sync, and the configured
 * managed-mail instance values. Verified workspace ownership is the access
 * boundary; MANAGED_MAIL_BROWSER_E2E=1 is the explicit test-harness opt-in.
 * `tests/Browser/managed-mail-fake-router.php` keeps the fake remote
 * principals available across the separate PHP requests made by Inertia.
 */

const subdomain = 'browser-outreach.example.com';
const rootDomains = [
    'browser-root-a.com',
    'browser-root-b.com',
    'browser-root-c.com',
];
const allDomains = [subdomain, ...rootDomains];

test.skip(
    process.env.MANAGED_MAIL_BROWSER_E2E !== '1',
    'Requires the opt-in managed-mail fake browser harness.',
);

async function signIn(page: Page): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email address').fill('david@outboss.test');
    await page.locator('#password').fill('password');
    await page.locator('[data-test="login-button"]').click();
    await expect(page).not.toHaveURL(/\/login/);
}

async function postJson(
    page: Page,
    url: string,
    body: Record<string, unknown>,
    allowedStatuses: number[] = [],
): Promise<number> {
    return page.evaluate(
        async ({ requestUrl, requestBody, allowedStatuses }) => {
            const cookie = document.cookie
                .split('; ')
                .find((value) => value.startsWith('XSRF-TOKEN='));
            const token = cookie
                ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length))
                : '';
            const response = await fetch(requestUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': token,
                },
                body: JSON.stringify(requestBody),
            });

            if (!response.ok && !allowedStatuses.includes(response.status)) {
                throw new Error(
                    `POST ${requestUrl} returned ${response.status}: ${await response.text()}`,
                );
            }

            return response.status;
        },
        { requestUrl: url, requestBody: body, allowedStatuses },
    );
}

function domainCard(page: Page, domain: string) {
    return page.locator('.managed-domain-card').filter({
        has: page.getByRole('heading', { name: domain, exact: true }),
    });
}

async function addAndVerifyDomain(
    page: Page,
    domain: string,
): Promise<ReturnType<typeof domainCard>> {
    await page.getByRole('textbox', { name: 'Domain' }).fill(domain);
    await page.getByRole('button', { name: 'Add domain', exact: true }).click();

    const card = domainCard(page, domain);
    await expect(card).toBeVisible();
    await expect(card.locator('.status')).toContainText(/pending dns|active/);

    // A retry against an already-provisioned fixture domain is idempotent. A
    // clean run takes the pending_dns branch and explicitly verifies it.
    if (
        (await card.locator('.status').innerText()).toLowerCase() !== 'active'
    ) {
        await card
            .getByRole('button', { name: 'Verify DNS', exact: true })
            .click();
    }

    await expect(card.locator('.status')).toHaveText('active');
    await expect(card.locator('.check-pass')).toHaveCount(7);

    return card;
}

async function createMailbox(
    card: ReturnType<typeof domainCard>,
    localPart: string,
    domain: string,
): Promise<string> {
    const email = `${localPart}@${domain}`;
    const form = card.locator('form.mailbox-form');
    await form.getByRole('textbox', { name: 'Email address' }).fill(localPart);
    await form
        .getByRole('textbox', { name: 'Sender name' })
        .fill(`${localPart} browser`);
    await form
        .getByRole('button', { name: 'Create mailbox', exact: true })
        .click();

    const row = card.locator('.managed-mailbox-row').filter({
        hasText: email,
    });
    await expect(row).toBeVisible();
    await expect(row).toContainText(/active/);

    return email;
}

async function selectMailbox(
    cohort: ReturnType<Page['locator']>,
    email: string,
): Promise<void> {
    const option = cohort.locator('label.mailbox-option').filter({
        hasText: email,
    });
    await expect(option).toBeVisible();
    await option.locator('input[type="checkbox"]').check();
}

test('owner can run the managed-mail domain and experiment evidence journey', async ({
    page,
}) => {
    test.setTimeout(120_000);

    await signIn(page);

    // Domain creation is deliberately visible as pending before the operator
    // runs the separate DNS verification action.
    await page.goto('/settings/managed-mail');
    await expect(
        page.getByRole('heading', { name: 'Managed mail', exact: true }),
    ).toBeVisible();
    await expect(page.locator('body')).not.toContainText('admin_api_key');

    const subdomainCard = await addAndVerifyDomain(page, subdomain);
    const rootCards: ReturnType<typeof domainCard>[] = [];
    for (const domain of rootDomains) {
        rootCards.push(await addAndVerifyDomain(page, domain));
    }

    const subdomainMailboxes: string[] = [];
    for (const localPart of ['sales', 'growth', 'ops']) {
        subdomainMailboxes.push(
            await createMailbox(subdomainCard, localPart, subdomain),
        );
    }
    const rootMailboxes: string[] = [];
    for (let index = 0; index < rootCards.length; index++) {
        rootMailboxes.push(
            await createMailbox(rootCards[index], 'sales', rootDomains[index]),
        );
    }

    await expect(page.locator('.managed-mailbox-row')).toHaveCount(6);

    // Give preparation a deterministic, workspace-scoped campaign and four
    // safe local prospects. These setup calls use the same authenticated
    // session and never contact a provider.
    await postJson(page, '/campaigns', {
        name: 'Managed mail browser template',
    });
    for (const [index, email] of [
        'one@browser-candidates.example.com',
        'two@browser-candidates.example.com',
        'three@browser-candidates.example.com',
        'four@browser-candidates.example.com',
    ].entries()) {
        await postJson(
            page,
            '/people',
            {
                email,
                full_name: `Browser candidate ${index + 1}`,
                job_title: 'Operator test candidate',
            },
            [422],
        );
    }

    await page.goto('/settings/mail-experiments');
    await page
        .getByRole('textbox', { name: 'Name' })
        .fill('Managed mail browser contract');
    await page
        .getByRole('textbox', { name: 'Hypothesis' })
        .fill('The fake managed-mail owner journey preserves review evidence.');
    await page
        .getByRole('button', { name: 'Create draft', exact: true })
        .click();
    await expect(page).toHaveURL(/\/settings\/mail-experiments\/\d+$/);
    await expect(
        page.getByRole('heading', {
            name: 'Managed mail browser contract',
            exact: true,
        }),
    ).toBeVisible();

    const cohorts = page.locator('.cohort-card');
    await expect(cohorts).toHaveCount(2);
    const subdomainCohort = cohorts.filter({
        has: page.getByRole('heading', {
            name: 'Branded subdomain',
            exact: true,
        }),
    });
    const rootCohort = cohorts.filter({
        has: page.getByRole('heading', {
            name: 'Separate root domains',
            exact: true,
        }),
    });
    await expect(subdomainCohort).toBeVisible();
    await expect(rootCohort).toBeVisible();

    for (const email of subdomainMailboxes) {
        await selectMailbox(subdomainCohort, email);
    }
    await subdomainCohort
        .getByRole('button', { name: 'Save cohort mailboxes', exact: true })
        .click();
    await expect(subdomainCohort.locator('.status')).toContainText(
        '3 mailboxes',
    );

    for (const email of rootMailboxes) {
        await selectMailbox(rootCohort, email);
    }
    await rootCohort
        .getByRole('button', { name: 'Save cohort mailboxes', exact: true })
        .click();
    await expect(rootCohort.locator('.status')).toContainText('3 mailboxes');

    const prepareForm = page.locator('form.prepare-form');
    const template = prepareForm.locator('select');
    expect(await template.locator('option').count()).toBeGreaterThan(1);
    const templateOption = template
        .locator('option')
        .filter({ hasText: 'Managed mail browser template' })
        .last();
    await expect(templateOption).toHaveCount(1);
    const templateId = await templateOption.getAttribute('value');
    expect(templateId).toBeTruthy();
    await template.selectOption(templateId!);
    const approvals = prepareForm.locator('input[type="checkbox"]');
    await expect(approvals).toHaveCount(2);
    await expect(
        prepareForm.getByRole('button', { name: 'Prepare', exact: true }),
    ).toBeDisabled();
    await approvals.nth(0).check();
    await approvals.nth(1).check();
    await expect(
        prepareForm.getByRole('button', { name: 'Prepare', exact: true }),
    ).toBeEnabled();
    await prepareForm
        .getByRole('button', { name: 'Prepare', exact: true })
        .click();
    await expect(page.locator('[data-test="approval-record"]')).toContainText(
        'Compliance and legal review confirmed via http',
    );
    await expect(page.locator('.eyebrow')).toContainText('ready');

    await page
        .getByRole('button', { name: 'Review balance', exact: true })
        .click();
    await expect(page.locator('[data-test="flash-success"]')).toContainText(
        'Balance preview refreshed',
    );

    await page.getByRole('button', { name: 'Freeze', exact: true }).click();
    await expect(page.locator('.eyebrow')).toContainText('ready');
    await expect(
        page.getByRole('button', { name: 'Freeze', exact: true }),
    ).toHaveCount(0);
    await expect(
        prepareForm.getByRole('button', { name: 'Prepare', exact: true }),
    ).toBeDisabled();
    await expect(prepareForm.locator('input[type="checkbox"]')).toHaveCount(2);
    await expect(
        prepareForm.locator('input[type="checkbox"]').nth(0),
    ).toBeDisabled();
    await expect(
        prepareForm.locator('input[type="checkbox"]').nth(1),
    ).toBeDisabled();

    // Keep the report links in the rendered frozen surface and verify both
    // evidence representations through the same authenticated browser
    // context. No report value claims delivery; the JSON is provider-
    // submission evidence and the CSV is the reviewer export.
    const jsonLink = page.getByRole('link', {
        name: 'JSON report',
        exact: true,
    });
    const csvLink = page.getByRole('link', {
        name: 'CSV evidence',
        exact: true,
    });
    await expect(jsonLink).toBeVisible();
    await expect(csvLink).toBeVisible();

    const jsonHref = await jsonLink.getAttribute('href');
    expect(jsonHref).toBeTruthy();
    const jsonResponse = await page.request.get(
        new URL(jsonHref!, page.url()).toString(),
    );
    expect(jsonResponse.status()).toBe(200);
    const json = (await jsonResponse.json()) as {
        experiment?: { status?: string; frozen_at?: string | null };
        summary?: { assignment_count?: number };
        evidence?: { email_normalized?: string }[];
    };
    expect(json.experiment?.status).toBe('ready');
    expect(json.experiment?.frozen_at).toBeTruthy();
    expect(json.summary?.assignment_count).toBeGreaterThanOrEqual(4);
    expect(json.evidence).toHaveLength(json.summary?.assignment_count ?? 0);
    expect(
        json.evidence?.some(
            (row) =>
                row.email_normalized === 'one@browser-candidates.example.com',
        ),
    ).toBe(true);

    const csvHref = await csvLink.getAttribute('href');
    expect(csvHref).toBeTruthy();
    const csvResponse = await page.request.get(
        new URL(csvHref!, page.url()).toString(),
    );
    expect(csvResponse.status()).toBe(200);
    expect(csvResponse.headers()['content-type']).toContain('text/csv');
    const csv = await csvResponse.text();
    expect(csv).toContain('assignment_id');
    expect(csv).toContain('one@browser-candidates.example.com');

    // Guard against an accidental unused fixture constant when this test is
    // edited: all four domains must have appeared in the managed surface.
    await page.goto('/settings/managed-mail');
    for (const domain of allDomains) {
        await expect(domainCard(page, domain)).toBeVisible();
    }
});
