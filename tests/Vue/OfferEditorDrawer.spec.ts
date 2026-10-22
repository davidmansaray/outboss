import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { postRequest, patchRequest } = vi.hoisted(() => ({
    postRequest: vi.fn(),
    patchRequest: vi.fn(),
}));

vi.mock('@/lib/jsonClient', () => ({
    jsonClient: { post: postRequest, patch: patchRequest },
    JsonHttpError: class JsonHttpError extends Error {
        status: number;
        data: unknown;
        errors: Record<string, string>;

        constructor(
            status = 422,
            data: {
                error?: {
                    message?: string;
                    errors?: Record<string, string[]>;
                    current_offer?: unknown;
                };
            } = {},
        ) {
            super(data.error?.message ?? 'Request failed');
            this.status = status;
            this.data = data;
            this.errors = Object.fromEntries(
                Object.entries(data.error?.errors ?? {}).map(
                    ([key, values]) => [key, values[0]],
                ),
            );
        }
    },
}));

import OfferEditorDrawer from '@/components/offers/OfferEditorDrawer.vue';

const stubs = {
    Sheet: {
        props: ['open'],
        template: '<div v-if="open"><slot /></div>',
    },
    SheetContent: { template: '<div><slot /></div>' },
    SheetDescription: { template: '<p><slot /></p>' },
    SheetFooter: { template: '<footer><slot /></footer>' },
    SheetHeader: { template: '<header><slot /></header>' },
    SheetTitle: { template: '<h2><slot /></h2>' },
};

describe('OfferEditorDrawer', () => {
    beforeEach(() => {
        postRequest.mockReset();
        patchRequest.mockReset();
        postRequest.mockResolvedValue({ uuid: 'offer-1', lock_version: 1 });
    });

    it('uses explicit Save and sends the complete offer contract for owners', async () => {
        const wrapper = mount(OfferEditorDrawer, {
            props: { open: true, isOwner: true },
            global: { stubs },
        });

        await wrapper
            .get('[data-offer-field="name"]')
            .setValue('Pipeline sprint');
        await wrapper
            .get('[data-offer-field="promise"]')
            .setValue('Build pipeline');
        await wrapper
            .get('[data-offer-field="target_audience"]')
            .setValue('Revenue teams');
        await wrapper
            .get('[data-offer-field="desired_outcome"]')
            .setValue('Qualified demand');
        await wrapper
            .get('[data-offer-field="call_to_action"]')
            .setValue('Book a session');
        await wrapper.get('.offer-save-button').trigger('click');
        await flushPromises();

        expect(postRequest).toHaveBeenCalledTimes(1);
        expect(postRequest.mock.calls[0][0]).toBe('/offers');
        expect(postRequest.mock.calls[0][1]).toMatchObject({
            name: 'Pipeline sprint',
            promise: 'Build pipeline',
            target_audience: 'Revenue teams',
            desired_outcome: 'Qualified demand',
            call_to_action: 'Book a session',
        });
        expect(postRequest.mock.calls[0][1].client_operation_id).toEqual(
            expect.any(String),
        );
        expect(wrapper.emitted('update:open')).toContainEqual([false]);
        wrapper.unmount();
    });

    it('allows an incomplete draft save and aligns every browser bound with the server contract', async () => {
        const wrapper = mount(OfferEditorDrawer, {
            props: { open: true, isOwner: true },
            global: { stubs },
        });

        await wrapper
            .get('[data-offer-field="name"]')
            .setValue('Working title');
        await wrapper.get('.offer-save-button').trigger('click');
        await flushPromises();

        expect(postRequest).toHaveBeenCalledTimes(1);
        expect(postRequest.mock.calls[0][1]).toMatchObject({
            name: 'Working title',
            promise: '',
            target_audience: '',
            desired_outcome: '',
            call_to_action: '',
            notes: '',
        });
        expect(
            wrapper
                .get('[data-offer-field="call_to_action"]')
                .attributes('maxlength'),
        ).toBe('5000');
        expect(
            wrapper.get('[data-offer-field="notes"]').attributes('maxlength'),
        ).toBe('2000');
        expect(wrapper.text()).toContain('Drafts can be saved');
        wrapper.unmount();
    });

    it('reuses the same client operation when a response is lost and the owner retries', async () => {
        postRequest
            .mockRejectedValueOnce(new Error('connection lost'))
            .mockResolvedValueOnce({ uuid: 'offer-1', lock_version: 0 });
        const wrapper = mount(OfferEditorDrawer, {
            props: { open: true, isOwner: true },
            global: { stubs },
        });

        await wrapper.get('.offer-save-button').trigger('click');
        await flushPromises();
        await wrapper.get('.offer-save-button').trigger('click');
        await flushPromises();

        expect(postRequest).toHaveBeenCalledTimes(2);
        expect(postRequest.mock.calls[1][1].client_operation_id).toBe(
            postRequest.mock.calls[0][1].client_operation_id,
        );
        wrapper.unmount();
    });

    it('replays a frozen lost-response attempt and keeps later edits queued', async () => {
        let rejectLostResponse: ((reason: unknown) => void) | undefined;
        postRequest
            .mockImplementationOnce(
                () =>
                    new Promise((_resolve, reject) => {
                        rejectLostResponse = reject;
                    }),
            )
            .mockResolvedValueOnce({
                offer: {
                    uuid: 'offer-recovered',
                    name: 'First payload',
                    promise: '',
                    target_audience: '',
                    desired_outcome: '',
                    call_to_action: '',
                    notes: '',
                    status: 'draft',
                    lock_version: 0,
                    version_count: 1,
                },
            });
        const wrapper = mount(OfferEditorDrawer, {
            props: { open: true, isOwner: true },
            global: { stubs },
        });

        await wrapper
            .get('[data-offer-field="name"]')
            .setValue('First payload');
        await wrapper.get('.offer-save-button').trigger('click');
        await wrapper
            .get('[data-offer-field="notes"]')
            .setValue('Typed after the request left');
        rejectLostResponse?.(new Error('response lost'));
        await flushPromises();
        await wrapper.get('.offer-save-button').trigger('click');
        await flushPromises();

        expect(postRequest).toHaveBeenCalledTimes(2);
        expect(postRequest.mock.calls[1][1]).toEqual(
            postRequest.mock.calls[0][1],
        );
        expect(postRequest.mock.calls[1][1].notes).toBe('');
        expect(
            wrapper.get('[data-offer-field="notes"]').element,
        ).toHaveProperty('value', 'Typed after the request left');
        expect(wrapper.get('[data-test="offer-save-notice"]').text()).toContain(
            'later edits are still unsaved',
        );
        expect(wrapper.emitted('saved')?.at(-1)?.[1]).toBe(true);
        expect(wrapper.emitted('update:open')).toBeUndefined();
        wrapper.unmount();
    });

    it('offers reload and field-aware reapply after a concurrent owner conflict', async () => {
        const original = {
            uuid: 'offer-1',
            name: 'Pipeline sprint',
            promise: 'Original promise',
            target_audience: 'Revenue teams',
            desired_outcome: 'Qualified demand',
            call_to_action: 'Book a session',
            notes: 'Original notes',
            status: 'draft' as const,
            lock_version: 3,
            version_count: 4,
        };
        const latest = {
            ...original,
            promise: 'Promise from another owner',
            notes: 'Latest owner notes',
            lock_version: 4,
            version_count: 5,
        };
        const { JsonHttpError } = await import('@/lib/jsonClient');
        patchRequest
            .mockRejectedValueOnce(
                new JsonHttpError(409, {
                    error: {
                        message: 'This Offer changed elsewhere.',
                        current_offer: latest,
                    },
                }),
            )
            .mockResolvedValueOnce({
                offer: {
                    ...latest,
                    name: 'Locally changed name',
                    lock_version: 5,
                },
            });
        const wrapper = mount(OfferEditorDrawer, {
            props: { open: true, isOwner: true, offer: original },
            global: { stubs },
        });

        await wrapper
            .get('[data-offer-field="name"]')
            .setValue('Locally changed name');
        await wrapper.get('.offer-save-button').trigger('click');
        await flushPromises();

        expect(wrapper.find('.offer-conflict-actions').exists()).toBe(true);
        await wrapper
            .get('.offer-conflict-actions button:last-child')
            .trigger('click');
        expect(wrapper.get('[data-offer-field="name"]').element).toHaveProperty(
            'value',
            'Locally changed name',
        );
        expect(
            wrapper.get('[data-offer-field="promise"]').element,
        ).toHaveProperty('value', 'Promise from another owner');
        expect(
            wrapper.get('[data-offer-field="notes"]').element,
        ).toHaveProperty('value', 'Latest owner notes');

        await wrapper.get('.offer-save-button').trigger('click');
        await flushPromises();

        expect(patchRequest.mock.calls[1][1]).toMatchObject({
            name: 'Locally changed name',
            promise: 'Promise from another owner',
            notes: 'Latest owner notes',
            expected_lock_version: 4,
        });
        expect(patchRequest.mock.calls[1][1].client_operation_id).not.toBe(
            patchRequest.mock.calls[0][1].client_operation_id,
        );
        wrapper.unmount();
    });

    it('guards dirty close and history actions with Save, Discard, and Cancel', async () => {
        const existing = {
            uuid: 'offer-1',
            name: 'Pipeline sprint',
            promise: 'Build pipeline',
            target_audience: 'Revenue teams',
            desired_outcome: 'Qualified demand',
            call_to_action: 'Book a session',
            notes: '',
            status: 'draft' as const,
            lock_version: 1,
            version_count: 2,
        };
        const wrapper = mount(OfferEditorDrawer, {
            props: { open: true, isOwner: true, offer: existing },
            global: { stubs },
        });

        await wrapper
            .get('[data-offer-field="notes"]')
            .setValue('Unsaved note');
        await wrapper.get('.offer-cancel-button').trigger('click');

        expect(
            wrapper.get('[data-test="offer-unsaved-guard"]').text(),
        ).toContain('Unsaved Offer changes');
        expect(wrapper.text()).toContain('Save');
        expect(wrapper.text()).toContain('Discard');
        expect(wrapper.emitted('update:open')).toBeUndefined();

        await wrapper.get('.offer-guard-cancel').trigger('click');
        expect(wrapper.find('[data-test="offer-unsaved-guard"]').exists()).toBe(
            false,
        );
        await wrapper.get('.offer-history-button').trigger('click');
        expect(wrapper.emitted('history')).toBeUndefined();
        await wrapper.get('.offer-guard-discard').trigger('click');
        expect(wrapper.emitted('history')).toContainEqual([existing]);
        wrapper.unmount();
    });

    it('connects every 422 field error to its control, including Notes', async () => {
        const { JsonHttpError } = await import('@/lib/jsonClient');
        const fieldErrors = {
            name: ['Name error'],
            promise: ['Promise error'],
            target_audience: ['Audience error'],
            desired_outcome: ['Outcome error'],
            call_to_action: ['CTA error'],
            notes: ['Notes error'],
        };
        postRequest.mockRejectedValueOnce(
            new JsonHttpError(422, {
                error: { message: 'Invalid Offer.', errors: fieldErrors },
            }),
        );
        const wrapper = mount(OfferEditorDrawer, {
            props: { open: true, isOwner: true },
            global: { stubs },
        });

        await wrapper.get('.offer-save-button').trigger('click');
        await flushPromises();

        for (const field of Object.keys(fieldErrors)) {
            const control = wrapper.get(`[data-offer-field="${field}"]`);
            const describedBy = control.attributes('aria-describedby');

            expect(control.attributes('aria-invalid')).toBe('true');
            expect(describedBy).toBeTruthy();
            expect(wrapper.get(`#${describedBy}`).attributes('role')).toBe(
                'alert',
            );
        }

        wrapper.unmount();
    });

    it('keeps member editors read-only and does not submit', async () => {
        const wrapper = mount(OfferEditorDrawer, {
            props: { open: true, isOwner: false },
            global: { stubs },
        });

        expect(wrapper.get('.offer-read-only').text()).toContain(
            'Only workspace owners',
        );
        expect(
            wrapper.get('.offer-save-button').attributes('disabled'),
        ).toBeDefined();
        await wrapper.get('form').trigger('submit');
        expect(postRequest).not.toHaveBeenCalled();
        wrapper.unmount();
    });
});
