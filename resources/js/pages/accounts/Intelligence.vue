<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, ExternalLink, RefreshCw, ShieldCheck } from '@lucide/vue';
import { computed, ref, toRef } from 'vue';
import IntelligenceStatusPill from '@/components/account-intelligence/IntelligenceStatusPill.vue';
import { useAccountIntelligenceActions } from '@/composables/useAccountIntelligenceActions';
import AppWorkspace from '@/layouts/AppWorkspace.vue';
import type {
    AccountIntelligencePageProps,
    IntelligenceClaim,
} from '@/types/accountIntelligence';

const props = defineProps<AccountIntelligencePageProps>();
const page = usePage();

const account = computed(() => props.account);
const run = toRef(props, 'researchRun');
const preflight = toRef(props, 'preflight');
const owner = toRef(props, 'isOwner');
const accountId = computed(() => props.account.id);
const selectedRunId = computed(() => props.selectedRunId);
const flashSuccess = computed(() => {
    const flash = page.props.flash as { success?: string | null } | undefined;

    return flash?.success ?? null;
});
const serverError = computed(() => {
    const errors = page.props.errors as Record<string, string> | undefined;

    return errors?.intelligence ?? errors?.research ?? null;
});

const {
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
} = useAccountIntelligenceActions(accountId, run, preflight, owner);

const correctionClaimId = ref<string | null>(null);
const correctionValue = ref('');
const correctionReason = ref('');
const qualificationReason = ref('');
const offerReviewReason = ref('');
const expandedHistory = ref(false);

const claimGroups = computed(() => [
    {
        key: 'supported',
        label: 'Supported',
        copy: 'Directly backed by usable evidence.',
    },
    {
        key: 'inferred',
        label: 'Inferred',
        copy: 'Derived with a confidence band; review before use.',
    },
    {
        key: 'conflicting',
        label: 'Conflicting',
        copy: 'Evidence disagrees, so this cannot qualify the Account.',
    },
    {
        key: 'stale',
        label: 'Stale',
        copy: 'Evidence is outside the freshness policy.',
    },
    {
        key: 'unsupported',
        label: 'Unsupported',
        copy: 'No usable evidence edge supports this Claim.',
    },
    {
        key: 'owner_confirmed',
        label: 'Owner confirmed',
        copy: 'A workspace owner appended this correction.',
    },
]);

const visibleRuns = computed(() =>
    expandedHistory.value ? props.researchRuns : props.researchRuns.slice(0, 4),
);

const capabilities = computed(() => {
    const required = preflight.value.capability_snapshot.required ?? {};

    return Object.entries(required).map(([name, ready]) => ({ name, ready }));
});

const hasActiveRun = computed(
    () =>
        run.value !== null &&
        [
            'planned',
            'awaiting_provider',
            'awaiting_budget',
            'running',
            'paused',
            'cancelling',
        ].includes(run.value.state),
);

const runActionLabel = computed(() => {
    if (run.value === null) {
        return 'Start bounded research';
    }

    if (run.value.state === 'awaiting_provider') {
        return 'Provider setup required';
    }

    if (run.value.state === 'awaiting_budget') {
        return 'Budget setup required';
    }

    return hasActiveRun.value
        ? 'Research is already running'
        : 'Start new research';
});

const offerState = computed(
    () =>
        props.offer?.readiness?.state ||
        props.offer?.availability_state ||
        'unknown',
);

const offerHasBlockers = computed(
    () => (props.offer?.readiness?.blockers.length ?? 0) > 0,
);

function valueLabel(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (typeof value === 'string') {
        return value;
    }

    try {
        return JSON.stringify(value);
    } catch {
        return String(value);
    }
}

function formatCost(micros: number, currency = 'USD'): string {
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(micros / 1_000_000);
}

function formatDate(value: string | null | undefined): string {
    if (!value) {
        return 'Not recorded';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return 'Not recorded';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}

function statusLabel(value: string | null | undefined): string {
    return (value || 'unknown')
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function stageLabel(value: string): string {
    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function openCorrection(claim: IntelligenceClaim): void {
    correctionClaimId.value = claim.claim_id;
    correctionValue.value = valueLabel(claim.value);
    correctionReason.value = '';
}

function closeCorrection(): void {
    correctionClaimId.value = null;
    correctionValue.value = '';
    correctionReason.value = '';
}

function parsedCorrectionValue(): unknown {
    const value = correctionValue.value.trim();

    if (value === '') {
        return '';
    }

    try {
        return JSON.parse(value);
    } catch {
        return value;
    }
}

function submitCorrection(): void {
    if (!correctionClaimId.value) {
        return;
    }

    correct(
        correctionClaimId.value,
        parsedCorrectionValue(),
        correctionReason.value,
        undefined,
        { onSuccess: closeCorrection },
    );
}

function reviewDecision(action: string): void {
    if (!props.qualification) {
        return;
    }

    review(props.qualification.id, action, qualificationReason.value);
}

function startResearch(): void {
    start();
}

function refreshResearch(): void {
    if (run.value === null) {
        return;
    }

    refresh();
}

function cancelResearch(): void {
    if (run.value === null) {
        return;
    }

    cancel();
}

function evaluateLinkedOffer(): void {
    if (!props.offer) {
        return;
    }

    evaluateOfferReadiness(props.offer.id);
}

function reviewLinkedOffer(action: string): void {
    if (!props.offer) {
        return;
    }

    reviewOffer(props.offer.id, action, offerReviewReason.value);
}
</script>

<template>
    <Head :title="`${account.name} intelligence`" />
    <AppWorkspace area="accounts" title="Account intelligence">
        <template #crumb>
            <Link class="intelligence-crumb-link" href="/accounts">
                Accounts
            </Link>
            <span aria-hidden="true">›</span>
            <strong>{{ account.name }}</strong>
        </template>

        <main class="account-intelligence-page">
            <header class="intelligence-page-head">
                <div>
                    <Link class="back-link" href="/accounts">
                        <ArrowLeft aria-hidden="true" />
                        Back to Accounts
                    </Link>
                    <p class="eyebrow">Single-candidate workspace</p>
                    <h1>{{ account.name }}</h1>
                    <p class="lead">
                        Evidence-backed commercial intelligence for one supplied
                        Account. No campaign enrollment or message sending
                        happens here.
                    </p>
                </div>
                <div class="head-state" data-test="account-intelligence-state">
                    <span class="state-label">Current state</span>
                    <IntelligenceStatusPill
                        :value="run?.state ?? 'not_started'"
                        :label="run?.state_label ?? 'Not researched yet'"
                    />
                </div>
            </header>

            <p
                v-if="flashSuccess"
                class="intelligence-alert success"
                role="status"
            >
                {{ flashSuccess }}
            </p>
            <p
                v-if="serverError || actionError"
                class="intelligence-alert error"
                role="alert"
            >
                {{ actionError || serverError }}
            </p>

            <section
                class="intelligence-card account-card"
                data-test="account-identity-card"
            >
                <div class="card-heading">
                    <div>
                        <p class="eyebrow">Account identity</p>
                        <h2>Supplied by S1</h2>
                    </div>
                    <ShieldCheck aria-hidden="true" />
                </div>
                <div class="identity-grid">
                    <div>
                        <span class="fact-label">Website</span>
                        <a
                            v-if="account.website"
                            :href="account.website"
                            target="_blank"
                            rel="noreferrer"
                        >
                            {{ account.website }}
                            <ExternalLink aria-hidden="true" />
                        </a>
                        <span v-else class="muted"
                            >No public website supplied</span
                        >
                    </div>
                    <div>
                        <span class="fact-label">Domain</span>
                        <strong>{{ account.domain || '—' }}</strong>
                    </div>
                    <div>
                        <span class="fact-label">Source record</span>
                        <strong>{{
                            account.source.replaceAll('_', ' ')
                        }}</strong>
                    </div>
                    <div>
                        <span class="fact-label">Observed</span>
                        <strong>{{
                            formatDate(account.supplied_by.last_observed_at)
                        }}</strong>
                    </div>
                </div>
                <div class="provenance-strip">
                    <span class="fact-label">Supplied by</span>
                    <strong v-if="account.supplied_by.source">
                        {{ account.supplied_by.source.name }}
                    </strong>
                    <strong v-else>Manual Account record</strong>
                    <span
                        >{{ account.supplied_by.source_count }} source{{
                            account.supplied_by.source_count === 1 ? '' : 's'
                        }}</span
                    >
                    <span
                        v-if="account.supplied_by.has_restrictions"
                        class="warning-text"
                    >
                        Some source fields are restricted by policy.
                    </span>
                    <span
                        v-if="account.supplied_by.has_conflicts"
                        class="warning-text"
                    >
                        S1 reports a provenance conflict.
                    </span>
                </div>
            </section>

            <section
                class="intelligence-card preflight-card"
                data-test="account-intelligence-preflight"
            >
                <div class="card-heading">
                    <div>
                        <p class="eyebrow">Before any provider call</p>
                        <h2>Research preflight</h2>
                    </div>
                    <IntelligenceStatusPill
                        :value="
                            preflight.blocked ? 'blocked' : 'ready_for_review'
                        "
                        :label="
                            preflight.blocked ? 'Blocked' : 'Ready to queue'
                        "
                    />
                </div>
                <p class="card-copy">
                    This check is side-effect free. It freezes the exact
                    Account, GTM Profile, policy and cost envelope when you
                    start.
                </p>
                <div
                    v-if="preflight.blockers.length"
                    class="blocker-list"
                    role="status"
                >
                    <strong>Resolve these checks before starting:</strong>
                    <ul>
                        <li
                            v-for="blocker in preflight.blockers"
                            :key="`${blocker.code}-${blocker.message}`"
                        >
                            <span>{{ blocker.message }}</span>
                            <code>{{ blocker.code }}</code>
                        </li>
                    </ul>
                </div>
                <div class="preflight-grid">
                    <div>
                        <span class="fact-label">GTM Profile Version</span>
                        <strong>{{
                            preflight.gtm_profile_version_id || 'Unavailable'
                        }}</strong>
                    </div>
                    <div>
                        <span class="fact-label">Offer context</span>
                        <strong>{{
                            preflight.offer_version_id || 'Not linked'
                        }}</strong>
                    </div>
                    <div>
                        <span class="fact-label">Conservative estimate</span>
                        <strong>{{
                            formatCost(
                                preflight.estimated_cost_micros,
                                preflight.currency,
                            )
                        }}</strong>
                    </div>
                    <div>
                        <span class="fact-label">Hard maximum</span>
                        <strong>{{
                            formatCost(
                                preflight.maximum_cost_micros,
                                preflight.currency,
                            )
                        }}</strong>
                    </div>
                </div>
                <div class="capability-list">
                    <span class="fact-label">Capability readiness</span>
                    <div
                        v-for="capability in capabilities"
                        :key="capability.name"
                        class="capability-row"
                    >
                        <span>{{ capability.name }}</span>
                        <span
                            :class="
                                capability.ready ? 'ready-text' : 'warning-text'
                            "
                        >
                            {{ capability.ready ? 'Ready' : 'Not ready' }}
                        </span>
                    </div>
                </div>
                <div class="card-actions">
                    <button
                        type="button"
                        class="primary-button"
                        data-test="start-account-research"
                        :disabled="
                            !canMutate || preflight.blocked || hasActiveRun
                        "
                        :aria-disabled="
                            !canMutate || preflight.blocked || hasActiveRun
                        "
                        @click="startResearch"
                    >
                        {{
                            busyAction === 'start'
                                ? 'Queueing…'
                                : runActionLabel
                        }}
                    </button>
                    <span v-if="!owner" class="member-note">
                        Members can inspect this preflight. Only the workspace
                        owner can start research.
                    </span>
                    <span v-else class="safe-note"
                        >No provider call or budget reservation occurs during
                        preflight.</span
                    >
                </div>
            </section>

            <section
                class="intelligence-card run-card"
                data-test="account-intelligence-run"
            >
                <div class="card-heading">
                    <div>
                        <p class="eyebrow">Durable orchestration</p>
                        <h2>Research run</h2>
                    </div>
                    <button
                        type="button"
                        class="quiet-button"
                        data-test="reload-same-run"
                        :disabled="busyAction !== null"
                        @click="reloadRun"
                    >
                        <RefreshCw aria-hidden="true" />
                        {{ polling ? 'Checking…' : 'Reload this run' }}
                    </button>
                </div>
                <div v-if="run" class="run-summary">
                    <div
                        class="run-status-line"
                        role="status"
                        aria-live="polite"
                    >
                        <IntelligenceStatusPill
                            :value="run.state"
                            :label="run.state_label"
                        />
                        <span v-if="run.current_stage"
                            >Current stage:
                            {{ stageLabel(run.current_stage) }}</span
                        >
                        <span v-if="run.awaiting_reason"
                            >Waiting on
                            {{ run.awaiting_reason.replaceAll('_', ' ') }}</span
                        >
                        <span v-if="run.safe_failure_code" class="warning-text"
                            >Code: {{ run.safe_failure_code }}</span
                        >
                    </div>
                    <div class="run-facts">
                        <div>
                            <span class="fact-label">Run ID</span
                            ><code>{{ run.id }}</code>
                        </div>
                        <div>
                            <span class="fact-label">Estimated</span
                            ><strong>{{
                                formatCost(
                                    run.estimated_cost_micros,
                                    run.currency,
                                )
                            }}</strong>
                        </div>
                        <div>
                            <span class="fact-label">Actual</span
                            ><strong>{{
                                formatCost(run.actual_cost_micros, run.currency)
                            }}</strong>
                        </div>
                        <div>
                            <span class="fact-label">Queued</span
                            ><strong>{{ formatDate(run.queued_at) }}</strong>
                        </div>
                    </div>
                    <ol class="stage-timeline" aria-label="Research stages">
                        <li
                            v-for="stage in run.stages"
                            :key="stage.id"
                            :data-stage-state="stage.state"
                        >
                            <span class="stage-number">{{
                                stage.stage_order
                            }}</span>
                            <div>
                                <strong>{{
                                    stageLabel(stage.stage_key)
                                }}</strong>
                                <span>{{ statusLabel(stage.state) }}</span>
                                <small
                                    v-if="stage.safe_error_code"
                                    class="warning-text"
                                    >{{ stage.safe_error_code }}</small
                                >
                            </div>
                        </li>
                    </ol>
                    <div v-if="owner" class="card-actions run-actions">
                        <button
                            v-if="hasActiveRun"
                            type="button"
                            class="danger-button"
                            data-test="cancel-account-research"
                            :disabled="busyAction !== null"
                            @click="cancelResearch"
                        >
                            {{
                                busyAction === 'cancel'
                                    ? 'Cancelling…'
                                    : 'Cancel run safely'
                            }}
                        </button>
                        <button
                            v-if="
                                run.state === 'completed' ||
                                run.state === 'partial' ||
                                run.state === 'failed' ||
                                run.state === 'cancelled'
                            "
                            type="button"
                            class="secondary-button"
                            data-test="refresh-account-research"
                            :disabled="busyAction !== null"
                            @click="refreshResearch"
                        >
                            {{
                                busyAction === 'refresh'
                                    ? 'Queueing refresh…'
                                    : 'Start fresh run with same context'
                            }}
                        </button>
                    </div>
                </div>
                <div v-else class="empty-state">
                    <strong>No research run has been created.</strong>
                    <span
                        >Complete the preflight above, then queue one bounded
                        run.</span
                    >
                </div>
                <div v-if="props.researchRuns.length > 1" class="run-history">
                    <div class="subheading-row">
                        <h3>Run history</h3>
                        <button
                            type="button"
                            class="text-button"
                            @click="expandedHistory = !expandedHistory"
                        >
                            {{
                                expandedHistory
                                    ? 'Show less'
                                    : `Show all (${props.researchRuns.length})`
                            }}
                        </button>
                    </div>
                    <Link
                        v-for="historyRun in visibleRuns"
                        :key="historyRun.id"
                        class="history-row"
                        :class="{ current: historyRun.id === selectedRunId }"
                        :href="`/accounts/${account.id}/intelligence?run=${historyRun.id}`"
                    >
                        <span>{{ formatDate(historyRun.queued_at) }}</span>
                        <IntelligenceStatusPill
                            :value="historyRun.state"
                            :label="historyRun.state_label"
                        />
                        <code>{{ historyRun.id.slice(0, 8) }}</code>
                    </Link>
                </div>
            </section>

            <section
                class="intelligence-card claims-card"
                data-test="account-intelligence-claims"
            >
                <div class="card-heading">
                    <div>
                        <p class="eyebrow">Immutable intelligence</p>
                        <h2>Claims and evidence</h2>
                    </div>
                    <span class="count-badge"
                        >{{ props.intelligence.claims.length }} Claims</span
                    >
                </div>
                <p class="card-copy">
                    Every visible fact below is linked to a source snapshot.
                    Conflicts, stale facts and missing evidence stay visible
                    instead of being silently promoted.
                </p>
                <div
                    v-if="props.intelligence.claims.length"
                    class="claim-groups"
                >
                    <section
                        v-for="group in claimGroups"
                        :key="group.key"
                        class="claim-group"
                    >
                        <div class="subheading-row">
                            <h3>{{ group.label }}</h3>
                            <span>{{
                                props.intelligence.status_counts[group.key] || 0
                            }}</span>
                        </div>
                        <p
                            v-if="
                                !props.intelligence.by_status[group.key]?.length
                            "
                            class="empty-line"
                        >
                            None recorded
                        </p>
                        <article
                            v-for="claim in props.intelligence.by_status[
                                group.key
                            ] || []"
                            :key="claim.claim_id"
                            class="claim-row"
                        >
                            <div class="claim-main">
                                <div class="claim-title-line">
                                    <strong>{{ claim.predicate }}</strong>
                                    <IntelligenceStatusPill
                                        :value="claim.status"
                                        :label="statusLabel(claim.status)"
                                    />
                                </div>
                                <p>{{ valueLabel(claim.value) }}</p>
                                <span class="claim-meta">
                                    {{ Math.round(claim.confidence * 100) }}%
                                    confidence ·
                                    {{
                                        claim.source_count ||
                                        claim.evidence.length
                                    }}
                                    evidence source{{
                                        (claim.source_count ||
                                            claim.evidence.length) === 1
                                            ? ''
                                            : 's'
                                    }}
                                </span>
                            </div>
                            <div class="claim-actions">
                                <details
                                    v-if="claim.evidence.length"
                                    class="evidence-details"
                                >
                                    <summary>
                                        View evidence ({{
                                            claim.evidence.length
                                        }})
                                    </summary>
                                    <div
                                        v-for="evidence in claim.evidence"
                                        :key="`${claim.claim_id}-${evidence.snapshot_id}-${evidence.edge_type}`"
                                        class="evidence-item"
                                    >
                                        <div class="evidence-topline">
                                            <strong>{{
                                                evidence.source_type ||
                                                'Source snapshot'
                                            }}</strong>
                                            <span>{{
                                                statusLabel(evidence.edge_type)
                                            }}</span>
                                        </div>
                                        <a
                                            v-if="evidence.url"
                                            :href="evidence.url"
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            {{ evidence.url }}
                                            <ExternalLink aria-hidden="true" />
                                        </a>
                                        <span
                                            v-if="evidence.locator"
                                            class="muted"
                                            >Citation:
                                            {{ evidence.locator }}</span
                                        >
                                        <span class="muted"
                                            >Observed
                                            {{
                                                formatDate(evidence.observed_at)
                                            }}
                                            · Retrieved
                                            {{
                                                formatDate(
                                                    evidence.retrieved_at,
                                                )
                                            }}</span
                                        >
                                        <span
                                            v-if="evidence.provider_operation"
                                            class="muted"
                                        >
                                            {{
                                                evidence.provider_operation
                                                    .provider
                                            }}
                                            ·
                                            {{
                                                evidence.provider_operation
                                                    .status
                                            }}
                                            ·
                                            {{
                                                formatCost(
                                                    evidence.provider_operation
                                                        .actual_cost_micros ||
                                                        evidence
                                                            .provider_operation
                                                            .reserved_cost_micros,
                                                )
                                            }}
                                        </span>
                                        <span
                                            v-if="
                                                evidence.display_classification !==
                                                'standard'
                                            "
                                            class="warning-text"
                                        >
                                            Display policy:
                                            {{
                                                evidence.display_classification
                                            }}
                                        </span>
                                    </div>
                                </details>
                                <details
                                    v-if="claim.history?.length"
                                    class="evidence-details claim-history"
                                >
                                    <summary>
                                        Prior version{{
                                            claim.history.length === 1
                                                ? ''
                                                : 's'
                                        }}
                                        ({{ claim.history.length }})
                                    </summary>
                                    <div
                                        v-for="prior in claim.history"
                                        :key="prior.claim_id"
                                        class="prior-claim"
                                    >
                                        <div class="evidence-topline">
                                            <strong>{{
                                                prior.predicate
                                            }}</strong>
                                            <IntelligenceStatusPill
                                                :value="prior.status"
                                                :label="
                                                    statusLabel(prior.status)
                                                "
                                            />
                                        </div>
                                        <p>{{ valueLabel(prior.value) }}</p>
                                        <span class="muted"
                                            >Preserved Claim
                                            <code>{{
                                                prior.claim_id
                                            }}</code></span
                                        >
                                        <span v-if="prior.reason" class="muted"
                                            >Correction reason:
                                            {{ prior.reason }}</span
                                        >
                                        <span
                                            v-if="prior.superseded_at"
                                            class="muted"
                                            >Superseded
                                            {{
                                                formatDate(prior.superseded_at)
                                            }}</span
                                        >
                                    </div>
                                </details>
                                <button
                                    v-if="owner"
                                    type="button"
                                    class="text-button"
                                    :data-test="`correct-claim-${claim.claim_id}`"
                                    :disabled="busyAction !== null"
                                    @click="openCorrection(claim)"
                                >
                                    Correct
                                </button>
                            </div>
                        </article>
                    </section>
                </div>
                <div v-else class="empty-state">
                    <strong>No Claims yet.</strong>
                    <span
                        >Claims appear after a run reaches evidence
                        synthesis.</span
                    >
                </div>
                <form
                    v-if="correctionClaimId"
                    class="correction-form"
                    @submit.prevent="submitCorrection"
                >
                    <div class="subheading-row">
                        <h3>Append owner correction</h3>
                        <button
                            type="button"
                            class="text-button"
                            @click="closeCorrection"
                        >
                            Close
                        </button>
                    </div>
                    <label>
                        Corrected value
                        <textarea
                            v-model="correctionValue"
                            rows="2"
                            required
                            aria-describedby="correction-help"
                        />
                    </label>
                    <span id="correction-help" class="field-help"
                        >Use JSON for lists, numbers, booleans or objects. The
                        original Claim remains immutable.</span
                    >
                    <label for="claim-correction-reason">
                        Reason <span class="muted">(optional)</span>
                        <input
                            id="claim-correction-reason"
                            v-model="correctionReason"
                            data-test="claim-correction-reason"
                            type="text"
                            maxlength="2000"
                        />
                    </label>
                    <button
                        type="submit"
                        class="primary-button"
                        :disabled="busyAction !== null"
                    >
                        {{
                            busyAction === 'correct'
                                ? 'Saving…'
                                : 'Save owner correction'
                        }}
                    </button>
                </form>
            </section>

            <section
                class="intelligence-card qualification-card"
                data-test="account-intelligence-qualification"
            >
                <div class="card-heading">
                    <div>
                        <p class="eyebrow">Human decision boundary</p>
                        <h2>Qualification</h2>
                    </div>
                    <IntelligenceStatusPill
                        v-if="props.qualification"
                        :value="props.qualification.outcome"
                        :label="statusLabel(props.qualification.outcome)"
                    />
                    <span v-else class="muted">Waiting for a decision</span>
                </div>
                <div v-if="props.qualification" class="qualification-content">
                    <div class="qualification-overview">
                        <div class="confidence-meter">
                            <span class="fact-label">Confidence</span>
                            <strong
                                >{{
                                    Math.round(
                                        props.qualification.confidence * 100,
                                    )
                                }}%</strong
                            >
                            <div class="meter-track" aria-hidden="true">
                                <i
                                    :style="{
                                        width: `${Math.round(props.qualification.confidence * 100)}%`,
                                    }"
                                />
                            </div>
                            <span
                                >{{
                                    props.qualification.calibration_band ||
                                    'Uncalibrated'
                                }}
                                band</span
                            >
                        </div>
                        <div>
                            <span class="fact-label">Review state</span>
                            <IntelligenceStatusPill
                                :value="props.qualification.review_state"
                                :label="
                                    statusLabel(
                                        props.qualification.review_state,
                                    )
                                "
                            />
                        </div>
                        <div>
                            <span class="fact-label">Rubric</span>
                            <strong>{{
                                props.qualification.rubric
                                    ? `v${props.qualification.rubric.version}`
                                    : 'Not recorded'
                            }}</strong>
                        </div>
                        <div>
                            <span class="fact-label">Decision cost</span>
                            <strong>{{
                                formatCost(
                                    props.qualification.provider_cost_micros,
                                )
                            }}</strong>
                        </div>
                    </div>
                    <p class="explanation">
                        {{ props.qualification.explanation }}
                    </p>
                    <div class="qualification-columns">
                        <div>
                            <h3>Missing facts</h3>
                            <p
                                v-if="!props.qualification.missing_facts.length"
                                class="empty-line"
                            >
                                None named
                            </p>
                            <ul v-else>
                                <li
                                    v-for="fact in props.qualification
                                        .missing_facts"
                                    :key="fact"
                                >
                                    {{ fact }}
                                </li>
                            </ul>
                        </div>
                        <div>
                            <h3>Disqualifiers</h3>
                            <p
                                v-if="!props.qualification.disqualifiers.length"
                                class="empty-line"
                            >
                                None found
                            </p>
                            <ul v-else>
                                <li
                                    v-for="rule in props.qualification
                                        .disqualifiers"
                                    :key="rule"
                                >
                                    {{ rule }}
                                </li>
                            </ul>
                        </div>
                    </div>
                    <div v-if="owner" class="review-controls">
                        <label>
                            Review note <span class="muted">(optional)</span>
                            <input
                                v-model="qualificationReason"
                                type="text"
                                maxlength="2000"
                                placeholder="Why are you accepting or rejecting this decision?"
                            />
                        </label>
                        <div class="card-actions">
                            <button
                                type="button"
                                class="primary-button"
                                :disabled="busyAction !== null"
                                data-test="qualification-accept"
                                @click="reviewDecision('accept')"
                            >
                                {{
                                    busyAction === 'review'
                                        ? 'Saving…'
                                        : 'Accept recommendation'
                                }}
                            </button>
                            <button
                                type="button"
                                class="secondary-button"
                                :disabled="busyAction !== null"
                                data-test="qualification-request-research"
                                @click="reviewDecision('request_research')"
                            >
                                Request more research
                            </button>
                            <button
                                type="button"
                                class="danger-button"
                                :disabled="busyAction !== null"
                                data-test="qualification-reject"
                                @click="reviewDecision('reject')"
                            >
                                Reject recommendation
                            </button>
                        </div>
                    </div>
                    <p v-else class="member-note">
                        Members can inspect the recommendation and review
                        history. Only the workspace owner can record a decision.
                    </p>
                    <div
                        v-if="props.qualification.review_events.length"
                        class="review-history"
                    >
                        <h3>Review history</h3>
                        <div
                            v-for="event in props.qualification.review_events"
                            :key="event.id"
                            class="review-event"
                        >
                            <strong>{{ statusLabel(event.action) }}</strong>
                            <span
                                >{{
                                    event.actor?.display_name ||
                                    'Workspace owner'
                                }}
                                · {{ formatDate(event.created_at) }}</span
                            >
                            <p v-if="event.reason">{{ event.reason }}</p>
                        </div>
                    </div>
                </div>
                <div v-else class="empty-state">
                    <strong>Qualification is not available yet.</strong>
                    <span
                        >Run the bounded evidence workflow first. Missing or
                        conflicting facts will remain visible here.</span
                    >
                </div>
            </section>

            <section
                class="intelligence-card reason-card"
                data-test="account-intelligence-reason"
            >
                <div class="card-heading">
                    <div>
                        <p class="eyebrow">Evidence-bound recommendation</p>
                        <h2>Reason to Contact</h2>
                    </div>
                    <span
                        v-if="props.qualification?.reasons_to_contact.length"
                        class="count-badge"
                        >{{
                            props.qualification.reasons_to_contact.length
                        }}
                        version{{
                            props.qualification.reasons_to_contact.length === 1
                                ? ''
                                : 's'
                        }}</span
                    >
                </div>
                <template v-if="props.qualification?.reasons_to_contact.length">
                    <article
                        v-for="reason in props.qualification.reasons_to_contact"
                        :key="reason.id"
                        class="reason-version"
                    >
                        <div class="reason-visibility">
                            <IntelligenceStatusPill
                                :value="
                                    reason.safe_for_message
                                        ? 'approved'
                                        : 'blocked'
                                "
                                :label="reason.visibility_label"
                            />
                            <span
                                >{{ Math.round(reason.confidence * 100) }}%
                                confidence</span
                            >
                        </div>
                        <dl>
                            <div v-if="reason.why_account">
                                <dt>Why this Account</dt>
                                <dd>{{ reason.why_account }}</dd>
                            </div>
                            <div v-if="reason.why_offer">
                                <dt>Why this Offer</dt>
                                <dd>{{ reason.why_offer }}</dd>
                            </div>
                            <div v-if="reason.why_now">
                                <dt>Why now</dt>
                                <dd>{{ reason.why_now }}</dd>
                            </div>
                            <div v-if="reason.factual_basis">
                                <dt>Factual basis</dt>
                                <dd>{{ reason.factual_basis }}</dd>
                            </div>
                            <div v-if="reason.internal_rationale">
                                <dt>Internal rationale</dt>
                                <dd>{{ reason.internal_rationale }}</dd>
                            </div>
                        </dl>
                        <p class="field-help">
                            Claim handles:
                            {{
                                reason.claim_ids.length
                                    ? reason.claim_ids.join(', ')
                                    : 'None'
                            }}
                        </p>
                    </article>
                </template>
                <div v-else class="empty-state">
                    <strong>No Reason to Contact is ready.</strong>
                    <span
                        >Generated reasons stay internal-only until policy and
                        review explicitly allow message use.</span
                    >
                </div>
            </section>

            <section
                class="intelligence-card offer-card"
                data-test="account-intelligence-offer"
            >
                <div class="card-heading">
                    <div>
                        <p class="eyebrow">Downstream safety check</p>
                        <h2>Linked Offer</h2>
                    </div>
                    <IntelligenceStatusPill
                        v-if="props.offer"
                        :value="
                            props.offer.readiness?.state ||
                            props.offer.availability_state
                        "
                        :label="
                            statusLabel(
                                props.offer.readiness?.state ||
                                    props.offer.availability_state,
                            )
                        "
                    />
                </div>
                <div v-if="props.offer" class="offer-content">
                    <div class="offer-summary">
                        <h3>
                            {{ props.offer.summary.name || 'Unnamed Offer' }}
                        </h3>
                        <p v-if="props.offer.summary.promise">
                            {{ props.offer.summary.promise }}
                        </p>
                        <dl>
                            <div v-if="props.offer.summary.target_audience">
                                <dt>Audience</dt>
                                <dd>
                                    {{ props.offer.summary.target_audience }}
                                </dd>
                            </div>
                            <div v-if="props.offer.summary.call_to_action">
                                <dt>CTA</dt>
                                <dd>
                                    {{ props.offer.summary.call_to_action }}
                                </dd>
                            </div>
                            <div v-if="props.offer.summary.fulfilment_mode">
                                <dt>Fulfilment</dt>
                                <dd>
                                    {{
                                        statusLabel(
                                            props.offer.summary.fulfilment_mode,
                                        )
                                    }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                    <div class="offer-readiness">
                        <span class="fact-label"
                            >Version
                            {{ props.offer.linked_version.version }}</span
                        >
                        <strong>{{
                            props.offer.linked_version.schema_version === 2
                                ? 'Operational contract'
                                : 'Legacy contract'
                        }}</strong>
                        <ul v-if="props.offer.readiness?.blockers.length">
                            <li
                                v-for="blocker in props.offer.readiness
                                    .blockers"
                                :key="blocker.code"
                            >
                                {{ blocker.message }}
                            </li>
                        </ul>
                        <p v-else class="ready-text">
                            No readiness blockers recorded.
                        </p>
                        <span class="field-help"
                            >Offer approval is separate from Account
                            qualification and never enrolls or sends.</span
                        >
                    </div>
                </div>
                <div v-if="owner && props.offer" class="offer-controls">
                    <label>
                        Offer review note <span class="muted">(optional)</span>
                        <input
                            v-model="offerReviewReason"
                            type="text"
                            maxlength="1000"
                            placeholder="Record the reason for this Offer review."
                        />
                    </label>
                    <div class="card-actions">
                        <button
                            type="button"
                            class="quiet-button"
                            data-test="offer-readiness-refresh"
                            :disabled="busyAction !== null"
                            @click="evaluateLinkedOffer"
                        >
                            {{
                                busyAction === 'offer-readiness'
                                    ? 'Checking…'
                                    : 'Re-evaluate readiness'
                            }}
                        </button>
                        <button
                            type="button"
                            class="secondary-button"
                            data-test="offer-submit-review"
                            :disabled="busyAction !== null || offerHasBlockers"
                            @click="reviewLinkedOffer('submit')"
                        >
                            Submit for review
                        </button>
                        <button
                            type="button"
                            class="primary-button"
                            data-test="offer-approve"
                            :disabled="
                                busyAction !== null ||
                                offerState !== 'ready_for_review'
                            "
                            @click="reviewLinkedOffer('approve')"
                        >
                            Approve version
                        </button>
                        <button
                            type="button"
                            class="danger-button"
                            data-test="offer-reject"
                            :disabled="busyAction !== null"
                            @click="reviewLinkedOffer('reject')"
                        >
                            Reject version
                        </button>
                        <button
                            v-if="offerState === 'blocked'"
                            type="button"
                            class="secondary-button"
                            data-test="offer-reopen"
                            :disabled="busyAction !== null"
                            @click="reviewLinkedOffer('reopen')"
                        >
                            Reopen version
                        </button>
                    </div>
                    <p class="field-help">
                        Approval changes readiness only. Offer activation and
                        message sending remain separate workspace controls.
                    </p>
                </div>
                <div v-else-if="props.offer" class="member-note">
                    Members can inspect Offer readiness. Only the workspace
                    owner can re-evaluate or review an Offer Version.
                </div>
                <div v-else class="empty-state">
                    <strong>No Offer Version is linked to this run.</strong>
                    <span
                        >Research can be reviewed without an Offer. Link an
                        approved operational Offer in a later workflow
                        step.</span
                    >
                </div>
            </section>

            <section
                class="intelligence-card conversion-card"
                data-test="account-intelligence-conversion"
            >
                <div class="card-heading">
                    <div>
                        <p class="eyebrow">Safe measurement surface</p>
                        <h2>Conversion signals</h2>
                    </div>
                    <span class="count-badge"
                        >{{ props.conversion.event_count }} matched</span
                    >
                </div>
                <div class="conversion-summary">
                    <div>
                        <span class="fact-label">Attribution</span>
                        <strong>Not evaluated</strong>
                        <span class="field-help"
                            >Matching an Account is not causal
                            attribution.</span
                        >
                    </div>
                    <div>
                        <span class="fact-label">Active definitions</span>
                        <strong>{{
                            props.conversion.definitions.length
                        }}</strong>
                        <span class="field-help"
                            >Events use an explicit workspace definition
                            version.</span
                        >
                    </div>
                </div>
                <div
                    v-if="props.conversion.events.length"
                    class="conversion-events"
                >
                    <h3>Recent Account events</h3>
                    <div
                        v-for="event in props.conversion.events"
                        :key="event.id"
                        class="conversion-event"
                    >
                        <div>
                            <strong>{{
                                statusLabel(
                                    event.event_key || 'conversion_event',
                                )
                            }}</strong>
                            <span>{{ formatDate(event.occurred_at) }}</span>
                        </div>
                        <IntelligenceStatusPill
                            :value="event.state"
                            :label="statusLabel(event.state)"
                        />
                        <span class="muted">{{
                            event.attribution_state.replaceAll('_', ' ')
                        }}</span>
                    </div>
                </div>
                <div v-else class="empty-state compact">
                    <strong>No matched conversion events.</strong>
                    <span
                        >Event intake remains explicit and auditable; this page
                        never invents conversion state.</span
                    >
                </div>
            </section>
        </main>
    </AppWorkspace>
</template>

<style scoped>
.account-intelligence-page {
    flex: 1;
    min-width: 0;
    min-height: 0;
    overflow: auto;
    padding: 25px clamp(14px, 3vw, 38px) 48px;
    background: var(--ob-canvas);
}

.intelligence-page-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 24px;
    max-width: 1180px;
    margin: 0 auto 20px;
}

.back-link,
.intelligence-crumb-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--ob-muted);
    font-size: 11px;
    font-weight: 650;
    text-decoration: none;
}

.back-link:hover,
.intelligence-crumb-link:hover {
    color: var(--ob-ink);
    text-decoration: underline;
}

.back-link svg {
    width: 14px;
    height: 14px;
}

.eyebrow {
    margin: 0;
    color: var(--ob-muted);
    font-size: 9px;
    font-weight: 750;
    letter-spacing: 0.14em;
    line-height: 1.3;
    text-transform: uppercase;
}

.intelligence-page-head .eyebrow {
    margin-top: 22px;
}

.intelligence-page-head h1 {
    margin: 5px 0 7px;
    color: var(--ob-ink);
    font-size: clamp(27px, 4vw, 39px);
    font-weight: 680;
    letter-spacing: -0.05em;
    line-height: 1.05;
}

.lead {
    max-width: 630px;
    margin: 0;
    color: var(--ob-muted);
    font-size: 13px;
    line-height: 1.55;
}

.head-state {
    display: flex;
    min-width: 190px;
    flex-direction: column;
    align-items: flex-end;
    gap: 7px;
    padding-top: 28px;
}

.state-label,
.fact-label {
    display: block;
    color: var(--ob-muted);
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.08em;
    line-height: 1.35;
    text-transform: uppercase;
}

.intelligence-alert {
    max-width: 1180px;
    margin: 0 auto 14px;
    padding: 10px 12px;
    border: 1px solid var(--ob-border);
    border-radius: 8px;
    font-size: 12px;
    line-height: 1.4;
}

.intelligence-alert.success {
    border-color: #b9d8bd;
    background: #f3faf4;
    color: #2f6438;
}

.intelligence-alert.error {
    border-color: #dfb2a8;
    background: #fff5f2;
    color: #8c3d2d;
}

.intelligence-card {
    max-width: 1180px;
    margin: 0 auto 15px;
    padding: clamp(15px, 2.2vw, 23px);
    border: 1px solid var(--ob-border);
    border-radius: 12px;
    background: var(--ob-surface);
    box-shadow: 0 2px 10px rgb(36 34 31 / 2%);
}

.card-heading,
.subheading-row,
.claim-title-line,
.run-status-line,
.evidence-topline,
.reason-visibility,
.conversion-event,
.capability-row,
.history-row {
    display: flex;
    align-items: center;
}

.card-heading {
    justify-content: space-between;
    gap: 12px;
}

.card-heading h2 {
    margin: 4px 0 0;
    color: var(--ob-ink);
    font-size: 18px;
    font-weight: 680;
    letter-spacing: -0.03em;
}

.card-heading > svg {
    width: 20px;
    height: 20px;
    color: #477b52;
}

.card-copy {
    max-width: 760px;
    margin: 12px 0 0;
    color: var(--ob-muted);
    font-size: 12px;
    line-height: 1.55;
}

.identity-grid,
.preflight-grid,
.run-facts,
.qualification-overview,
.conversion-summary {
    display: grid;
    gap: 15px 22px;
    margin-top: 18px;
}

.identity-grid,
.preflight-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.identity-grid > div,
.preflight-grid > div,
.run-facts > div,
.qualification-overview > div,
.conversion-summary > div {
    min-width: 0;
}

.identity-grid strong,
.identity-grid a,
.preflight-grid strong,
.run-facts strong,
.run-facts code,
.qualification-overview strong,
.conversion-summary strong {
    display: block;
    overflow-wrap: anywhere;
    margin-top: 5px;
    color: var(--ob-text);
    font-size: 12px;
    font-weight: 650;
    line-height: 1.35;
}

.identity-grid a,
.evidence-item a {
    color: #3f5e74;
    text-decoration: none;
}

.identity-grid a:hover,
.evidence-item a:hover {
    text-decoration: underline;
}

.identity-grid a svg,
.evidence-item a svg {
    width: 11px;
    height: 11px;
    vertical-align: -2px;
}

.muted,
.field-help,
.safe-note,
.member-note,
.claim-meta,
.run-status-line > span,
.history-row > span,
.history-row > code {
    color: var(--ob-muted);
    font-size: 10px;
    line-height: 1.45;
}

.provenance-strip {
    display: flex;
    align-items: baseline;
    flex-wrap: wrap;
    gap: 7px 12px;
    margin-top: 20px;
    padding-top: 14px;
    border-top: 1px solid var(--ob-border-soft);
}

.provenance-strip strong {
    color: var(--ob-text);
    font-size: 12px;
}

.provenance-strip > span:not(.fact-label):not(.warning-text) {
    color: var(--ob-muted);
    font-size: 10px;
}

.warning-text {
    color: #8b5b1a;
    font-size: 10px;
    line-height: 1.45;
}

.ready-text {
    color: #2f6b3b;
    font-size: 10px;
}

.blocker-list {
    margin-top: 15px;
    padding: 11px 13px;
    border: 1px solid #e2c79e;
    border-radius: 8px;
    background: #fffaf0;
    color: #6d5118;
    font-size: 11px;
}

.blocker-list ul,
.qualification-columns ul,
.offer-readiness ul {
    margin: 8px 0 0;
    padding-left: 18px;
}

.blocker-list li,
.qualification-columns li,
.offer-readiness li {
    margin: 4px 0;
    line-height: 1.4;
}

.blocker-list code {
    display: inline-block;
    margin-left: 7px;
    color: #8b6b37;
    font-size: 9px;
}

.capability-list {
    max-width: 660px;
    margin-top: 18px;
}

.capability-row {
    justify-content: space-between;
    gap: 12px;
    padding: 8px 0;
    border-bottom: 1px solid var(--ob-border-soft);
    color: var(--ob-text);
    font-size: 11px;
}

.capability-row:last-child {
    border-bottom: 0;
}

.card-actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 9px;
    margin-top: 18px;
}

button,
.primary-button,
.secondary-button,
.danger-button,
.quiet-button,
.text-button {
    min-height: 34px;
    border-radius: 7px;
    font-family: inherit;
    font-size: 11px;
    font-weight: 650;
    cursor: pointer;
}

button:focus-visible,
a:focus-visible,
summary:focus-visible,
input:focus-visible,
textarea:focus-visible {
    outline: 2px solid var(--ob-focus);
    outline-offset: 2px;
}

button:disabled {
    cursor: not-allowed;
    opacity: 0.52;
}

.primary-button,
.secondary-button,
.danger-button,
.quiet-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding: 7px 12px;
    border: 1px solid transparent;
}

.primary-button {
    background: var(--ob-accent-strong);
    color: #fff;
}

.primary-button:hover:not(:disabled) {
    background: #000;
}

.secondary-button,
.quiet-button {
    border-color: var(--ob-control-border);
    background: #fff;
    color: var(--ob-text);
}

.secondary-button:hover:not(:disabled),
.quiet-button:hover:not(:disabled) {
    border-color: #a8a39c;
}

.danger-button {
    border-color: #d4a29a;
    background: #fff7f4;
    color: #8a3d2e;
}

.danger-button:hover:not(:disabled) {
    border-color: #bb7d71;
}

.quiet-button {
    min-height: 30px;
    padding: 5px 9px;
    font-size: 10px;
}

.quiet-button svg {
    width: 13px;
    height: 13px;
}

.text-button {
    min-height: auto;
    padding: 0;
    border: 0;
    background: transparent;
    color: #4b6172;
    text-decoration: underline;
    text-underline-offset: 2px;
}

.empty-state {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-top: 17px;
    padding: 16px;
    border: 1px dashed var(--ob-border-strong);
    border-radius: 8px;
    background: var(--ob-accent-tint-soft);
    color: var(--ob-muted);
    font-size: 11px;
    line-height: 1.45;
}

.empty-state strong {
    color: var(--ob-text);
}

.empty-state.compact {
    margin-top: 15px;
}

.run-summary {
    margin-top: 17px;
}

.run-status-line {
    flex-wrap: wrap;
    gap: 8px 12px;
}

.run-facts {
    grid-template-columns: repeat(4, minmax(0, 1fr));
    padding: 13px 0;
    border-bottom: 1px solid var(--ob-border-soft);
}

.run-facts code {
    font-size: 9px;
}

.stage-timeline {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 7px;
    margin: 17px 0 0;
    padding: 0;
    list-style: none;
}

.stage-timeline li {
    position: relative;
    min-width: 0;
    padding: 9px 8px;
    border: 1px solid var(--ob-border);
    border-radius: 7px;
    background: var(--ob-accent-tint-soft);
}

.stage-timeline li[data-stage-state='succeeded'],
.stage-timeline li[data-stage-state='skipped'] {
    border-color: #b7d2bc;
    background: #f5faf5;
}

.stage-timeline li[data-stage-state='running'] {
    border-color: #e0c68c;
    background: #fffaf0;
}

.stage-timeline li[data-stage-state='failed'],
.stage-timeline li[data-stage-state='paid_result_lost'] {
    border-color: #ddb0a6;
    background: #fff6f3;
}

.stage-number {
    display: grid;
    width: 20px;
    height: 20px;
    margin-bottom: 8px;
    place-items: center;
    border-radius: 50%;
    background: #e6e2dc;
    color: var(--ob-muted);
    font-size: 9px;
    font-weight: 700;
}

.stage-timeline strong,
.stage-timeline li > div > span,
.stage-timeline small {
    display: block;
    overflow-wrap: anywhere;
    font-size: 10px;
    line-height: 1.35;
}

.stage-timeline strong {
    color: var(--ob-text);
}

.stage-timeline li > div > span {
    margin-top: 3px;
    color: var(--ob-muted);
}

.run-actions {
    margin-bottom: 2px;
}

.run-history {
    margin-top: 20px;
    padding-top: 15px;
    border-top: 1px solid var(--ob-border-soft);
}

.subheading-row {
    justify-content: space-between;
    gap: 10px;
}

.subheading-row h3,
.claim-group h3,
.qualification-columns h3,
.review-history h3,
.conversion-events h3 {
    margin: 0;
    color: var(--ob-text);
    font-size: 12px;
    font-weight: 680;
}

.history-row {
    gap: 10px;
    margin-top: 8px;
    padding: 8px 9px;
    border: 1px solid var(--ob-border-soft);
    border-radius: 7px;
    color: var(--ob-text);
    text-decoration: none;
}

.history-row:hover,
.history-row.current {
    border-color: var(--ob-border-strong);
    background: var(--ob-accent-tint-soft);
}

.history-row > span:first-child {
    flex: 1;
}

.history-row > code {
    font-size: 9px;
}

.count-badge {
    display: inline-flex;
    min-height: 23px;
    align-items: center;
    padding: 4px 8px;
    border: 1px solid var(--ob-border);
    border-radius: 999px;
    color: var(--ob-muted);
    font-size: 10px;
    font-weight: 650;
}

.claim-groups {
    display: grid;
    gap: 18px;
    margin-top: 18px;
}

.claim-group {
    padding-top: 15px;
    border-top: 1px solid var(--ob-border-soft);
}

.claim-group:first-child {
    padding-top: 0;
    border-top: 0;
}

.claim-group .subheading-row > span {
    color: var(--ob-muted);
    font-size: 10px;
}

.empty-line {
    margin: 9px 0 0;
    color: var(--ob-muted);
    font-size: 10px;
}

.claim-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 15px;
    margin-top: 9px;
    padding: 11px;
    border: 1px solid var(--ob-border-soft);
    border-radius: 8px;
}

.claim-main {
    min-width: 0;
}

.claim-title-line {
    flex-wrap: wrap;
    gap: 7px;
}

.claim-title-line strong {
    color: var(--ob-text);
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 11px;
    overflow-wrap: anywhere;
}

.claim-main p {
    margin: 7px 0 4px;
    color: var(--ob-text);
    font-size: 12px;
    line-height: 1.45;
    overflow-wrap: anywhere;
}

.claim-actions {
    display: flex;
    min-width: 118px;
    flex-direction: column;
    align-items: flex-end;
    gap: 9px;
}

.evidence-details {
    min-width: 0;
}

.evidence-details summary {
    color: #4b6172;
    font-size: 10px;
    font-weight: 650;
    cursor: pointer;
    text-align: right;
}

.evidence-item,
.prior-claim {
    display: flex;
    min-width: min(330px, 75vw);
    flex-direction: column;
    gap: 4px;
    margin-top: 8px;
    padding: 9px;
    border: 1px solid var(--ob-border);
    border-radius: 7px;
    background: #fff;
    text-align: left;
}

.prior-claim code {
    overflow-wrap: anywhere;
}

.evidence-topline {
    justify-content: space-between;
    gap: 8px;
}

.evidence-topline strong,
.evidence-topline span {
    font-size: 10px;
}

.evidence-topline span {
    color: var(--ob-muted);
}

.correction-form,
.review-controls,
.offer-controls {
    margin-top: 18px;
    padding: 14px;
    border: 1px solid var(--ob-border-strong);
    border-radius: 8px;
    background: var(--ob-accent-tint-soft);
}

label {
    display: block;
    margin-top: 12px;
    color: var(--ob-text);
    font-size: 10px;
    font-weight: 650;
}

input,
textarea {
    display: block;
    width: 100%;
    margin-top: 5px;
    padding: 8px 9px;
    border: 1px solid var(--ob-control-border);
    border-radius: 6px;
    background: #fff;
    color: var(--ob-text);
    font: inherit;
    font-size: 11px;
}

textarea {
    min-height: 58px;
    resize: vertical;
}

.correction-form .field-help {
    display: block;
    margin-top: 5px;
}

.correction-form .primary-button {
    margin-top: 13px;
}

.qualification-content {
    margin-top: 17px;
}

.qualification-overview {
    grid-template-columns: 1.4fr repeat(3, minmax(0, 1fr));
    margin-top: 0;
}

.confidence-meter strong {
    display: block;
    margin-top: 4px;
    color: var(--ob-text);
    font-size: 23px;
    letter-spacing: -0.04em;
}

.confidence-meter > span:last-child {
    display: block;
    margin-top: 4px;
    color: var(--ob-muted);
    font-size: 10px;
}

.meter-track {
    height: 5px;
    margin-top: 7px;
    overflow: hidden;
    border-radius: 99px;
    background: var(--ob-border-soft);
}

.meter-track i {
    display: block;
    height: 100%;
    border-radius: inherit;
    background: #4a8155;
}

.explanation {
    margin: 17px 0 0;
    color: var(--ob-text);
    font-size: 12px;
    line-height: 1.55;
}

.qualification-columns {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 22px;
    margin-top: 18px;
    padding-top: 15px;
    border-top: 1px solid var(--ob-border-soft);
}

.qualification-columns ul {
    margin-top: 7px;
    color: var(--ob-text);
    font-size: 11px;
}

.review-controls .card-actions {
    margin-top: 13px;
}

.offer-controls {
    margin-top: 18px;
}

.offer-controls .card-actions {
    margin-top: 13px;
}

.review-history {
    margin-top: 19px;
    padding-top: 15px;
    border-top: 1px solid var(--ob-border-soft);
}

.review-event {
    margin-top: 8px;
    padding: 9px;
    border-left: 2px solid var(--ob-border-strong);
    background: var(--ob-accent-tint-soft);
}

.review-event strong,
.review-event span,
.review-event p {
    display: block;
    font-size: 10px;
    line-height: 1.4;
}

.review-event span {
    margin-top: 2px;
    color: var(--ob-muted);
}

.review-event p {
    margin: 5px 0 0;
    color: var(--ob-text);
}

.reason-version {
    margin-top: 14px;
    padding: 13px;
    border: 1px solid var(--ob-border-soft);
    border-radius: 8px;
}

.reason-visibility {
    flex-wrap: wrap;
    gap: 8px;
}

.reason-visibility > span {
    color: var(--ob-muted);
    font-size: 10px;
}

.reason-version dl,
.offer-summary dl {
    display: grid;
    gap: 10px;
    margin: 15px 0 0;
}

.reason-version dt,
.offer-summary dt {
    color: var(--ob-muted);
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.reason-version dd,
.offer-summary dd {
    margin: 3px 0 0;
    color: var(--ob-text);
    font-size: 11px;
    line-height: 1.45;
}

.reason-version .field-help {
    display: block;
    margin-top: 13px;
    overflow-wrap: anywhere;
}

.offer-content {
    display: grid;
    grid-template-columns: minmax(0, 1.2fr) minmax(220px, 0.8fr);
    gap: 24px;
    margin-top: 17px;
}

.offer-summary h3 {
    margin: 0;
    color: var(--ob-text);
    font-size: 16px;
}

.offer-summary > p {
    margin: 7px 0 0;
    color: var(--ob-muted);
    font-size: 12px;
    line-height: 1.5;
}

.offer-readiness {
    padding: 12px;
    border: 1px solid var(--ob-border-soft);
    border-radius: 8px;
    background: var(--ob-accent-tint-soft);
}

.offer-readiness strong {
    display: block;
    margin-top: 4px;
    color: var(--ob-text);
    font-size: 12px;
}

.offer-readiness .field-help {
    display: block;
    margin-top: 12px;
}

.conversion-summary {
    grid-template-columns: 1fr 1fr;
    margin-top: 17px;
}

.conversion-summary strong {
    margin-top: 5px;
    font-size: 17px;
}

.conversion-summary .field-help {
    display: block;
    margin-top: 4px;
}

.conversion-events {
    margin-top: 19px;
    padding-top: 15px;
    border-top: 1px solid var(--ob-border-soft);
}

.conversion-event {
    justify-content: space-between;
    gap: 12px;
    margin-top: 8px;
    padding: 9px;
    border: 1px solid var(--ob-border-soft);
    border-radius: 7px;
}

.conversion-event > div {
    display: flex;
    min-width: 0;
    flex: 1;
    flex-direction: column;
    gap: 2px;
}

.conversion-event strong,
.conversion-event span {
    overflow-wrap: anywhere;
    font-size: 10px;
}

.conversion-event > div > span {
    color: var(--ob-muted);
}

@media (max-width: 860px) {
    .identity-grid,
    .preflight-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .stage-timeline {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .qualification-overview {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .offer-content {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 620px) {
    .account-intelligence-page {
        padding: 17px 11px 34px;
    }

    .intelligence-page-head {
        display: block;
    }

    .head-state {
        align-items: flex-start;
        padding-top: 15px;
    }

    .identity-grid,
    .preflight-grid,
    .run-facts,
    .qualification-overview,
    .qualification-columns,
    .conversion-summary {
        grid-template-columns: 1fr 1fr;
        gap: 14px;
    }

    .stage-timeline {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .claim-row {
        display: block;
    }

    .claim-actions {
        min-width: 0;
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
        margin-top: 10px;
    }

    .evidence-details {
        flex: 1;
    }

    .evidence-details summary {
        text-align: left;
    }

    .evidence-item {
        min-width: 0;
    }

    .conversion-event {
        align-items: flex-start;
        flex-wrap: wrap;
    }

    .conversion-event > .muted {
        width: 100%;
    }
}

@media (max-width: 390px) {
    .intelligence-card {
        padding: 14px 12px;
        border-radius: 10px;
    }

    .identity-grid,
    .preflight-grid,
    .run-facts,
    .qualification-overview,
    .qualification-columns,
    .conversion-summary {
        grid-template-columns: 1fr;
    }

    .stage-timeline {
        grid-template-columns: 1fr 1fr;
        gap: 6px;
    }

    .stage-timeline li {
        padding: 8px 7px;
    }

    .history-row {
        align-items: flex-start;
        flex-wrap: wrap;
    }

    .history-row > span:first-child {
        width: 100%;
        flex: auto;
    }

    .card-actions > button {
        width: 100%;
    }

    .member-note,
    .safe-note {
        display: block;
        width: 100%;
    }
}

@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        scroll-behavior: auto !important;
        transition-duration: 0.01ms !important;
        animation-duration: 0.01ms !important;
    }
}
</style>
