import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { formPost } = vi.hoisted(() => ({
    formPost: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div />' },
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    useForm: (data: Record<string, unknown>) => ({
        ...data,
        processing: false,
        errors: {},
        post: formPost,
        reset() {
            this.name = '';
        },
        clearErrors: vi.fn(),
    }),
}));

import CampaignIndex from '@/pages/campaigns/Index.vue';

const campaigns = [
    {
        id: 1,
        name: 'Series A founders',
        status: 'active',
        people_count: 120,
        steps_count: 3,
        mailboxes_count: 2,
        sender_emails: ['a@outboss.test', 'b@outboss.test'],
        senders_more: 0,
        sent_count: 40,
        replies_count: 8,
        interested_count: 3,
        queued_count: 12,
        bounced_count: 2,
        unsubscribed_count: 1,
        updated_at: '2026-08-22T10:00:00Z',
    },
    {
        id: 2,
        name: 'Commerce leaders',
        status: 'draft',
        people_count: 24,
        steps_count: 1,
        mailboxes_count: 0,
        sender_emails: [],
        senders_more: 0,
        sent_count: 0,
        replies_count: 0,
        interested_count: 0,
        queued_count: 24,
        bounced_count: 0,
        unsubscribed_count: 0,
        updated_at: '2026-08-21T10:00:00Z',
    },
];

const stubs = {
    AppWorkspace: {
        template:
            '<div><div class="top"><slot name="actions" /></div><aside><slot name="sidebar" /></aside><slot /></div>',
    },
};

describe('Campaigns index', () => {
    beforeEach(() => formPost.mockReset());

    it('shows campaign scale, status, and the correct next action', () => {
        const wrapper = mount(CampaignIndex, {
            props: { campaigns },
            global: { stubs },
        });

        const rows = wrapper.findAll('.campaign-row');
        expect(rows).toHaveLength(2);
        expect(rows[0].text()).toContain('Series A founders');
        expect(rows[0].text()).toContain('Active');
        expect(rows[0].text()).toContain('120');
        expect(rows[0].text()).toContain('people');
        expect(rows[0].text()).toContain('a@outboss.test');
        expect(rows[0].text()).toContain('40');
        expect(rows[0].text()).toContain(
            '3 interested · 8 replies · 2 bounced · 1 unsubscribed',
        );
        expect(rows[0].text()).toContain('View campaign');
        expect(rows[1].text()).toContain('Continue setup');
    });

    it('filters campaign states from the secondary sidebar', async () => {
        const wrapper = mount(CampaignIndex, {
            props: { campaigns },
            global: { stubs },
        });
        const drafts = wrapper
            .findAll('.campaign-side-nav button')
            .find((button) => button.text().includes('Drafts'))!;

        await drafts.trigger('click');

        expect(wrapper.findAll('.campaign-row')).toHaveLength(1);
        expect(wrapper.get('.campaign-row').text()).toContain(
            'Commerce leaders',
        );
    });

    it('opens the creation dialog with focus and restores focus on Escape', async () => {
        const wrapper = mount(CampaignIndex, {
            props: { campaigns },
            attachTo: document.body,
            global: { stubs },
        });
        const trigger = wrapper.get('.top .btn');

        await trigger.trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="dialog"]').attributes('aria-modal')).toBe(
            'true',
        );
        expect(document.activeElement).toBe(
            wrapper.get('#campaign-name').element,
        );

        const dialogButtons = wrapper.findAll('[role="dialog"] button');
        const cancelButton = dialogButtons.find(
            (button) => button.text() === 'Cancel',
        )!;
        cancelButton.element.focus();
        document.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }),
        );
        expect(document.activeElement).toBe(dialogButtons[0].element);

        dialogButtons[0].element.focus();
        document.dispatchEvent(
            new KeyboardEvent('keydown', {
                key: 'Tab',
                shiftKey: true,
                bubbles: true,
            }),
        );
        expect(document.activeElement).toBe(cancelButton.element);

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await flushPromises();

        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        expect(document.activeElement).toBe(trigger.element);
        wrapper.unmount();
    });

    it('uses singular person wording for a one-person campaign', () => {
        const wrapper = mount(CampaignIndex, {
            props: {
                campaigns: [
                    {
                        ...campaigns[0],
                        people_count: 1,
                    },
                ],
            },
            global: { stubs },
        });

        expect(wrapper.get('.campaign-row').text()).toContain('1');
        expect(wrapper.get('.campaign-row').text()).toContain('person');
        expect(wrapper.get('.campaign-row').text()).not.toContain('people');
    });
});
