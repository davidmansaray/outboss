<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import WorkspaceOpsSidebar from '@/components/WorkspaceOpsSidebar.vue';
import AppWorkspace from '@/layouts/AppWorkspace.vue';

type Mailbox = { id: number; email: string; status: string };
type Cohort = {
    id: number;
    key: string;
    label: string;
    domain_strategy: string;
    snapshot_hash: string | null;
    campaign_id: number | null;
    campaign_name: string | null;
    mailboxes: Mailbox[];
};
type Experiment = {
    id: number;
    name: string;
    hypothesis: string;
    status: string;
    primary_metric: string;
    target_assignments_per_cohort: number | null;
    frozen_at: string | null;
    started_at: string | null;
    completed_at: string | null;
    approval_evidence?: {
        compliance_review_confirmed?: boolean;
        legal_review_confirmed?: boolean;
        confirmed_at?: string;
        source?: string;
    };
    cohorts: Cohort[];
};
type ManagedMailbox = {
    id: number;
    mailbox_id: number | null;
    email: string;
    domain: string | null;
    status: string;
    mailbox_status: string | null;
};
type Metric = {
    assignment_count?: number;
    excluded_count?: number;
    exposed_count?: number;
    human_reply_count?: number;
    human_reply_rate?: number | null;
    interested_count?: number;
    bounced_count?: number;
    unsubscribed_count?: number;
};

const props = defineProps<{
    experiment: Experiment;
    report: {
        summary?: Metric;
        cohorts?: Record<string, Metric>;
        warnings?: string[];
    };
    managedMailboxes: ManagedMailbox[];
    campaigns: { id: number; name: string; status: string }[];
    candidateCount: number;
    canManage: boolean;
}>();

const page = usePage();
const flash = computed(
    () =>
        (page.props.flash as
            { success?: string; error?: string } | undefined) ?? {},
);
const selectedMailboxes = reactive<Record<number, number[]>>(
    Object.fromEntries(
        props.experiment.cohorts.map((cohort) => [
            cohort.id,
            cohort.mailboxes.map((mailbox) => mailbox.id),
        ]),
    ),
);
const prepareForm = reactive({
    template_campaign_id: '',
    compliance_review_confirmed: false,
    legal_review_confirmed: false,
});

function attach(cohort: Cohort) {
    if (!props.canManage) {
        return;
    }

    router.post(
        `/settings/mail-experiments/${props.experiment.id}/cohorts/${cohort.id}/mailboxes`,
        { mailbox_ids: selectedMailboxes[cohort.id] ?? [] },
        { preserveScroll: true },
    );
}

function prepare() {
    if (!props.canManage) {
        return;
    }

    router.post(
        `/settings/mail-experiments/${props.experiment.id}/prepare`,
        {
            ...(prepareForm.template_campaign_id
                ? {
                      template_campaign_id: Number(
                          prepareForm.template_campaign_id,
                      ),
                  }
                : {}),
            compliance_review_confirmed:
                prepareForm.compliance_review_confirmed,
            legal_review_confirmed: prepareForm.legal_review_confirmed,
        },
        { preserveScroll: true },
    );
}

function action(
    name: 'balance' | 'freeze' | 'launch' | 'pause' | 'resume' | 'complete',
) {
    if (props.canManage) {
        router.post(
            `/settings/mail-experiments/${props.experiment.id}/${name}`,
            {},
            { preserveScroll: true },
        );
    }
}

function label(value: string | null | undefined): string {
    return (value ?? 'unknown').replaceAll('_', ' ');
}

function percent(value: number | null | undefined): string {
    return typeof value === 'number' ? `${(value * 100).toFixed(2)}%` : 'n/a';
}

function metric(value: number | undefined): number {
    return value ?? 0;
}
</script>

<template>
    <Head :title="`${experiment.name} (internal)`" />
    <AppWorkspace
        area="mail-experiments"
        title="Mail experiment"
        :show-sidebar-title="false"
    >
        <template #sidebar>
            <WorkspaceOpsSidebar
                current="mail-experiments"
                :show-internal="canManage"
            />
        </template>
        <div class="pane experiment-show" data-test="mail-experiment-show">
            <header class="experiment-head">
                <div>
                    <Link class="back-link" href="/settings/mail-experiments"
                        >← All experiments</Link
                    >
                    <p class="eyebrow">{{ label(experiment.status) }}</p>
                    <h1>{{ experiment.name }}</h1>
                    <p class="hint">{{ experiment.hypothesis }}</p>
                </div>
                <div class="actions lifecycle-actions" v-if="canManage">
                    <button
                        class="btn ghost"
                        type="button"
                        @click="action('balance')"
                    >
                        Review balance
                    </button>
                    <button
                        v-if="!experiment.frozen_at"
                        class="btn ghost"
                        type="button"
                        @click="action('freeze')"
                    >
                        Freeze
                    </button>
                    <button
                        v-if="
                            experiment.status === 'ready' &&
                            experiment.frozen_at
                        "
                        class="btn"
                        type="button"
                        @click="action('launch')"
                    >
                        Launch
                    </button>
                    <button
                        v-if="experiment.status === 'running'"
                        class="btn ghost"
                        type="button"
                        @click="action('pause')"
                    >
                        Pause
                    </button>
                    <button
                        v-if="experiment.status === 'paused'"
                        class="btn"
                        type="button"
                        data-test="resume-experiment"
                        @click="action('resume')"
                    >
                        Resume
                    </button>
                    <button
                        v-if="
                            experiment.status === 'running' ||
                            experiment.status === 'paused'
                        "
                        class="btn ghost"
                        type="button"
                        @click="action('complete')"
                    >
                        Complete
                    </button>
                </div>
            </header>

            <p v-if="flash.success" class="hint" data-test="flash-success">
                {{ flash.success }}
            </p>
            <p v-if="flash.error" class="bad" data-test="flash-error">
                {{ flash.error }}
            </p>

            <section class="box prep-box">
                <div>
                    <h2>Prepare assignments</h2>
                    <p class="meta">
                        {{ candidateCount }} workspace prospects are available
                        to evaluate. Preparation stores exclusions and immutable
                        provider snapshots.
                    </p>
                </div>
                <form
                    class="prepare-form"
                    @submit.prevent="prepare"
                    v-if="canManage"
                >
                    <label>
                        Template campaign
                        <select v-model="prepareForm.template_campaign_id">
                            <option value="">
                                Use existing cohort campaigns
                            </option>
                            <option
                                v-for="campaign in campaigns"
                                :key="campaign.id"
                                :value="campaign.id"
                            >
                                {{ campaign.name }} ({{
                                    label(campaign.status)
                                }})
                            </option>
                        </select>
                    </label>
                    <label class="approval-option">
                        <input
                            v-model="prepareForm.compliance_review_confirmed"
                            type="checkbox"
                            required
                            :disabled="Boolean(experiment.frozen_at)"
                        />
                        <span>
                            I confirm this exact audience passed compliance
                            review.
                        </span>
                    </label>
                    <label class="approval-option">
                        <input
                            v-model="prepareForm.legal_review_confirmed"
                            type="checkbox"
                            required
                            :disabled="Boolean(experiment.frozen_at)"
                        />
                        <span>
                            I confirm this exact audience passed legal review.
                        </span>
                    </label>
                    <button
                        class="btn"
                        type="submit"
                        :disabled="
                            Boolean(experiment.frozen_at) ||
                            !prepareForm.compliance_review_confirmed ||
                            !prepareForm.legal_review_confirmed
                        "
                    >
                        Prepare
                    </button>
                </form>
                <p
                    v-if="experiment.approval_evidence?.confirmed_at"
                    class="meta approval-record"
                    data-test="approval-record"
                >
                    Compliance and legal review confirmed via
                    {{ label(experiment.approval_evidence.source) }} at
                    {{ experiment.approval_evidence.confirmed_at }}. This
                    evidence is included in the frozen snapshot.
                </p>
            </section>

            <section class="cohort-grid">
                <article
                    v-for="cohort in experiment.cohorts"
                    :key="cohort.id"
                    class="box cohort-card"
                >
                    <header class="cohort-head">
                        <div>
                            <h2>{{ cohort.label }}</h2>
                            <p class="meta">
                                {{ cohort.key }} ·
                                {{ label(cohort.domain_strategy) }}
                            </p>
                        </div>
                        <span class="status"
                            >{{ cohort.mailboxes.length }} mailbox{{
                                cohort.mailboxes.length === 1 ? '' : 'es'
                            }}</span
                        >
                    </header>
                    <p v-if="cohort.campaign_name" class="meta">
                        Campaign: {{ cohort.campaign_name }}
                    </p>
                    <div class="mailbox-picker" v-if="canManage">
                        <label
                            v-for="mailbox in managedMailboxes"
                            :key="mailbox.id"
                            class="mailbox-option"
                        >
                            <input
                                v-if="mailbox.mailbox_id !== null"
                                v-model="selectedMailboxes[cohort.id]"
                                type="checkbox"
                                :value="mailbox.mailbox_id"
                                :disabled="Boolean(experiment.frozen_at)"
                            />
                            <span>{{ mailbox.email }}</span>
                            <small
                                >{{ label(mailbox.status) }} ·
                                {{ mailbox.domain }}</small
                            >
                        </label>
                    </div>
                    <p v-if="managedMailboxes.length === 0" class="meta">
                        Provision managed mailboxes before attaching this
                        cohort.
                    </p>
                    <button
                        v-if="canManage"
                        class="btn ghost"
                        type="button"
                        :disabled="Boolean(experiment.frozen_at)"
                        @click="attach(cohort)"
                    >
                        Save cohort mailboxes
                    </button>
                </article>
            </section>

            <section class="box report-box">
                <header class="report-head">
                    <div>
                        <h2>Report</h2>
                        <p class="meta">
                            Recipient-level evidence; provider submission is not
                            represented as delivery.
                        </p>
                    </div>
                    <div class="actions">
                        <a
                            class="btn ghost"
                            :href="`/settings/mail-experiments/${experiment.id}/report`"
                            >JSON report</a
                        >
                        <a
                            class="btn ghost"
                            :href="`/settings/mail-experiments/${experiment.id}/export?format=csv`"
                            >CSV evidence</a
                        >
                    </div>
                </header>
                <div class="metric-grid">
                    <div>
                        <strong>{{
                            metric(report.summary?.assignment_count)
                        }}</strong
                        ><span>assignments</span>
                    </div>
                    <div>
                        <strong>{{
                            metric(report.summary?.exposed_count)
                        }}</strong
                        ><span>provider-submitted</span>
                    </div>
                    <div>
                        <strong>{{
                            metric(report.summary?.human_reply_count)
                        }}</strong
                        ><span>human replies</span>
                    </div>
                    <div>
                        <strong>{{
                            percent(report.summary?.human_reply_rate)
                        }}</strong
                        ><span>human reply rate</span>
                    </div>
                    <div>
                        <strong>{{
                            metric(report.summary?.excluded_count)
                        }}</strong
                        ><span>excluded</span>
                    </div>
                </div>
                <div
                    v-if="report.cohorts && Object.keys(report.cohorts).length"
                    class="cohort-metrics"
                >
                    <h3>Cohort balance and outcomes</h3>
                    <div
                        v-for="(cohortMetric, key) in report.cohorts"
                        :key="key"
                        class="cohort-metric"
                    >
                        <strong>{{ key }}</strong>
                        <span
                            >{{
                                metric(cohortMetric.assignment_count)
                            }}
                            assignments</span
                        >
                        <span
                            >{{
                                metric(cohortMetric.exposed_count)
                            }}
                            exposed</span
                        >
                        <span
                            >{{ percent(cohortMetric.human_reply_rate) }} reply
                            rate</span
                        >
                    </div>
                </div>
                <ul v-if="report.warnings?.length" class="warnings">
                    <li v-for="warning in report.warnings" :key="warning">
                        {{ warning }}
                    </li>
                </ul>
            </section>
        </div>
    </AppWorkspace>
</template>

<style scoped>
.experiment-show {
    display: grid;
    gap: 18px;
    padding: 26px;
}

.experiment-head,
.cohort-head,
.report-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
}

.experiment-head h1,
.cohort-head h2,
.box h2,
.cohort-metrics h3 {
    margin: 0;
}

.back-link {
    display: inline-block;
    margin-bottom: 14px;
    color: var(--ob-accent);
    font-size: 13px;
    text-decoration: none;
}

.eyebrow {
    margin: 0 0 4px;
    color: var(--ob-accent);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.prep-box,
.cohort-card,
.report-box {
    display: grid;
    gap: 14px;
}

.prepare-form {
    display: flex;
    align-items: end;
    flex-wrap: wrap;
    gap: 12px;
}

.prepare-form label {
    display: grid;
    min-width: 260px;
    gap: 5px;
    color: #4e4942;
    font-size: 12px;
    font-weight: 700;
}

.prepare-form .approval-option {
    grid-template-columns: 18px minmax(0, 1fr);
    min-width: min(100%, 320px);
    align-items: start;
}

.approval-option input {
    margin-top: 2px;
}

.approval-record {
    margin: 0;
}

.prepare-form select {
    min-height: 38px;
    border: 1px solid #d7d1c8;
    border-radius: 7px;
    padding: 0 10px;
    background: #fff;
    color: #25221f;
    font: inherit;
}

.cohort-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}

.status {
    border-radius: 999px;
    padding: 4px 9px;
    background: #eeeae4;
    color: #59534c;
    font-size: 11px;
    font-weight: 700;
    text-transform: capitalize;
}

.mailbox-picker {
    display: grid;
    gap: 7px;
}

.mailbox-option {
    display: grid;
    grid-template-columns: 18px 1fr;
    gap: 4px 8px;
    align-items: center;
    border: 1px solid var(--ob-border-soft);
    border-radius: 7px;
    padding: 8px;
    color: #34302b;
    font-size: 13px;
}

.mailbox-option small {
    grid-column: 2;
    color: #777067;
    font-size: 11px;
}

.report-head {
    align-items: center;
}

.metric-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 8px;
}

.metric-grid div {
    display: grid;
    gap: 4px;
    border: 1px solid var(--ob-border-soft);
    border-radius: 7px;
    padding: 11px;
}

.metric-grid strong {
    font-size: 20px;
}

.metric-grid span,
.cohort-metric span {
    color: #777067;
    font-size: 11px;
}

.cohort-metrics {
    display: grid;
    gap: 8px;
}

.cohort-metric {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    border-top: 1px solid var(--ob-border-soft);
    padding-top: 9px;
}

.warnings {
    margin: 0;
    color: #825f16;
    font-size: 12px;
}

@media (max-width: 900px) {
    .cohort-grid,
    .metric-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 720px) {
    .experiment-show {
        padding: 18px;
    }

    .experiment-head,
    .cohort-head,
    .report-head {
        flex-direction: column;
    }
}
</style>
