import type { FormDataConvertible } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import type { Ref } from 'vue';
import type {
    IntelligencePreflight,
    IntelligenceRun,
} from '@/types/accountIntelligence';

type ActionName =
    | 'start'
    | 'cancel'
    | 'refresh'
    | 'review'
    | 'correct'
    | 'offer-readiness'
    | 'offer-review';

type ActionOptions = {
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
};

const terminalStates = new Set(['cancelled', 'partial', 'completed', 'failed']);

const activeStates = new Set([
    'planned',
    'awaiting_provider',
    'awaiting_budget',
    'running',
    'paused',
    'cancelling',
]);

/**
 * Shared browser mutation/polling boundary for the intelligence page.
 * Inertia always reloads the canonical server projection after a mutation;
 * local state is limited to busy/error affordances and never becomes a fake
 * source of truth.
 */
export function useAccountIntelligenceActions(
    accountId: Ref<number>,
    researchRun: Ref<IntelligenceRun | null>,
    preflight: Ref<IntelligencePreflight>,
    isOwner: Ref<boolean>,
) {
    const busyAction = ref<ActionName | null>(null);
    const actionError = ref<string | null>(null);
    const polling = ref(false);
    let pollTimer: ReturnType<typeof setTimeout> | null = null;
    let pollAttempt = 0;

    const canMutate = computed(
        () => isOwner.value && busyAction.value === null,
    );

    function key(prefix: string): string {
        const cryptoApi = globalThis.crypto;

        if (typeof cryptoApi?.randomUUID === 'function') {
            return `${prefix}-${cryptoApi.randomUUID()}`;
        }

        return `${prefix}-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    }

    function complete(options?: ActionOptions): void {
        busyAction.value = null;
        options?.onSuccess?.();
    }

    function fail(
        errors: Record<string, string> = {},
        options?: ActionOptions,
    ): void {
        actionError.value =
            errors.intelligence ??
            errors.research ??
            errors.message ??
            'The action could not be saved. Reload this Account and try again.';
        busyAction.value = null;
        options?.onError?.(errors);
    }

    function start(options?: ActionOptions): void {
        if (!canMutate.value || preflight.value.blocked) {
            return;
        }

        actionError.value = null;
        busyAction.value = 'start';
        const payload = {
            idempotency_key: key('account-research'),
            preflight_hash: preflight.value.input_hash,
            gtm_profile_version_id: preflight.value.gtm_profile_version_id,
            offer_version_id: preflight.value.offer_version_id,
            freshness_policy: preflight.value.freshness_policy,
            capabilities: preflight.value.capability_snapshot.selected ?? [],
            maximum_cost_micros: preflight.value.maximum_cost_micros,
        } as unknown as Record<string, FormDataConvertible>;
        router.post(
            `/accounts/${accountId.value}/intelligence/research-runs`,
            payload,
            {
                preserveScroll: true,
                onSuccess: () => complete(options),
                onError: (errors) => fail(errors, options),
                onFinish: () => {
                    if (busyAction.value === 'start') {
                        busyAction.value = null;
                    }
                },
            },
        );
    }

    function cancel(options?: ActionOptions): void {
        const run = researchRun.value;

        if (!canMutate.value || run === null || terminalStates.has(run.state)) {
            return;
        }

        actionError.value = null;
        busyAction.value = 'cancel';
        router.post(
            `/account-research-runs/${run.id}/cancel`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => complete(options),
                onError: (errors) => fail(errors, options),
                onFinish: () => {
                    if (busyAction.value === 'cancel') {
                        busyAction.value = null;
                    }
                },
            },
        );
    }

    function refresh(options?: ActionOptions): void {
        const run = researchRun.value;

        if (!canMutate.value || run === null) {
            return;
        }

        actionError.value = null;
        busyAction.value = 'refresh';
        router.post(
            `/account-research-runs/${run.id}/refresh`,
            {
                idempotency_key: key('account-research-refresh'),
                preflight_hash: preflight.value.input_hash,
                maximum_cost_micros: preflight.value.maximum_cost_micros,
            },
            {
                preserveScroll: true,
                onSuccess: () => complete(options),
                onError: (errors) => fail(errors, options),
                onFinish: () => {
                    if (busyAction.value === 'refresh') {
                        busyAction.value = null;
                    }
                },
            },
        );
    }

    function review(
        decisionId: string,
        action: string,
        reason = '',
        options?: ActionOptions,
    ): void {
        if (!canMutate.value || decisionId === '') {
            return;
        }

        actionError.value = null;
        busyAction.value = 'review';
        router.post(
            `/qualification-decisions/${decisionId}/reviews`,
            { action, reason: reason || null },
            {
                preserveScroll: true,
                onSuccess: () => complete(options),
                onError: (errors) => fail(errors, options),
                onFinish: () => {
                    if (busyAction.value === 'review') {
                        busyAction.value = null;
                    }
                },
            },
        );
    }

    function correct(
        claimId: string,
        value: unknown,
        reason = '',
        valueType?: string,
        options?: ActionOptions,
    ): void {
        if (!canMutate.value || claimId === '') {
            return;
        }

        actionError.value = null;
        busyAction.value = 'correct';
        router.post(
            `/research-claims/${claimId}/corrections`,
            {
                value: value as FormDataConvertible,
                reason: reason || null,
                value_type: valueType || undefined,
            },
            {
                preserveScroll: true,
                onSuccess: () => complete(options),
                onError: (errors) => fail(errors, options),
                onFinish: () => {
                    if (busyAction.value === 'correct') {
                        busyAction.value = null;
                    }
                },
            },
        );
    }

    function evaluateOfferReadiness(
        offerId: string,
        options?: ActionOptions,
    ): void {
        if (!canMutate.value || offerId === '') {
            return;
        }

        actionError.value = null;
        busyAction.value = 'offer-readiness';
        router.post(
            '/offers/' + offerId + '/readiness',
            {},
            {
                preserveScroll: true,
                onSuccess: () => complete(options),
                onError: (errors) => fail(errors, options),
                onFinish: () => {
                    if (busyAction.value === 'offer-readiness') {
                        busyAction.value = null;
                    }
                },
            },
        );
    }

    function reviewOffer(
        offerId: string,
        action: string,
        reason = '',
        options?: ActionOptions,
    ): void {
        if (!canMutate.value || offerId === '') {
            return;
        }

        actionError.value = null;
        busyAction.value = 'offer-review';
        router.post(
            '/offers/' + offerId + '/review',
            { action, reason: reason || null },
            {
                preserveScroll: true,
                onSuccess: () => complete(options),
                onError: (errors) => fail(errors, options),
                onFinish: () => {
                    if (busyAction.value === 'offer-review') {
                        busyAction.value = null;
                    }
                },
            },
        );
    }

    function reloadRun(): void {
        actionError.value = null;
        router.reload({
            only: [
                'preflight',
                'researchRun',
                'researchRuns',
                'intelligence',
                'qualification',
                'offer',
                'conversion',
                'selectedRunId',
            ],
            preserveUrl: true,
        });
    }

    function stopPolling(): void {
        polling.value = false;
        pollAttempt = 0;

        if (pollTimer !== null) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
    }

    function schedulePolling(): void {
        const run = researchRun.value;

        if (!run || !activeStates.has(run.state) || pollTimer !== null) {
            if (!run || !activeStates.has(run?.state ?? '')) {
                stopPolling();
            }

            return;
        }

        polling.value = true;
        const delay = Math.min(15_000, 2_000 * 2 ** Math.min(pollAttempt, 3));
        pollTimer = setTimeout(() => {
            pollTimer = null;
            pollAttempt += 1;
            reloadRun();
            schedulePolling();
        }, delay);
    }

    watch(
        () => researchRun.value?.state,
        () => {
            pollAttempt = 0;
            schedulePolling();
        },
        { immediate: true },
    );

    onBeforeUnmount(stopPolling);

    return {
        busyAction,
        actionError,
        polling,
        canMutate,
        start,
        cancel,
        refresh,
        review,
        correct,
        evaluateOfferReadiness,
        reviewOffer,
        reloadRun,
        key,
    };
}
