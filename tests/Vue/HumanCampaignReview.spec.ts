import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
const post = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    useForm: (data: Record<string, unknown>) =>
        reactive({ ...data, errors: {}, processing: false, post }),
}));
import Review from '@/pages/campaigns/Review.vue';
const base = {
    campaign: { id: 7, name: 'Consultations' },
    revision: 3,
    operation_id: 'request-1',
};
const global = {
    stubs: { AppWorkspace: { template: '<main><slot /></main>' } },
};
const reviewState = {
    status: 'awaiting_approval',
    approval_status: 'pending',
    approval_expires_at: null,
    display_manifest_hash: 'display-hash',
    action_fingerprints: ['action-hash'],
    blockers: [],
    can_approve: false,
    can_launch: false,
    objective: 'Meetings',
    offer: 'Consultation',
};
describe('Human campaign review', () => {
    it('offers draft recovery for an expired uncompiled review without offering launch', async () => {
        const wrapper = mount(Review, {
            props: {
                ...base,
                review: {
                    ...reviewState,
                    id: 'expired-plan',
                    plan_hash: 'hash',
                    prepared_at: 'today',
                    messages: [],
                    approval_status: 'expired',
                    can_return_to_draft: true,
                },
            },
            global,
        });
        expect(wrapper.text()).toContain('Approval expired');
        expect(wrapper.text()).toContain(
            'still counts toward its sending allowance',
        );
        expect(wrapper.text()).not.toContain('Launch campaign');
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Return to draft')!
            .trigger('click');
        expect(post).toHaveBeenLastCalledWith(
            '/campaigns/7/review/expired-plan/return-to-draft',
            { preserveScroll: true },
        );
    });
    it('prepares saved selections without offering a send action', async () => {
        const wrapper = mount(Review, {
            props: { ...base, review: null },
            global,
        });
        expect(wrapper.text()).toContain(
            'Preparing review does not send email',
        );
        await wrapper.get('button').trigger('click');
        expect(post).toHaveBeenCalledWith('/campaigns/7/review', {
            preserveScroll: true,
        });
        expect(wrapper.findAll('button')).toHaveLength(1);
    });
    it('switches exact messages and renders authored HTML as text', async () => {
        const message = {
            id: 'a',
            to: 'a@example.com',
            from: 'sender@example.com',
            sender_name: 'Sender',
            reply_to: 'sender@example.com',
            subject: 'First',
            body: '<script>unsafe()</script>',
            starts_at: '2026-09-07',
            ends_at: '2026-09-08',
        };
        const wrapper = mount(Review, {
            props: {
                ...base,
                review: {
                    ...reviewState,
                    id: 'plan',
                    plan_hash: 'hash',
                    prepared_at: 'today',
                    messages: [
                        message,
                        {
                            ...message,
                            id: 'b',
                            to: 'b@example.com',
                            subject: 'Second',
                            body: 'Hello B',
                        },
                    ],
                    campaign_window: { starts_at: null, ends_at: null },
                },
            },
            global,
        });
        expect(wrapper.get('.review-body').text()).toBe(message.body);
        expect(wrapper.text()).toContain(
            'Run until finished — no campaign deadline.',
        );
        expect(wrapper.text()).toContain(
            'Sending permission ends: 8 Sept 2026, 00:00 (UTC)',
        );
        expect(wrapper.find('script').exists()).toBe(false);
        await wrapper.findAll('button')[1].trigger('click');
        expect(wrapper.get('.review-body').text()).toBe('Hello B');
        expect(wrapper.text()).toContain('Not approved to send');
    });
    it('uses the fresh preparation token after discarding without a reload', async () => {
        const wrapper = mount(Review, {
            props: { ...base, review: null },
            global,
        });
        await wrapper.get('button').trigger('click');
        expect(post.mock.contexts.at(-1)).toMatchObject({
            operation_id: 'request-1',
            expected_revision: 3,
        });
        await wrapper.setProps({
            review: {
                ...reviewState,
                id: 'plan',
                plan_hash: 'hash',
                prepared_at: 'today',
                messages: [],
                can_discard: true,
            },
        });
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Discard review')!
            .trigger('click');
        expect(post).toHaveBeenLastCalledWith(
            '/campaigns/7/review/plan/discard',
            { preserveScroll: true },
        );
        await wrapper.setProps({
            review: null,
            operation_id: 'replacement-request',
            revision: 4,
        });
        await wrapper.get('button').trigger('click');
        expect(post.mock.contexts.at(-1)).toMatchObject({
            operation_id: 'replacement-request',
            expected_revision: 4,
        });
    });
    it('requires an explicit approval confirmation and keeps launch separate', async () => {
        const review = {
            ...reviewState,
            id: 'plan',
            plan_hash: 'hash',
            prepared_at: 'today',
            can_approve: true,
            messages: [
                {
                    id: 'a',
                    to: 'a@example.com',
                    from: 'sender@example.com',
                    sender_name: 'Sender',
                    reply_to: 'sender@example.com',
                    subject: 'Hello',
                    body: 'A consultation?',
                    starts_at: 'today',
                    ends_at: 'tomorrow',
                },
            ],
        };
        const wrapper = mount(Review, { props: { ...base, review }, global });
        const approve = wrapper
            .findAll('button')
            .find((button) => button.text() === 'Approve these messages')!;
        expect(approve.attributes('disabled')).toBeDefined();
        expect(wrapper.text()).not.toContain('Launch campaign');
        await wrapper.get('input[type="checkbox"]').setValue(true);
        await approve.trigger('click');
        expect(post).toHaveBeenLastCalledWith(
            '/campaigns/7/review/plan/approve',
            { preserveScroll: true },
        );
        await wrapper.setProps({
            review: {
                ...review,
                status: 'approved',
                approval_status: 'approved',
                can_approve: false,
                can_launch: true,
            },
        });
        expect(wrapper.text()).toContain('Approved · not launched');
        const launch = wrapper
            .findAll('button')
            .find((button) => button.text() === 'Launch campaign')!;
        await launch.trigger('click');
        expect(post).toHaveBeenLastCalledWith(
            '/campaigns/7/review/plan/launch',
            { preserveScroll: true },
        );
    });
});
