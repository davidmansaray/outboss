import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

type BrowserOffer = {
    uuid: string;
    lock_version: number;
};

type MutationResponse = {
    offer?: BrowserOffer;
};

async function signIn(page: Page): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email address').fill('david@outboss.test');
    await page.locator('#password').fill('password');
    await page.locator('[data-test="login-button"]').click();
    await expect(page).not.toHaveURL(/\/login/);
}

async function jsonMutation<T extends Record<string, unknown>>(
    page: Page,
    url: string,
    method: 'POST' | 'PATCH' | 'DELETE',
    body?: Record<string, unknown>,
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
                ...(requestBody ? { body: JSON.stringify(requestBody) } : {}),
            });
            const payload = (await response.json()) as T;

            if (!response.ok) {
                throw new Error(
                    `${requestMethod} ${requestUrl} returned ${response.status}: ${JSON.stringify(payload)}`,
                );
            }

            return payload;
        },
        {
            requestUrl: url,
            requestMethod: method,
            requestBody: body,
        },
    );
}

test('owner can create, page, filter, inspect, reconcile, and safely leave Offers', async ({
    page,
}) => {
    test.setTimeout(60_000);

    const suffix = `${Date.now().toString(36)}-${Math.random()
        .toString(36)
        .slice(2, 8)}`;
    const primaryName = `Browser pipeline ${suffix}`;
    const bulkOfferIds: string[] = [];
    let concurrentPage: Page | null = null;

    await signIn(page);

    try {
        await page.goto('/offers');
        await page.getByRole('button', { name: 'New offer' }).click();
        await expect(
            page.locator('[data-test="offer-editor-drawer"]'),
        ).toBeVisible();

        await page.getByLabel('Name').fill(primaryName);
        await page
            .getByLabel('Promise')
            .fill('Turn the next quarter into qualified pipeline.');
        await page
            .getByLabel('Target audience')
            .fill('Founder-led B2B revenue teams');
        await page
            .getByLabel('Desired outcome')
            .fill('A dependable flow of qualified conversations');
        await page
            .getByLabel('Call to action')
            .fill('Book a pipeline working session');
        await page
            .getByLabel(/Notes/)
            .fill('Created by the Offer browser test.');

        const createResponsePromise = page.waitForResponse(
            (response) =>
                new URL(response.url()).pathname === '/offers' &&
                response.request().method() === 'POST',
        );
        await page.getByRole('button', { name: 'Save draft' }).click();
        const createResponse = await createResponsePromise;
        expect(createResponse.status()).toBe(201);
        const createPayload = (await createResponse.json()) as MutationResponse;
        const primary = createPayload.offer;
        expect(primary?.uuid).toBeTruthy();
        const primaryId = primary!.uuid;

        await expect(
            page.locator('[data-test="offer-editor-drawer"]'),
        ).toBeHidden();
        const primaryRow = page.locator('.offer-row').filter({
            has: page.getByText(primaryName, { exact: true }),
        });
        await expect(primaryRow).toBeVisible();

        const statusResponsePromise = page.waitForResponse(
            (response) =>
                new URL(response.url()).pathname ===
                    `/offers/${primaryId}/status` &&
                response.request().method() === 'PATCH',
        );
        await primaryRow
            .getByRole('button', {
                name: 'Change offer status, currently Draft',
            })
            .click();
        await page.getByRole('menuitem', { name: 'Active' }).click();
        expect((await statusResponsePromise).status()).toBe(200);
        await expect(
            primaryRow.getByRole('button', {
                name: 'Change offer status, currently Active',
            }),
        ).toBeVisible();

        await primaryRow
            .getByRole('button', { name: `Open ${primaryName}` })
            .click();
        await page.getByRole('button', { name: 'History' }).click();
        await expect(
            page.locator('[data-test="offer-history-drawer"]'),
        ).toContainText('Version 2');
        await page.keyboard.press('Escape');
        await expect(
            page.locator('[data-test="offer-history-drawer"]'),
        ).toBeHidden();

        // Two owners edit the same version. The stale page must be able to
        // reapply only its fields on top of the newly authoritative version.
        await primaryRow
            .getByRole('button', { name: `Open ${primaryName}` })
            .click();
        await page.getByLabel(/Notes/).fill(`Local owner note ${suffix}`);
        concurrentPage = await page.context().newPage();
        await concurrentPage.goto(
            `/offers?panel=edit&offer=${encodeURIComponent(primaryId)}`,
        );
        await concurrentPage
            .getByLabel('Promise')
            .fill(`Concurrent owner promise ${suffix}`);
        const concurrentSavePromise = concurrentPage.waitForResponse(
            (response) =>
                new URL(response.url()).pathname === `/offers/${primaryId}` &&
                response.request().method() === 'PATCH',
        );
        await concurrentPage
            .getByRole('button', { name: 'Save changes' })
            .click();
        expect((await concurrentSavePromise).status()).toBe(200);

        const staleSavePromise = page.waitForResponse(
            (response) =>
                new URL(response.url()).pathname === `/offers/${primaryId}` &&
                response.request().method() === 'PATCH',
        );
        await page.getByRole('button', { name: 'Save changes' }).click();
        expect((await staleSavePromise).status()).toBe(409);
        await expect(
            page.getByRole('button', { name: 'Reapply my changes' }),
        ).toBeVisible();
        await page.getByRole('button', { name: 'Reapply my changes' }).click();
        await expect(page.getByLabel('Promise')).toHaveValue(
            `Concurrent owner promise ${suffix}`,
        );
        await expect(page.getByLabel(/Notes/)).toHaveValue(
            `Local owner note ${suffix}`,
        );
        const reconciledSavePromise = page.waitForResponse(
            (response) =>
                new URL(response.url()).pathname === `/offers/${primaryId}` &&
                response.request().method() === 'PATCH',
        );
        await page.getByRole('button', { name: 'Save changes' }).click();
        expect((await reconciledSavePromise).status()).toBe(200);
        await expect(
            page.locator('[data-test="offer-editor-drawer"]'),
        ).toBeHidden();
        await concurrentPage.close();
        concurrentPage = null;

        // Six newer drafts put the active Offer beyond a five-item unfiltered
        // page while keeping the mutation volume safe for production limits.
        for (let index = 0; index < 6; index++) {
            const response = await jsonMutation<MutationResponse>(
                page,
                '/offers',
                'POST',
                {
                    name: `Pagination draft ${suffix}-${index}`,
                    client_operation_id: `browser-page-${suffix}-${index}`,
                },
            );
            expect(response.offer?.uuid).toBeTruthy();
            bulkOfferIds.push(response.offer!.uuid);
        }

        await page.goto('/offers?per_page=5');
        const rows = page.locator('.offer-row');
        await expect(rows).toHaveCount(5);
        const beforeLoadMore = await rows.count();
        await page.getByRole('button', { name: 'Load more offers' }).click();
        await expect.poll(() => rows.count()).toBeGreaterThan(beforeLoadMore);

        const activeFilterResponse = page.waitForResponse(
            (response) =>
                new URL(response.url()).pathname === '/offers' &&
                new URL(response.url()).searchParams.get('status') ===
                    'active' &&
                response.request().method() === 'GET',
        );
        await page
            .getByRole('navigation', { name: 'Offer views' })
            .getByRole('button', { name: /^Active/ })
            .click();
        expect((await activeFilterResponse).status()).toBe(200);
        await expect(page).toHaveURL(/\/offers\?status=active$/);
        await expect(
            page.getByRole('button', { name: `Open ${primaryName}` }),
        ).toBeVisible();

        await page.goto(
            `/offers?panel=history&offer=${encodeURIComponent(primaryId)}`,
        );
        await expect(
            page.locator('[data-test="offer-history-drawer"]'),
        ).toContainText('Version 4');
        const invalidDeepLink = await page.request.get(
            '/offers?panel=edit&offer=00000000-0000-4000-8000-000000000000',
        );
        expect(invalidDeepLink.status()).toBe(404);
        await page.keyboard.press('Escape');

        await page.getByRole('button', { name: `Open ${primaryName}` }).click();
        await page
            .getByLabel(/Notes/)
            .fill(`Unsaved navigation guard ${suffix}`);
        let prospectRequests = 0;
        const countProspectRequest = (request: { url: () => string }) => {
            if (new URL(request.url()).pathname === '/prospects') {
                prospectRequests++;
            }
        };
        page.on('request', countProspectRequest);
        const prospectsLink = page.locator('a[href="/prospects"]').first();

        await prospectsLink.evaluate((element: HTMLElement) => element.click());
        await expect(page.getByRole('alertdialog')).toBeVisible();
        expect(prospectRequests).toBe(0);
        await page
            .getByRole('alertdialog')
            .getByRole('button', { name: 'Cancel' })
            .click();
        await expect(page).toHaveURL(/\/offers\?panel=edit&offer=/);

        await prospectsLink.evaluate((element: HTMLElement) => element.click());
        await page
            .getByRole('alertdialog')
            .getByRole('button', { name: 'Discard' })
            .click();
        await expect(page).toHaveURL(/\/prospects$/);
        await expect.poll(() => prospectRequests).toBe(1);
        page.off('request', countProspectRequest);
    } finally {
        await concurrentPage?.close();
        await Promise.allSettled(
            bulkOfferIds.map((offerId) =>
                jsonMutation<Record<string, unknown>>(
                    page,
                    `/offers/${encodeURIComponent(offerId)}`,
                    'DELETE',
                ),
            ),
        );
    }
});
