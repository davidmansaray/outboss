import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import MailboxRow from '@/components/mailboxes/MailboxRow.vue';
import type { MailboxRow as MailboxRowData } from '@/types/mailbox';

function mailbox(
    overrides: Partial<MailboxRowData> & {
        sync?: Partial<MailboxRowData['sync']>;
    } = {},
): MailboxRowData {
    const { sync, ...rest } = overrides;

    return {
        id: 1,
        email: 'david@outboss.io',
        provider: 'zoho',
        status: 'connected',
        error: null,
        replies_today: 0,
        sync: {
            phase: 'idle',
            percent: null,
            phase_label: 'Up to date',
            detail: 'Inbox + Sent',
            button_label: 'Sync',
            last_sync_label: 'Last synced 2 minutes ago',
            scope_label: 'Inbox + Sent',
            active_scope: null,
            ...sync,
        },
        ...rest,
    };
}

describe('MailboxRow', () => {
    it('shows a pause reason and resume control', () => {
        const wrapper = mount(MailboxRow, {
            props: {
                box: mailbox({
                    sending_paused_at: '2026-08-27T10:00:00Z',
                    sending_paused_reason: 'bounce_threshold',
                }),
            },
        });

        expect(wrapper.get('[data-test="mailbox-sending-paused"]').text()).toContain(
            'hard bounces',
        );
        expect(wrapper.get('[data-test="mailbox-resume-sending"]').text()).toBe(
            'Resume sending',
        );
        expect(wrapper.get('[data-test="mailbox-daily-cap"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="mailbox-check-dns"]').text()).toBe(
            'Check DNS',
        );
    });

    it('renders idle sync without progress or a spinner', () => {
        const wrapper = mount(MailboxRow, {
            props: { box: mailbox() },
        });

        expect(wrapper.get('[data-test="mailbox-sync-button"]').text()).toBe(
            'Sync',
        );
        expect(wrapper.find('.spinner').exists()).toBe(false);
        expect(wrapper.find('.inline-progress').isVisible()).toBe(false);
        expect(wrapper.classes()).not.toContain('syncing');
    });

    it('shows queued progress and a spinner', () => {
        const wrapper = mount(MailboxRow, {
            props: {
                box: mailbox({
                    sync: {
                        phase: 'queued',
                        percent: null,
                        phase_label: 'Sync queued',
                        detail: 'Waiting for the background worker to start',
                        button_label: 'Queued',
                        last_sync_label: 'Sync queued',
                        scope_label: 'Inbox + Sent',
                        active_scope: null,
                    },
                }),
            },
        });

        expect(wrapper.classes()).toContain('syncing');
        expect(wrapper.get('[data-test="mailbox-sync-button"]').text()).toBe(
            'Queued',
        );
        expect(wrapper.find('.spinner').exists()).toBe(true);
        expect(wrapper.find('.inline-progress').isVisible()).toBe(true);
        expect(wrapper.get('.inline-progress').text()).toContain(
            'Waiting for the background worker to start',
        );
    });

    it('sets the bar width when percent is real', () => {
        const wrapper = mount(MailboxRow, {
            props: {
                box: mailbox({
                    sync: {
                        phase: 'syncing',
                        percent: 60,
                        phase_label: 'Importing sent mail',
                        detail: 'Last 30 days · Sent',
                        button_label: 'Syncing · 60%',
                        last_sync_label: 'Syncing now',
                        scope_label: 'Sent',
                        active_scope: 'sent',
                    },
                }),
            },
        });

        expect(
            wrapper.get('[data-test="mailbox-sync-button"]').text(),
        ).toContain('Syncing');
        expect(wrapper.get('.progress-bar').attributes('style')).toContain(
            'width: 60%',
        );
        expect(wrapper.get('.progress-bar').classes()).not.toContain(
            'indeterminate',
        );
    });

    it('uses an indeterminate bar when percent is null', () => {
        const wrapper = mount(MailboxRow, {
            props: {
                box: mailbox({
                    sync: {
                        phase: 'syncing',
                        percent: null,
                        phase_label: 'Checking for new mail',
                        detail: 'Inbox',
                        button_label: 'Syncing',
                        last_sync_label: 'Syncing now',
                        scope_label: 'Inbox',
                        active_scope: 'inbox',
                    },
                }),
            },
        });

        expect(wrapper.get('[data-test="mailbox-sync-button"]').text()).toBe(
            'Syncing',
        );
        expect(
            wrapper.get('[data-test="mailbox-sync-button"]').text(),
        ).not.toContain('%');
        expect(wrapper.get('.progress-bar').classes()).toContain(
            'indeterminate',
        );
    });

    it('shows a complete afterglow with Sync again', () => {
        const wrapper = mount(MailboxRow, {
            props: {
                box: mailbox({
                    sync: {
                        phase: 'complete',
                        percent: 100,
                        phase_label: 'Sync complete',
                        detail: 'Inbox + Sent · up to date',
                        button_label: 'Sync again',
                        last_sync_label: 'Last synced just now',
                        scope_label: 'Inbox + Sent',
                        active_scope: null,
                    },
                }),
            },
        });

        expect(wrapper.classes()).toContain('complete');
        expect(
            wrapper.get('[data-test="mailbox-sync-button"]').classes(),
        ).toContain('complete');
        expect(
            wrapper.get('[data-test="mailbox-sync-button"]').text(),
        ).toContain('Sync again');
        expect(wrapper.find('.check').exists()).toBe(true);
        expect(wrapper.find('.spinner').exists()).toBe(false);
    });

    it('shows Reconnect instead of Sync when the mailbox needs reconnect', () => {
        const wrapper = mount(MailboxRow, {
            props: {
                box: mailbox({
                    provider: 'microsoft',
                    status: 'needs_reconnect',
                    sync: {
                        phase: 'failed',
                        percent: null,
                        phase_label: 'Sync failed',
                        detail: 'Sync paused',
                        button_label: 'Reconnect',
                        last_sync_label: 'Last synced yesterday',
                        scope_label: 'Sync paused',
                        active_scope: null,
                    },
                }),
            },
        });

        expect(wrapper.find('[data-test="mailbox-sync-button"]').exists()).toBe(
            false,
        );
        expect(
            wrapper.get('[data-test="mailbox-reconnect-button"]').text(),
        ).toBe('Reconnect');
        expect(wrapper.text()).toContain('Microsoft');
        expect(wrapper.text()).toContain('Needs reconnect');
        expect(wrapper.text()).toContain('Sync paused');
    });
});
