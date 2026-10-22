<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import AppWorkspace from '@/layouts/AppWorkspace.vue';
import { campaignDate } from '@/lib/campaignDate';

type Message = {
    id: string;
    to: string;
    from: string;
    sender_name: string | null;
    reply_to: string;
    subject: string;
    body: string;
    starts_at: string;
    ends_at: string;
    status?: string;
};
const props = defineProps<{
    campaign: { id: number; name: string; status?: string };
    revision: number;
    operation_id: string;
    review: {
        id: string;
        plan_hash: string;
        prepared_at: string;
        messages: Message[];
        status: string;
        approval_status: string;
        approval_expires_at: string | null;
        approval_lifetime?: 'unchanged_campaign' | 'time_limited';
        campaign_window?: {
            starts_at: string | null;
            ends_at: string | null;
        } | null;
        display_manifest_hash: string;
        action_fingerprints: string[];
        blockers: { code: string; message: string }[];
        can_approve: boolean;
        can_discard?: boolean;
        can_return_to_draft?: boolean;
        can_launch: boolean;
        objective: string | null;
        offer: string | null;
        offer_id?: string | null;
    } | null;
}>();
const selectedId = ref<string | null>(null);
const selected = computed(
    () =>
        props.review?.messages.find(
            (message) => message.id === selectedId.value,
        ) ?? props.review?.messages[0],
);
const form = useForm({
    expected_revision: props.revision,
    operation_id: props.operation_id,
    replace_plan_id: null as string | null,
});
const decision = useForm({
    expected_revision: props.revision,
    plan_hash: '',
    display_manifest_hash: '',
    action_fingerprints: [] as string[],
    confirmed: false,
});
watch(
    () => props.review?.id,
    () => {
        decision.confirmed = false;
    },
);
const errors = computed(() => [
    ...Object.values(form.errors),
    ...Object.values(decision.errors),
]);
const stateLabel = computed(() => {
    if (
        props.review?.status === 'executing' &&
        ['paused', 'completed'].includes(props.campaign.status ?? '')
    ) {
        return 'Sending paused · review before resuming';
    }

    if (props.review?.status === 'executing') {
        return props.review.messages.length &&
            props.review.messages.every((message) => message.status === 'sent')
            ? 'Messages sent'
            : 'Campaign launched';
    }

    if (props.review?.approval_status === 'approved') {
        return 'Approved · not launched';
    }

    if (props.review?.approval_status === 'expired') {
        return 'Approval expired';
    }

    if (props.review?.approval_status === 'invalidated') {
        return 'Review needs updating';
    }

    return 'Not approved to send';
});
function decide(action: 'approve' | 'launch' | 'discard' | 'return-to-draft') {
    if (!props.review) {
        return;
    }

    decision.expected_revision = props.revision;
    decision.plan_hash = props.review.plan_hash;
    decision.display_manifest_hash = props.review.display_manifest_hash;
    decision.action_fingerprints = [...props.review.action_fingerprints];
    decision.post(
        `/campaigns/${props.campaign.id}/review/${props.review.id}/${action}`,
        { preserveScroll: true },
    );
}
function prepare() {
    if (form.processing) {
        return;
    }

    form.expected_revision = props.revision;
    form.operation_id = props.operation_id;
    form.replace_plan_id =
        props.review?.can_discard && props.review.blockers.length
            ? props.review.id
            : null;
    form.post(`/campaigns/${props.campaign.id}/review`, {
        preserveScroll: true,
    });
}
onMounted(() => {
    if (
        (!props.review ||
            (props.review.can_discard && props.review.blockers.length > 0)) &&
        new URL(window.location.href).searchParams.get('prepare') === '1'
    ) {
        prepare();
    }
});
</script>

<template>
    <Head :title="'Review · ' + campaign.name" />
    <AppWorkspace area="campaigns" :title="campaign.name">
        <template #sidebar>
            <div class="review-sidebar">
                <h2>Campaign review</h2>
                <p>Inspect the saved messages before approval.</p>
                <Link :href="`/campaigns/${campaign.id}`">Back to setup</Link>
            </div>
        </template>
        <div class="human-review">
            <Link :href="`/campaigns/${campaign.id}`" class="review-back"
                >← Back to campaign setup</Link
            >
            <header class="review-header">
                <div>
                    <h1>Review messages</h1>
                    <p>
                        Check the exact recipient, sender and saved copy before
                        approval.
                    </p>
                </div>
                <span class="review-state" role="status">{{ stateLabel }}</span>
            </header>
            <div v-if="errors.length" role="alert" class="review-errors">
                <p v-for="error in errors" :key="error">{{ error }}</p>
            </div>
            <section
                v-if="review?.can_discard"
                class="review-note"
                aria-label="Edit campaign"
            >
                <p>
                    Want to make changes? Your draft is kept. Replace this
                    review after editing; changed messages need a new exact
                    approval.
                </p>
                <button
                    type="button"
                    class="btn ghost"
                    :disabled="decision.processing"
                    @click="decide('discard')"
                >
                    Edit campaign
                </button>
            </section>
            <section v-if="!review" class="review-empty">
                <h2>Prepare your saved campaign</h2>
                <p>
                    Save your Objective, active Offer, prospects, message, and
                    sending account first. Start and deadline dates are
                    optional. Preparing review does not send email.
                </p>
                <button
                    class="btn"
                    type="button"
                    :disabled="form.processing"
                    @click="prepare"
                >
                    {{
                        form.processing ? 'Preparing…' : 'Prepare exact review'
                    }}
                </button>
            </section>
            <template v-else>
                <section
                    v-if="review.can_return_to_draft"
                    class="review-errors"
                    aria-label="Review recovery"
                >
                    <h2>Prepare a fresh review</h2>
                    <p>
                        This approval can no longer send. Return to your saved
                        draft, check its setup, then prepare and approve the
                        messages again.
                    </p>
                    <p>
                        The old approval stays in history and still counts
                        toward its sending allowance. Choose a new allowance
                        explicitly if the current one is fully committed.
                    </p>
                    <button
                        class="btn"
                        type="button"
                        :disabled="decision.processing"
                        @click="decide('return-to-draft')"
                    >
                        Return to draft
                    </button>
                </section>
                <p class="review-note">
                    Objective: {{ review.objective }} · Offer:
                    {{ review.offer }}
                </p>
                <div
                    v-if="review.blockers.length"
                    class="review-errors"
                    role="alert"
                >
                    <p v-for="blocker in review.blockers" :key="blocker.code">
                        {{ blocker.message }}
                    </p>
                    <Link :href="`/campaigns/${campaign.id}`"
                        >Review campaign setup</Link
                    >
                    <Link
                        v-if="review.offer_id"
                        :href="`/offers/${review.offer_id}/setup?from_campaign=${campaign.id}`"
                        >Open Offer setup</Link
                    >
                </div>
                <div v-if="review.messages.length" class="review-workspace">
                    <aside class="review-recipients" aria-label="Recipients">
                        <h2>
                            {{ review.messages.length }}
                            {{
                                review.messages.length === 1
                                    ? 'recipient'
                                    : 'recipients'
                            }}
                        </h2>
                        <button
                            v-for="message in review.messages"
                            :key="message.id"
                            type="button"
                            :aria-pressed="selected?.id === message.id"
                            @click="selectedId = message.id"
                        >
                            <strong>{{ message.to }}</strong
                            ><span>{{ message.subject }}</span>
                            <small v-if="message.status === 'sent'">Sent</small>
                        </button>
                    </aside>
                    <section
                        v-if="selected"
                        class="review-message"
                        aria-label="Exact message"
                    >
                        <dl>
                            <dt>From</dt>
                            <dd>
                                {{ selected.sender_name }} &lt;{{
                                    selected.from
                                }}&gt;
                            </dd>
                            <dt>To</dt>
                            <dd>{{ selected.to }}</dd>
                            <dt>Reply to</dt>
                            <dd>{{ selected.reply_to }}</dd>
                        </dl>
                        <h2>{{ selected.subject }}</h2>
                        <div class="review-body">{{ selected.body }}</div>
                        <footer>
                            <p v-if="review.campaign_window">
                                {{
                                    review.campaign_window.ends_at
                                        ? `Campaign deadline: ${campaignDate(review.campaign_window.ends_at)}`
                                        : 'Run until finished — no campaign deadline.'
                                }}
                            </p>
                            <p>
                                Earliest send:
                                {{ campaignDate(selected.starts_at) }}. Sending
                                permission ends:
                                {{ campaignDate(selected.ends_at) }}.
                            </p>
                            <p>
                                Sending hours and inbox limits still apply. An
                                unfinished campaign stops for attention when its
                                sending permission ends.
                            </p>
                        </footer>
                    </section>
                </div>
                <p v-else role="status">
                    This review has no messages. Return to campaign setup.
                </p>
                <section
                    v-if="review.can_approve"
                    class="review-decision"
                    aria-label="Approve exact messages"
                >
                    <label>
                        <input
                            v-model="decision.confirmed"
                            type="checkbox"
                            :disabled="decision.processing"
                        />
                        I approve these exact recipients, senders, messages and
                        sending window.
                    </label>
                    <p>
                        Approval saves your decision. It does not launch the
                        campaign.
                    </p>
                    <p v-if="review.approval_lifetime === 'unchanged_campaign'">
                        Your approval covers this unchanged campaign through
                        completion, within its sending allowance and any
                        deadline. Safety checks still apply before every send.
                    </p>
                    <button
                        class="btn"
                        type="button"
                        :disabled="!decision.confirmed || decision.processing"
                        @click="decide('approve')"
                    >
                        {{
                            decision.processing
                                ? 'Approving…'
                                : 'Approve these messages'
                        }}
                    </button>
                </section>
                <section
                    v-else-if="review.can_launch"
                    class="review-decision"
                    aria-label="Launch approved campaign"
                >
                    <p>
                        Launching schedules these approved messages within the
                        sending window. Current safety checks run again before
                        each send.
                    </p>
                    <p v-if="review.approval_expires_at">
                        Approval expires:
                        {{ campaignDate(review.approval_expires_at) }}
                    </p>
                    <button
                        class="btn"
                        type="button"
                        :disabled="decision.processing"
                        @click="decide('launch')"
                    >
                        {{
                            decision.processing
                                ? 'Launching…'
                                : ['paused', 'completed'].includes(
                                        campaign.status ?? '',
                                    )
                                  ? 'Resume campaign'
                                  : 'Launch campaign'
                        }}
                    </button>
                </section>
                <p
                    v-else-if="review.status === 'executing'"
                    class="review-note"
                >
                    This campaign has launched. Delivery status is shown beside
                    each recipient.
                </p>
                <p
                    v-else-if="
                        review.approval_status === 'expired' ||
                        review.approval_status === 'invalidated'
                    "
                    class="review-note"
                >
                    This approval cannot be used to send. Update the review
                    before launching.
                </p>
                <details class="review-lineage">
                    <summary>Review reference</summary>
                    <dl>
                        <dt>Review</dt>
                        <dd>{{ review.id }}</dd>
                        <dt>Exact snapshot</dt>
                        <dd>{{ review.plan_hash }}</dd>
                        <dt>Prepared</dt>
                        <dd>{{ review.prepared_at }}</dd>
                    </dl>
                </details>
                <section
                    v-if="review.can_discard"
                    class="review-decision"
                    aria-label="Change this review"
                >
                    <p>
                        Need to change something? Discard this unapproved
                        review, update your campaign, then prepare it again.
                        Your campaign draft is kept.
                    </p>
                    <button
                        type="button"
                        :disabled="decision.processing"
                        @click="decide('discard')"
                    >
                        Discard review
                    </button>
                </section>
            </template>
        </div>
    </AppWorkspace>
</template>

<style scoped>
.human-review {
    padding: 28px;
    max-width: 1280px;
}
.review-back {
    color: var(--muted-foreground);
    font-size: 13px;
}
.review-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    margin: 24px 0;
}
h1 {
    font-size: 24px;
    font-weight: 600;
}
h2 {
    font-size: 15px;
    font-weight: 600;
}
.review-header p,
.review-note,
.review-empty p {
    color: var(--muted-foreground);
    font-size: 14px;
    margin-top: 8px;
}
.review-state {
    font-size: 12px;
    white-space: nowrap;
}
.review-decision {
    margin: 24px 0;
    display: grid;
    justify-items: start;
    gap: 12px;
    max-width: 720px;
    font-size: 14px;
}
.review-decision label {
    display: flex;
    gap: 10px;
    align-items: baseline;
}
.review-decision input[type='checkbox'] {
    width: 16px;
    height: 16px;
    flex: 0 0 16px;
    margin: 0;
}
.review-decision p {
    color: var(--muted-foreground);
}
.review-decision .btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.review-empty {
    padding: 40px 0;
    max-width: 620px;
}
.review-empty .btn {
    margin-top: 20px;
}
.review-errors {
    border-left: 3px solid #b45309;
    padding: 12px;
    margin-bottom: 16px;
}
.review-errors a {
    display: inline-block;
    margin: 8px 16px 0 0;
    text-decoration: underline;
}
.review-workspace {
    display: grid;
    grid-template-columns: 280px minmax(0, 1fr);
    margin-top: 24px;
    border-top: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
}
.review-recipients {
    border-right: 1px solid var(--border);
    padding: 20px 16px 20px 0;
}
.review-recipients h2 {
    margin-bottom: 16px;
}
.review-recipients button {
    display: block;
    width: 100%;
    text-align: left;
    padding: 12px;
    border-radius: 6px;
    transition: background-color 120ms;
}
.review-recipients button[aria-pressed='true'],
.review-recipients button:hover {
    background: var(--surface-hover, #f3f4f6);
}
.review-recipients strong,
.review-recipients span {
    display: block;
    overflow-wrap: anywhere;
    font-size: 13px;
}
.review-recipients span {
    color: var(--muted-foreground);
    margin-top: 4px;
}
.review-message {
    padding: 24px;
    min-width: 0;
}
dl {
    display: grid;
    grid-template-columns: 80px minmax(0, 1fr);
    gap: 6px 12px;
    font-size: 13px;
}
dt {
    color: var(--muted-foreground);
}
dd {
    overflow-wrap: anywhere;
}
.review-message h2 {
    margin: 24px 0 16px;
}
.review-body {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    line-height: 1.7;
    font-size: 14px;
}
.review-message footer {
    color: var(--muted-foreground);
    font-size: 12px;
    margin-top: 36px;
}
.review-lineage {
    margin-top: 24px;
    font-size: 12px;
}
.review-sidebar {
    padding: 24px;
    font-size: 13px;
}
.review-sidebar p {
    color: var(--muted-foreground);
    margin: 12px 0;
}
.review-lineage dl {
    margin-top: 12px;
}
@media (max-width: 760px) {
    .human-review {
        padding: 16px;
    }
    .review-header {
        align-items: flex-start;
        flex-direction: column;
    }
    .review-workspace {
        grid-template-columns: 1fr;
    }
    .review-recipients {
        border-right: 0;
        border-bottom: 1px solid var(--border);
    }
    .review-message {
        padding: 20px 0;
    }
}
</style>
