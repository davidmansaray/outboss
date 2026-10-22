import { router } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, toValue, watch } from 'vue';
import type { MaybeRefOrGetter } from 'vue';
import type { MailboxRow, MailboxSyncFeedback } from '@/types/mailbox';

const POLL_MS = 2000;
const POLL_CAP_MS = 15 * 60 * 1000;
const AFTERGLOW_MS = 4000;

const queuedOverlay: Partial<MailboxSyncFeedback> = {
    phase: 'queued',
    percent: null,
    phase_label: 'Sync queued',
    detail: 'Waiting for the background worker to start',
    button_label: 'Queued',
    last_sync_label: 'Sync queued',
};

const completeOverlay: Partial<MailboxSyncFeedback> = {
    phase: 'complete',
    percent: 100,
    phase_label: 'Sync complete',
    button_label: 'Sync again',
};

type LocalOverride = {
    kind: 'queued' | 'complete';
    sync: Partial<MailboxSyncFeedback>;
    timer?: ReturnType<typeof setTimeout>;
};

function isBusy(phase: string): boolean {
    return phase === 'queued' || phase === 'syncing';
}

export function useMailboxSyncPolling(source: MaybeRefOrGetter<MailboxRow[]>) {
    const overrides = ref<Record<number, LocalOverride>>({});
    let pollTimer: ReturnType<typeof setInterval> | null = null;
    let pollStartedAt: number | null = null;

    const displayMailboxes = computed(() =>
        toValue(source).map((box) => {
            const override = overrides.value[box.id];

            if (!override) {
                return box;
            }

            return {
                ...box,
                sync: {
                    ...box.sync,
                    ...override.sync,
                },
            };
        }),
    );

    function clearOverride(id: number) {
        const existing = overrides.value[id];

        if (existing?.timer) {
            clearTimeout(existing.timer);
        }

        const next = { ...overrides.value };
        delete next[id];
        overrides.value = next;
    }

    function startPolling() {
        if (pollTimer) {
            return;
        }

        pollStartedAt = Date.now();
        reload();
        pollTimer = setInterval(() => {
            if (
                pollStartedAt !== null &&
                Date.now() - pollStartedAt > POLL_CAP_MS
            ) {
                stopPolling();

                return;
            }

            reload();
        }, POLL_MS);
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }

        pollStartedAt = null;
    }

    function reload() {
        router.reload({
            only: ['mailboxes'],
        });
    }

    function markLocalQueued(id: number) {
        clearOverride(id);
        overrides.value = {
            ...overrides.value,
            [id]: { kind: 'queued', sync: queuedOverlay },
        };
        startPolling();
    }

    function startAfterglow(id: number) {
        clearOverride(id);
        const timer = setTimeout(() => {
            clearOverride(id);
        }, AFTERGLOW_MS);
        overrides.value = {
            ...overrides.value,
            [id]: { kind: 'complete', sync: completeOverlay, timer },
        };
    }

    watch(
        () =>
            toValue(source).map((box) => ({
                id: box.id,
                phase: box.sync.phase,
            })),
        (next, prev) => {
            for (const row of next) {
                const before = prev?.find((item) => item.id === row.id);
                const override = overrides.value[row.id];

                if (row.phase === 'failed') {
                    if (override) {
                        clearOverride(row.id);
                    }

                    continue;
                }

                if (override?.kind === 'queued' && isBusy(row.phase)) {
                    clearOverride(row.id);
                }

                if (before && isBusy(before.phase) && row.phase === 'idle') {
                    startAfterglow(row.id);
                }
            }

            const busy =
                next.some((row) => isBusy(row.phase)) ||
                Object.values(overrides.value).some(
                    (item) => item.kind === 'queued',
                );

            if (busy) {
                startPolling();
            } else {
                stopPolling();
            }
        },
        { immediate: true },
    );

    onUnmounted(() => {
        stopPolling();
        Object.keys(overrides.value).forEach((id) => {
            clearOverride(Number(id));
        });
    });

    return {
        displayMailboxes,
        markLocalQueued,
    };
}
