import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { router } = vi.hoisted(() => ({
    router: {
        get: vi.fn(),
        post: vi.fn(),
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
}));

import UniboxIndex from '@/pages/unibox/Index.vue';

const fetchMock = vi.fn();

const stubs = {
    Link: {
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
};

const baseProps = {
    folder: 'leads',
    mailbox: null as number | null,
    campaign: 'all',
    threads: {
        data: [
            {
                id: 11,
                subject: 'Re: hiring',
                from: 'Maya Chen',
                snippet: 'This is timely',
                mailbox: 'david@outboss.io',
                unread: true,
                classification: 'human',
                state: 'needs_action',
                campaign_recipient_id: 4,
                campaign_id: 7,
                campaign_name: 'Q3 SDR hire',
                person: { id: 21, name: 'Maya Chen' },
                account: { id: 9, name: 'Stripe' },
                recipient_status: null,
                participant_email: 'maya@example.test',
                last_message_at: '2026-08-22T09:42:00Z',
            },
        ],
    },
    counts: {
        leads: 1,
        all_replies: 1,
        closed: 0,
        needs: 1,
        all_open: 1,
        ooo: 0,
        delivery: 0,
        opt_outs: 0,
        problems: 0,
        done: 0,
    },
    campaigns: [{ id: 7, name: 'Q3 SDR hire' }],
    mailboxes: [
        { id: 3, email_address: 'david@outboss.io', status: 'connected' },
    ],
};

describe('UniboxIndex filters', () => {
    beforeEach(() => {
        router.get.mockReset();
        router.post.mockReset();
        fetchMock.mockReset();
        vi.stubGlobal('fetch', fetchMock);
    });

    it('keeps folders in the sidebar and campaign plus inbox as list dropdowns defaulting to all', async () => {
        const wrapper = mount(UniboxIndex, {
            props: baseProps,
            global: { stubs },
        });

        expect(wrapper.text()).toContain('Leads');
        expect(wrapper.text()).not.toContain('Inboxes');
        expect(wrapper.findAll('.unibox-primary-nav .nav')).toHaveLength(3);
        expect(
            wrapper
                .findAll('.unibox-primary-nav .nav')
                .map((button) => button.text()),
        ).toEqual(['Inbox1', 'Leads1', 'All replies1']);
        expect(
            wrapper
                .findAll('.unibox-history-nav .nav')
                .map((button) => button.text()),
        ).toEqual(['Closed0', 'Sent0']);
        expect(wrapper.get('.unibox-exceptions summary').text()).toContain(
            'Automated replies0',
        );
        expect(wrapper.text()).toContain('Delivery issues');
        expect(wrapper.text()).toContain('Opt-outs');
        expect(wrapper.get('.unibox-exceptions').text()).toContain(
            'Delivery issues',
        );
        expect(wrapper.find('.unibox-delivery-nav').exists()).toBe(false);
        expect(wrapper.get('.list-heading h1').text()).toBe('Leads');
        expect(wrapper.get('.list-heading').text()).toContain(
            '1 conversations',
        );
        const scopeControls = wrapper.findAll('.list-toolbar .dd-btn');
        expect(scopeControls[0].attributes('title')).toBe(
            'Mailbox: All inboxes',
        );
        expect(scopeControls[1].attributes('title')).toBe(
            'Campaign: All campaigns',
        );
        expect(wrapper.get('[title="Mailbox: All inboxes"]').text()).toContain(
            'All inboxes',
        );
        expect(
            wrapper.get('[title="Campaign: All campaigns"]').text(),
        ).toContain('All campaigns');
        expect(wrapper.find('.list-reset').exists()).toBe(false);
        expect(wrapper.find('.thread.sel').exists()).toBe(false);
        expect(wrapper.get('.read').text()).toContain('Select a conversation.');

        await wrapper.get('[title="Campaign: All campaigns"]').trigger('click');
        const campaignOption = wrapper
            .findAll('.popover button')
            .find((button) => button.text() === 'Q3 SDR hire');
        expect(campaignOption).toBeTruthy();
        await campaignOption!.trigger('click');

        expect(router.get).toHaveBeenCalledWith(
            '/unibox',
            { folder: 'leads', campaign: '7' },
            { preserveState: true, preserveScroll: true },
        );

        await wrapper.get('[title="Mailbox: All inboxes"]').trigger('click');
        const inboxOption = wrapper
            .findAll('.popover button')
            .find((button) => button.text() === 'david@outboss.io');
        await inboxOption!.trigger('click');

        expect(router.get).toHaveBeenCalledWith(
            '/unibox',
            { folder: 'leads', mailbox: 3 },
            { preserveState: true, preserveScroll: true },
        );
    });

    it('offers a single reset when mailbox or campaign scope is narrowed', async () => {
        const wrapper = mount(UniboxIndex, {
            props: {
                ...baseProps,
                mailbox: 3,
                campaign: '7',
            },
            global: { stubs },
        });

        await wrapper.get('.list-reset').trigger('click');

        expect(router.get).toHaveBeenCalledWith(
            '/unibox',
            { folder: 'leads' },
            { preserveState: true, preserveScroll: true },
        );
    });

    it('sends the user to mailboxes when no inbox is connected', () => {
        const wrapper = mount(UniboxIndex, {
            props: {
                ...baseProps,
                threads: { data: [] },
                mailboxes: [
                    {
                        id: 3,
                        email_address: 'david@outboss.io',
                        status: 'disconnected',
                    },
                ],
            },
            global: { stubs },
        });

        expect(wrapper.text()).toContain('Connect inbox');
        expect(
            wrapper.get('.empty a[href="/settings/mailboxes"]').text(),
        ).toContain('Connect inbox');
    });

    it('opens a continuous email thread and slides context into the layout', async () => {
        fetchMock.mockResolvedValue({
            json: async () => ({
                thread: {
                    ...baseProps.threads.data[0],
                    has_inbound: true,
                    messages: [
                        {
                            id: 101,
                            from: 'David',
                            direction: 'outbound',
                            body: 'Hi Maya',
                            html: null,
                            at: '2026-08-21T15:00:00Z',
                        },
                        {
                            id: 102,
                            from: 'Maya Chen',
                            direction: 'inbound',
                            body: 'This is timely',
                            html: null,
                            at: '2026-08-22T09:42:00Z',
                        },
                    ],
                },
            }),
        });

        const wrapper = mount(UniboxIndex, {
            props: baseProps,
            global: { stubs },
        });

        await wrapper.get('.thread').trigger('click');
        await flushPromises();

        expect(wrapper.get('.continuous-thread').text()).toContain(
            'Latest reply',
        );
        expect(wrapper.get('.reply-from').text()).toContain('david@outboss.io');
        expect(wrapper.get('.conversation-details-trigger').text()).toContain(
            'Context',
        );
        expect(
            wrapper
                .get('.conversation-details-trigger')
                .attributes('aria-label'),
        ).toBe('Open context');
        expect(
            wrapper.get('.conversation-details').attributes('aria-hidden'),
        ).toBe('true');

        await wrapper.get('.conversation-details-trigger').trigger('click');

        expect(wrapper.get('.inbox').classes()).toContain(
            'conversation-details-open',
        );
        expect(wrapper.get('.conversation-details').classes()).toContain(
            'open',
        );
        expect(wrapper.find('.conversation-details-trigger').exists()).toBe(
            false,
        );
        expect(
            wrapper.get('.conversation-details').attributes('aria-hidden'),
        ).toBe('false');

        const notNow = wrapper
            .findAll('.conversation-classification button')
            .find((button) => button.text() === 'Not now');
        await notNow!.trigger('click');

        expect(router.post).toHaveBeenCalledWith(
            '/threads/11/classification',
            { action: 'not_now' },
            expect.objectContaining({
                onSuccess: expect.any(Function),
                onError: expect.any(Function),
                onFinish: expect.any(Function),
            }),
        );

        expect(wrapper.get('.conversation-details').text()).toContain(
            'Contact',
        );
        expect(wrapper.get('.conversation-details').text()).toContain(
            'Qualification',
        );
        expect(wrapper.get('.registry-chip').attributes('href')).toBe(
            '/prospects?person=21',
        );
        expect(wrapper.text()).toContain('Stripe');

        await wrapper.get('[aria-label="Close context"]').trigger('click');

        expect(wrapper.get('.inbox').classes()).not.toContain(
            'conversation-details-open',
        );
        expect(wrapper.get('.conversation-details-trigger').text()).toContain(
            'Context',
        );
    });

    it('shows the complete plain-text diagnostic for delivery notices with incomplete HTML', async () => {
        const diagnostic =
            "There was a temporary problem delivering your message to the following recipients:\n\n<david@checkoutwatch.co> (connection to 'checkoutwatch.co' failed: I/O error: Connection timed out (os error 110))";

        fetchMock.mockResolvedValue({
            ok: true,
            json: async () => ({
                thread: {
                    ...baseProps.threads.data[0],
                    subject: 'Warning: Delay in message delivery',
                    classification: 'bounce',
                    has_inbound: true,
                    messages: [
                        {
                            id: 103,
                            from: 'Mail Delivery Subsystem',
                            direction: 'inbound',
                            body: diagnostic,
                            html: 'There was a temporary problem delivering your message to the following recipients:',
                            at: '2026-09-03T12:58:24Z',
                        },
                    ],
                },
            }),
        });

        const wrapper = mount(UniboxIndex, {
            props: {
                ...baseProps,
                threads: {
                    data: [
                        {
                            ...baseProps.threads.data[0],
                            subject: 'Warning: Delay in message delivery',
                            classification: 'bounce',
                        },
                    ],
                },
            },
            global: { stubs },
        });

        await wrapper.get('.thread').trigger('click');
        await flushPromises();

        expect(wrapper.find('iframe[title="Email body"]').exists()).toBe(false);
        expect(wrapper.get('.message-content').text()).toContain(
            "connection to 'checkoutwatch.co' failed",
        );
        expect(wrapper.get('.message-content').text()).toContain(
            'Connection timed out (os error 110)',
        );
    });

    it('continues rendering regular HTML email in the isolated email frame', async () => {
        fetchMock.mockResolvedValue({
            ok: true,
            json: async () => ({
                thread: {
                    ...baseProps.threads.data[0],
                    has_inbound: true,
                    messages: [
                        {
                            id: 104,
                            from: 'Maya Chen',
                            direction: 'inbound',
                            body: 'This is timely',
                            html: '<p><strong>This is timely</strong></p>',
                            at: '2026-08-22T09:42:00Z',
                        },
                    ],
                },
            }),
        });

        const wrapper = mount(UniboxIndex, {
            props: baseProps,
            global: { stubs },
        });

        await wrapper.get('.thread').trigger('click');
        await flushPromises();

        expect(
            wrapper.get('iframe[title="Email body"]').attributes('srcdoc'),
        ).toContain('<p><strong>This is timely</strong></p>');
    });

    it('selects the current outcome and posts replied from the qualification menu', async () => {
        fetchMock.mockResolvedValue({
            json: async () => ({
                thread: {
                    ...baseProps.threads.data[0],
                    has_inbound: true,
                    recipient_status: 'interested',
                    messages: [],
                },
            }),
        });

        const wrapper = mount(UniboxIndex, {
            props: baseProps,
            global: { stubs },
        });

        await wrapper.get('.thread').trigger('click');
        await flushPromises();
        await wrapper.get('.conversation-details-trigger').trigger('click');

        const buttons = wrapper.findAll('.conversation-classification button');
        expect(buttons.map((button) => button.text())).toEqual([
            'Interested',
            'Replied',
            'Not now',
            'Wrong person',
        ]);
        expect(buttons[0].classes()).toContain('active');

        await buttons[1].trigger('click');

        expect(router.post).toHaveBeenCalledWith(
            '/threads/11/classification',
            { action: 'replied' },
            expect.objectContaining({
                onSuccess: expect.any(Function),
            }),
        );
    });

    it('previews a reply locally without sending it', async () => {
        fetchMock.mockResolvedValue({
            json: async () => ({
                thread: {
                    ...baseProps.threads.data[0],
                    has_inbound: true,
                    messages: [
                        {
                            id: 102,
                            from: 'Maya Chen',
                            direction: 'inbound',
                            body: 'This is timely',
                            html: null,
                            at: '2026-08-22T09:42:00Z',
                        },
                    ],
                },
            }),
        });

        const wrapper = mount(UniboxIndex, {
            props: { ...baseProps, replyPreview: true },
            global: { stubs },
        });

        await wrapper.get('.thread').trigger('click');
        await flushPromises();
        await wrapper.get('[aria-label="Reply"]').setValue('Sounds good.');
        await wrapper.get('.reply-actions .btn').trigger('click');
        await flushPromises();

        expect(router.post).not.toHaveBeenCalled();
        expect(wrapper.get('.continuous-thread').text()).toContain(
            'Sounds good.',
        );
        expect(wrapper.get('.preview-reply-badge').text()).toBe('Preview');
        expect(wrapper.get('.preview-reply-note').text()).toContain(
            'no email will be sent',
        );
        expect(
            (wrapper.get('[aria-label="Reply"]').element as HTMLTextAreaElement)
                .value,
        ).toBe('');
    });

    it('shows loading and recoverable error states while opening a conversation', async () => {
        let rejectFetch!: (error: Error) => void;
        fetchMock.mockReturnValue(
            new Promise((_, reject) => {
                rejectFetch = reject;
            }),
        );

        const wrapper = mount(UniboxIndex, {
            props: baseProps,
            global: { stubs },
        });

        await wrapper.get('.thread').trigger('click');

        expect(wrapper.get('.read').attributes('aria-busy')).toBe('true');
        expect(wrapper.get('.thread').attributes('aria-current')).toBe('true');

        rejectFetch(new Error('Network unavailable'));
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain(
            'Conversation unavailable',
        );
        expect(wrapper.get('[role="alert"]').text()).toContain('Try again');
        expect(wrapper.get('.read').attributes('aria-busy')).toBe('false');

        fetchMock.mockResolvedValue({
            ok: true,
            json: async () => ({
                thread: {
                    ...baseProps.threads.data[0],
                    has_inbound: true,
                    messages: [],
                },
            }),
        });
        await wrapper.get('.thread-state .btn').trigger('click');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
        expect(wrapper.get('.continuous-read-head').text()).toContain(
            'Re: hiring',
        );
    });

    it('uses truthful qualification labels in the conversation list', () => {
        const wrapper = mount(UniboxIndex, {
            props: {
                ...baseProps,
                threads: {
                    data: [
                        {
                            ...baseProps.threads.data[0],
                            recipient_status: 'wrong_person',
                        },
                    ],
                },
            },
            global: { stubs },
        });

        expect(wrapper.get('.lead-status').text()).toBe('Wrong person');
        expect(wrapper.get('.lead-status').classes()).toContain('status-muted');
    });

    it('keeps a thread action locked after switching away and back', async () => {
        const secondThread = {
            ...baseProps.threads.data[0],
            id: 12,
            from: 'Sam Rivera',
            subject: 'Re: second conversation',
            participant_email: 'sam@example.test',
        };
        const threads = [baseProps.threads.data[0], secondThread];
        fetchMock.mockImplementation(async (url: string) => {
            const id = Number(url.split('/').pop());
            const thread = threads.find((item) => item.id === id)!;

            return {
                ok: true,
                json: async () => ({
                    thread: { ...thread, has_inbound: true, messages: [] },
                }),
            };
        });
        const wrapper = mount(UniboxIndex, {
            props: { ...baseProps, threads: { data: threads } },
            global: { stubs },
        });

        await wrapper.findAll('.thread')[0].trigger('click');
        await flushPromises();
        await wrapper.get('.conversation-details-trigger').trigger('click');
        const notNow = wrapper
            .findAll('.conversation-classification button')
            .find((button) => button.text() === 'Not now')!;
        await notNow.trigger('click');
        const callbacks = router.post.mock.calls[0][2] as {
            onSuccess: () => void;
            onError: () => void;
            onFinish: () => void;
        };

        await wrapper.findAll('.thread')[1].trigger('click');
        await flushPromises();
        await wrapper.findAll('.thread')[0].trigger('click');
        await flushPromises();
        await wrapper.get('.conversation-details-trigger').trigger('click');

        expect(
            wrapper
                .findAll('.conversation-classification button')
                .every((button) => button.attributes('disabled') !== undefined),
        ).toBe(true);
        expect(router.post).toHaveBeenCalledTimes(1);

        callbacks.onSuccess();
        callbacks.onFinish();
        await flushPromises();

        expect(wrapper.get('.continuous-read-head').text()).toContain(
            'Re: hiring',
        );
        expect(wrapper.find('.inline-action-error').exists()).toBe(false);
        expect(
            wrapper
                .findAll('.conversation-classification button')
                .every((button) => button.attributes('disabled') === undefined),
        ).toBe(true);
        expect(fetchMock).toHaveBeenCalledTimes(4);
    });

    it('prevents a duplicate reply after switching away and back', async () => {
        const secondThread = {
            ...baseProps.threads.data[0],
            id: 12,
            from: 'Sam Rivera',
            subject: 'Re: second conversation',
            participant_email: 'sam@example.test',
        };
        const threads = [baseProps.threads.data[0], secondThread];
        fetchMock.mockImplementation(async (url: string) => {
            const id = Number(url.split('/').pop());
            const thread = threads.find((item) => item.id === id)!;

            return {
                ok: true,
                json: async () => ({
                    thread: { ...thread, has_inbound: true, messages: [] },
                }),
            };
        });
        const wrapper = mount(UniboxIndex, {
            props: { ...baseProps, threads: { data: threads } },
            global: { stubs },
        });

        await wrapper.findAll('.thread')[0].trigger('click');
        await flushPromises();
        await wrapper.get('[aria-label="Reply"]').setValue('First reply');
        await wrapper.get('.reply-actions .btn').trigger('click');
        const callbacks = router.post.mock.calls[0][2] as {
            onSuccess: () => void;
            onError: () => void;
            onFinish: () => void;
        };

        await wrapper.findAll('.thread')[1].trigger('click');
        await flushPromises();
        await wrapper.findAll('.thread')[0].trigger('click');
        await flushPromises();

        expect(wrapper.get('[aria-label="Reply"]').attributes('disabled')).toBe(
            '',
        );
        expect(wrapper.get('.reply-actions .btn').text()).toBe('Sending…');
        await wrapper.get('.reply-actions .btn').trigger('click');
        expect(router.post).toHaveBeenCalledTimes(1);
        expect(fetchMock).toHaveBeenCalledTimes(3);

        callbacks.onError();
        callbacks.onFinish();
        await flushPromises();

        expect(
            (wrapper.get('[aria-label="Reply"]').element as HTMLTextAreaElement)
                .value,
        ).toBe('First reply');
        expect(wrapper.get('.inline-action-error').text()).toContain(
            'not sent',
        );
        expect(wrapper.get('[aria-label="Reply"]').attributes('disabled')).toBe(
            undefined,
        );
    });

    it('keeps unsent reply drafts isolated by thread', async () => {
        const secondThread = {
            ...baseProps.threads.data[0],
            id: 12,
            from: 'Sam Rivera',
            subject: 'Re: second conversation',
            participant_email: 'sam@example.test',
        };
        const threads = [baseProps.threads.data[0], secondThread];
        fetchMock.mockImplementation(async (url: string) => {
            const id = Number(url.split('/').pop());
            const thread = threads.find((item) => item.id === id)!;

            return {
                ok: true,
                json: async () => ({
                    thread: { ...thread, has_inbound: true, messages: [] },
                }),
            };
        });
        const wrapper = mount(UniboxIndex, {
            props: { ...baseProps, threads: { data: threads } },
            global: { stubs },
        });

        await wrapper.findAll('.thread')[0].trigger('click');
        await flushPromises();
        await wrapper.get('[aria-label="Reply"]').setValue('Draft for Maya');
        await wrapper.findAll('.thread')[1].trigger('click');
        await flushPromises();

        expect(
            (wrapper.get('[aria-label="Reply"]').element as HTMLTextAreaElement)
                .value,
        ).toBe('');

        await wrapper.get('[aria-label="Reply"]').setValue('Draft for Sam');
        await wrapper.findAll('.thread')[0].trigger('click');
        await flushPromises();

        expect(
            (wrapper.get('[aria-label="Reply"]').element as HTMLTextAreaElement)
                .value,
        ).toBe('Draft for Maya');

        await wrapper.findAll('.thread')[1].trigger('click');
        await flushPromises();

        expect(
            (wrapper.get('[aria-label="Reply"]').element as HTMLTextAreaElement)
                .value,
        ).toBe('Draft for Sam');
    });

    it('does not show a compose action in the inbox header', () => {
        const connectedWrapper = mount(UniboxIndex, {
            props: {
                ...baseProps,
                mailboxes: [
                    {
                        id: 3,
                        email_address: 'david@outboss.io',
                        status: 'connected',
                        effective_reply_identity: {
                            email: 'david@outboss.io',
                            name: 'David',
                        },
                    },
                ],
            },
            global: { stubs },
        });

        const disconnectedWrapper = mount(UniboxIndex, {
            props: baseProps,
            global: { stubs },
        });

        expect(connectedWrapper.find('.top .btn').exists()).toBe(false);
        expect(connectedWrapper.text()).not.toContain('Compose');
        expect(disconnectedWrapper.find('.top .btn').exists()).toBe(false);
        expect(disconnectedWrapper.text()).not.toContain('Compose');
    });
});
