<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import { campaignDate } from '@/lib/campaignDate';

export type Composition = {
    allowance_proposal?: {
        name: string;
        maximum_people: number;
        maximum_accounts: number;
        maximum_messages: number;
    };
    revision: number;
    objective_goal_version_id: string | null;
    offer_version_id: string | null;
    execution_authority_scope_id?: string | null;
    target_contribution_count?: number | null;
    sending_starts_at?: string | null;
    sending_ends_at?: string | null;
    sending_forecast?: {
        allowance?: {
            name: string;
            exceeded: boolean;
            quantities: Record<
                string,
                {
                    maximum: number;
                    committed: number;
                    remaining: number;
                    projected: number;
                }
            >;
        };
        status: string;
        messages: number;
        inbox_daily_capacity?: number;
        requested_daily_limit?: number | null;
        effective_daily_capacity?: number;
        estimated_finish_at: string | null;
        deadline_at: string | null;
        messages_by_deadline: number;
        shortfall: number;
        daily_capacity: number;
        competing_messages: number;
        message: string;
    };
    execution_scopes?: {
        id: string;
        name: string;
        expired: boolean;
        revoked: boolean;
        authority: {
            starts_at: string;
            ends_at: string;
            maximum_people: number;
            maximum_accounts: number;
            maximum_messages: number;
        };
    }[];
    editable: boolean;
    goals: {
        id: string;
        objective_id: string;
        name: string;
        version: number;
        target_count: number | null;
        measurement: string;
        deadline: string | null;
        current: boolean;
        measurement_configured?: boolean;
        status?: string;
    }[];
    offers: {
        id: string;
        offer_id: string;
        name: string;
        version: number;
        status: string;
        current: boolean;
    }[];
};
const props = defineProps<{
    campaignId: number;
    composition: Composition;
    setupFeedback?: 'composition' | 'schedule' | null;
    section?: 'purpose' | 'sending';
}>();
const emit = defineEmits<{ sending: []; dirty: [value: boolean]; saved: [] }>();
function requestId() {
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join(
        '',
    );

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
const mode = ref<'select' | 'create_goal' | 'create_offer' | 'create_scope'>(
    'select',
);
const scheduledStart = ref(Boolean(props.composition.sending_starts_at));
const hasDeadline = ref(Boolean(props.composition.sending_ends_at));
const form = useForm({
    action: 'select',
    objective_goal_version_id: props.composition.objective_goal_version_id,
    offer_version_id: props.composition.offer_version_id,
    execution_authority_scope_id:
        props.composition.execution_authority_scope_id ?? null,
    target_contribution_count:
        props.composition.target_contribution_count ?? null,
    sending_starts_at: props.composition.sending_starts_at?.slice(0, 16) ?? '',
    sending_ends_at: props.composition.sending_ends_at?.slice(0, 16) ?? '',
    authority: {
        starts_at: '',
        ends_at: '',
        maximum_people: 1,
        maximum_accounts: 1,
        maximum_messages: 1,
        maximum_ai_cost_micros: 0,
        approval_required: true,
    },
    expected_revision: props.composition.revision,
    operation_id: requestId(),
    name: '',
    promise: '',
});
const goal = computed(() =>
    props.composition.goals.find(
        (g) => g.id === form.objective_goal_version_id,
    ),
);
const offer = computed(() =>
    props.composition.offers.find((o) => o.id === form.offer_version_id),
);
const updatedGoal = computed(() =>
    goal.value?.measurement_configured === false
        ? props.composition.goals.find(
              (item) =>
                  item.objective_id === goal.value?.objective_id &&
                  item.current &&
                  item.measurement_configured === true,
          )
        : undefined,
);
const updatedOffer = computed(() =>
    offer.value && !offer.value.current
        ? props.composition.offers.find(
              (item) =>
                  item.offer_id === offer.value?.offer_id &&
                  item.current &&
                  item.status === 'active',
          )
        : undefined,
);
const scope = computed(() =>
    props.composition.execution_scopes?.find(
        (item) => item.id === form.execution_authority_scope_id,
    ),
);
const saveState = ref<'idle' | 'saving' | 'saved' | 'error'>('idle');
const saveMessage = ref(
    'Changes save automatically. This does not approve or launch the campaign.',
);
let pendingSave: Promise<boolean> | null = null;
let lastAttempt = '';
function selectionKey() {
    return JSON.stringify([
        form.objective_goal_version_id,
        form.offer_version_id,
        form.execution_authority_scope_id ?? null,
        form.target_contribution_count ?? null,
        scheduledStart.value,
        hasDeadline.value,
        form.sending_starts_at,
        form.sending_ends_at,
    ]);
}
let savedSelection = selectionKey();
async function autosave(): Promise<boolean> {
    await nextTick();

    if (pendingSave) {
        return pendingSave;
    }

    if (!props.composition.editable || mode.value !== 'select') {
        return !form.isDirty;
    }

    if (selectionKey() === savedSelection) {
        if (saveState.value === 'error') {
            form.clearErrors();
            saveState.value = 'saved';
            saveMessage.value =
                'Saved — no pending changes. Approval and launch stay separate.';
            emit('saved');
        }

        return true;
    }

    if (
        (scheduledStart.value && !form.sending_starts_at) ||
        (hasDeadline.value && !form.sending_ends_at)
    ) {
        saveState.value = 'error';
        saveMessage.value =
            'Not saved — enter the selected start or deadline date, or turn that schedule option off.';

        return false;
    }

    return submit();
}
defineExpose({ ensureSaved: autosave });
async function openRecord(url: string) {
    if (await autosave()) {
        router.visit(url);
    }
}
watch(
    () => form.isDirty,
    (dirty) => emit('dirty', Boolean(dirty)),
    { immediate: true },
);
const goalFeedback = computed(() => {
    if (props.setupFeedback !== 'composition') {
        return '';
    }

    if (!form.objective_goal_version_id) {
        return 'Choose or create an Objective to continue. Selections save automatically.';
    }

    if (
        goal.value?.status &&
        !['draft', 'ready', 'active'].includes(goal.value.status)
    ) {
        return 'This Objective is not available for a new campaign. Resolve its status or choose another Objective.';
    }

    if (
        form.objective_goal_version_id !==
        props.composition.objective_goal_version_id
    ) {
        return 'Your Objective selection is not saved yet. Check the save status above.';
    }

    if (goal.value?.measurement_configured === false) {
        return 'Complete the Objective measurement or choose its updated version.';
    }

    return '';
});
const offerFeedback = computed(() => {
    if (props.setupFeedback !== 'composition') {
        return '';
    }

    if (!form.offer_version_id) {
        return 'Choose or create an Offer to continue. Selections save automatically.';
    }

    if (
        offer.value &&
        (!offer.value.current || offer.value.status !== 'active')
    ) {
        return 'This Offer needs its current active version. Complete or review the Offer below, then select its current version.';
    }

    return form.offer_version_id !== props.composition.offer_version_id
        ? 'Your Offer selection is not saved yet. Check the save status above.'
        : '';
});
const scopeFeedback = computed(() => {
    if (props.setupFeedback !== 'schedule') {
        return '';
    }

    if (!form.execution_authority_scope_id) {
        return 'Choose or create a sending allowance to continue. Selections save automatically; campaign dates are optional.';
    }

    if (
        form.execution_authority_scope_id !==
        props.composition.execution_authority_scope_id
    ) {
        return 'Your sending allowance selection is not saved yet. Check the save status above.';
    }

    return [
        'over_capacity',
        'daily_limit_exceeded',
        'unsupported',
        'unavailable',
    ].includes(props.composition.sending_forecast?.status ?? '')
        ? (props.composition.sending_forecast?.message ?? '')
        : '';
});
watch(
    () => props.composition.revision,
    () => {
        form.defaults({
            ...form.data(),
            objective_goal_version_id:
                props.composition.objective_goal_version_id,
            offer_version_id: props.composition.offer_version_id,
            execution_authority_scope_id:
                props.composition.execution_authority_scope_id ?? null,
            target_contribution_count:
                props.composition.target_contribution_count ?? null,
            sending_starts_at:
                props.composition.sending_starts_at?.slice(0, 16) ?? '',
            sending_ends_at:
                props.composition.sending_ends_at?.slice(0, 16) ?? '',
            expected_revision: props.composition.revision,
            operation_id: requestId(),
            name: '',
            promise: '',
            action: 'select',
        });
        form.reset();
        scheduledStart.value = Boolean(props.composition.sending_starts_at);
        hasDeadline.value = Boolean(props.composition.sending_ends_at);
        mode.value = 'select';
        savedSelection = selectionKey();
    },
);
async function open(kind: typeof mode.value) {
    mode.value = kind;
    form.name = '';
    form.promise = '';
    form.clearErrors();

    if (kind === 'create_scope' && props.composition.allowance_proposal) {
        const proposal = props.composition.allowance_proposal;
        form.name = proposal.name;
        form.authority.maximum_people = proposal.maximum_people;
        form.authority.maximum_accounts = proposal.maximum_accounts;
        form.authority.maximum_messages = proposal.maximum_messages;
        form.authority.starts_at = new Date().toISOString().slice(0, 16);
        form.authority.ends_at = new Date(Date.now() + 30 * 86400000)
            .toISOString()
            .slice(0, 16);
    }

    await nextTick();

    if (kind !== 'select') {
        const input = document.getElementById('composition-name');
        input?.scrollIntoView?.({ block: 'center', behavior: 'smooth' });
        input?.focus({ preventScroll: true });
    }
}
function submit(): Promise<boolean> {
    if (pendingSave) {
        return pendingSave;
    }

    const attempt = JSON.stringify([
        selectionKey(),
        mode.value,
        form.name,
        form.promise,
        form.authority,
    ]);

    if (lastAttempt && lastAttempt !== attempt) {
        form.operation_id = requestId();
    }

    lastAttempt = attempt;
    saveState.value = 'saving';
    saveMessage.value = 'Saving…';
    form.action = mode.value;
    form.transform((data) => ({
        ...data,
        target_contribution_count: data.target_contribution_count || null,
        sending_starts_at:
            scheduledStart.value && data.sending_starts_at
                ? `${data.sending_starts_at}:00Z`
                : null,
        sending_ends_at:
            hasDeadline.value && data.sending_ends_at
                ? `${data.sending_ends_at}:00Z`
                : null,
        authority: {
            ...data.authority,
            starts_at: data.authority.starts_at
                ? `${data.authority.starts_at}:00Z`
                : '',
            ends_at: data.authority.ends_at
                ? `${data.authority.ends_at}:00Z`
                : '',
        },
    }));
    let resolveSave!: (saved: boolean) => void;
    pendingSave = new Promise<boolean>((resolve) => {
        resolveSave = resolve;
    });
    const result = pendingSave;
    let succeeded = false;
    form.post(`/campaigns/${props.campaignId}/composition`, {
        preserveScroll: true,
        onSuccess: () => {
            succeeded = true;
            savedSelection = selectionKey();
            saveState.value = 'saved';
            emit('saved');
            saveMessage.value = `Saved — Objective: ${goal.value ? `${goal.value.name} (v${goal.value.version})` : 'not selected'}; Offer: ${offer.value ? `${offer.value.name} (v${offer.value.version})` : 'not selected'}. Approval and launch are still separate.`;
        },
        onError: () => {
            saveState.value = 'error';
            saveMessage.value =
                'Not saved — check the errors below and retry. Your changes are kept here.';
        },
        onFinish: () => {
            if (!succeeded && saveState.value === 'saving') {
                saveState.value = 'error';
                saveMessage.value =
                    'Save was not confirmed. Retry before continuing.';
            }

            pendingSave = null;
            resolveSave(succeeded);
        },
    });

    return result;
}
</script>

<template>
    <section class="composition" aria-labelledby="composition-heading">
        <header>
            <h2 id="composition-heading">
                {{
                    section === 'sending'
                        ? 'Timing and sending permission'
                        : 'Objective and Offer'
                }}
            </h2>
            <p role="status" aria-live="polite">{{ saveMessage }}</p>
            <button
                v-if="saveState === 'error' && mode === 'select'"
                type="button"
                @click="autosave"
            >
                Retry save
            </button>
            <p v-if="section !== 'sending'">
                Reuse your library. Audience, messages and sending belong to
                this campaign.
            </p>
        </header>
        <form
            @submit.prevent="mode === 'select' ? autosave() : submit()"
            @change="autosave"
            @focusout="autosave"
            @input="
                mode === 'select' &&
                (saveMessage =
                    'Changes save when you finish this field. Approval and launch stay separate.')
            "
        >
            <fieldset
                :disabled="
                    !composition.editable ||
                    form.processing ||
                    saveState === 'saving'
                "
            >
                <div v-if="section !== 'sending'" class="choices">
                    <div>
                        <label for="campaign-goal">Objective</label>
                        <select
                            id="campaign-goal"
                            v-model="form.objective_goal_version_id"
                            :aria-invalid="
                                Boolean(
                                    goalFeedback ||
                                    form.errors.objective_goal_version_id,
                                )
                            "
                            :aria-describedby="
                                goalFeedback ||
                                form.errors.objective_goal_version_id
                                    ? 'campaign-goal-feedback'
                                    : undefined
                            "
                        >
                            <option :value="null">Choose later</option>
                            <option
                                v-for="item in composition.goals"
                                :key="item.id"
                                :value="item.id"
                            >
                                {{ item.name }} ·
                                {{
                                    item.measurement_configured === false
                                        ? 'Needs measurement'
                                        : 'Ready to use'
                                }}{{
                                    item.current
                                        ? ''
                                        : ' · selected older version'
                                }}
                            </option>
                        </select>
                        <p
                            v-if="
                                goalFeedback ||
                                form.errors.objective_goal_version_id
                            "
                            id="campaign-goal-feedback"
                            class="field-feedback"
                            role="alert"
                        >
                            {{
                                form.errors.objective_goal_version_id ||
                                goalFeedback
                            }}
                        </p>
                        <p v-if="goal">
                            Meeting booked. This outcome is reusable across
                            campaigns.
                        </p>
                        <button
                            v-if="composition.editable"
                            type="button"
                            class="text-action"
                            @click="open('create_goal')"
                        >
                            New objective
                        </button>
                    </div>
                    <div>
                        <label for="campaign-offer">Offer</label>
                        <select
                            id="campaign-offer"
                            v-model="form.offer_version_id"
                            :aria-invalid="
                                Boolean(
                                    offerFeedback ||
                                    form.errors.offer_version_id,
                                )
                            "
                            :aria-describedby="
                                offerFeedback || form.errors.offer_version_id
                                    ? 'campaign-offer-feedback'
                                    : undefined
                            "
                        >
                            <option :value="null">Choose later</option>
                            <option
                                v-for="item in composition.offers"
                                :key="item.id"
                                :value="item.id"
                            >
                                {{ item.name }} ·
                                {{
                                    item.status === 'active'
                                        ? 'Ready to use'
                                        : 'Needs setup'
                                }}{{
                                    item.current
                                        ? ''
                                        : ' · selected older version'
                                }}
                            </option>
                        </select>
                        <p
                            v-if="offerFeedback || form.errors.offer_version_id"
                            id="campaign-offer-feedback"
                            class="field-feedback"
                            role="alert"
                        >
                            {{ form.errors.offer_version_id || offerFeedback }}
                        </p>
                        <p v-if="offer">
                            Offer acceptance may differ from the Objective
                            outcome. Activation does not approve sending.
                        </p>
                        <button
                            v-if="composition.editable"
                            type="button"
                            class="text-action"
                            @click="open('create_offer')"
                        >
                            New offer
                        </button>
                    </div>
                </div>
                <div v-if="section !== 'sending'" class="choices">
                    <div>
                        <label for="campaign-target"
                            >Campaign target (optional)</label
                        >
                        <input
                            id="campaign-target"
                            v-model.number="form.target_contribution_count"
                            type="number"
                            min="1"
                            max="1000000"
                        />
                        <p>
                            How many Objective outcomes this campaign aims to
                            contribute. This is not a sending limit.
                        </p>
                    </div>
                </div>
                <details
                    v-if="section !== 'purpose'"
                    class="composition-advanced"
                    :open="section !== 'sending'"
                >
                    <summary v-if="section === 'sending'">
                        Advanced sending permission
                    </summary>
                    <div class="choices">
                        <div>
                            <label for="campaign-scope"
                                >Sending allowance</label
                            >
                            <select
                                id="campaign-scope"
                                v-model="form.execution_authority_scope_id"
                                :aria-invalid="
                                    Boolean(
                                        scopeFeedback ||
                                        form.errors
                                            .execution_authority_scope_id,
                                    )
                                "
                                :aria-describedby="
                                    scopeFeedback ||
                                    form.errors.execution_authority_scope_id
                                        ? 'campaign-scope-feedback'
                                        : undefined
                                "
                            >
                                <option :value="null">Choose later</option>
                                <option
                                    v-for="item in composition.execution_scopes ??
                                    []"
                                    :key="item.id"
                                    :value="item.id"
                                    :disabled="item.revoked || item.expired"
                                >
                                    {{ item.name
                                    }}{{
                                        item.revoked
                                            ? ' · revoked'
                                            : item.expired
                                              ? ' · expired'
                                              : ''
                                    }}
                                </option>
                            </select>
                            <p
                                v-if="
                                    scopeFeedback ||
                                    form.errors.execution_authority_scope_id
                                "
                                id="campaign-scope-feedback"
                                class="field-feedback"
                                role="alert"
                            >
                                {{
                                    form.errors.execution_authority_scope_id ||
                                    scopeFeedback
                                }}
                            </p>
                            <p v-if="scope">
                                {{ scope.authority.maximum_messages }} messages
                                · {{ scope.authority.maximum_people }} people ·
                                {{ scope.authority.maximum_accounts }} accounts.
                                Permission window:
                                {{ campaignDate(scope.authority.starts_at) }} —
                                {{ campaignDate(scope.authority.ends_at) }}.
                            </p>
                            <p>
                                Shared across every campaign using this
                                allowance. Selecting it does not reset usage or
                                approve sending.
                            </p>
                            <button
                                v-if="composition.editable"
                                type="button"
                                class="text-action"
                                @click="open('create_scope')"
                            >
                                New sending allowance
                            </button>
                        </div>
                    </div>
                </details>
                <template v-if="section !== 'purpose'">
                    <details class="composition-advanced schedule-options">
                        <summary>Advanced schedule</summary>
                        <div class="choices">
                            <div>
                                <label for="campaign-start-mode"
                                    >Start sending</label
                                >
                                <select
                                    id="campaign-start-mode"
                                    v-model="scheduledStart"
                                >
                                    <option :value="false">
                                        When I launch
                                    </option>
                                    <option :value="true">
                                        Schedule a start
                                    </option>
                                </select>
                                <label
                                    v-if="scheduledStart"
                                    for="campaign-start"
                                    >Scheduled start (UTC)</label
                                >
                                <input
                                    v-if="scheduledStart"
                                    id="campaign-start"
                                    v-model="form.sending_starts_at"
                                    type="datetime-local"
                                    required
                                />
                            </div>
                            <div>
                                <label for="campaign-finish-mode"
                                    >Finish sending</label
                                >
                                <select
                                    id="campaign-finish-mode"
                                    v-model="hasDeadline"
                                >
                                    <option :value="false">
                                        Run until finished
                                    </option>
                                    <option :value="true">
                                        Stop by a deadline
                                    </option>
                                </select>
                                <label v-if="hasDeadline" for="campaign-end"
                                    >Sending deadline (UTC)</label
                                >
                                <input
                                    v-if="hasDeadline"
                                    id="campaign-end"
                                    v-model="form.sending_ends_at"
                                    type="datetime-local"
                                    required
                                />
                            </div>
                        </div>
                    </details>
                    <p>
                        Sending finishes when every prospect has finished the
                        sequence or stopped. Replies remain available in Inbox.
                        Daily limits and sending hours still apply. Approval or
                        allowance expiry pauses sending for attention; it does
                        not mark the campaign complete.
                    </p>
                    <section
                        v-if="composition.sending_forecast"
                        aria-label="Sending capacity"
                    >
                        <h3>Sending capacity</h3>
                        <p>{{ composition.sending_forecast.message }}</p>
                        <details class="composition-advanced capacity-details">
                            <summary>
                                View capacity and allowance details
                            </summary>
                            <div
                                v-if="composition.sending_forecast.allowance"
                                aria-label="Shared allowance usage"
                            >
                                <h4>
                                    {{
                                        composition.sending_forecast.allowance
                                            .name
                                    }}
                                </h4>
                                <p
                                    v-for="(quantity, kind) in composition
                                        .sending_forecast.allowance.quantities"
                                    :key="kind"
                                >
                                    {{ kind }}:
                                    {{ quantity.committed }} committed /
                                    {{ quantity.maximum }} maximum ·
                                    {{ quantity.remaining }} remaining ·
                                    {{ quantity.projected }} including this
                                    campaign
                                </p>
                                <p>
                                    Shared across campaigns. Expiry does not
                                    release committed usage. Unlinked accounts
                                    are estimated conservatively; exact approval
                                    rechecks all limits.
                                </p>
                            </div>
                            <p
                                v-if="
                                    composition.sending_forecast
                                        .estimated_finish_at
                                "
                            >
                                Estimated finish:
                                {{
                                    campaignDate(
                                        composition.sending_forecast
                                            .estimated_finish_at,
                                    )
                                }}.
                                {{ composition.sending_forecast.messages }}
                                messages at up to
                                {{
                                    composition.sending_forecast.daily_capacity
                                }}
                                messages per day across selected inboxes.
                            </p>
                            <p
                                v-if="
                                    composition.sending_forecast.status ===
                                        'over_capacity' ||
                                    composition.sending_forecast.status ===
                                        'daily_limit_exceeded'
                                "
                            >
                                {{ composition.sending_forecast.shortfall }}
                                messages do not fit the saved window or daily
                                target.
                            </p>
                            <p>
                                Estimate for saved settings, not a guarantee.
                                Existing active campaigns have priority; pauses,
                                replies and inbox problems can change
                                completion. Estimates update after changes are
                                saved automatically.
                            </p>
                        </details>
                        <div
                            v-if="
                                composition.sending_forecast.status ===
                                    'over_capacity' ||
                                composition.sending_forecast.status ===
                                    'daily_limit_exceeded'
                            "
                        >
                            <button
                                type="button"
                                class="text-action"
                                @click="emit('sending')"
                            >
                                Choose more ready inboxes
                            </button>
                            <Link
                                :href="`/settings/mailboxes?from_campaign=${campaignId}`"
                                >Increase an inbox daily cap</Link
                            >
                            <button
                                type="button"
                                class="text-action"
                                @click="
                                    hasDeadline = false;
                                    autosave();
                                "
                            >
                                Remove deadline
                            </button>
                            <p>
                                Limits are never increased automatically. Save
                                and review changes before approval.
                            </p>
                        </div>
                    </section>
                    <p v-if="scope && !hasDeadline">
                        No campaign-specific deadline. Sending permission still
                        ends {{ campaignDate(scope.authority.ends_at) }};
                        unfinished work pauses for your attention.
                    </p>
                </template>
                <div v-if="mode !== 'select'" class="inline-create">
                    <h3>
                        {{
                            mode === 'create_goal'
                                ? 'New objective'
                                : mode === 'create_offer'
                                  ? 'New offer'
                                  : 'New sending allowance'
                        }}
                    </h3>
                    <p>
                        {{
                            mode === 'create_scope'
                                ? 'Suggested limits match this saved campaign with a 30-day permission window. Review or adjust them before creating. This is a new shared allowance, not a renewal; it does not approve sending.'
                                : 'Saved to your library and selected for this campaign.'
                        }}
                    </p>
                    <label for="composition-name">Name</label>
                    <input
                        id="composition-name"
                        v-model="form.name"
                        required
                        maxlength="120"
                    />
                    <template v-if="mode === 'create_goal'">
                        <p>
                            Objective type: meeting booked. This creates a
                            reusable draft; complete its measurement before
                            review. Qualification is not implied.
                        </p>
                    </template>
                    <template v-else-if="mode === 'create_offer'">
                        <label for="composition-promise"
                            >Promise (optional)</label
                        >
                        <textarea
                            id="composition-promise"
                            v-model="form.promise"
                            rows="2"
                        />
                        <p>
                            This saves a draft. Complete its contract and
                            approval in Offer setup before sending.
                        </p>
                    </template>
                    <template v-else>
                        <label for="scope-start">Allowance starts (UTC)</label>
                        <input
                            id="scope-start"
                            v-model="form.authority.starts_at"
                            type="datetime-local"
                            required
                        />
                        <label for="scope-end">Allowance ends (UTC)</label>
                        <input
                            id="scope-end"
                            v-model="form.authority.ends_at"
                            type="datetime-local"
                            required
                        />
                        <label for="scope-people">Maximum people</label>
                        <input
                            id="scope-people"
                            v-model.number="form.authority.maximum_people"
                            type="number"
                            min="1"
                            required
                        />
                        <label for="scope-accounts">Maximum accounts</label>
                        <input
                            id="scope-accounts"
                            v-model.number="form.authority.maximum_accounts"
                            type="number"
                            min="1"
                            required
                        />
                        <label for="scope-messages">Maximum messages</label>
                        <input
                            id="scope-messages"
                            v-model.number="form.authority.maximum_messages"
                            type="number"
                            min="1"
                            required
                        />
                        <p>
                            Human-written messages only. No AI spend. The
                            campaign schedule must fit within this window.
                        </p>
                    </template>
                    <button
                        type="button"
                        class="text-action"
                        @click="open('select')"
                    >
                        Cancel
                    </button>
                </div>
                <button
                    v-if="composition.editable && mode !== 'select'"
                    type="submit"
                    class="save"
                >
                    {{
                        form.processing
                            ? 'Saving…'
                            : mode === 'create_scope'
                              ? 'Create and select'
                              : 'Create draft and select'
                    }}
                </button>
            </fieldset>
            <p v-for="(error, key) in form.errors" :key="key" role="alert">
                {{ error }}
            </p>
            <p v-if="!composition.editable">
                These selections are read-only. Only an owner can change an
                unlaunched, uncompiled draft.
            </p>
            <p
                v-else-if="composition.revision > 0 && saveState === 'idle'"
                role="status"
            >
                Saved revision {{ composition.revision }}. Exact campaign review
                and approval are required before sending.
            </p>
        </form>
        <nav v-if="section !== 'sending'" aria-label="Selected library records">
            <Link
                v-if="goal"
                :href="`/objectives/${goal.objective_id}?from_campaign=${campaignId}`"
                @click.prevent="
                    openRecord(
                        `/objectives/${goal.objective_id}?from_campaign=${campaignId}`,
                    )
                "
                >{{
                    goal.measurement_configured === false
                        ? 'Complete objective: What counts'
                        : 'View objective'
                }}</Link
            >
            <Link
                v-if="offer"
                :href="`/offers/${offer.offer_id}/setup?from_campaign=${campaignId}`"
                @click.prevent="
                    openRecord(
                        `/offers/${offer.offer_id}/setup?from_campaign=${campaignId}`,
                    )
                "
                >Complete or review offer</Link
            >
        </nav>
        <div v-if="updatedOffer && section !== 'sending'" role="status">
            <p>
                Your selected Offer has a newer ready version. Nothing changes
                until you choose it.
            </p>
            <button
                type="button"
                class="btn ghost"
                :disabled="!composition.editable || form.processing"
                @click="
                    form.offer_version_id = updatedOffer.id;
                    autosave();
                "
            >
                Use updated Offer (v{{ updatedOffer.version }})
            </button>
        </div>
        <div v-if="updatedGoal && section !== 'sending'" role="status">
            <p>
                This campaign uses an older Objective version without its
                measurement. Version {{ updatedGoal.version }} already has it
                configured.
            </p>
            <button
                id="campaign-update-goal"
                type="button"
                class="btn ghost"
                :disabled="!composition.editable || form.processing"
                @click="
                    form.objective_goal_version_id = updatedGoal.id;
                    autosave();
                "
            >
                Use updated Objective (v{{ updatedGoal.version }})
            </button>
            <p>
                Choosing this version saves it to this campaign automatically.
            </p>
        </div>
        <p v-else-if="goal?.measurement_configured === false" role="status">
            This Objective needs an outcome definition. Open What counts, save
            its measurement, then select the updated version here.
        </p>
    </section>
</template>

<style scoped>
.composition {
    padding: 24px 0;
    border-bottom: 1px solid var(--ob-border);
}
.field-feedback {
    color: #b42318;
    font-weight: 500;
}
select[aria-invalid='true'] {
    border-color: #b42318;
    box-shadow: 0 0 0 1px #b42318;
}
h2 {
    font-size: 18px;
    font-weight: 600;
}
h3 {
    font-weight: 600;
}
p {
    font-size: 13px;
    color: var(--ob-muted);
    margin: 8px 0;
}
fieldset {
    border: 0;
    padding: 0;
    min-width: 0;
}
.choices {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin: 20px 0;
}
.composition-advanced {
    margin: 16px 0;
    border: 1px solid var(--ob-border);
    border-radius: 8px;
    padding: 0 14px;
}
.composition-advanced > summary {
    padding: 12px 0;
    color: var(--ob-ink);
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
}
.composition-advanced > .choices {
    margin-top: 4px;
}
.capacity-details {
    margin-top: 12px;
}
label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 8px;
}
select,
input,
textarea {
    width: 100%;
    padding: 9px 12px;
    border: 1px solid var(--ob-border);
    border-radius: 6px;
    background: var(--ob-surface);
}
.text-action,
nav a {
    font-size: 13px;
    text-decoration: underline;
    text-underline-offset: 3px;
}
.inline-create {
    max-width: 540px;
    display: grid;
    gap: 8px;
    margin-bottom: 20px;
}
.save {
    background: #191918;
    color: white;
    padding: 9px 14px;
    border-radius: 6px;
}
button:disabled {
    opacity: 0.5;
}
nav {
    display: flex;
    gap: 20px;
    margin-top: 12px;
}
[role='alert'] {
    color: #a12622;
}
@media (max-width: 700px) {
    .choices {
        grid-template-columns: 1fr;
    }
}
</style>
