<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { AlertTriangle, LoaderCircle, Plus, RefreshCw } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import type { GtmResearchState } from '@/types/gtmProfile';

const props = withDefaults(
    defineProps<{
        researchState: GtmResearchState;
        isOwner?: boolean;
    }>(),
    { isOwner: false },
);

const emit = defineEmits<{
    addWebsite: [];
    retry: [];
    refresh: [];
}>();

const confirmPaidRetry = ref(false);
const isPaidResultLost = computed(
    () =>
        props.researchState.state === 'failed' &&
        props.researchState.safe_error_code === 'paid_result_lost',
);

watch(
    () => [
        props.researchState.state,
        props.researchState.safe_error_code,
        props.researchState.run_uuid,
    ],
    () => {
        confirmPaidRetry.value = false;
    },
);

const title = computed(() => {
    switch (props.researchState.state) {
        case 'not_started':
            return 'Add a website when you are ready';
        case 'awaiting_provider':
            return 'Research is waiting for provider setup';
        case 'awaiting_budget':
            return 'Research is waiting for workspace budget';
        case 'queued':
            return 'Research queued';
        case 'running':
            return 'Researching your company…';
        case 'failed':
            return isPaidResultLost.value
                ? 'A paid research result could not be recovered'
                : 'Research needs another try';
        case 'cancelled':
            return 'Research was cancelled';
        default:
            return props.researchState.stale
                ? 'Profile research is getting stale'
                : 'Profile research is up to date';
    }
});

const detail = computed(() => {
    switch (props.researchState.state) {
        case 'not_started':
            return 'Outboss can prepare a first profile from public company pages. You can edit every field.';
        case 'awaiting_provider':
            return 'Connect the required AI and research providers to resume this queued run.';
        case 'awaiting_budget':
            return 'Increase or restore the research budget to let this queued run continue.';
        case 'queued':
            return 'The workspace is usable while the bounded background run starts.';
        case 'running':
            return 'You can leave this page; the profile will update when the run finishes.';
        case 'failed':
            return isPaidResultLost.value
                ? 'The provider result cannot be replayed. Starting a new run may repeat paid work and create another charge.'
                : 'Completed evidence remains available. Retry starts safely from the last durable checkpoint.';
        case 'cancelled':
            return 'Completed evidence remains available. Retry starts safely from the last durable checkpoint.';
        default:
            return props.researchState.stale
                ? 'Refresh research when the company or market context has changed.'
                : 'Freshness is based on the last completed research run.';
    }
});

const lastResearchedLabel = computed(() => {
    const value = props.researchState.last_researched_at;

    if (!value) {
        return null;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? null
        : new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
          }).format(date);
});

const tone = computed(() => {
    if (props.researchState.state === 'failed') {
        return 'danger';
    }

    if (
        props.researchState.state === 'awaiting_provider' ||
        props.researchState.state === 'awaiting_budget' ||
        props.researchState.stale
    ) {
        return 'warning';
    }

    if (
        props.researchState.state === 'queued' ||
        props.researchState.state === 'running'
    ) {
        return 'working';
    }

    return 'quiet';
});
</script>

<template>
    <section
        class="gtm-research-state"
        :class="`is-${tone}`"
        aria-live="polite"
        data-test="gtm-research-state"
    >
        <span class="research-state-icon" aria-hidden="true">
            <LoaderCircle
                v-if="
                    researchState.state === 'queued' ||
                    researchState.state === 'running'
                "
                class="spin"
            />
            <AlertTriangle
                v-else-if="tone === 'danger' || tone === 'warning'"
            />
            <RefreshCw v-else-if="researchState.state === 'ready'" />
            <Plus v-else />
        </span>
        <div class="research-state-copy">
            <strong>{{ title }}</strong>
            <p>{{ detail }}</p>
            <small v-if="lastResearchedLabel">
                Last completed {{ lastResearchedLabel
                }}<template v-if="researchState.source_count !== undefined">
                    · {{ researchState.source_count }} sources</template
                >
            </small>
        </div>
        <div class="research-state-actions">
            <button
                v-if="researchState.state === 'not_started' && isOwner"
                type="button"
                class="gtm-inline-action"
                @click="emit('addWebsite')"
            >
                Add website
            </button>
            <Link
                v-else-if="researchState.state === 'awaiting_provider'"
                href="/settings/ai-research"
                class="gtm-inline-action"
            >
                Open AI & research
            </Link>
            <Link
                v-else-if="researchState.state === 'awaiting_budget'"
                href="/settings/ai-research"
                class="gtm-inline-action"
            >
                Open budget
            </Link>
            <button
                v-else-if="
                    isOwner &&
                    (researchState.state === 'failed' ||
                        researchState.state === 'cancelled') &&
                    !isPaidResultLost
                "
                type="button"
                class="gtm-inline-action"
                @click="emit('retry')"
            >
                Retry research
            </button>
            <button
                v-else-if="isOwner && isPaidResultLost && !confirmPaidRetry"
                type="button"
                class="gtm-inline-action"
                data-test="gtm-paid-result-new-run"
                @click="confirmPaidRetry = true"
            >
                Start a new run
            </button>
            <div
                v-else-if="isOwner && isPaidResultLost"
                class="gtm-paid-retry-confirm"
                role="alert"
                data-test="gtm-paid-result-confirmation"
            >
                <span>This may create another provider charge.</span>
                <button type="button" @click="confirmPaidRetry = false">
                    Cancel
                </button>
                <button type="button" @click="emit('retry')">
                    Confirm new run
                </button>
            </div>
            <button
                v-else-if="isOwner && researchState.state === 'ready'"
                type="button"
                class="gtm-inline-action"
                @click="emit('refresh')"
            >
                Refresh
            </button>
        </div>
    </section>
</template>

<style scoped>
.gtm-research-state {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin: 0 0 30px;
    padding: 13px 0;
    border-top: 1px solid var(--ob-border);
    border-bottom: 1px solid var(--ob-border);
}

.research-state-icon {
    display: grid;
    flex: 0 0 28px;
    width: 28px;
    height: 28px;
    place-items: center;
    border-radius: 50%;
    background: #f0ede7;
    color: #6b655c;
}

.research-state-icon :deep(svg) {
    width: 15px;
    height: 15px;
}

.is-warning .research-state-icon {
    background: #fff3da;
    color: #9a6500;
}

.is-danger .research-state-icon {
    background: #fff0ee;
    color: #b42318;
}

.is-working .research-state-icon {
    background: var(--ob-accent-tint-strong);
    color: var(--ob-accent-strong);
}

.research-state-copy {
    min-width: 0;
    flex: 1;
}

.research-state-copy strong {
    display: block;
    color: #29251f;
    font-size: 12px;
    font-weight: 650;
}

.research-state-copy p {
    max-width: 560px;
    margin: 3px 0 0;
    color: #6b655c;
    font-size: 11px;
    line-height: 1.45;
}

.research-state-copy small {
    display: block;
    margin-top: 5px;
    color: #8a847a;
    font-size: 9px;
}

.research-state-actions {
    flex: 0 0 auto;
}

.gtm-inline-action {
    border: 0;
    background: transparent;
    color: var(--ob-accent-strong);
    font-size: 10px;
    font-weight: 650;
    text-decoration: none;
    cursor: pointer;
}

.gtm-inline-action:hover {
    color: var(--ob-accent-strong);
    text-decoration: underline;
    text-underline-offset: 3px;
}

.gtm-paid-retry-confirm {
    display: grid;
    grid-template-columns: auto auto;
    justify-items: end;
    gap: 5px 8px;
    max-width: 220px;
    color: #8e241d;
    font-size: 9px;
}

.gtm-paid-retry-confirm span {
    grid-column: 1 / -1;
}

.gtm-paid-retry-confirm button {
    border: 0;
    padding: 0;
    background: transparent;
    color: var(--ob-accent-strong);
    font-size: 9px;
    font-weight: 650;
    cursor: pointer;
}

.spin {
    animation: gtm-spin 800ms linear infinite;
}

@keyframes gtm-spin {
    to {
        transform: rotate(360deg);
    }
}

@media (max-width: 620px) {
    .gtm-research-state {
        flex-wrap: wrap;
    }

    .research-state-actions {
        width: 100%;
        padding-left: 40px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .spin {
        animation: none;
    }
}
</style>
