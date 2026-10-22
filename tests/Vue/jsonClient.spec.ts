import { beforeEach, describe, expect, it, vi } from 'vitest';
import { JsonHttpError, jsonClient } from '@/lib/jsonClient';

describe('jsonClient', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        document.cookie = 'XSRF-TOKEN=token%2Bvalue';
    });

    it('sends same-origin JSON mutations with the CSRF token', async () => {
        const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(JSON.stringify({ ok: true }), {
                status: 200,
                headers: { 'content-type': 'application/json' },
            }),
        );

        await jsonClient.patch('/offers/offer-1', { name: 'Pipeline' });

        expect(fetchMock).toHaveBeenCalledWith(
            '/offers/offer-1',
            expect.objectContaining({
                method: 'PATCH',
                credentials: 'same-origin',
                body: JSON.stringify({ name: 'Pipeline' }),
                headers: expect.any(Headers),
            }),
        );
        const headers = fetchMock.mock.calls[0][1]?.headers as Headers;
        expect(headers.get('Accept')).toBe('application/json');
        expect(headers.get('Content-Type')).toBe('application/json');
        expect(headers.get('X-XSRF-TOKEN')).toBe('token+value');
    });

    it('normalizes JSON validation errors into JsonHttpError', async () => {
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(
                JSON.stringify({
                    message: 'Invalid offer',
                    errors: { name: ['Give it a name.'] },
                }),
                {
                    status: 422,
                    headers: { 'content-type': 'application/json' },
                },
            ),
        );

        await expect(jsonClient.post('/offers', {})).rejects.toSatisfy(
            (error: unknown) =>
                error instanceof JsonHttpError &&
                error.status === 422 &&
                error.errors.name === 'Give it a name.',
        );
    });

    it('surfaces the nested ApiError validation bag used by JSON Form Requests', async () => {
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(
                JSON.stringify({
                    error: {
                        type: 'validation_error',
                        message: 'The submitted profile section is invalid.',
                        errors: {
                            'company.name': ['Give the company a name.'],
                            'company.category': [
                                'Keep the category under 160 characters.',
                            ],
                        },
                    },
                }),
                {
                    status: 422,
                    headers: { 'content-type': 'application/json' },
                },
            ),
        );

        await expect(
            jsonClient.patch('/settings/gtm-profile/sections/company', {}),
        ).rejects.toSatisfy(
            (error: unknown) =>
                error instanceof JsonHttpError &&
                error.errorType === 'validation_error' &&
                error.message === 'The submitted profile section is invalid.' &&
                error.errors['company.name'] === 'Give the company a name.' &&
                error.errors['company.category'] ===
                    'Keep the category under 160 characters.',
        );
    });

    it('exposes the authoritative lock version from conflict envelopes', async () => {
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(
                JSON.stringify({
                    error: {
                        type: 'conflict',
                        code: 'stale_version',
                        lock_version: 8,
                        current_section: {
                            name: { value: 'Server company' },
                        },
                    },
                    errors: {
                        code: 'stale_version',
                        lock_version: 8,
                    },
                    conflict: { lock_version: 8 },
                }),
                {
                    status: 409,
                    headers: { 'content-type': 'application/json' },
                },
            ),
        );

        await expect(
            jsonClient.patch('/settings/gtm-profile/sections/company', {}),
        ).rejects.toSatisfy(
            (error: unknown) =>
                error instanceof JsonHttpError &&
                error.status === 409 &&
                error.currentLockVersion === 8 &&
                error.currentSection?.name !== undefined,
        );
    });
});
