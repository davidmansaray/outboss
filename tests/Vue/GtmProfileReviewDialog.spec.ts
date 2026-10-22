import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { postRequest } = vi.hoisted(() => ({
    postRequest: vi.fn(),
}));

vi.mock('@/lib/jsonClient', () => ({
    jsonClient: { post: postRequest },
    JsonHttpError: class JsonHttpError extends Error {
        constructor(
            public status = 409,
            public errors: Record<string, string> = {},
        ) {
            super('Request failed');
        }
    },
}));

import GtmProfileReviewDialog from '@/components/gtm-profile/GtmProfileReviewDialog.vue';
import { JsonHttpError } from '@/lib/jsonClient';
import type { GtmProfileReviewItem } from '@/types/gtmProfile';

const stubs = {
    Dialog: {
        props: ['open'],
        template: '<div v-if="open"><slot /></div>',
    },
    DialogContent: { template: '<div role="dialog"><slot /></div>' },
    DialogDescription: { template: '<p><slot /></p>' },
    DialogFooter: { template: '<footer><slot /></footer>' },
    DialogHeader: { template: '<header><slot /></header>' },
    DialogTitle: { template: '<h2><slot /></h2>' },
};

const items: GtmProfileReviewItem[] = [
    {
        uuid: 'review-1',
        field_path: 'buyers.target_roles',
        type: 'missing',
        question: 'Which roles buy first?',
        proposed_value: null,
        status: 'open',
    },
    {
        uuid: 'review-2',
        field_path: 'messaging.prohibited_claims',
        type: 'conflict',
        question: 'What should Outboss avoid claiming?',
        proposed_value: 'Fully autonomous outreach',
        status: 'open',
    },
];

const threeItems: GtmProfileReviewItem[] = [
    {
        uuid: 'review-1',
        field_path: 'buyers.target_roles',
        type: 'conflict',
        question: 'Which roles buy first?',
        proposed_value: 'VP Revenue',
        status: 'open',
    },
    items[1],
    {
        uuid: 'review-3',
        field_path: 'market.geographies',
        type: 'inferred',
        question: 'Should Europe be included?',
        proposed_value: 'Europe',
        status: 'open',
    },
];

describe('GtmProfileReviewDialog', () => {
    beforeEach(() => {
        postRequest.mockReset();
        postRequest.mockResolvedValue({ lock_version: 2 });
    });

    it('advances one question at a time and keeps skipped items open', async () => {
        const wrapper = mount(GtmProfileReviewDialog, {
            props: { open: true, reviewItems: items, isOwner: true },
            global: { stubs },
        });

        expect(wrapper.text()).toContain('Which roles buy first?');
        await wrapper.get('button').trigger('click');

        expect(wrapper.text()).toContain('What should Outboss avoid claiming?');
        expect(wrapper.emitted('resolved')).toBeUndefined();

        await wrapper.get('button.gtm-review-accept').trigger('click');
        await flushPromises();

        expect(postRequest).toHaveBeenCalledTimes(1);
        expect(postRequest.mock.calls[0][1]).toMatchObject({
            action: 'accept_research',
            value: 'Fully autonomous outreach',
        });
        expect(wrapper.emitted('resolved')).toHaveLength(1);
        wrapper.unmount();
    });

    it('does not expose resolving controls to members', () => {
        const wrapper = mount(GtmProfileReviewDialog, {
            props: { open: true, reviewItems: items, isOwner: false },
            global: { stubs },
        });

        expect(wrapper.find('.gtm-review-accept').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Keep mine');
        expect(
            wrapper
                .get('[data-test="gtm-review-save-answer"]')
                .attributes('disabled'),
        ).toBeDefined();
        wrapper.unmount();
    });

    it('answers a missing review with edit_owner and hides invalid proposal actions', async () => {
        const wrapper = mount(GtmProfileReviewDialog, {
            props: {
                open: true,
                reviewItems: [items[0]],
                isOwner: true,
                lockVersion: 1,
            },
            global: { stubs },
        });

        expect(wrapper.text()).not.toContain('Research proposes');
        expect(wrapper.text()).not.toContain('Keep mine');
        expect(wrapper.text()).not.toContain('Accept research');
        expect(wrapper.text()).not.toContain('Edit mine');

        await wrapper
            .get('[data-test="gtm-review-answer-input"]')
            .setValue('VP Revenue');
        await wrapper
            .get('[data-test="gtm-review-save-answer"]')
            .trigger('click');
        await flushPromises();

        expect(postRequest).toHaveBeenCalledTimes(1);
        expect(postRequest.mock.calls[0][1]).toMatchObject({
            action: 'edit_owner',
            edited_value: { value: 'VP Revenue' },
            expected_lock_version: 1,
        });
        expect(wrapper.emitted('resolved')).toHaveLength(1);
        wrapper.unmount();
    });

    it('resolves an edited conflict answer instead of opening an unrelated editor', async () => {
        const wrapper = mount(GtmProfileReviewDialog, {
            props: {
                open: true,
                reviewItems: [items[1]],
                isOwner: true,
                lockVersion: 4,
            },
            global: { stubs },
        });

        const editButton = wrapper
            .findAll('button')
            .find((button) => button.text().includes('Edit mine'))!;
        await editButton.trigger('click');
        await wrapper
            .get('[data-test="gtm-review-answer-input"]')
            .setValue('Never promise full autonomy');
        await wrapper
            .get('[data-test="gtm-review-save-answer"]')
            .trigger('click');
        await flushPromises();

        expect(postRequest.mock.calls[0][1]).toMatchObject({
            action: 'edit_owner',
            edited_value: { value: 'Never promise full autonomy' },
            expected_lock_version: 4,
        });
        expect(wrapper.emitted('resolved')).toHaveLength(1);
        wrapper.unmount();
    });

    it('accepts three queued reviews by uuid with each returned lock after reconciliation', async () => {
        let finishFirstReconciliation: (() => void) | undefined;
        const firstReconciliation = new Promise<void>((resolve) => {
            finishFirstReconciliation = resolve;
        });
        const reconcile = vi
            .fn()
            .mockImplementationOnce(() => firstReconciliation)
            .mockResolvedValue(undefined);
        postRequest
            .mockResolvedValueOnce({ lock_version: 2 })
            .mockResolvedValueOnce({ lock_version: 3 })
            .mockResolvedValueOnce({ lock_version: 4 });
        const wrapper = mount(GtmProfileReviewDialog, {
            props: {
                open: true,
                reviewItems: threeItems,
                isOwner: true,
                lockVersion: 1,
                reconcile,
            },
            global: { stubs },
        });

        await wrapper.get('button.gtm-review-accept').trigger('click');
        await flushPromises();

        expect(postRequest).toHaveBeenCalledTimes(1);
        expect(
            wrapper.get('button.gtm-review-accept').attributes('disabled'),
        ).toBeDefined();
        expect(wrapper.text()).toContain('Which roles buy first?');
        await wrapper.get('button.gtm-review-accept').trigger('click');
        expect(postRequest).toHaveBeenCalledTimes(1);

        finishFirstReconciliation?.();
        await flushPromises();
        expect(wrapper.text()).toContain('What should Outboss avoid claiming?');

        await wrapper.get('button.gtm-review-accept').trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('Should Europe be included?');

        await wrapper.get('button.gtm-review-accept').trigger('click');
        await flushPromises();

        expect(postRequest.mock.calls.map(([url]) => url)).toEqual([
            '/settings/gtm-profile/review/review-1',
            '/settings/gtm-profile/review/review-2',
            '/settings/gtm-profile/review/review-3',
        ]);
        expect(
            postRequest.mock.calls.map(
                ([, payload]) => payload.expected_lock_version,
            ),
        ).toEqual([1, 2, 3]);
        expect(reconcile).toHaveBeenCalledTimes(3);
        expect(wrapper.emitted('resolved')).toHaveLength(3);
        expect(wrapper.emitted('update:open')).toEqual([[false]]);
        wrapper.unmount();
    });

    it('retries an ambiguous lost response with the exact frozen request body and operation id', async () => {
        postRequest
            .mockRejectedValueOnce(new Error('socket closed'))
            .mockResolvedValueOnce({ lock_version: 2 });
        const wrapper = mount(GtmProfileReviewDialog, {
            props: {
                open: true,
                reviewItems: [items[1]],
                isOwner: true,
                lockVersion: 1,
            },
            global: { stubs },
        });

        await wrapper.get('button.gtm-review-accept').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('exact same answer');
        const firstBody = postRequest.mock.calls[0][1];
        await wrapper
            .get('[data-test="gtm-review-retry-request"]')
            .trigger('click');
        await flushPromises();

        expect(postRequest).toHaveBeenCalledTimes(2);
        expect(postRequest.mock.calls[1][1]).toBe(firstBody);
        expect(postRequest.mock.calls[1][1].client_operation_id).toBe(
            firstBody.client_operation_id,
        );
        expect(wrapper.emitted('resolved')).toHaveLength(1);
        wrapper.unmount();
    });

    it('clears a definitively rejected request before allowing a corrected attempt', async () => {
        postRequest
            .mockRejectedValueOnce(
                new JsonHttpError(422, { action: 'Invalid answer.' }),
            )
            .mockResolvedValueOnce({ lock_version: 2 });
        const wrapper = mount(GtmProfileReviewDialog, {
            props: {
                open: true,
                reviewItems: [items[1]],
                isOwner: true,
                lockVersion: 1,
            },
            global: { stubs },
        });

        await wrapper.get('button.gtm-review-accept').trigger('click');
        await flushPromises();
        const firstBody = postRequest.mock.calls[0][1];

        expect(wrapper.text()).toContain('Invalid answer.');
        expect(
            wrapper.find('[data-test="gtm-review-retry-request"]').exists(),
        ).toBe(false);

        await wrapper.get('button.gtm-review-accept').trigger('click');
        await flushPromises();

        expect(postRequest.mock.calls[1][1]).not.toBe(firstBody);
        expect(postRequest.mock.calls[1][1].client_operation_id).not.toBe(
            firstBody.client_operation_id,
        );
        wrapper.unmount();
    });
});
