import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { patchRequest, putRequest } = vi.hoisted(() => ({
    patchRequest: vi.fn(),
    putRequest: vi.fn(),
}));

vi.mock('@/lib/jsonClient', () => ({
    jsonClient: {
        patch: patchRequest,
        put: putRequest,
    },
    JsonHttpError: class JsonHttpError extends Error {
        status: number;
        errors: Record<string, string>;
        currentLockVersion: number | null;
        currentSection: Record<string, unknown> | null;

        constructor(
            status = 500,
            errors: Record<string, string> = {},
            currentLockVersion: number | null = null,
            currentSection: Record<string, unknown> | null = null,
        ) {
            super(errors.message ?? 'Request failed');
            this.status = status;
            this.errors = errors;
            this.currentLockVersion = currentLockVersion;
            this.currentSection = currentSection;
        }
    },
}));

import GtmProfileEditorDrawer from '@/components/gtm-profile/GtmProfileEditorDrawer.vue';
import { JsonHttpError } from '@/lib/jsonClient';
import type { GtmProfile } from '@/types/gtmProfile';

const stubs = {
    Sheet: {
        props: ['open'],
        emits: ['update:open'],
        template: '<div v-if="open"><slot /></div>',
    },
    SheetContent: {
        template: '<section v-bind="$attrs"><slot /></section>',
    },
    SheetDescription: { template: '<p><slot /></p>' },
    SheetFooter: { template: '<footer><slot /></footer>' },
    SheetHeader: { template: '<header><slot /></header>' },
    SheetTitle: { template: '<h2><slot /></h2>' },
};

function profile(payload: GtmProfile['payload']): GtmProfile {
    return {
        uuid: 'profile-1',
        lock_version: 0,
        payload,
    };
}

describe('GtmProfileEditorDrawer', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        patchRequest.mockReset();
        putRequest.mockReset();
        patchRequest.mockResolvedValue({ lock_version: 1 });
        putRequest.mockResolvedValue({ lock_version: 1 });
    });

    it('saves website edits through the dedicated PUT endpoint and not section PATCH', async () => {
        const wrapper = mount(GtmProfileEditorDrawer, {
            props: {
                open: true,
                profile: profile({
                    company: { website_url: 'https://old.test' },
                }),
                section: 'company',
                isOwner: true,
            },
            global: { stubs },
        });

        await wrapper
            .get('textarea[aria-label="Company Website"]')
            .setValue('example.com');
        await vi.advanceTimersByTimeAsync(800);
        await flushPromises();

        expect(putRequest).toHaveBeenCalledTimes(1);
        expect(putRequest).toHaveBeenCalledWith(
            '/settings/gtm-profile/website',
            expect.objectContaining({
                website_url: 'example.com',
                expected_lock_version: 0,
            }),
        );
        expect(patchRequest).not.toHaveBeenCalled();

        wrapper.unmount();
        vi.useRealTimers();
    });

    it('round-trips repeated fields as stable items and tombstones deleted values', async () => {
        const firstId = '11111111-1111-4111-8111-111111111111';
        const secondId = '22222222-2222-4222-8222-222222222222';
        const wrapper = mount(GtmProfileEditorDrawer, {
            props: {
                open: true,
                profile: profile({
                    buyers: {
                        target_roles: [
                            { id: firstId, value: 'Founder' },
                            { id: secondId, value: 'Head of Growth' },
                        ],
                    },
                }),
                section: 'buyers',
                isOwner: true,
            },
            global: { stubs },
        });

        await wrapper
            .get('textarea[aria-label="Target Roles"]')
            .setValue('Founder');
        await vi.advanceTimersByTimeAsync(800);
        await flushPromises();

        const payload = patchRequest.mock.calls[0][1].payload;
        expect(payload.target_roles).toEqual([
            { id: firstId, value: 'Founder' },
            { id: secondId, value: 'Head of Growth', tombstoned: true },
        ]);
        expect(payload.target_roles).not.toContain('Founder\nHead of Growth');

        wrapper.unmount();
        vi.useRealTimers();
    });

    it('keeps repeated item identity when lines are reordered', async () => {
        const firstId = '11111111-1111-4111-8111-111111111111';
        const secondId = '22222222-2222-4222-8222-222222222222';
        const wrapper = mount(GtmProfileEditorDrawer, {
            props: {
                open: true,
                profile: profile({
                    market: {
                        geographies: [
                            { id: firstId, value: 'North America' },
                            { id: secondId, value: 'Europe' },
                        ],
                    },
                }),
                section: 'market',
                isOwner: true,
            },
            global: { stubs },
        });

        await wrapper
            .get('textarea[aria-label="Geographies"]')
            .setValue('Europe\nNorth America');
        await vi.advanceTimersByTimeAsync(800);
        await flushPromises();

        expect(patchRequest.mock.calls[0][1].payload.geographies).toEqual([
            { id: secondId, value: 'Europe' },
            { id: firstId, value: 'North America' },
        ]);

        wrapper.unmount();
        vi.useRealTimers();
    });

    it('does not assign an existing id to an inserted or edited repeated line', async () => {
        const firstId = '11111111-1111-4111-8111-111111111111';
        const secondId = '22222222-2222-4222-8222-222222222222';
        const wrapper = mount(GtmProfileEditorDrawer, {
            props: {
                open: true,
                profile: profile({
                    buyers: {
                        target_roles: [
                            { id: firstId, value: 'Founder' },
                            { id: secondId, value: 'Head of Growth' },
                        ],
                    },
                }),
                section: 'buyers',
                isOwner: true,
            },
            global: { stubs },
        });

        await wrapper
            .get('textarea[aria-label="Target Roles"]')
            .setValue('Chief Revenue Officer\nFounder\nGrowth Lead');
        await vi.advanceTimersByTimeAsync(800);
        await flushPromises();

        expect(patchRequest.mock.calls[0][1].payload.target_roles).toEqual([
            { value: 'Chief Revenue Officer' },
            { id: firstId, value: 'Founder' },
            { value: 'Growth Lead' },
            {
                id: secondId,
                value: 'Head of Growth',
                tombstoned: true,
            },
        ]);

        wrapper.unmount();
        vi.useRealTimers();
    });

    it('does not rewrite untouched repeated fields when another field changes', async () => {
        const roleId = '11111111-1111-4111-8111-111111111111';
        const wrapper = mount(GtmProfileEditorDrawer, {
            props: {
                open: true,
                profile: profile({
                    market: {
                        target_segment: 'B2B SaaS',
                        geographies: [{ id: roleId, value: 'North America' }],
                    },
                }),
                section: 'market',
                isOwner: true,
            },
            global: { stubs },
        });

        await wrapper
            .get('textarea[aria-label="Target Segment"]')
            .setValue('Seed-stage B2B SaaS');
        await vi.advanceTimersByTimeAsync(800);
        await flushPromises();

        expect(patchRequest.mock.calls[0][1].payload).toEqual({
            target_segment: 'Seed-stage B2B SaaS',
        });

        wrapper.unmount();
        vi.useRealTimers();
    });

    it('keeps failed autosave visible with Retry and Discard controls', async () => {
        patchRequest.mockRejectedValueOnce(new Error('offline'));
        const wrapper = mount(GtmProfileEditorDrawer, {
            props: {
                open: true,
                profile: profile({ company: { name: 'Acme' } }),
                section: 'company',
                isOwner: true,
            },
            global: { stubs },
        });

        await wrapper
            .get('textarea[aria-label="Company Name"]')
            .setValue('Acme Labs');
        await vi.advanceTimersByTimeAsync(800);
        await flushPromises();

        expect(wrapper.get('[data-test="gtm-save-error"]').text()).toContain(
            'Retry',
        );
        expect(wrapper.get('[data-test="gtm-save-error"]').text()).toContain(
            'Discard',
        );
        expect(wrapper.vm.hasPending).toBe(true);

        wrapper.unmount();
        vi.useRealTimers();
    });

    it('maps nested 422 field errors and focuses the first invalid field', async () => {
        patchRequest.mockRejectedValueOnce(
            new JsonHttpError(422, {
                'company.category': 'Keep the category under 160 characters.',
                message: 'The submitted section is invalid.',
            }),
        );
        const wrapper = mount(GtmProfileEditorDrawer, {
            attachTo: document.body,
            props: {
                open: true,
                profile: profile({ company: { category: 'SaaS' } }),
                section: 'company',
                isOwner: true,
            },
            global: { stubs },
        });

        const category = wrapper.get('textarea[aria-label="Category"]');
        await category.setValue('x'.repeat(161));
        await vi.advanceTimersByTimeAsync(800);
        await flushPromises();

        expect(category.attributes('aria-invalid')).toBe('true');
        expect(wrapper.text()).toContain(
            'Keep the category under 160 characters.',
        );
        expect(document.activeElement).toBe(category.element);

        wrapper.unmount();
        vi.useRealTimers();
    });

    it('requires a visible review before explicitly reapplying a conflict', async () => {
        patchRequest.mockRejectedValueOnce(
            new JsonHttpError(
                409,
                { message: 'The profile changed in another tab.' },
                2,
                { name: { value: 'Server company' } },
            ),
        );
        const wrapper = mount(GtmProfileEditorDrawer, {
            props: {
                open: true,
                profile: profile({ company: { name: 'Acme' } }),
                section: 'company',
                isOwner: true,
            },
            global: { stubs },
        });

        await wrapper
            .get('textarea[aria-label="Company Name"]')
            .setValue('My draft');
        await vi.advanceTimersByTimeAsync(800);
        await flushPromises();

        const firstOperation = patchRequest.mock.calls[0][1];
        expect(wrapper.text()).toContain('Reload latest');
        expect(patchRequest).toHaveBeenCalledTimes(1);

        await wrapper
            .get('[data-test="gtm-save-error"] button:nth-of-type(2)')
            .trigger('click');
        expect(
            wrapper.get('[data-test="gtm-conflict-review"]').text(),
        ).toContain('Latest: Server company');

        patchRequest.mockResolvedValueOnce({ lock_version: 3 });
        await wrapper
            .get('[data-test="gtm-conflict-reapply"]')
            .trigger('click');
        await flushPromises();

        expect(patchRequest).toHaveBeenCalledTimes(2);
        expect(patchRequest.mock.calls[1][1].expected_lock_version).toBe(2);
        expect(patchRequest.mock.calls[1][1].client_operation_id).not.toBe(
            firstOperation.client_operation_id,
        );

        wrapper.unmount();
        vi.useRealTimers();
    });
});
