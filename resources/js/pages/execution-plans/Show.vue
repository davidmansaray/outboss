<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppWorkspace from '@/layouts/AppWorkspace.vue';

type IdentifierMap = Record<string, string>;

type PlanAction = {
    id: string;
    position: number;
    kind: string;
    status: string;
    recipient: {
        address: string | null;
        person_id: number | null;
        account_id: number | null;
        audience_member_id: string;
    };
    sender: {
        address: string;
        display_name: string | null;
        reply_to: string | null;
        mailbox_id: number;
        provider_connection_id: number;
        provider_connection_version: number | null;
    };
    subject: string | null;
    body_text: string | null;
    body_html: string | null;
    schedule: {
        not_before: string;
        not_after: string;
    };
    selected_claim_ids: string[];
    selected_evidence_ids: string[];
    selected_claim_safety_hashes: Record<string, string>;
    evidence_review: {
        reason_to_contact_version_id: string | null;
        fresh_until: string | null;
    };
    offer: {
        content_hash: string | null;
        contract_hash: string | null;
        destination_url: string | null;
        response_route: string | null;
    };
    lineage: IdentifierMap;
    hashes: IdentifierMap;
};

type ExecutionPlan = {
    id: string;
    version: number;
    status: string;
    created_at: string | null;
    compiled_at: string | null;
    superseded_at: string | null;
    created_by: { id: number; name: string };
    objective: {
        id: string;
        run_id: string;
        version_id: string;
        name: string;
        target_kind: string;
        target_count: number | null;
        target_value: string | null;
        currency: string | null;
        starts_at: string | null;
        ends_at: string | null;
    };
    lineage: Record<string, string | null> & { experiment_name: string | null };
    model: IdentifierMap;
    counts: {
        arms: number;
        accounts: number;
        people: number;
        actions: number;
        omissions: number;
    };
    audience_counts: {
        included: number;
        excluded: number;
        uncertain: number;
        suppressed: number;
    };
    costs: {
        maximum_micros: number;
        estimated_micros: number;
        actual_micros: number;
    };
    hashes: IdentifierMap;
    omissions: { member_id: string; reason: string }[];
    actions: PlanAction[];
};

type ApprovalReview = {
    state: string;
    batch_id: string | null;
    plan_hash: string;
    display_manifest_hash: string;
    action_fingerprints: string[];
    action_count: number;
    policy_version_id: string;
    expires_at: string | null;
    decided_at: string | null;
    decision_by: { id: number; name: string } | null;
    reason_code: string | null;
    reason_detail: string | null;
    blockers: { code: string; message: string; action_id?: string }[];
    warnings: { code: string; message: string; action_id?: string }[];
    available_actions: string[];
    consequence: string;
    grants: {
        id: string;
        action_id: string;
        action_fingerprint: string;
        decision: string;
        expires_at: string | null;
        latest_event: string | null;
    }[];
};

type ExecutionReview = {
    is_owner: boolean;
    state: string;
    plan_id: string;
    plan_hash: string;
    available_actions: string[];
    blockers: { code: string; message: string; campaign_id?: number }[];
    latest_compilation: {
        id: string;
        attempt: number;
        state: string;
        safe_error_code: string | null;
        safe_error_detail: string | null;
        started_at: string;
        finished_at: string | null;
    } | null;
    campaigns: {
        id: number;
        name: string;
        status: string;
        arm_id: string;
        arm_label: string;
        href: string;
    }[];
    action_progress: Record<string, number>;
    outcomes: {
        exposures: {
            total: number;
            provider_accepted: number;
            confirmed_sent: number;
            uncertain: number;
        };
        conversations: { total: number; replied: number; stopped: number };
        conversions: { total: number };
        attributions: {
            attributed: number;
            ambiguous: number;
            rejected: number;
            pending: number;
        };
    };
    lineage: {
        exposures: {
            id: string;
            action_id: string;
            send_attempt_id: number;
            message_id: number;
            thread_id: number;
            source_event_id: string;
            boundary: string;
            occurred_at: string | null;
        }[];
        conversations: {
            id: string;
            action_id: string;
            exposure_id: string;
            thread_id: number;
            state: string;
            latest_event_at: string | null;
        }[];
        conversions: {
            event_id: string;
            assessment_id: string | null;
            assessment_state: string;
            selected_exposure_id: string | null;
            method: string | null;
            reason_codes: string[];
            assessed_at: string | null;
        }[];
    };
    consequence: string;
};

const props = defineProps<{
    plan: ExecutionPlan;
    approval: ApprovalReview;
    execution?: ExecutionReview;
    errors?: Record<string, string>;
    safety: {
        creates_campaign: false;
        grants_sending_authority: false;
        compilation_sends_email?: false;
        available_actions: string[];
    };
}>();

const decisionReason = ref('');
const decisionPending = ref(false);
const decisionKey = ref(crypto.randomUUID());
const executionPending = ref<'compile' | 'launch' | null>(null);
const executionKey = ref(crypto.randomUUID());
const launchConfirmationOpen = ref(false);

const executionReview = computed<ExecutionReview>(() =>
    props.execution
        ? props.execution
        : {
              is_owner: false,
              state: props.plan.status,
              plan_id: props.plan.id,
              plan_hash: props.plan.hashes.plan,
              available_actions: [],
              blockers: [],
              latest_compilation: null,
              campaigns: [],
              action_progress: {},
              outcomes: {
                  exposures: {
                      total: 0,
                      provider_accepted: 0,
                      confirmed_sent: 0,
                      uncertain: 0,
                  },
                  conversations: { total: 0, replied: 0, stopped: 0 },
                  conversions: { total: 0 },
                  attributions: {
                      attributed: 0,
                      ambiguous: 0,
                      rejected: 0,
                      pending: 0,
                  },
              },
              lineage: { exposures: [], conversations: [], conversions: [] },
              consequence: 'This plan is not ready to prepare or launch.',
          },
);

const lineageAvailable = computed(
    () =>
        executionReview.value.lineage.exposures.length +
            executionReview.value.lineage.conversations.length +
            executionReview.value.lineage.conversions.length >
        0,
);
const lineageThreadIds = computed(
    () =>
        new Set(
            executionReview.value.lineage.conversations.map(
                (conversation) => conversation.thread_id,
            ),
        ),
);
const lineageExposureIds = computed(
    () =>
        new Set(
            executionReview.value.lineage.exposures.map(
                (exposure) => exposure.id,
            ),
        ),
);

const executionError = computed(
    () =>
        props.errors?.compile ??
        props.errors?.launch ??
        props.errors?.plan ??
        props.errors?.actions ??
        null,
);

const targetSummary = computed(() => {
    if (props.plan.objective.target_kind === 'count') {
        return `${props.plan.objective.target_count ?? 0} conversions`;
    }

    const amount = Number(props.plan.objective.target_value ?? 0);

    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: props.plan.objective.currency ?? 'USD',
    }).format(amount);
});

function label(value: string): string {
    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function formatDate(value: string | null): string {
    if (!value) {
        return 'Not set';
    }

    return new Intl.DateTimeFormat(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZoneName: 'short',
    }).format(new Date(value));
}

function formatCost(micros: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 6,
    }).format(micros / 1_000_000);
}

function shortReference(value: string | number): string {
    const reference = String(value);

    return reference.length > 12 ? `${reference.slice(0, 8)}…` : reference;
}

function claimSafetyEntries(action: PlanAction): [string, string][] {
    return Object.entries(action.selected_claim_safety_hashes ?? {}).sort(
        ([left], [right]) => left.localeCompare(right),
    );
}

function decide(action: 'approve' | 'reject' | 'revoke'): void {
    const consequence =
        action === 'approve'
            ? 'Approve only these exact action fingerprints? This does not prepare, launch, or send email.'
            : action === 'reject'
              ? 'Reject this entire fixed action batch?'
              : 'Revoke all remaining authority for this exact action batch?';

    if (!window.confirm(consequence)) {
        return;
    }

    const url =
        action === 'revoke'
            ? `/approval-batches/${props.approval.batch_id}/revoke`
            : `/execution-plans/${props.plan.id}/${action}`;
    decisionPending.value = true;
    router.post(
        url,
        {
            plan_hash: props.approval.plan_hash,
            display_manifest_hash: props.approval.display_manifest_hash,
            action_fingerprints: props.approval.action_fingerprints,
            reason: decisionReason.value || null,
        },
        {
            preserveScroll: true,
            headers: { 'Idempotency-Key': decisionKey.value },
            onFinish: () => {
                decisionPending.value = false;
                decisionKey.value = crypto.randomUUID();
            },
        },
    );
}

function execute(action: 'compile' | 'launch'): void {
    launchConfirmationOpen.value = false;
    executionPending.value = action;
    router.post(
        `/execution-plans/${props.plan.id}/${action}`,
        {
            plan_id: executionReview.value.plan_id,
            plan_hash: executionReview.value.plan_hash,
        },
        {
            preserveScroll: true,
            headers: { 'Idempotency-Key': executionKey.value },
            onFinish: () => {
                executionPending.value = null;
                executionKey.value = crypto.randomUUID();
            },
        },
    );
}
</script>

<template>
    <Head :title="`Execution plan · ${plan.objective.name}`" />
    <AppWorkspace area="campaigns" title="Execution plan">
        <template #sidebar>
            <Link class="plan-back" href="/campaigns">← Outreach</Link>
            <div class="plan-side-status">
                <span aria-hidden="true" />
                <strong>{{ label(plan.status) }}</strong>
            </div>
            <nav class="plan-side-nav" aria-label="Execution plan sections">
                <a href="#approval">Approval</a>
                <a href="#execution">Prepare and launch</a>
                <a href="#summary">Summary</a>
                <a href="#actions"
                    >Exact actions <small>{{ plan.actions.length }}</small></a
                >
                <a href="#omissions"
                    >Omissions <small>{{ plan.omissions.length }}</small></a
                >
                <a href="#hashes">Hashes and lineage</a>
            </nav>
        </template>

        <main class="plan-page" data-test="execution-plan-review">
            <header class="plan-heading">
                <div>
                    <p class="eyebrow">
                        Immutable plan · version {{ plan.version }}
                    </p>
                    <h1>{{ plan.objective.name }}</h1>
                    <p>
                        Review every frozen recipient, sender, message, evidence
                        reference, and scheduled window.
                    </p>
                </div>
                <span class="status-label">{{ label(plan.status) }}</span>
            </header>

            <section
                class="safety-notice"
                role="note"
                aria-label="Review-only safety boundary"
            >
                <ShieldCheck aria-hidden="true" />
                <div>
                    <strong>Exact owner approval checkpoint</strong>
                    <p>
                        Approval creates no external effect and does not launch
                        or send email. It grants authority only for the exact
                        fingerprints shown here, until the earliest safe expiry.
                        <template v-if="!approval.available_actions.length">
                            There is no approval action on this page.
                        </template>
                    </p>
                </div>
            </section>

            <section
                id="execution"
                class="plan-section execution-panel"
                aria-labelledby="execution-title"
                data-test="execution-controls"
            >
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">Execution</p>
                        <h2 id="execution-title">Prepare and launch</h2>
                    </div>
                    <span class="status-label">{{
                        label(executionReview.state)
                    }}</span>
                </div>

                <ol class="execution-steps" aria-label="Execution progress">
                    <li :class="{ complete: approval.state === 'approved' }">
                        <strong>1</strong>
                        <span>Approved</span>
                    </li>
                    <li
                        :class="{
                            complete: [
                                'compiled',
                                'executing',
                                'completed',
                            ].includes(executionReview.state),
                        }"
                    >
                        <strong>2</strong>
                        <span>Prepared</span>
                    </li>
                    <li
                        :class="{
                            complete: ['executing', 'completed'].includes(
                                executionReview.state,
                            ),
                        }"
                    >
                        <strong>3</strong>
                        <span>Launched</span>
                    </li>
                </ol>

                <p class="execution-consequence">
                    {{ executionReview.consequence }}
                </p>

                <div
                    v-if="executionError"
                    class="approval-blockers"
                    role="alert"
                    data-test="execution-error"
                >
                    <strong>The plan was not changed</strong>
                    <p>{{ executionError }}</p>
                </div>

                <div
                    v-if="executionReview.blockers.length"
                    class="approval-blockers"
                    role="alert"
                >
                    <strong>Not ready yet</strong>
                    <ul>
                        <li
                            v-for="blocker in executionReview.blockers"
                            :key="`${blocker.code}:${blocker.campaign_id ?? ''}`"
                        >
                            {{ blocker.message }}
                            <Link
                                v-if="blocker.campaign_id"
                                :href="`/campaigns/${blocker.campaign_id}`"
                                >Open campaign</Link
                            >
                        </li>
                    </ul>
                </div>

                <div
                    v-if="executionReview.latest_compilation"
                    class="compilation-summary"
                >
                    <strong
                        >Preparation attempt
                        {{ executionReview.latest_compilation.attempt }}</strong
                    >
                    <span>{{
                        label(executionReview.latest_compilation.state)
                    }}</span>
                    <p
                        v-if="
                            executionReview.latest_compilation.safe_error_detail
                        "
                    >
                        {{
                            executionReview.latest_compilation.safe_error_detail
                        }}
                    </p>
                </div>

                <div
                    v-if="executionReview.campaigns.length"
                    class="campaign-links"
                >
                    <h3>Prepared campaigns</h3>
                    <ul>
                        <li
                            v-for="campaign in executionReview.campaigns"
                            :key="campaign.id"
                        >
                            <Link :href="campaign.href">{{
                                campaign.name
                            }}</Link>
                            <span>{{ campaign.arm_label }}</span>
                            <small>{{ label(campaign.status) }}</small>
                        </li>
                    </ul>
                </div>

                <dl
                    v-if="Object.keys(executionReview.action_progress).length"
                    class="execution-progress"
                >
                    <div
                        v-for="(
                            count, state
                        ) in executionReview.action_progress"
                        :key="state"
                    >
                        <dt>{{ label(state) }}</dt>
                        <dd>{{ count }}</dd>
                    </div>
                </dl>

                <dl class="outcome-progress" aria-label="Observed outcomes">
                    <div>
                        <dt>Accepted exposures</dt>
                        <dd>
                            {{ executionReview.outcomes.exposures.total }}
                        </dd>
                    </div>
                    <div>
                        <dt>Replies</dt>
                        <dd>
                            {{ executionReview.outcomes.conversations.replied }}
                        </dd>
                    </div>
                    <div>
                        <dt>Conversions</dt>
                        <dd>
                            {{ executionReview.outcomes.conversions.total }}
                        </dd>
                    </div>
                    <div>
                        <dt>Attributed</dt>
                        <dd>
                            {{
                                executionReview.outcomes.attributions.attributed
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt>Ambiguous</dt>
                        <dd>
                            {{
                                executionReview.outcomes.attributions.ambiguous
                            }}
                        </dd>
                    </div>
                </dl>

                <section
                    v-if="lineageAvailable"
                    class="execution-lineage"
                    aria-labelledby="execution-lineage-title"
                    data-test="execution-lineage"
                >
                    <div class="lineage-heading">
                        <div>
                            <p class="eyebrow">Observed lineage</p>
                            <h3 id="execution-lineage-title">
                                Recent execution trail
                            </h3>
                        </div>
                        <small>Newest 25 records per stage</small>
                    </div>

                    <div class="lineage-groups">
                        <div
                            v-if="executionReview.lineage.exposures.length"
                            class="lineage-group"
                        >
                            <h4>Exposures</h4>
                            <ol>
                                <li
                                    v-for="exposure in executionReview.lineage
                                        .exposures"
                                    :id="'lineage-exposure-' + exposure.id"
                                    :key="exposure.id"
                                >
                                    <strong>{{
                                        label(exposure.boundary)
                                    }}</strong>
                                    <p class="lineage-chain">
                                        <a
                                            :href="
                                                '#action-' + exposure.action_id
                                            "
                                            :title="exposure.action_id"
                                            >Action
                                            {{
                                                shortReference(
                                                    exposure.action_id,
                                                )
                                            }}</a
                                        >
                                        <span aria-hidden="true">→</span>
                                        <code
                                            :title="
                                                String(exposure.send_attempt_id)
                                            "
                                            >Attempt
                                            {{ exposure.send_attempt_id }}</code
                                        >
                                        <span aria-hidden="true">→</span>
                                        <a
                                            v-if="
                                                lineageThreadIds.has(
                                                    exposure.thread_id,
                                                )
                                            "
                                            :href="
                                                '#lineage-thread-' +
                                                exposure.thread_id
                                            "
                                            >Thread {{ exposure.thread_id }}</a
                                        >
                                        <code v-else
                                            >Thread
                                            {{ exposure.thread_id }}</code
                                        >
                                    </p>
                                    <small>
                                        Exposure
                                        <code :title="exposure.id">{{
                                            shortReference(exposure.id)
                                        }}</code>
                                        · {{ formatDate(exposure.occurred_at) }}
                                    </small>
                                </li>
                            </ol>
                        </div>

                        <div
                            v-if="executionReview.lineage.conversations.length"
                            class="lineage-group"
                        >
                            <h4>Conversations</h4>
                            <ol>
                                <li
                                    v-for="conversation in executionReview
                                        .lineage.conversations"
                                    :id="
                                        'lineage-thread-' +
                                        conversation.thread_id
                                    "
                                    :key="conversation.id"
                                >
                                    <strong>{{
                                        label(conversation.state)
                                    }}</strong>
                                    <p class="lineage-chain">
                                        <code
                                            >Thread
                                            {{ conversation.thread_id }}</code
                                        >
                                        <span aria-hidden="true">←</span>
                                        <a
                                            :href="
                                                '#lineage-exposure-' +
                                                conversation.exposure_id
                                            "
                                            :title="conversation.exposure_id"
                                            >Exposure
                                            {{
                                                shortReference(
                                                    conversation.exposure_id,
                                                )
                                            }}</a
                                        >
                                        <span aria-hidden="true">·</span>
                                        <a
                                            :href="
                                                '#action-' +
                                                conversation.action_id
                                            "
                                            :title="conversation.action_id"
                                            >Action
                                            {{
                                                shortReference(
                                                    conversation.action_id,
                                                )
                                            }}</a
                                        >
                                    </p>
                                    <small>
                                        Latest event
                                        {{
                                            formatDate(
                                                conversation.latest_event_at,
                                            )
                                        }}
                                    </small>
                                </li>
                            </ol>
                        </div>

                        <div
                            v-if="executionReview.lineage.conversions.length"
                            class="lineage-group"
                        >
                            <h4>Conversions</h4>
                            <ol>
                                <li
                                    v-for="conversion in executionReview.lineage
                                        .conversions"
                                    :key="conversion.event_id"
                                >
                                    <strong>{{
                                        label(conversion.assessment_state)
                                    }}</strong>
                                    <p class="lineage-chain">
                                        <code :title="conversion.event_id"
                                            >Event
                                            {{
                                                shortReference(
                                                    conversion.event_id,
                                                )
                                            }}</code
                                        >
                                        <template
                                            v-if="
                                                conversion.selected_exposure_id
                                            "
                                        >
                                            <span aria-hidden="true">→</span>
                                            <a
                                                v-if="
                                                    lineageExposureIds.has(
                                                        conversion.selected_exposure_id,
                                                    )
                                                "
                                                :href="
                                                    '#lineage-exposure-' +
                                                    conversion.selected_exposure_id
                                                "
                                                :title="
                                                    conversion.selected_exposure_id
                                                "
                                                >Exposure
                                                {{
                                                    shortReference(
                                                        conversion.selected_exposure_id,
                                                    )
                                                }}</a
                                            >
                                            <code v-else
                                                >Exposure
                                                {{
                                                    shortReference(
                                                        conversion.selected_exposure_id,
                                                    )
                                                }}</code
                                            >
                                        </template>
                                    </p>
                                    <small>
                                        {{
                                            conversion.method
                                                ? label(conversion.method)
                                                : 'Assessment pending'
                                        }}
                                        <template
                                            v-if="
                                                conversion.reason_codes.length
                                            "
                                        >
                                            ·
                                            {{
                                                conversion.reason_codes
                                                    .map(label)
                                                    .join(', ')
                                            }}
                                        </template>
                                        ·
                                        {{ formatDate(conversion.assessed_at) }}
                                    </small>
                                </li>
                            </ol>
                        </div>
                    </div>
                </section>

                <div
                    v-if="executionReview.available_actions.length"
                    class="execution-actions"
                >
                    <button
                        v-if="
                            executionReview.available_actions.includes(
                                'compile',
                            )
                        "
                        type="button"
                        :disabled="executionPending !== null"
                        @click="execute('compile')"
                    >
                        {{
                            executionPending === 'compile'
                                ? 'Preparing…'
                                : 'Prepare campaigns'
                        }}
                    </button>
                    <button
                        v-if="
                            executionReview.available_actions.includes('launch')
                        "
                        type="button"
                        :disabled="executionPending !== null"
                        @click="launchConfirmationOpen = true"
                    >
                        {{
                            executionPending === 'launch'
                                ? 'Launching…'
                                : 'Launch all arms'
                        }}
                    </button>
                </div>
                <div
                    v-if="launchConfirmationOpen"
                    class="launch-confirmation"
                    role="alertdialog"
                    aria-labelledby="launch-confirmation-title"
                    aria-describedby="launch-confirmation-detail"
                >
                    <div>
                        <strong id="launch-confirmation-title"
                            >Launch every experiment arm?</strong
                        >
                        <p id="launch-confirmation-detail">
                            The prepared campaigns will become active together.
                            Each message remains held until its approved
                            scheduled window opens.
                        </p>
                    </div>
                    <div>
                        <button
                            type="button"
                            class="secondary"
                            @click="launchConfirmationOpen = false"
                        >
                            Keep reviewing
                        </button>
                        <button
                            type="button"
                            data-test="confirm-launch"
                            :disabled="executionPending !== null"
                            @click="execute('launch')"
                        >
                            Confirm launch
                        </button>
                    </div>
                </div>
                <p v-else-if="!executionReview.is_owner" class="read-only-note">
                    Only the workspace owner can prepare or launch this plan.
                </p>
            </section>

            <section
                id="approval"
                class="plan-section approval-panel"
                aria-labelledby="approval-title"
                data-test="approval-checkpoint"
            >
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">Owner checkpoint</p>
                        <h2 id="approval-title">{{ label(approval.state) }}</h2>
                    </div>
                    <p>{{ approval.consequence }}</p>
                </div>

                <dl class="approval-identifiers">
                    <div>
                        <dt>Complete plan hash</dt>
                        <dd>
                            <code>{{ approval.plan_hash }}</code>
                        </dd>
                    </div>
                    <div>
                        <dt>Display manifest hash</dt>
                        <dd>
                            <code>{{ approval.display_manifest_hash }}</code>
                        </dd>
                    </div>
                    <div>
                        <dt>Policy version</dt>
                        <dd>
                            <code>{{ approval.policy_version_id }}</code>
                        </dd>
                    </div>
                    <div>
                        <dt>Approval expiry</dt>
                        <dd>{{ formatDate(approval.expires_at) }}</dd>
                    </div>
                </dl>

                <div
                    v-if="approval.blockers.length"
                    class="approval-blockers"
                    role="alert"
                >
                    <strong>Approval is blocked safely</strong>
                    <ul>
                        <li
                            v-for="blocker in approval.blockers"
                            :key="`${blocker.code}:${blocker.action_id ?? ''}`"
                        >
                            <code>{{ blocker.code }}</code> —
                            {{ blocker.message }}
                            <small v-if="blocker.action_id"
                                >Action {{ blocker.action_id }}</small
                            >
                        </li>
                    </ul>
                </div>
                <div v-if="approval.warnings.length" class="approval-warnings">
                    <strong>Review warnings</strong>
                    <ul>
                        <li
                            v-for="warning in approval.warnings"
                            :key="warning.code"
                        >
                            <code>{{ warning.code }}</code> —
                            {{ warning.message }}
                        </li>
                    </ul>
                </div>

                <div
                    v-if="approval.available_actions.length"
                    class="approval-controls"
                >
                    <label for="decision-reason">Decision reason</label>
                    <textarea
                        id="decision-reason"
                        v-model="decisionReason"
                        maxlength="500"
                        rows="3"
                        placeholder="Optional for approval; required for rejection or revocation"
                    />
                    <div>
                        <button
                            v-if="
                                approval.available_actions.includes('approve')
                            "
                            type="button"
                            :disabled="decisionPending"
                            @click="decide('approve')"
                        >
                            Approve exact batch
                        </button>
                        <button
                            v-if="approval.available_actions.includes('reject')"
                            type="button"
                            class="secondary"
                            :disabled="
                                decisionPending || !decisionReason.trim()
                            "
                            @click="decide('reject')"
                        >
                            Reject entire batch
                        </button>
                        <button
                            v-if="approval.available_actions.includes('revoke')"
                            type="button"
                            class="danger"
                            :disabled="
                                decisionPending || !decisionReason.trim()
                            "
                            @click="decide('revoke')"
                        >
                            Revoke remaining authority
                        </button>
                    </div>
                </div>

                <details class="technical-detail">
                    <summary>Exact action fingerprints</summary>
                    <ol class="fingerprint-list">
                        <li
                            v-for="fingerprint in approval.action_fingerprints"
                            :key="fingerprint"
                        >
                            <code>{{ fingerprint }}</code>
                        </li>
                    </ol>
                </details>
            </section>

            <section
                id="summary"
                class="plan-section"
                aria-labelledby="summary-title"
            >
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">Scope</p>
                        <h2 id="summary-title">Plan summary</h2>
                    </div>
                    <p>
                        Created by {{ plan.created_by.name }} ·
                        {{ formatDate(plan.created_at) }}
                    </p>
                </div>

                <dl class="summary-grid">
                    <div>
                        <dt>Objective target</dt>
                        <dd>{{ targetSummary }}</dd>
                    </div>
                    <div>
                        <dt>Horizon</dt>
                        <dd>{{ formatDate(plan.objective.starts_at) }}</dd>
                        <small
                            >through
                            {{ formatDate(plan.objective.ends_at) }}</small
                        >
                    </div>
                    <div>
                        <dt>Experiment</dt>
                        <dd>
                            {{
                                plan.lineage.experiment_name ??
                                'Not part of an experiment'
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt>Plan status</dt>
                        <dd>{{ label(plan.status) }}</dd>
                    </div>
                </dl>

                <div class="metric-bands">
                    <div class="metric-band">
                        <p>Planned volume</p>
                        <dl>
                            <div>
                                <dt>Arms</dt>
                                <dd>{{ plan.counts.arms }}</dd>
                            </div>
                            <div>
                                <dt>Accounts</dt>
                                <dd>{{ plan.counts.accounts }}</dd>
                            </div>
                            <div>
                                <dt>People</dt>
                                <dd>{{ plan.counts.people }}</dd>
                            </div>
                            <div>
                                <dt>Actions</dt>
                                <dd>{{ plan.counts.actions }}</dd>
                            </div>
                            <div>
                                <dt>Omissions</dt>
                                <dd>{{ plan.counts.omissions }}</dd>
                            </div>
                        </dl>
                    </div>
                    <div class="metric-band">
                        <p>Frozen audience</p>
                        <dl>
                            <div>
                                <dt>Included</dt>
                                <dd>{{ plan.audience_counts.included }}</dd>
                            </div>
                            <div>
                                <dt>Excluded</dt>
                                <dd>{{ plan.audience_counts.excluded }}</dd>
                            </div>
                            <div>
                                <dt>Uncertain</dt>
                                <dd>{{ plan.audience_counts.uncertain }}</dd>
                            </div>
                            <div>
                                <dt>Suppressed</dt>
                                <dd>{{ plan.audience_counts.suppressed }}</dd>
                            </div>
                        </dl>
                    </div>
                    <div class="metric-band">
                        <p>AI cost</p>
                        <dl>
                            <div>
                                <dt>Maximum</dt>
                                <dd>
                                    {{ formatCost(plan.costs.maximum_micros) }}
                                </dd>
                            </div>
                            <div>
                                <dt>Estimated</dt>
                                <dd>
                                    {{
                                        formatCost(plan.costs.estimated_micros)
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt>Actual</dt>
                                <dd>
                                    {{ formatCost(plan.costs.actual_micros) }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </section>

            <section
                id="actions"
                class="plan-section"
                aria-labelledby="actions-title"
            >
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">Frozen output</p>
                        <h2 id="actions-title">Exact actions</h2>
                    </div>
                    <p>
                        {{ plan.actions.length }} action{{
                            plan.actions.length === 1 ? '' : 's'
                        }}
                    </p>
                </div>

                <p v-if="!plan.actions.length" class="empty-state">
                    This plan contains no executable actions.
                </p>
                <article
                    v-for="(action, index) in plan.actions"
                    :key="action.id"
                    class="action-review"
                    :aria-labelledby="`action-${action.id}`"
                >
                    <header>
                        <div>
                            <p class="eyebrow">
                                Action {{ index + 1 }} ·
                                {{ label(action.kind) }}
                            </p>
                            <h3 :id="`action-${action.id}`">
                                {{
                                    action.recipient.address ??
                                    'Redacted recipient'
                                }}
                            </h3>
                        </div>
                        <span class="action-status">{{
                            label(action.status)
                        }}</span>
                    </header>

                    <dl class="delivery-grid">
                        <div>
                            <dt>Recipient</dt>
                            <dd>
                                {{ action.recipient.address ?? 'Redacted' }}
                            </dd>
                            <small
                                >Person
                                {{ action.recipient.person_id ?? 'redacted' }} ·
                                Account
                                {{
                                    action.recipient.account_id ?? 'redacted'
                                }}</small
                            >
                        </div>
                        <div>
                            <dt>Sender</dt>
                            <dd>
                                <template v-if="action.sender.display_name"
                                    >{{ action.sender.display_name }} &lt;{{
                                        action.sender.address
                                    }}&gt;</template
                                >
                                <template v-else>{{
                                    action.sender.address
                                }}</template>
                            </dd>
                            <small
                                >Reply-to
                                {{ action.sender.reply_to ?? 'not set' }} ·
                                Mailbox {{ action.sender.mailbox_id }}</small
                            >
                        </div>
                        <div>
                            <dt>Not before</dt>
                            <dd>
                                {{ formatDate(action.schedule.not_before) }}
                            </dd>
                            <small>{{ action.schedule.not_before }}</small>
                        </div>
                        <div>
                            <dt>Not after</dt>
                            <dd>{{ formatDate(action.schedule.not_after) }}</dd>
                            <small>{{ action.schedule.not_after }}</small>
                        </div>
                    </dl>

                    <div class="message-preview">
                        <div class="message-subject">
                            <span>Subject</span>
                            <strong>{{
                                action.subject ?? 'Redacted subject'
                            }}</strong>
                        </div>
                        <pre>{{
                            action.body_text ?? 'Message content redacted.'
                        }}</pre>
                    </div>

                    <section
                        class="offer-review"
                        aria-label="Frozen Offer contract"
                    >
                        <div>
                            <p class="eyebrow">Approved response path</p>
                            <h4>Frozen Offer contract</h4>
                        </div>
                        <dl class="offer-identifiers">
                            <div>
                                <dt>CTA destination URL</dt>
                                <dd>
                                    <code>{{
                                        action.offer.destination_url ||
                                        'Not recorded'
                                    }}</code>
                                </dd>
                            </div>
                            <div>
                                <dt>CTA response route</dt>
                                <dd>
                                    <code>{{
                                        action.offer.response_route ||
                                        'Not recorded'
                                    }}</code>
                                </dd>
                            </div>
                            <div>
                                <dt>Offer content hash</dt>
                                <dd>
                                    <code>{{
                                        action.offer.content_hash ||
                                        'Not recorded'
                                    }}</code>
                                </dd>
                            </div>
                            <div>
                                <dt>Offer contract hash</dt>
                                <dd>
                                    <code>{{
                                        action.offer.contract_hash ||
                                        'Not recorded'
                                    }}</code>
                                </dd>
                            </div>
                        </dl>
                    </section>

                    <details v-if="action.body_html" class="technical-detail">
                        <summary>Exact HTML body source</summary>
                        <pre>{{ action.body_html }}</pre>
                    </details>

                    <div class="evidence-grid">
                        <section class="evidence-review">
                            <h4>Reason to Contact and freshness</h4>
                            <dl class="claim-safety-list">
                                <div>
                                    <dt>Reason to Contact version</dt>
                                    <dd>
                                        <code>{{
                                            action.evidence_review
                                                .reason_to_contact_version_id ||
                                            'Not recorded'
                                        }}</code>
                                    </dd>
                                </div>
                                <div>
                                    <dt>Evidence fresh until</dt>
                                    <dd>
                                        {{
                                            formatDate(
                                                action.evidence_review
                                                    .fresh_until,
                                            )
                                        }}
                                    </dd>
                                    <small
                                        v-if="
                                            action.evidence_review.fresh_until
                                        "
                                        >{{
                                            action.evidence_review.fresh_until
                                        }}</small
                                    >
                                </div>
                            </dl>
                        </section>
                        <section>
                            <h4>Selected Claim identifiers</h4>
                            <p v-if="!action.selected_claim_ids.length">
                                None selected
                            </p>
                            <code
                                v-for="id in action.selected_claim_ids"
                                :key="id"
                                >{{ id }}</code
                            >
                        </section>
                        <section>
                            <h4>Selected evidence identifiers</h4>
                            <p v-if="!action.selected_evidence_ids.length">
                                None selected
                            </p>
                            <code
                                v-for="id in action.selected_evidence_ids"
                                :key="id"
                                >{{ id }}</code
                            >
                        </section>
                        <section class="claim-safety-review">
                            <h4>Selected Claim safety hashes</h4>
                            <p v-if="!claimSafetyEntries(action).length">
                                None recorded
                            </p>
                            <dl v-else class="claim-safety-list">
                                <div
                                    v-for="[
                                        claimId,
                                        safetyHash,
                                    ] in claimSafetyEntries(action)"
                                    :key="claimId"
                                >
                                    <dt>Claim ID</dt>
                                    <dd>
                                        <code>{{ claimId }}</code>
                                    </dd>
                                    <dt>Safety hash</dt>
                                    <dd>
                                        <code>{{ safetyHash }}</code>
                                    </dd>
                                </div>
                            </dl>
                        </section>
                    </div>

                    <details class="technical-detail">
                        <summary>Action hashes and lineage</summary>
                        <dl class="identifier-list">
                            <div
                                v-for="(value, key) in action.hashes"
                                :key="key"
                            >
                                <dt>{{ label(key) }}</dt>
                                <dd>
                                    <code>{{ value }}</code>
                                </dd>
                            </div>
                            <div
                                v-for="(value, key) in action.lineage"
                                :key="key"
                            >
                                <dt>{{ label(key) }}</dt>
                                <dd>
                                    <code>{{ value }}</code>
                                </dd>
                            </div>
                            <div>
                                <dt>Audience member ID</dt>
                                <dd>
                                    <code>{{
                                        action.recipient.audience_member_id
                                    }}</code>
                                </dd>
                            </div>
                            <div>
                                <dt>Provider connection</dt>
                                <dd>
                                    <code
                                        >{{
                                            action.sender.provider_connection_id
                                        }}
                                        · version
                                        {{
                                            action.sender
                                                .provider_connection_version ??
                                            'not set'
                                        }}</code
                                    >
                                </dd>
                            </div>
                        </dl>
                    </details>
                </article>
            </section>

            <section
                id="omissions"
                class="plan-section"
                aria-labelledby="omissions-title"
            >
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">Abstentions and exclusions</p>
                        <h2 id="omissions-title">Omissions</h2>
                    </div>
                    <p>Every omitted frozen-audience member is listed.</p>
                </div>
                <p v-if="!plan.omissions.length" class="empty-state">
                    No audience members were omitted from this plan.
                </p>
                <ol v-else class="omission-list">
                    <li
                        v-for="omission in plan.omissions"
                        :key="`${omission.member_id}:${omission.reason}`"
                    >
                        <div>
                            <span>Audience member</span
                            ><code>{{ omission.member_id }}</code>
                        </div>
                        <strong>{{ label(omission.reason) }}</strong>
                    </li>
                </ol>
            </section>

            <section
                id="hashes"
                class="plan-section"
                aria-labelledby="hashes-title"
            >
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">Verification</p>
                        <h2 id="hashes-title">Hashes and lineage</h2>
                    </div>
                    <p>Complete values are shown for byte-level review.</p>
                </div>
                <dl class="identifier-list plan-identifiers">
                    <div v-for="(value, key) in plan.hashes" :key="key">
                        <dt>{{ label(key) }}</dt>
                        <dd>
                            <code>{{ value || 'Not recorded' }}</code>
                        </dd>
                    </div>
                    <div>
                        <dt>Execution plan ID</dt>
                        <dd>
                            <code>{{ plan.id }}</code>
                        </dd>
                    </div>
                    <div>
                        <dt>Objective ID</dt>
                        <dd>
                            <code>{{ plan.objective.id }}</code>
                        </dd>
                    </div>
                    <div>
                        <dt>Objective run ID</dt>
                        <dd>
                            <code>{{ plan.objective.run_id }}</code>
                        </dd>
                    </div>
                    <div>
                        <dt>Objective version ID</dt>
                        <dd>
                            <code>{{ plan.objective.version_id }}</code>
                        </dd>
                    </div>
                    <div v-for="(value, key) in plan.lineage" :key="key">
                        <template v-if="key !== 'experiment_name'">
                            <dt>{{ label(key) }}</dt>
                            <dd>
                                <code>{{ value ?? 'Not applicable' }}</code>
                            </dd>
                        </template>
                    </div>
                </dl>

                <details class="technical-detail model-detail">
                    <summary>Model and validator versions</summary>
                    <dl class="identifier-list">
                        <div v-for="(value, key) in plan.model" :key="key">
                            <dt>{{ label(key) }}</dt>
                            <dd>
                                <code>{{ value }}</code>
                            </dd>
                        </div>
                    </dl>
                </details>
            </section>
        </main>
    </AppWorkspace>
</template>

<style scoped>
.plan-page {
    flex: 1;
    min-height: 0;
    overflow: auto;
    scroll-behavior: smooth;
    background: var(--ob-surface);
    padding: 34px clamp(24px, 4vw, 64px) 72px;
}

.plan-heading,
.section-heading,
.action-review > header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 24px;
}

.plan-heading {
    max-width: 1120px;
    margin: 0 auto 24px;
}

.plan-heading h1 {
    max-width: 760px;
    margin: 4px 0 8px;
    color: var(--ob-ink);
    font-size: clamp(28px, 3vw, 42px);
    font-weight: 650;
    letter-spacing: -0.035em;
    line-height: 1.08;
}

.plan-heading > div > p:last-child,
.section-heading > p {
    max-width: 560px;
    margin: 0;
    color: var(--ob-muted);
    font-size: 13px;
    line-height: 1.55;
}

.eyebrow {
    margin: 0;
    color: #756f66;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.status-label,
.action-status {
    flex: 0 0 auto;
    border: 1px solid #c7b98f;
    border-radius: 999px;
    background: #fbf7e9;
    color: #69551d;
    padding: 6px 10px;
    font-size: 11px;
    font-weight: 650;
}

.safety-notice {
    display: grid;
    grid-template-columns: 24px minmax(0, 1fr);
    gap: 14px;
    max-width: 1120px;
    margin: 0 auto 40px;
    border-radius: 10px;
    background: #22211e;
    color: #fff;
    padding: 18px 20px;
}

.safety-notice svg {
    width: 22px;
    margin-top: 1px;
    color: #ddd2ae;
}

.safety-notice strong {
    font-size: 14px;
}

.safety-notice p {
    margin: 4px 0 0;
    color: #d9d6d0;
    font-size: 12px;
    line-height: 1.55;
}

.plan-section {
    max-width: 1120px;
    margin: 0 auto;
    border-top: 1px solid var(--ob-border-strong);
    padding: 34px 0 42px;
    scroll-margin-top: 20px;
}

.approval-panel {
    border: 1px solid var(--ob-border-strong);
    border-radius: 10px;
    margin-bottom: 38px;
    background: #fff;
    padding: 26px;
}

.execution-panel {
    border: 1px solid var(--ob-border-strong);
    border-radius: 10px;
    margin-bottom: 38px;
    background: #fff;
    padding: 26px;
}

.execution-steps {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1px;
    overflow: hidden;
    margin: 0;
    border: 1px solid var(--ob-border);
    border-radius: 8px;
    background: var(--ob-border);
    padding: 0;
    list-style: none;
}

.execution-steps li {
    display: flex;
    align-items: center;
    gap: 9px;
    background: #f7f5f1;
    color: var(--ob-muted);
    padding: 13px 15px;
    font-size: 12px;
    font-weight: 620;
}

.execution-steps strong {
    display: grid;
    width: 22px;
    height: 22px;
    place-items: center;
    border: 1px solid #c9c3b9;
    border-radius: 999px;
    font-size: 10px;
}

.execution-steps li.complete {
    background: #f1f7f0;
    color: #335b35;
}

.execution-steps li.complete strong {
    border-color: #6f956e;
    background: #ddebdc;
}

.execution-consequence,
.compilation-summary p,
.read-only-note {
    margin: 16px 0 0;
    color: var(--ob-muted);
    font-size: 12px;
    line-height: 1.55;
}

.compilation-summary,
.campaign-links {
    display: grid;
    gap: 6px;
    margin-top: 20px;
    border-top: 1px solid var(--ob-border);
    padding-top: 18px;
    font-size: 12px;
}

.compilation-summary > span {
    color: var(--ob-muted);
}

.campaign-links h3 {
    margin: 0 0 4px;
    font-size: 12px;
}

.campaign-links ul {
    display: grid;
    gap: 8px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.campaign-links li {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto auto;
    align-items: center;
    gap: 12px;
}

.campaign-links a {
    color: var(--ob-ink);
    font-weight: 650;
}

.campaign-links span,
.campaign-links small {
    color: var(--ob-muted);
}

.execution-progress,
.outcome-progress {
    display: flex;
    flex-wrap: wrap;
    gap: 22px;
    margin: 20px 0 0;
    border-top: 1px solid var(--ob-border);
    padding-top: 18px;
}

.execution-progress dd,
.outcome-progress dd {
    font-size: 17px;
    font-weight: 650;
}

.execution-lineage {
    margin-top: 22px;
    border-top: 1px solid var(--ob-border);
    padding-top: 20px;
}

.lineage-heading {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 18px;
}

.lineage-heading h3,
.lineage-group h4 {
    margin: 0;
    font-size: 13px;
}

.lineage-heading > small {
    color: var(--ob-muted);
}

.lineage-groups {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 24px;
    margin-top: 16px;
}

.lineage-group ol {
    display: grid;
    gap: 10px;
    margin: 10px 0 0;
    padding: 0;
    list-style: none;
}

.lineage-group li {
    min-width: 0;
    border-left: 2px solid var(--ob-border-strong);
    padding-left: 10px;
    font-size: 11px;
}

.lineage-group li > strong {
    font-size: 11px;
}

.lineage-group li > small {
    display: block;
    margin-top: 5px;
    color: var(--ob-muted);
    line-height: 1.45;
}

.lineage-chain {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 5px;
    margin: 4px 0 0;
    line-height: 1.45;
}

.lineage-chain a {
    color: var(--ob-ink);
    font-weight: 650;
}

.lineage-chain code,
.lineage-group small code {
    overflow-wrap: anywhere;
    font-size: 10px;
}

.execution-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 22px;
}

.execution-actions button {
    border: 1px solid #22211e;
    border-radius: 7px;
    background: #22211e;
    color: #fff;
    padding: 9px 13px;
    font-size: 12px;
    font-weight: 650;
    cursor: pointer;
}

.execution-actions button:disabled {
    cursor: not-allowed;
    opacity: 0.55;
}

.launch-confirmation {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: end;
    gap: 24px;
    margin-top: 14px;
    border: 1px solid #ddc892;
    border-radius: 8px;
    background: #fffaf0;
    padding: 14px 16px;
    font-size: 12px;
}

.launch-confirmation p {
    max-width: 64ch;
    margin: 5px 0 0;
    color: var(--ob-muted);
    line-height: 1.5;
}

.launch-confirmation > div:last-child {
    display: flex;
    gap: 8px;
}

.launch-confirmation button {
    border: 1px solid #22211e;
    border-radius: 7px;
    background: #22211e;
    color: #fff;
    padding: 8px 11px;
    font: inherit;
    font-weight: 650;
    cursor: pointer;
}

.launch-confirmation button.secondary {
    border-color: var(--ob-border-strong);
    background: #fff;
    color: var(--ob-ink);
}

@media (max-width: 720px) {
    .launch-confirmation {
        grid-template-columns: 1fr;
        align-items: start;
    }

    .lineage-groups {
        grid-template-columns: 1fr;
    }
}

.approval-identifiers {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
    margin: 0;
}

.approval-identifiers dd,
.approval-identifiers code {
    overflow-wrap: anywhere;
}

.approval-blockers,
.approval-warnings {
    margin-top: 20px;
    border-radius: 8px;
    padding: 14px 16px;
    font-size: 12px;
}

.approval-blockers {
    border: 1px solid #e4bbb4;
    background: #fff5f3;
    color: #79392f;
}

.approval-warnings {
    border: 1px solid #dbc88f;
    background: #fffae9;
    color: #6b5720;
}

.approval-blockers ul,
.approval-warnings ul,
.fingerprint-list {
    margin: 8px 0 0;
    padding-left: 20px;
}

.approval-blockers li,
.approval-warnings li {
    margin-top: 6px;
}

.approval-blockers small {
    display: block;
}

.approval-controls {
    display: grid;
    gap: 8px;
    margin-top: 22px;
}

.approval-controls label {
    font-size: 12px;
    font-weight: 650;
}

.approval-controls textarea {
    width: 100%;
    border: 1px solid var(--ob-border-strong);
    border-radius: 7px;
    padding: 10px;
    resize: vertical;
}

.approval-controls > div {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.approval-controls button {
    border: 1px solid #22211e;
    border-radius: 7px;
    background: #22211e;
    color: #fff;
    padding: 9px 13px;
    font-size: 12px;
    font-weight: 650;
    cursor: pointer;
}

.approval-controls button.secondary {
    background: #fff;
    color: #22211e;
}

.approval-controls button.danger {
    border-color: #9b3e32;
    background: #9b3e32;
}

.approval-controls button:disabled {
    cursor: not-allowed;
    opacity: 0.55;
}

.fingerprint-list code {
    overflow-wrap: anywhere;
}

.section-heading {
    margin-bottom: 24px;
}

.section-heading h2 {
    margin: 3px 0 0;
    color: var(--ob-ink);
    font-size: 21px;
    font-weight: 650;
    letter-spacing: -0.02em;
}

.section-heading > p {
    max-width: 360px;
    text-align: right;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    margin: 0 0 28px;
}

.summary-grid > div {
    min-width: 0;
    border-left: 1px solid var(--ob-border);
    padding: 0 20px;
}

.summary-grid > div:first-child {
    border-left: 0;
    padding-left: 0;
}

dt,
.message-subject span,
.metric-band > p {
    color: #777168;
    font-size: 10px;
    font-weight: 650;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

dd {
    margin: 5px 0 0;
    color: var(--ob-text);
}

.summary-grid dd {
    font-size: 14px;
    font-weight: 620;
}

.summary-grid small,
.delivery-grid small {
    display: block;
    margin-top: 3px;
    color: #8a847a;
    font-size: 10px;
    line-height: 1.45;
    overflow-wrap: anywhere;
}

.metric-bands {
    display: grid;
    grid-template-columns: 1.15fr 1fr 0.85fr;
    gap: 1px;
    overflow: hidden;
    border: 1px solid var(--ob-border);
    border-radius: 9px;
    background: var(--ob-border);
}

.metric-band {
    background: var(--ob-accent-tint-soft);
    padding: 18px;
}

.metric-band > p {
    margin: 0 0 14px;
}

.metric-band dl {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    margin: 0;
}

.metric-band dd {
    margin-top: 2px;
    font-size: 17px;
    font-weight: 650;
}

.action-review {
    border: 1px solid var(--ob-border-strong);
    border-radius: 10px;
    margin-top: 18px;
    background: #fff;
    padding: 22px;
}

.action-review > header {
    padding-bottom: 18px;
}

.action-review h3 {
    margin: 4px 0 0;
    font-size: 18px;
    font-weight: 650;
    letter-spacing: -0.015em;
}

.delivery-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0;
    margin: 0;
    border-top: 1px solid var(--ob-border);
}

.delivery-grid > div {
    min-width: 0;
    border-bottom: 1px solid var(--ob-border);
    padding: 15px 18px 15px 0;
}

.delivery-grid > div:nth-child(even) {
    border-left: 1px solid var(--ob-border);
    padding-left: 18px;
}

.delivery-grid dd {
    overflow-wrap: anywhere;
    font-size: 13px;
    font-weight: 600;
}

.message-preview {
    margin: 22px 0 0;
    overflow: hidden;
    border: 1px solid var(--ob-border);
    border-radius: 8px;
    background: #fdfcfb;
}

.message-subject {
    display: grid;
    grid-template-columns: 64px minmax(0, 1fr);
    gap: 12px;
    border-bottom: 1px solid var(--ob-border);
    padding: 13px 16px;
}

.message-subject strong {
    overflow-wrap: anywhere;
    font-size: 13px;
    font-weight: 620;
}

.offer-review {
    display: grid;
    grid-template-columns: minmax(170px, 0.28fr) minmax(0, 1fr);
    gap: 24px;
    margin-top: 18px;
    border: 1px solid var(--ob-border);
    border-radius: 8px;
    background: var(--ob-accent-tint-soft);
    padding: 16px;
}

.offer-review h4 {
    margin: 4px 0 0;
    font-size: 13px;
    font-weight: 650;
}

.offer-identifiers {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px 20px;
    margin: 0;
}

.offer-identifiers > div,
.offer-identifiers dd {
    min-width: 0;
}

.offer-identifiers dd {
    margin-top: 4px;
}

pre {
    margin: 0;
    color: #34312d;
    font-family: 'IBM Plex Sans', sans-serif;
    font-size: 13px;
    line-height: 1.7;
    overflow-wrap: anywhere;
    padding: 18px 16px;
    white-space: pre-wrap;
}

.evidence-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 22px;
    margin-top: 22px;
}

.evidence-grid section {
    min-width: 0;
}

.evidence-grid h4 {
    margin: 0 0 8px;
    font-size: 12px;
    font-weight: 650;
}

.evidence-grid p,
.empty-state {
    margin: 0;
    color: var(--ob-muted);
    font-size: 13px;
}

.evidence-grid code {
    display: block;
    margin-top: 5px;
}

.claim-safety-review {
    grid-column: 1 / -1;
}

.claim-safety-list {
    display: grid;
    gap: 8px;
    margin: 0;
}

.claim-safety-list > div {
    display: grid;
    grid-template-columns: 74px minmax(0, 1fr);
    gap: 5px 12px;
    border-left: 2px solid var(--ob-border-strong);
    padding-left: 10px;
}

.claim-safety-list dd {
    min-width: 0;
    margin: 0;
}

.claim-safety-list code {
    margin-top: 0;
}

code {
    color: #514c45;
    font-family:
        ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 10px;
    overflow-wrap: anywhere;
}

.technical-detail {
    margin-top: 18px;
    border-top: 1px solid var(--ob-border);
    padding-top: 14px;
}

.technical-detail summary {
    color: #5f5951;
    cursor: pointer;
    font-size: 12px;
    font-weight: 620;
}

.technical-detail > pre {
    margin-top: 12px;
    border-radius: 7px;
    background: #f6f4f0;
    font-family:
        ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 10px;
}

.identifier-list {
    display: grid;
    gap: 10px;
    margin: 16px 0 0;
}

.identifier-list > div {
    display: grid;
    grid-template-columns: minmax(130px, 0.3fr) minmax(0, 1fr);
    gap: 18px;
    min-width: 0;
}

.identifier-list dd {
    min-width: 0;
    margin: 0;
}

.omission-list {
    margin: 0;
    padding: 0;
    list-style: none;
}

.omission-list li {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
    gap: 20px;
    border-top: 1px solid var(--ob-border);
    padding: 15px 0;
}

.omission-list li:first-child {
    border-top: 0;
}

.omission-list li div {
    display: grid;
    gap: 4px;
}

.omission-list span {
    color: var(--ob-muted);
    font-size: 10px;
}

.omission-list strong {
    font-size: 12px;
    font-weight: 620;
}

.plan-identifiers {
    margin-top: 0;
}

.model-detail {
    margin-top: 26px;
}

.plan-back {
    display: inline-block;
    margin: 2px 8px 18px;
    color: #6b655c;
    font-size: 12px;
    text-decoration: none;
}

.plan-back:hover {
    color: var(--ob-ink);
}

.plan-side-status {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0 8px 20px;
    color: #514a37;
    font-size: 12px;
}

.plan-side-status > span {
    width: 7px;
    height: 7px;
    border-radius: 999px;
    background: #a1832f;
}

.plan-side-nav {
    display: grid;
    gap: 2px;
}

.plan-side-nav a {
    display: flex;
    min-height: 38px;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    border-radius: 7px;
    color: #5e5952;
    padding: 0 10px;
    font-size: 12px;
    text-decoration: none;
}

.plan-side-nav a:hover,
.plan-side-nav a:focus-visible {
    background: #f0ede7;
    color: var(--ob-ink);
}

.plan-side-nav small {
    color: #8a847a;
    font-size: 10px;
}

@media (max-width: 1050px) {
    .summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        row-gap: 22px;
    }

    .summary-grid > div:nth-child(3) {
        border-left: 0;
        padding-left: 0;
    }

    .metric-bands {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 720px) {
    .plan-page {
        padding: 24px 18px 56px;
    }

    .plan-heading,
    .section-heading,
    .action-review > header {
        flex-direction: column;
        gap: 12px;
    }

    .section-heading > p {
        text-align: left;
    }

    .summary-grid,
    .delivery-grid,
    .evidence-grid,
    .offer-review,
    .offer-identifiers {
        grid-template-columns: 1fr;
    }

    .summary-grid > div,
    .summary-grid > div:nth-child(3) {
        border-left: 0;
        border-top: 1px solid var(--ob-border);
        padding: 14px 0 0;
    }

    .summary-grid > div:first-child {
        border-top: 0;
        padding-top: 0;
    }

    .delivery-grid > div:nth-child(even) {
        border-left: 0;
        padding-left: 0;
    }

    .action-review {
        padding: 18px;
    }

    .identifier-list > div,
    .omission-list li {
        grid-template-columns: 1fr;
        gap: 5px;
    }

    .execution-steps,
    .campaign-links li {
        grid-template-columns: 1fr;
    }
}
</style>
