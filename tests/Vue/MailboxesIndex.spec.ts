import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { router } = vi.hoisted(() => ({
    router: {
        post: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
        reload: vi.fn(),
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div />' },
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    router,
    usePage: () => ({
        props: { flash: {} },
    }),
}));

import MailboxesIndex from '@/pages/mailboxes/Index.vue';

const stubs = {
    Link: {
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
};

describe('MailboxesIndex connect actions', () => {
    beforeEach(() => {
        router.post.mockReset();
        router.patch.mockReset();
        router.delete.mockReset();
        router.reload.mockReset();
    });

    it('shows one connect control per provider in the connect inbox row', () => {
        const wrapper = mount(MailboxesIndex, {
            props: { mailboxes: [] },
            global: { stubs },
        });

        expect(wrapper.find('.top').exists()).toBe(true);
        expect(
            wrapper.findAll('#connect a[href="/oauth/zoho/redirect"]'),
        ).toHaveLength(1);
        expect(
            wrapper.findAll('#connect a[href="/oauth/gmail/redirect"]'),
        ).toHaveLength(1);
        expect(
            wrapper.findAll('#connect a[href="/oauth/microsoft/redirect"]'),
        ).toHaveLength(1);
        expect(wrapper.findAll('a[href="/oauth/zoho/redirect"]')).toHaveLength(
            1,
        );
        expect(wrapper.findAll('a[href="/oauth/gmail/redirect"]')).toHaveLength(
            1,
        );
        expect(
            wrapper.findAll('a[href="/oauth/microsoft/redirect"]'),
        ).toHaveLength(1);
    });

    const connectedMailbox = {
        id: 9,
        email: 'david@outboss.io',
        provider: 'zoho',
        status: 'connected',
        error: null,
        replies_today: 0,
        reply_identities: [
            {
                key: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                email: 'david@outboss.io',
                name: 'David Mansaray',
                mode: 'primary',
            },
            {
                key: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                email: 'partnerships@outboss.io',
                name: 'Partnerships',
                mode: 'alias',
            },
        ],
        selected_reply_identity_key:
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        effective_reply_identity: {
            key: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            email: 'david@outboss.io',
            name: 'David Mansaray',
        },
        sync: {
            phase: 'idle',
            percent: null,
            phase_label: 'Never synced',
            detail: 'Inbox + Sent',
            button_label: 'Sync',
            last_sync_label: 'Never synced',
            scope_label: 'Inbox + Sent',
            active_scope: null,
        },
    };

    it('asks for confirmation before disconnecting a mailbox', async () => {
        const wrapper = mount(MailboxesIndex, {
            props: { mailboxes: [connectedMailbox] },
            global: { stubs },
        });

        const button = wrapper
            .findAll('button')
            .find((item) => item.text() === 'Disconnect');

        expect(button).toBeTruthy();
        await button!.trigger('click');

        expect(router.delete).not.toHaveBeenCalled();
        expect(wrapper.get('[role="dialog"]').text()).toContain(
            'Disconnect david@outboss.io?',
        );

        await wrapper.get('[data-test="cancel-disconnect"]').trigger('click');

        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        expect(router.delete).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('david@outboss.io');
    });

    it('removes a mailbox after the user confirms disconnect', async () => {
        const wrapper = mount(MailboxesIndex, {
            props: { mailboxes: [connectedMailbox] },
            global: { stubs },
        });

        const button = wrapper
            .findAll('button')
            .find((item) => item.text() === 'Disconnect');

        await button!.trigger('click');
        await wrapper.get('[data-test="confirm-disconnect"]').trigger('click');

        expect(router.delete).toHaveBeenCalledWith(
            '/mailboxes/9',
            expect.objectContaining({ preserveState: false }),
        );
        expect(wrapper.text()).not.toContain('david@outboss.io');
        expect(wrapper.text()).toContain('No inboxes connected yet.');
    });

    it('does not render a disconnected mailbox', () => {
        const wrapper = mount(MailboxesIndex, {
            props: {
                mailboxes: [
                    {
                        id: 9,
                        email: 'gone@outboss.io',
                        provider: 'zoho',
                        status: 'disconnected',
                        error: null,
                        replies_today: 0,
                        sync: {
                            phase: 'idle',
                            percent: null,
                            phase_label: 'Never synced',
                            detail: 'Inbox + Sent',
                            button_label: 'Sync',
                            last_sync_label: 'Never synced',
                            scope_label: 'Inbox + Sent',
                            active_scope: null,
                        },
                    },
                ],
            },
            global: { stubs },
        });

        expect(wrapper.text()).toContain('No inboxes connected yet.');
        expect(wrapper.text()).not.toContain('gone@outboss.io');
        expect(wrapper.text()).not.toContain('Disconnect');
    });

    it('shows the selected sender identity as the primary trigger and the connected account as secondary context', () => {
        const wrapper = mount(MailboxesIndex, {
            props: { mailboxes: [connectedMailbox] },
            global: { stubs },
        });

        const row = wrapper.get('[data-test="mailbox-row"]');
        const identity = row.get('.mailbox-identity');
        const trigger = row.get('[data-test="mailbox-reply-identity-button"]');
        const metadata = row.get('.mailbox-meta');

        expect(row.text()).not.toContain('Reply as');
        expect(trigger.text()).toContain('David Mansaray <david@outboss.io>');
        expect(
            identity.element.firstElementChild?.contains(trigger.element),
        ).toBe(true);
        expect(metadata.text()).toContain('david@outboss.io');
        expect(metadata.text()).toContain('Zoho');
        expect(metadata.text()).toContain('Connected');
    });

    it('hides the sender identity affordance while preserving an unavailable-identity error', () => {
        const wrapper = mount(MailboxesIndex, {
            props: {
                mailboxes: [
                    {
                        ...connectedMailbox,
                        reply_identities: [],
                        selected_reply_identity_key: null,
                        effective_reply_identity: undefined,
                        reply_identity_error:
                            'Sender identities are currently unavailable.',
                    },
                ],
            },
            global: { stubs },
        });

        const row = wrapper.get('[data-test="mailbox-row"]');

        expect(
            row.find('[data-test="mailbox-reply-identity-button"]').exists(),
        ).toBe(false);
        expect(row.text()).toContain(
            'Sender identities are currently unavailable.',
        );
    });

    it('shows internal managed-mail navigation only for an eligible owner', () => {
        const hidden = mount(MailboxesIndex, {
            props: { mailboxes: [] },
            global: { stubs },
        });
        const visible = mount(MailboxesIndex, {
            props: { mailboxes: [], canManageInternal: true },
            global: { stubs },
        });

        expect(
            hidden.find('a[href="/settings/managed-mail"]').exists(),
        ).toBe(false);
        expect(
            visible.find('a[href="/settings/managed-mail"]').exists(),
        ).toBe(true);
    });

    it('opens the existing modal from the primary identity trigger and saves the selection', async () => {
        router.patch.mockImplementation((_url, _data, options) => {
            options?.onSuccess?.();
            options?.onFinish?.();
        });

        const wrapper = mount(MailboxesIndex, {
            props: { mailboxes: [connectedMailbox] },
            global: { stubs },
        });

        const row = wrapper.get('[data-test="mailbox-row"]');
        const identity = row.get('.mailbox-identity');
        const trigger = row.get('[data-test="mailbox-reply-identity-button"]');

        expect(
            identity.element.firstElementChild?.contains(trigger.element),
        ).toBe(true);
        await trigger.trigger('click');

        expect(wrapper.get('[role="dialog"]').text()).toContain(
            'Reply identity',
        );
        const radios = wrapper.findAll('input[type="radio"]');
        await radios[1].setValue();
        await wrapper.get('[data-test="save-reply-identity"]').trigger('click');

        expect(router.patch).toHaveBeenCalledWith(
            '/mailboxes/9/reply-identity',
            {
                identity_key:
                    'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            },
            expect.any(Object),
        );
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
    });
});
