import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

type SourceMutation = {
    data: {
        id: string;
        state: string;
        received: number;
        accepted: number;
        created: number;
        linked: number;
        suppressed: number;
        rejected: number;
    };
};

async function signIn(page: Page): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email address').fill('david@outboss.test');
    await page.locator('#password').fill('password');
    await page.locator('[data-test="login-button"]').click();
    await expect(page).not.toHaveURL(/\/login/);
}

async function jsonMutation<T>(
    page: Page,
    url: string,
    method: 'POST' | 'DELETE',
    body: Record<string, unknown>,
): Promise<T> {
    return page.evaluate(
        async ({ requestUrl, requestMethod, requestBody }) => {
            const cookie = document.cookie
                .split('; ')
                .find((value) => value.startsWith('XSRF-TOKEN='));
            const token = cookie
                ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length))
                : '';
            const response = await fetch(requestUrl, {
                method: requestMethod,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': token,
                },
                body: JSON.stringify(requestBody),
            });
            const payload = (await response.json()) as T;

            if (!response.ok) {
                throw new Error(
                    `${requestMethod} ${requestUrl} returned ${response.status}: ${JSON.stringify(payload)}`,
                );
            }

            return payload;
        },
        { requestUrl: url, requestMethod: method, requestBody: body },
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

test('owner can create named sources and trace idempotent multi-source ingestion', async ({
    page,
}) => {
    test.setTimeout(60_000);
    const suffix = `${Date.now().toString(36)}-${Math.random()
        .toString(36)
        .slice(2, 8)}`;
    const email = `source-${suffix}@example.test`;

    await signIn(page);

    const firstSourceId = await createSource(
        page,
        `Browser source A ${suffix}`,
    );
    const firstPayload = {
        idempotency_key: `browser:first:${suffix}`,
        input_reference: { transport: 'browser_e2e' },
        input: [
            {
                entity_type: 'person',
                row_number: 1,
                input_key: email,
                observed_values: {
                    email,
                    full_name: 'Browser Source Prospect',
                    job_title: 'Founder',
                },
            },
        ],
    };
    const first = await jsonMutation<SourceMutation>(
        page,
        `/prospect-sources/${firstSourceId}/runs`,
        'POST',
        firstPayload,
    );
    const retry = await jsonMutation<SourceMutation>(
        page,
        `/prospect-sources/${firstSourceId}/runs`,
        'POST',
        firstPayload,
    );

    expect(retry.data.id).toBe(first.data.id);
    expect(first.data.received).toBe(1);
    expect(first.data.created).toBe(1);

    await page.goto(`/prospect-sources/${firstSourceId}/runs/${first.data.id}`);
    await expect(
        page.getByRole('heading', { name: first.data.id }),
    ).toBeVisible();
    await expect(page.getByText('Browser Source Prospect')).toHaveCount(0);
    await expect(page.getByText('created', { exact: true })).toBeVisible();

    const secondSourceId = await createSource(
        page,
        `Browser source B ${suffix}`,
    );
    const second = await jsonMutation<SourceMutation>(
        page,
        `/prospect-sources/${secondSourceId}/runs`,
        'POST',
        {
            idempotency_key: `browser:second:${suffix}`,
            input: [
                {
                    entity_type: 'person',
                    row_number: 1,
                    input_key: email,
                    observed_values: { email },
                },
            ],
        },
    );

    expect(second.data.created).toBe(0);
    expect(second.data.linked).toBe(1);

    await page.goto(`/prospect-sources/${secondSourceId}`);
    await expect(page.locator('.source-funnel')).toContainText('1linked');
    await page.getByRole('button', { name: 'Archive' }).click();
    await page.getByRole('button', { name: 'Confirm archive' }).click();
    await expect(page.getByText('archived', { exact: true })).toBeVisible();

    await jsonMutation(
        page,
        `/prospect-sources/${firstSourceId}/data`,
        'DELETE',
        { delete_canonical: false },
    );
    await jsonMutation(
        page,
        `/prospect-sources/${secondSourceId}/data`,
        'DELETE',
        { delete_canonical: false },
    );
});
