<script setup lang="ts">
import {
    ArrowLeft,
    ArrowRight,
    Check,
    CheckCheck,
    CircleHelp,
    FileText,
    Mail,
    Pause,
    Play,
    ShieldCheck,
    Users,
} from '@lucide/vue';
import { computed, nextTick, ref, toRef, watch } from 'vue';
import {
    approve,
    blockers,
    convert,
    fulfil,
    isApproved,
    launch,
    people,
    receiveReply,
    renderMessage,
    selectedPeople,
    sendReply,
    steps,
} from './workflow';
import type { Workflow } from './workflow';
const props = defineProps<{ workflow: Workflow; step: number }>();
const emit = defineEmits<{
    navigate: [step: number];
    entity: [kind: 'offer' | 'objective'];
}>();
const state = toRef(props, 'workflow');
const step = toRef(props, 'step');
const error = ref('');
const notice = ref('');
const confirmed = ref(false);
const previewId = ref('p1');
const filter = ref('all');
const selected = computed(() => selectedPeople(state.value));
const recipient = computed(
    () =>
        selected.value.find((p) => p.id === previewId.value) ??
        selected.value[0] ??
        people[0],
);
const approved = computed(() => isApproved(state.value));
const issues = computed(() => blockers(state.value, step.value));
const visiblePeople = computed(() =>
    people.filter((p) => filter.value !== 'eligible' || !p.excluded),
);
const lead = computed(
    () =>
        people.find((p) => p.id === state.value.accepted[0]) ??
        selected.value[0] ??
        people[0],
);
const progress = computed(() =>
    Math.min(100, state.value.converted ? 100 / state.value.draft.target : 0),
);
watch(
    () => state.value.draft,
    () => {
        if (state.value.approval && !approved.value) {
            state.value.approval = null;
            state.value.activity.push({
                label: 'Previous approval invalidated',
                detail: 'Campaign inputs changed. Fresh approval required.',
            });
            notice.value =
                'Plan changed. Review and approve again before launch.';
        }

        confirmed.value = false;
    },
    { deep: true },
);
function go(index: number) {
    error.value = '';
    notice.value = '';
    emit('navigate', index);
    void nextTick(() => {
        document.documentElement.scrollTop = 0;
        document.body.scrollTop = 0;
    });
}
function next() {
    if (issues.value.length) {
        error.value = issues.value[0];

        return;
    }

    go(Math.min(4, step.value + 1));
}
function approvePlan() {
    if (!confirmed.value) {
        return;
    }

    if (!approve(state.value)) {
        error.value = blockers(state.value)[0] ?? 'Already launched.';

        return;
    }

    notice.value = 'Exact plan approved. No outreach has started.';
}
function launchDemo() {
    if (launch(state.value)) {
        go(5);
        notice.value = 'Demo launched. No real emails were sent.';
    }
}
function togglePause() {
    state.value.paused = !state.value.paused;
    state.value.activity.push({
        label: state.value.paused
            ? 'Campaign paused by human'
            : 'Campaign resumed by human',
        detail: 'Unknown sends remain held. Accepted messages are not resent.',
    });
}
</script>
<template>
    <div class="campaign-execution">
        <p v-if="error" role="alert" class="hw-alert">{{ error }}</p>
        <p v-if="notice" role="status" class="hw-notice">{{ notice }}</p>
        <p v-if="state.launched && step < 4" class="hw-notice">
            Approved snapshot · read-only. Changing an Offer or Objective does
            not rewrite a launched campaign.
        </p>
        <div v-if="step === 2">
            <div class="hw-toolbar">
                <div>
                    <strong>Agency founders</strong
                    ><span>Supplied list · 5 sample prospects</span>
                </div>
                <label class="hw-filter"
                    >Show<select v-model="filter">
                        <option value="all">All prospects</option>
                        <option value="eligible">Eligible only</option>
                    </select></label
                >
            </div>
            <div class="hw-table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Include</th>
                            <th scope="col">Person</th>
                            <th scope="col">Why this person?</th>
                            <th scope="col">Eligibility</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="person in visiblePeople"
                            :key="person.id"
                            :class="{
                                'hw-excluded': person.excluded,
                            }"
                        >
                            <td>
                                <input
                                    v-model="state.draft.selected"
                                    type="checkbox"
                                    :value="person.id"
                                    :disabled="
                                        !!person.excluded || state.launched
                                    "
                                    :aria-label="`Include ${person.name}`"
                                />
                            </td>
                            <td>
                                <strong>{{ person.name }}</strong
                                ><span
                                    >{{ person.company }} ·
                                    {{ person.role }}</span
                                >
                            </td>
                            <td>{{ person.reason }}</td>
                            <td>
                                <span
                                    class="hw-status"
                                    :class="{
                                        'hw-warning': person.excluded,
                                    }"
                                    >{{
                                        person.excluded || 'Eligible in sample'
                                    }}</span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="hw-audience-summary">
                <Users /><strong>{{ selected.length }} selected</strong
                ><span
                    >2 excluded · suppression and source permissions always
                    apply</span
                >
            </div>
            <p class="hw-help">
                Sample eligibility is fixed for this walkthrough. Production
                must recheck permissions, suppression, freshness and mailbox
                health before every send.
            </p>
        </div>

        <div v-if="step === 3" class="hw-columns hw-message-columns">
            <fieldset :disabled="state.launched" class="hw-form">
                <div class="hw-section-title">
                    <h3>Email 1</h3>
                    <span>No automated follow-ups</span>
                </div>
                <label>Subject<input v-model="state.draft.subject" /></label
                ><label
                    >Message<textarea v-model="state.draft.body" rows="11" />
                </label>
                <p class="hw-help" v-pre>
                    Variables: {{ first_name }} and {{ company }}. Every
                    recipient gets a reviewable, rendered message.
                </p>
                <div class="hw-fields">
                    <label
                        >Send from<select v-model="state.draft.mailbox">
                            <option>alex@studio.example</option>
                        </select></label
                    ><label
                        >Daily limit<input
                            v-model.number="state.draft.daily"
                            type="number"
                            min="1"
                            max="20"
                    /></label>
                </div>
                <p class="hw-help">
                    Schedule: weekdays, 09:00–17:00 UTC. No sending after the
                    Objective horizon. Replies stop campaign outreach.
                </p>
            </fieldset>
            <aside class="hw-inspector hw-email">
                <label
                    >Preview recipient<select v-model="previewId">
                        <option
                            v-for="person in selected.length
                                ? selected
                                : people.slice(0, 1)"
                            :key="person.id"
                            :value="person.id"
                        >
                            {{ person.name }}
                        </option>
                    </select></label
                >
                <div class="hw-email-envelope">
                    <span>To: {{ recipient.email }}</span
                    ><strong>{{
                        renderMessage(state.draft.subject, recipient) ||
                        'Subject preview'
                    }}</strong>
                </div>
                <p class="hw-email-body">
                    {{
                        renderMessage(state.draft.body, recipient) ||
                        'Message preview'
                    }}
                </p>
            </aside>
        </div>

        <div v-if="step === 4" class="hw-columns">
            <div>
                <div class="hw-review-title">
                    <ShieldCheck />
                    <h3>
                        {{
                            approved
                                ? 'This exact plan is approved'
                                : 'Your launch checklist'
                        }}
                    </h3>
                </div>
                <dl class="hw-review-list">
                    <dt>Outcome</dt>
                    <dd>
                        {{ state.draft.target }}
                        {{ state.draft.success.toLowerCase() }} ·
                        {{ state.draft.endsAt
                        }}<button @click="emit('entity', 'objective')">
                            Review Objective
                        </button>
                    </dd>
                    <dt>Offer</dt>
                    <dd>
                        {{ state.draft.offer
                        }}<button @click="emit('entity', 'offer')">
                            Review Offer
                        </button>
                    </dd>
                    <dt>Audience</dt>
                    <dd>
                        {{ selected.length }} exact recipients · 2
                        excluded<button @click="go(2)">Review audience</button>
                    </dd>
                    <dt>Sending</dt>
                    <dd>
                        {{ state.draft.mailbox
                        }}<small
                            >{{ state.draft.daily }}/day · weekdays 09:00–17:00
                            UTC · 1 email/person</small
                        >
                    </dd>
                    <dt>Spend</dt>
                    <dd>
                        $0 simulated · ${{ state.draft.budget }}
                        ceiling
                    </dd>
                    <dt>Stop conditions</dt>
                    <dd>
                        Reply, opt-out, suppression, unhealthy mailbox, expired
                        authority, or unknown send outcome.
                    </dd>
                </dl>
                <div v-if="issues.length" class="hw-alert">
                    <strong>Resolve before approval</strong>
                    <ul>
                        <li v-for="issue in issues" :key="issue">
                            {{ issue }}
                        </li>
                    </ul>
                </div>
                <div v-if="!state.launched" class="hw-approval">
                    <label v-if="!approved" class="hw-check-label"
                        ><input
                            v-model="confirmed"
                            type="checkbox"
                            :disabled="!!issues.length"
                        />I have reviewed the exact recipients, messages, Offer,
                        and sending limits.</label
                    ><button
                        v-if="!approved"
                        class="hw-primary"
                        :disabled="!confirmed || !!issues.length"
                        @click="approvePlan"
                    >
                        <ShieldCheck /> Approve exact plan</button
                    ><template v-else
                        ><p>
                            <CheckCheck /> Approved by Alex Morgan ·
                            plan-demo-01
                        </p>
                        <button class="hw-primary" @click="launchDemo">
                            <Play /> Launch demo campaign</button
                        ><small
                            >Simulation only. Nothing is sent to a mail
                            provider.</small
                        ></template
                    >
                </div>
            </div>
            <aside class="hw-inspector hw-email">
                <p class="hw-eyebrow">Exact message review</p>
                <label
                    >Recipient<select v-model="previewId">
                        <option
                            v-for="person in selected"
                            :key="person.id"
                            :value="person.id"
                        >
                            {{ person.name }}
                        </option>
                    </select></label
                >
                <div v-if="selected.length" class="hw-email-envelope">
                    <span>To: {{ recipient.email }}</span
                    ><strong>{{
                        renderMessage(state.draft.subject, recipient)
                    }}</strong>
                </div>
                <p v-if="selected.length" class="hw-email-body">
                    {{ renderMessage(state.draft.body, recipient) }}
                </p>
                <p v-else>No recipients selected.</p>
                <p class="hw-help">
                    Editing any plan input invalidates approval. Accepted sends
                    cannot be undone.
                </p>
            </aside>
        </div>

        <div v-if="step === 5">
            <div class="hw-toolbar">
                <div>
                    <span class="hw-status">{{
                        state.paused ? 'Paused' : 'Demo active'
                    }}</span
                    ><strong
                        >{{ state.accepted.length }} accepted ·
                        {{ state.unknown.length }} unknown ·
                        {{
                            Math.max(
                                0,
                                selected.length -
                                    state.accepted.length -
                                    state.unknown.length,
                            )
                        }}
                        queued</strong
                    >
                </div>
                <button class="hw-secondary" @click="togglePause">
                    <Play v-if="state.paused" /><Pause v-else />{{
                        state.paused ? 'Resume demo' : 'Pause campaign'
                    }}
                </button>
            </div>
            <div v-if="state.unknown.length" class="hw-exception">
                <CircleHelp />
                <div>
                    <strong
                        >{{
                            people.find((p) => p.id === state.unknown[0])?.name
                        }}
                        · send outcome unknown</strong
                    >
                    <p>
                        We can’t confirm provider acceptance. This person is
                        held; no retry is allowed until the outcome is
                        reconciled.
                    </p>
                    <details>
                        <summary>Inspect send record</summary>
                        <p>
                            send-demo-02 · provider response lost · no exposure
                            recorded · reconciliation required. This prototype
                            does not fabricate a resolution.
                        </p>
                    </details>
                </div>
                <span class="hw-status hw-warning">Held</span>
            </div>
            <div class="hw-columns">
                <div class="hw-conversation">
                    <div class="hw-section-title">
                        <h3>{{ lead.name }}</h3>
                        <span>{{ lead.company }}</span>
                    </div>
                    <div class="hw-sent">
                        <span class="hw-message-meta"
                            >Alex → {{ lead.name }} · provider acceptance
                            simulated</span
                        >
                        <p>
                            {{ renderMessage(state.draft.body, lead) }}
                        </p>
                    </div>
                    <template v-if="state.replied"
                        ><div class="hw-received">
                            <span class="hw-message-meta"
                                >{{ lead.name }} → Alex · sample reply</span
                            >
                            <p>
                                This sounds useful. We’re trying to make our
                                pipeline less dependent on referrals. What times
                                do you have next week?
                            </p>
                        </div>
                        <span class="hw-status"
                            >Interested · campaign outreach stopped</span
                        ><label class="hw-reply-label"
                            >Your reply<textarea
                                v-model="state.reply"
                                :disabled="state.replySent"
                                rows="5"
                            /></label
                        ><button
                            class="hw-primary"
                            :disabled="state.replySent || !state.reply.trim()"
                            @click="sendReply(state)"
                        >
                            <Mail />{{
                                state.replySent
                                    ? 'Demo reply sent'
                                    : 'Send demo reply'
                            }}
                        </button></template
                    ><button
                        v-else
                        class="hw-secondary"
                        @click="receiveReply(state)"
                    >
                        Simulate interested reply <ArrowRight />
                    </button>
                </div>
                <aside class="hw-inspector">
                    <p class="hw-eyebrow">Follow through</p>
                    <h2>{{ state.draft.offer }}</h2>
                    <p>Owner: {{ state.draft.owner }}</p>
                    <ol class="hw-tasks">
                        <li :class="{ done: state.replied }">
                            <Check /> Respond to interest
                        </li>
                        <li :class="{ done: state.replySent }">
                            <Check /> Send available times
                        </li>
                        <li :class="{ done: state.fulfilled }">
                            <Check /> Confirm the booking
                        </li>
                    </ol>
                    <button
                        class="hw-secondary"
                        :disabled="!state.replySent || state.fulfilled"
                        @click="fulfil(state)"
                    >
                        {{
                            state.fulfilled
                                ? 'Booking confirmed'
                                : 'Simulate booking confirmation'
                        }}
                    </button>
                    <p class="hw-help">
                        Booking is acceptance of the Offer; delivering the
                        workshop is still outstanding.
                    </p>
                    <button
                        v-if="state.fulfilled"
                        class="hw-text-button"
                        @click="go(6)"
                    >
                        Record the outcome <ArrowRight />
                    </button>
                </aside>
            </div>
        </div>

        <div v-if="step === 6">
            <div class="hw-outcome-head">
                <div>
                    <p class="hw-eyebrow">
                        This campaign · {{ state.draft.success }}
                    </p>
                    <div class="hw-result">
                        {{ state.converted ? 1 : 0 }}
                        <span>/ {{ state.draft.target }}</span>
                    </div>
                    <div
                        class="hw-progress"
                        role="progressbar"
                        :aria-valuenow="state.converted ? 1 : 0"
                        :aria-valuemax="state.draft.target"
                        aria-valuemin="0"
                        aria-label="Objective bookings"
                    >
                        <span :style="{ width: `${progress}%` }"></span>
                    </div>
                    <p>
                        {{ state.draft.endsAt }} deadline · manual booking
                        evidence
                    </p>
                </div>
                <div class="hw-outcome-metrics">
                    <span
                        ><strong>{{ state.accepted.length }}</strong
                        >Provider accepted</span
                    ><span
                        ><strong>{{ state.replied ? 1 : 0 }}</strong
                        >Interested replies</span
                    ><span><strong>$0</strong>Simulated spend</span>
                </div>
            </div>
            <div class="hw-toolbar">
                <div>
                    <h3>Conversion evidence</h3>
                    <span
                        >Only a confirmed booking counts toward this
                        Objective.</span
                    >
                </div>
                <button
                    class="hw-primary"
                    :disabled="!state.fulfilled || state.converted"
                    @click="convert(state)"
                >
                    <CheckCheck />{{
                        state.converted
                            ? 'Conversion recorded'
                            : 'Record confirmed booking'
                    }}
                </button>
            </div>
            <p v-if="!state.fulfilled" class="hw-help">
                First confirm the booking in Conversations. An interested reply
                alone is not a conversion.
            </p>
            <div v-if="state.converted" class="hw-conversion">
                <span class="hw-avatar">{{
                    lead.name
                        .split(' ')
                        .map((n) => n[0])
                        .join('')
                }}</span>
                <div>
                    <strong>{{ lead.name }} · {{ state.draft.success }}</strong>
                    <p>
                        Human-confirmed sample event ·
                        {{ state.contextId }}:conversion-demo-01
                    </p>
                </div>
                <span class="hw-status">Associated with outreach</span>
            </div>
            <div class="hw-section-title hw-lineage-title">
                <h3>Inspectable lineage</h3>
                <span
                    >Objective → Offer → plan → send → conversation →
                    outcome</span
                >
            </div>
            <ol class="hw-timeline">
                <li v-for="(event, index) in state.activity" :key="index">
                    <span class="hw-timeline-dot"></span>
                    <div>
                        <strong>{{ event.label }}</strong>
                        <p>{{ event.detail }}</p>
                    </div>
                </li>
            </ol>
            <p class="hw-help">
                These records demonstrate traceability, not a claim that
                outreach caused the booking. Unknown sends do not count as
                accepted or delivered.
            </p>
            <div class="hw-next-action">
                <FileText />
                <div>
                    <h3>What should happen next?</h3>
                    <p>
                        Deliver the promised workshop and review the result
                        before changing your approach. No automatic
                        optimization.
                    </p>
                </div>
                <button class="hw-secondary" @click="go(5)">
                    Back to conversations
                </button>
            </div>
        </div>

        <footer v-if="step < 4" class="hw-footer">
            <button
                class="hw-text-button"
                :disabled="false"
                @click="go(step - 1)"
            >
                <ArrowLeft /> Back</button
            ><span>{{
                state.launched
                    ? 'Approved snapshot · read-only'
                    : 'Draft in this tab · not saved to workspace'
            }}</span
            ><button class="hw-primary" @click="next">
                Continue to {{ steps[step + 1] }} <ArrowRight />
            </button>
        </footer>
    </div>
</template>
