import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';
import type { PropType } from 'vue';
import { useMailboxSyncPolling } from '@/composables/useMailboxSyncPolling';
import type { MailboxRow } from '@/types/mailbox';

const { router } = vi.hoisted(() => ({
    router: {
        reload: vi.fn(),
        post: vi.fn(),
        delete: vi.fn(),
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    router,
}));

function mailbox(
    overrides: Partial<MailboxRow> & {
        sync?: Partial<MailboxRow['sync']>;
    } = {},
): MailboxRow {
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

const Host = defineComponent({
    props: {
        mailboxes: {
            type: Array as PropType<MailboxRow[]>,
            required: true,
        },
    },
    setup(props) {
        return useMailboxSyncPolling(() => props.mailboxes);
    },
    template: '<div />',
});

describe('useMailboxSyncPolling', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        router.reload.mockReset();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('shows Sync again for four seconds after syncing settles to idle', async () => {
        const wrapper = mount(Host, {
            props: {
                mailboxes: [
                    mailbox({
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
                ],
            },
        });

        await wrapper.setProps({
            mailboxes: [mailbox()],
        });

        expect(wrapper.vm.displayMailboxes[0].sync.phase).toBe('complete');
        expect(wrapper.vm.displayMailboxes[0].sync.button_label).toBe(
            'Sync again',
        );

        await vi.advanceTimersByTimeAsync(3999);
        expect(wrapper.vm.displayMailboxes[0].sync.phase).toBe('complete');

        await vi.advanceTimersByTimeAsync(1);
        expect(wrapper.vm.displayMailboxes[0].sync.phase).toBe('idle');
        expect(wrapper.vm.displayMailboxes[0].sync.button_label).toBe('Sync');
    });

    it('polls while a mailbox is queued and stops when idle', async () => {
        const wrapper = mount(Host, {
            props: {
                mailboxes: [
                    mailbox({
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
                ],
            },
        });

        expect(router.reload).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(2000);
        expect(router.reload).toHaveBeenCalledTimes(2);

        await wrapper.setProps({ mailboxes: [mailbox()] });
        const callsAfterIdle = router.reload.mock.calls.length;

        await vi.advanceTimersByTimeAsync(4000);
        expect(router.reload.mock.calls.length).toBe(callsAfterIdle);
    });
});
