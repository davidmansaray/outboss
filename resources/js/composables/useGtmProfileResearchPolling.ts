import { router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, toValue, watch } from 'vue';
import type { MaybeRefOrGetter } from 'vue';
import { isGtmProfileResearchBusy } from '@/types/gtmProfile';
import type {
    GtmResearchState,
    GtmResearchStateName,
} from '@/types/gtmProfile';

const DEFAULT_POLL_MS = 2000;
const DEFAULT_CAP_MS = 15 * 60 * 1000;

type PollingOptions = {
    pollMs?: number;
    capMs?: number;
    onTerminal?: (
        state: GtmResearchStateName,
        previous: GtmResearchStateName,
    ) => void | Promise<void>;
};

function stateName(
    value: GtmResearchState | GtmResearchStateName | undefined,
): GtmResearchStateName | undefined {
    return typeof value === 'string' ? value : value?.state;
}

function runKey(
    value: GtmResearchState | GtmResearchStateName | undefined,
): string {
    if (typeof value === 'object' && value?.run_uuid) {
        return value.run_uuid;
    }

    return stateName(value) ?? 'unknown';
}

export function useGtmProfileResearchPolling(
    source: MaybeRefOrGetter<
        GtmResearchState | GtmResearchStateName | undefined
    >,
    options: PollingOptions = {},
) {
    const isPolling = ref(false);
    const isVisible = ref(true);
    const inFlight = ref(false);
    const isHandlingTerminal = ref(false);
    const terminalRefreshNeeded = ref(false);
    const timedOut = ref(false);
    let timer: ReturnType<typeof setTimeout> | null = null;
    let startedAt: number | null = null;
    let mounted = false;
    let handledTerminal: string | null = null;
    let pendingTerminal: {
        state: GtmResearchStateName;
        previous: GtmResearchStateName;
    } | null = null;

    const currentState = computed(() => stateName(toValue(source)));
    const currentRun = computed(() => runKey(toValue(source)));
    const busy = computed(() => isGtmProfileResearchBusy(currentState.value));

    function clearTimer(): void {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    }

    function pause(): void {
        clearTimer();
        isPolling.value = false;
    }

    function stop(): void {
        pause();
        startedAt = null;
    }

    function reachedCap(): boolean {
        return (
            startedAt !== null &&
            Date.now() - startedAt >= (options.capMs ?? DEFAULT_CAP_MS)
        );
    }

    function scheduleNext(): void {
        clearTimer();

        if (
            !mounted ||
            !isPolling.value ||
            !busy.value ||
            !isVisible.value ||
            inFlight.value ||
            timedOut.value
        ) {
            return;
        }

        timer = setTimeout(() => {
            timer = null;
            tick();
        }, options.pollMs ?? DEFAULT_POLL_MS);
    }

    function tick(): void {
        if (
            !mounted ||
            !isPolling.value ||
            !busy.value ||
            !isVisible.value ||
            inFlight.value
        ) {
            return;
        }

        if (reachedCap()) {
            timedOut.value = true;
            stop();

            return;
        }

        inFlight.value = true;
        router.reload({
            only: ['researchState'],
            preserveUrl: true,
            onFinish: () => {
                inFlight.value = false;

                if (isPolling.value && busy.value && isVisible.value) {
                    scheduleNext();
                }
            },
        });
    }

    function start(): void {
        if (
            !mounted ||
            isPolling.value ||
            !busy.value ||
            !isVisible.value ||
            timedOut.value
        ) {
            return;
        }

        isPolling.value = true;
        startedAt ??= Date.now();

        if (!inFlight.value) {
            tick();
        }
    }

    async function handleTerminal(
        state: GtmResearchStateName,
        previous: GtmResearchStateName,
    ): Promise<boolean> {
        const key = `${currentRun.value}:${state}`;

        if (handledTerminal === key || isHandlingTerminal.value) {
            return false;
        }

        handledTerminal = key;
        pendingTerminal = { state, previous };
        isHandlingTerminal.value = true;

        try {
            await options.onTerminal?.(state, previous);
            terminalRefreshNeeded.value = false;
            pendingTerminal = null;

            return true;
        } catch {
            // The terminal state is durable, but its read-model refresh is
            // not. Keep a retryable transition instead of treating an
            // Inertia error/cancellation as successfully handled.
            handledTerminal = null;
            terminalRefreshNeeded.value = true;

            return false;
        } finally {
            isHandlingTerminal.value = false;
        }
    }

    async function retryTerminal(): Promise<boolean> {
        if (!pendingTerminal || isHandlingTerminal.value) {
            return false;
        }

        return handleTerminal(pendingTerminal.state, pendingTerminal.previous);
    }

    function handleVisibilityChange(): void {
        isVisible.value = document.visibilityState !== 'hidden';

        if (!isVisible.value) {
            pause();

            return;
        }

        start();
    }

    watch(
        [currentState, currentRun],
        ([state, run], [previousState, previousRun]) => {
            if (run !== previousRun && isGtmProfileResearchBusy(state)) {
                pause();
                startedAt = null;
                timedOut.value = false;
                handledTerminal = null;
                pendingTerminal = null;
                terminalRefreshNeeded.value = false;
                start();

                return;
            }

            if (
                previousState &&
                isGtmProfileResearchBusy(previousState) &&
                state &&
                !isGtmProfileResearchBusy(state)
            ) {
                stop();
                timedOut.value = false;
                void handleTerminal(state, previousState);

                return;
            }

            if (isGtmProfileResearchBusy(state)) {
                start();
            } else {
                stop();
            }
        },
    );

    onMounted(() => {
        mounted = true;
        isVisible.value = document.visibilityState !== 'hidden';
        document.addEventListener('visibilitychange', handleVisibilityChange);

        if (isVisible.value) {
            start();
        }
    });

    onUnmounted(() => {
        mounted = false;
        stop();
        document.removeEventListener(
            'visibilitychange',
            handleVisibilityChange,
        );
    });

    return {
        busy,
        inFlight,
        isHandlingTerminal,
        isPolling,
        retryTerminal,
        start,
        stop,
        terminalRefreshNeeded,
        timedOut,
    };
}
