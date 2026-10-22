import { execFile } from 'node:child_process';
import path from 'node:path';
import { promisify } from 'node:util';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

const execFileAsync = promisify(execFile);

type JsonMethod = 'GET' | 'POST' | 'PATCH' | 'DELETE';

type ApiResult<T> = {
    status: number;
    body: T;
};

type Resource<T> = { data: T };

type Proof = {
    workspace_id: number;
    owner_user_id: number;
    account_research_runs: number;
    provider_operations: number;
    conversion_events: number;
    fulfilment_requests: number;
    capacity_reservations: number;
    campaigns: number;
    campaign_recipients: number;
    messages: number;
    send_attempts: number;
    queued_jobs: number;
};

type Offer = {
    id: string;
    status: string;
    lock_version: number;
    current_version_id: string;
    current_version: { id: string; version: number };
    readiness?: {
        state: string;
        blockers: { code: string; message: string }[];
    };
};

type ConversionDefinition = {
    id: string;
    lock_version: number;
    status: string;
    current_version_id: string;
};

type ConversionConnection = {
    id: string;
    public_id: string;
    webhook_url: string;
};

type ResearchRun = {
    id: string;
    state: string;
    workspace_id: number;
    parent_run_id: string | null;
};

async function signIn(page: Page): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email address').fill('s2-browser@outboss.test');
    await page.locator('#password').fill('password');
    await page.locator('[data-test="login-button"]').click();
    await expect(page).not.toHaveURL(/\/login/);
}

async function jsonRequest<T>(
    page: Page,
    url: string,
    method: JsonMethod = 'GET',
    body?: Record<string, unknown>,
    idempotencyKey?: string,
): Promise<ApiResult<T>> {
    return page.evaluate(
        async ({ requestUrl, requestMethod, requestBody, operationKey }) => {
            const cookie = document.cookie
                .split('; ')
                .find((value) => value.startsWith('XSRF-TOKEN='));
            const token = cookie
                ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length))
                : '';
            const headers: Record<string, string> = {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': token,
            };
            if (operationKey) {
                headers['Idempotency-Key'] = operationKey;
            }

            const response = await fetch(requestUrl, {
                method: requestMethod,
                credentials: 'same-origin',
                headers,
                body:
                    requestMethod === 'GET' || requestBody === undefined
                        ? undefined
                        : JSON.stringify(requestBody),
            });
            const text = await response.text();
            const payload = (text === '' ? null : JSON.parse(text)) as T;

            if (!response.ok) {
                throw new Error(
                    `${requestMethod} ${requestUrl} returned ${response.status}: ${text}`,
                );
            }

            return { status: response.status, body: payload };
        },
        {
            requestUrl: url,
            requestMethod: method,
            requestBody: body,
            operationKey: idempotencyKey,
        },
    );
}

async function signedWebhookRequest<T>(
    page: Page,
    url: string,
    body: Record<string, unknown>,
    secret: string,
): Promise<ApiResult<T>> {
    return page.evaluate(
        async ({ requestUrl, requestBody, signingSecret }) => {
            const rawBody = JSON.stringify(requestBody);
            const timestamp = Math.floor(Date.now() / 1000);
            const encoder = new TextEncoder();
            const key = await crypto.subtle.importKey(
                'raw',
                encoder.encode(signingSecret),
                { name: 'HMAC', hash: 'SHA-256' },
                false,
                ['sign'],
            );
            const digest = await crypto.subtle.sign(
                'HMAC',
                key,
                encoder.encode(`${timestamp}.${rawBody}`),
            );
            const signature = Array.from(new Uint8Array(digest))
                .map((byte) => byte.toString(16).padStart(2, '0'))
                .join('');
            const response = await fetch(requestUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Webhook-Signature': `t=${timestamp},v1=${signature}`,
                },
                body: rawBody,
            });
            const text = await response.text();
            const payload = (text === '' ? null : JSON.parse(text)) as T;

            if (!response.ok) {
                throw new Error(
                    `POST ${requestUrl} returned ${response.status}: ${text}`,
                );
            }

            return { status: response.status, body: payload };
        },
        { requestUrl: url, requestBody: body, signingSecret: secret },
    );
}

async function createSource(page: Page, name: string): Promise<string> {
    await page.goto('/prospects?view=sources');
    await page.locator('[data-test="add-source"]').click();
    await page.getByLabel('Source name').fill(name);
    await page.getByLabel('Source kind').selectOption('csv_import');
    await page.getByRole('button', { name: 'Create source' }).click();
    await expect(page.getByText(name, { exact: true })).toBeVisible();
    await page
        .getByRole('button', { name: `Open details for ${name}` })
        .click();
    const exportHref = await page
        .getByRole('link', { name: 'Export allowed data' })
        .getAttribute('href');
    const match = exportHref?.match(/\/prospect-sources\/([^/]+)\/export$/);

    expect(match?.[1]).toBeTruthy();

    return match![1];
}

async function importAccount(
    page: Page,
    sourceId: string,
    suffix: string,
): Promise<number> {
    const run = await jsonRequest<
        Resource<{ id: string; accepted: number; created: number }>
    >(page, `/prospect-sources/${sourceId}/runs`, 'POST', {
        idempotency_key: `s2-source-${suffix}`,
        input_reference: { transport: 's2_browser_e2e' },
        input: [
            {
                entity_type: 'account',
                row_number: 1,
                input_key: `s2-account-${suffix}`,
                observed_values: {
                    name: 'S2 Browser Account',
                    website: 'https://example.com',
                    tags: ['supplied', 's2-browser'],
                },
            },
        ],
    });
    expect(run.body.data.accepted).toBe(1);
    expect(run.body.data.created).toBe(1);

    const detail = await jsonRequest<
        Resource<{
            observations: { data: { account_id: number | null }[] };
        }>
    >(page, `/prospect-sources/${sourceId}/runs/${run.body.data.id}`);
    const accountId = detail.body.data.observations.data[0]?.account_id;

    expect(accountId).toBeTruthy();

    return Number(accountId);
}

function definitionPayload(): Record<string, unknown> {
    return {
        event_key: 'offer_accepted',
        display_name: 'Offer accepted',
        description: 'The supplied Account accepted the reviewed Offer.',
        subject_type: 'Account',
        allowed_sources: ['manual', 'api', 'webhook'],
        property_schema: {
            type: 'object',
            properties: {},
            required: [],
            additionalProperties: false,
        },
        allowed_identity_keys: ['account_id', 'external_identity', 'domain'],
        dedupe_strategy: 'source_and_external_id',
        dedupe_configuration: {},
        attribution_window: { days: 30 },
        value_policy: { allowed: false, currency: null },
        correction_policy: { allowed: true },
        reversal_policy: { allowed: true },
        retention_policy: { unmatched_identity_days: 30 },
        export_policy: { allowed: true },
    };
}

function operationalOfferPayload(
    definitionVersionId: string,
    ownerUserId: number,
    capabilityId: string,
): Record<string, unknown> {
    return {
        schema_version: 2,
        name: 'Account intelligence workshop',
        promise: 'Turn verified Account evidence into a practical next step.',
        target_audience: 'Founder-led B2B software companies.',
        desired_outcome: 'A reviewed single-Account action brief.',
        call_to_action: 'Accept the workshop.',
        notes: 'S2 keeps every downstream outreach path disabled.',
        proposition: {
            problem_or_opportunity: 'Account research is difficult to verify.',
            value: 'A traceable recommendation backed by cited evidence.',
            proof: 'Every supported fact retains its source lineage.',
        },
        requested_commitment: 'Book one 30-minute workshop.',
        cta: {
            action: 'book_workshop',
            destination_url: 'https://example.com/workshop',
            response_route: 'calendar',
            required_inputs: [],
            acceptance_conversion_definition_version_id: definitionVersionId,
        },
        fulfilment: {
            mode: 'human_assisted',
            capability_id: capabilityId,
            asset_version_ids: [],
            owner_user_id: ownerUserId,
            response_sla_hours: 24,
            capacity_units: 1,
        },
        eligibility: {
            includes: ['Supplied B2B software Account'],
            excludes: ['Suppressed or policy-restricted Account'],
        },
        commercial_authority: {
            approved: true,
            notes: 'Approved for the isolated S2 browser workspace.',
        },
        approved_claims: ['Workshop outputs are reviewed before use.'],
        prohibited_claims: ['Guaranteed pipeline outcome.'],
        expires_at: '2099-01-01T00:00:00+00:00',
        agent_parameter_policy: { allowed_keys: [] },
    };
}

async function sweepResearch(
    passes: number,
    workspaceId: number,
): Promise<void> {
    const database = path.resolve(
        process.env.S2_BROWSER_DB_PATH ??
            'storage/framework/testing/s2-browser.sqlite',
    );
    const { stdout } = await execFileAsync(
        'php',
        [
            'artisan',
            'account-research:sweep',
            `--workspace=${workspaceId}`,
            `--passes=${passes}`,
            '--json',
        ],
        {
            cwd: process.cwd(),
            env: {
                ...process.env,
                APP_ENV: 'testing',
                APP_URL: 'http://127.0.0.1:8012',
                DB_CONNECTION: 'sqlite',
                DB_DATABASE: database,
                QUEUE_CONNECTION: 'sync',
                OUTBOSS_TEST_FAKE_INTELLIGENCE_PROVIDERS: 'true',
                CAMPAIGN_SENDING_ENABLED: 'false',
            },
            timeout: 60_000,
        },
    );
    const result = JSON.parse(stdout.trim()) as { published: number };

    expect(result.published).toBeGreaterThan(0);
}

test('owner can research and qualify one supplied Account without creating outreach', async ({
    page,
}) => {
    test.setTimeout(120_000);
    const suffix = `${Date.now().toString(36)}-${Math.random()
        .toString(36)
        .slice(2, 8)}`;

    await signIn(page);

    const baseline = await jsonRequest<Resource<Proof>>(
        page,
        '/_test/s2-proof',
    );
    expect(baseline.body.data).toMatchObject({
        account_research_runs: 0,
        provider_operations: 0,
        conversion_events: 0,
        fulfilment_requests: 0,
        capacity_reservations: 0,
        campaigns: 0,
        campaign_recipients: 0,
        messages: 0,
        send_attempts: 0,
        queued_jobs: 0,
    });
    const { workspace_id: workspaceId, owner_user_id: ownerUserId } =
        baseline.body.data;

    const sourceName = `S2 supplied Accounts ${suffix}`;
    const sourceId = await createSource(page, sourceName);
    const accountId = await importAccount(page, sourceId, suffix);

    await page.goto(`/prospects?view=accounts&account=${accountId}`);
    const inspector = page.locator('.registry-inspector');
    await expect(inspector).toContainText('Supplied by');
    await expect(inspector).toContainText(sourceName);
    await expect(
        inspector.locator('[data-test="account-intelligence-link"]'),
    ).toBeVisible();

    const definition = await jsonRequest<Resource<ConversionDefinition>>(
        page,
        '/s2/conversion-definitions',
        'POST',
        definitionPayload(),
        `definition-create-${suffix}`,
    );
    const activatedDefinition = await jsonRequest<
        Resource<ConversionDefinition>
    >(
        page,
        `/s2/conversion-definitions/${definition.body.data.id}/activate`,
        'POST',
        { expected_lock_version: definition.body.data.lock_version },
        `definition-activate-${suffix}`,
    );
    expect(activatedDefinition.body.data.status).toBe('active');

    const webhookSecret = `s2-browser-secret-${suffix}`;
    const connection = await jsonRequest<Resource<ConversionConnection>>(
        page,
        '/s2/conversion-connections',
        'POST',
        {
            kind: 'webhook',
            secret: webhookSecret,
            display_name: 'S2 signed acceptance source',
            allowed_conversion_definition_ids: [definition.body.data.id],
            allowed_source_types: ['webhook'],
            payload_limit_bytes: 4096,
            replay_window_seconds: 300,
        },
        `connection-create-${suffix}`,
    );
    expect(connection.body.data.webhook_url).toContain(
        `/webhooks/v1/conversions/${connection.body.data.public_id}`,
    );

    const asset = await jsonRequest<
        Resource<{ id: string; current_version: { id: string } }>
    >(
        page,
        '/s2/assets',
        'POST',
        {
            title: 'S2 Account intelligence guide',
            description: 'An approved external deliverable metadata record.',
            external_url: 'https://example.com/account-guide.pdf',
            media_type: 'application/pdf',
            approval_state: 'approved',
            availability_state: 'available',
            classification: 'public',
        },
        `asset-create-${suffix}`,
    );
    expect(asset.body.data.current_version.id).toBeTruthy();

    const offer = await jsonRequest<Resource<Offer>>(
        page,
        '/s2/offers',
        'POST',
        {
            client_operation_id: `offer-create-${suffix}`,
            name: 'Account intelligence workshop',
            promise:
                'Turn verified Account evidence into a practical next step.',
            target_audience: 'Founder-led B2B software companies.',
            desired_outcome: 'A reviewed single-Account action brief.',
            call_to_action: 'Accept the workshop.',
            notes: 'No automated outreach.',
        },
        `offer-create-${suffix}`,
    );
    const missingCapabilityId = '00000000-0000-4000-8000-000000000099';
    const blockedOffer = await jsonRequest<Resource<Offer>>(
        page,
        `/s2/offers/${offer.body.data.id}/configure`,
        'PATCH',
        {
            expected_lock_version: offer.body.data.lock_version,
            client_operation_id: `offer-blocked-${suffix}`,
            payload: operationalOfferPayload(
                activatedDefinition.body.data.current_version_id,
                ownerUserId,
                missingCapabilityId,
            ),
        },
        `offer-blocked-${suffix}`,
    );
    const blockedReadiness = await jsonRequest<
        Resource<{ state: string; blockers: { code: string }[] }>
    >(page, `/s2/offers/${offer.body.data.id}/readiness`);
    expect(blockedReadiness.body.data.state).not.toBe('ready_for_review');
    expect(blockedReadiness.body.data.blockers.length).toBeGreaterThan(0);

    const capability = await jsonRequest<Resource<{ id: string }>>(
        page,
        '/s2/fulfilment-capabilities',
        'POST',
        {
            display_name: 'Owner workshop delivery',
            mode: 'human_assisted',
            status: 'active',
            owner_user_id: ownerUserId,
            response_sla_hours: 24,
            capacity_limit: 1,
            capacity_window: 'week',
            health_state: 'healthy',
        },
        `capability-create-${suffix}`,
    );
    const readyOffer = await jsonRequest<Resource<Offer>>(
        page,
        `/s2/offers/${offer.body.data.id}/configure`,
        'PATCH',
        {
            expected_lock_version: blockedOffer.body.data.lock_version,
            client_operation_id: `offer-ready-${suffix}`,
            payload: operationalOfferPayload(
                activatedDefinition.body.data.current_version_id,
                ownerUserId,
                capability.body.data.id,
            ),
        },
        `offer-ready-${suffix}`,
    );
    const readyReadiness = await jsonRequest<
        Resource<{ state: string; blockers: unknown[] }>
    >(page, `/s2/offers/${offer.body.data.id}/readiness`);
    expect(readyReadiness.body.data).toMatchObject({
        state: 'ready_for_review',
        blockers: [],
    });

    const preflight = await jsonRequest<{
        preflight: {
            input_hash: string;
            blocked: boolean;
            stages: unknown[];
            maximum_cost_micros: number;
            estimated_cost_micros: number;
            currency: string;
            gtm_profile_version_id: string;
            capability_snapshot: { selected: string[] };
            freshness_policy: Record<string, unknown>;
        };
    }>(page, `/accounts/${accountId}/intelligence/preflight`, 'POST', {
        offer_version_id: readyOffer.body.data.current_version_id,
    });
    expect(preflight.body.preflight.blocked).toBe(false);
    expect(preflight.body.preflight.gtm_profile_version_id).toBeTruthy();
    expect(preflight.body.preflight.stages).toHaveLength(6);
    expect(preflight.body.preflight.maximum_cost_micros).toBeGreaterThanOrEqual(
        preflight.body.preflight.estimated_cost_micros,
    );

    const researchPayload = {
        idempotency_key: `research-start-${suffix}`,
        preflight_hash: preflight.body.preflight.input_hash,
        gtm_profile_version_id: preflight.body.preflight.gtm_profile_version_id,
        offer_version_id: readyOffer.body.data.current_version_id,
        freshness_policy: preflight.body.preflight.freshness_policy,
        capabilities: preflight.body.preflight.capability_snapshot.selected,
        maximum_cost_micros: preflight.body.preflight.maximum_cost_micros,
    };
    const started = await jsonRequest<{ run: ResearchRun }>(
        page,
        `/accounts/${accountId}/intelligence/research-runs`,
        'POST',
        researchPayload,
    );
    const replayed = await jsonRequest<{ run: ResearchRun }>(
        page,
        `/accounts/${accountId}/intelligence/research-runs`,
        'POST',
        researchPayload,
    );
    expect(replayed.body.run.id).toBe(started.body.run.id);

    await sweepResearch(1, workspaceId);
    await page.goto(
        `/accounts/${accountId}/intelligence?run=${started.body.run.id}`,
    );
    const runCard = page.locator('[data-test="account-intelligence-run"]');
    await expect(runCard).toContainText(started.body.run.id);
    await page.locator('[data-test="reload-same-run"]').click();
    await expect(runCard).toContainText(started.body.run.id);

    await sweepResearch(10, workspaceId);
    await page.reload();
    await expect(
        page.locator('[data-test="account-intelligence-state"]'),
    ).toContainText('Completed');
    await expect(runCard.locator('[data-stage-state="succeeded"]')).toHaveCount(
        5,
    );
    await expect(runCard.locator('[data-stage-state="skipped"]')).toHaveCount(
        1,
    );

    const claims = page.locator('[data-test="account-intelligence-claims"]');
    for (const status of [
        'Supported',
        'Inferred',
        'Conflicting',
        'Unsupported',
    ]) {
        const group = claims.locator('.claim-group').filter({
            has: page.getByRole('heading', { name: status, exact: true }),
        });
        await expect(group.locator('.claim-row')).toHaveCount(1);
    }
    const supported = claims.locator('.claim-group').filter({
        has: page.getByRole('heading', { name: 'Supported', exact: true }),
    });
    await supported.getByText(/View evidence/).click();
    await expect(supported).toContainText('Citation: Business model');
    await expect(supported).toContainText('https://example.com');

    const conflicting = claims.locator('.claim-group').filter({
        has: page.getByRole('heading', {
            name: 'Conflicting',
            exact: true,
        }),
    });
    await conflicting.getByRole('button', { name: 'Correct' }).click();
    await page.getByLabel('Corrected value').fill('Reviewed positioning');
    await page
        .getByLabel('Reason (optional)')
        .fill('Owner resolved the conflicting public wording.');
    await page.getByRole('button', { name: 'Save owner correction' }).click();
    const ownerConfirmed = claims.locator('.claim-group').filter({
        has: page.getByRole('heading', {
            name: 'Owner confirmed',
            exact: true,
        }),
    });
    await expect(ownerConfirmed).toContainText('Reviewed positioning');
    await expect(ownerConfirmed).toContainText('Prior version');

    const qualification = page.locator(
        '[data-test="account-intelligence-qualification"]',
    );
    await expect(qualification).toContainText('Needs Review');
    await qualification
        .getByPlaceholder(/Why are you accepting/)
        .fill('Conservative result reviewed against the cited evidence.');
    await qualification.locator('[data-test="qualification-accept"]').click();
    await expect(qualification).toContainText('Accepted');
    await expect(qualification).toContainText('Review history');

    const firstRunId = started.body.run.id;
    await page.locator('[data-test="refresh-account-research"]').click();
    await expect(runCard).not.toContainText(firstRunId);
    const refreshedRunId = await runCard.locator('code').first().textContent();
    expect(refreshedRunId).toBeTruthy();
    await sweepResearch(10, workspaceId);
    await page.reload();
    await expect(
        page.locator('[data-test="account-intelligence-state"]'),
    ).toContainText('Completed');
    await expect(qualification).toContainText('Needs Review');

    const linkedOffer = page.locator(
        '[data-test="account-intelligence-offer"]',
    );
    await expect(linkedOffer).toContainText('Operational contract');
    await expect(linkedOffer).toContainText('No readiness blockers recorded');
    await linkedOffer.locator('[data-test="offer-submit-review"]').click();
    await linkedOffer.locator('[data-test="offer-approve"]').click();
    await expect(linkedOffer).toContainText('Approved');

    const approvedOffer = await jsonRequest<Resource<Offer>>(
        page,
        `/s2/offers/${offer.body.data.id}`,
    );
    const activeOffer = await jsonRequest<Resource<Offer>>(
        page,
        `/s2/offers/${offer.body.data.id}/activate`,
        'POST',
        {
            status: 'active',
            expected_lock_version: approvedOffer.body.data.lock_version,
            client_operation_id: `offer-activate-${suffix}`,
        },
        `offer-activate-${suffix}`,
    );
    expect(activeOffer.body.data.status).toBe('active');

    const conversionPayload = {
        definition_version_id: activatedDefinition.body.data.current_version_id,
        external_event_id: `accepted-${suffix}`,
        properties: {},
        identities: [{ type: 'account_id', value: String(accountId) }],
    };
    const conversion = await signedWebhookRequest<
        Resource<{ accepted: boolean }>
    >(page, connection.body.data.webhook_url, conversionPayload, webhookSecret);
    const duplicate = await signedWebhookRequest<
        Resource<{ accepted: boolean }>
    >(page, connection.body.data.webhook_url, conversionPayload, webhookSecret);
    expect(conversion.status).toBe(202);
    expect(duplicate.status).toBe(202);
    expect(conversion.body.data.accepted).toBe(true);
    expect(duplicate.body.data.accepted).toBe(true);

    const events = await jsonRequest<{
        data: {
            id: string;
            matched_account_id: number;
            attribution_state: string;
        }[];
    }>(page, `/s2/conversion-events?account_id=${accountId}`);
    expect(events.body.data).toHaveLength(1);
    expect(events.body.data[0]).toMatchObject({
        matched_account_id: accountId,
        attribution_state: 'not_evaluated',
    });

    const fulfilment = await jsonRequest<
        Resource<
            {
                id: string;
                account_id: number;
                conversion_event_id: string;
                state: string;
            }[]
        >
    >(page, `/s2/fulfilment-requests?account_id=${accountId}`);
    expect(fulfilment.body.data).toHaveLength(1);
    expect(fulfilment.body.data[0]).toMatchObject({
        account_id: accountId,
        conversion_event_id: events.body.data[0]?.id,
        state: 'pending',
    });

    await page.reload();
    const conversionPanel = page.locator(
        '[data-test="account-intelligence-conversion"]',
    );
    await expect(conversionPanel).toContainText('1 matched');
    await expect(conversionPanel).toContainText('Not evaluated');
    await expect(conversionPanel).toContainText('Offer Accepted');

    const proof = await jsonRequest<Resource<Proof>>(page, '/_test/s2-proof');
    expect(proof.body.data).toMatchObject({
        account_research_runs: 2,
        provider_operations: 5,
        conversion_events: 1,
        fulfilment_requests: 1,
        capacity_reservations: 1,
        campaigns: 0,
        campaign_recipients: 0,
        messages: 0,
        send_attempts: 0,
        queued_jobs: 0,
    });

    await page.setViewportSize({ width: 390, height: 844 });
    await page.reload();
    await expect(
        page.getByRole('heading', { name: 'S2 Browser Account' }),
    ).toBeVisible();
    await expect(claims).toBeVisible();
    await expect(qualification).toBeVisible();
    await expect(linkedOffer).toBeVisible();
    const horizontalOverflow = await page.evaluate(
        () => document.documentElement.scrollWidth - window.innerWidth,
    );
    expect(horizontalOverflow).toBeLessThanOrEqual(1);
});
