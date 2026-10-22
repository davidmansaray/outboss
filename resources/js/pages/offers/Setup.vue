<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import AppWorkspace from '@/layouts/AppWorkspace.vue';

type Payload = {
    name: string;
    promise: string;
    target_audience: string;
    desired_outcome: string;
    call_to_action: string;
    schema_version?: number;
    proposition?: {
        problem_or_opportunity: string;
        value: string;
        proof?: string;
    };
    requested_commitment?: string;
    cta?: {
        destination_url?: string;
        acceptance_conversion_definition_version_id?: string;
    };
    fulfilment?: { mode: string };
    commercial_authority?: { approved: boolean; notes?: string };
    approved_claims?: string[];
};
const props = defineProps<{
    offer: {
        id: string;
        version_id: string;
        version: number;
        lock_version: number;
        status: string;
        payload: Payload;
        readiness: {
            state: string;
            blockers: { code: string; message: string }[];
        } | null;
    };
    definitions: { id: string; name: string }[];
    is_owner: boolean;
    return_campaign?: { id: number; name: string } | null;
}>();
function requestId() {
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = Array.from(bytes, (byte) =>
        byte.toString(16).padStart(2, '0'),
    ).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
function initial() {
    const p = props.offer.payload;

    return {
        name: p.name,
        promise: p.promise,
        target_audience: p.target_audience,
        desired_outcome: p.desired_outcome,
        problem: p.proposition?.problem_or_opportunity ?? '',
        value: p.proposition?.value ?? '',
        proof: p.proposition?.proof ?? '',
        commitment: p.requested_commitment ?? p.call_to_action,
        destination_url: p.cta?.destination_url ?? '',
        acceptance_version_id:
            p.cta?.acceptance_conversion_definition_version_id ?? '',
        claims_text: (p.approved_claims ?? []).join('\n'),
        commercial_approved: p.commercial_authority?.approved ?? false,
        commercial_notes: p.commercial_authority?.notes ?? '',
        expected_lock_version: props.offer.lock_version,
        client_operation_id: requestId(),
    };
}
const form = useForm(initial());
const review = useForm({
    version_id: props.offer.version_id,
    lock_version: props.offer.lock_version,
    action: '',
    operation_id: requestId(),
});
const measurement = useForm({});
const campaignQuery = computed(() =>
    props.return_campaign ? `?from_campaign=${props.return_campaign.id}` : '',
);
const compatible = computed(
    () =>
        !props.offer.payload.schema_version ||
        props.offer.payload.schema_version === 1 ||
        props.offer.payload.fulfilment?.mode === 'external_destination',
);
const editable = computed(
    () =>
        props.is_owner &&
        compatible.value &&
        ['draft', 'paused'].includes(props.offer.status),
);
watch(
    () => props.offer.lock_version,
    () => {
        form.defaults(initial());
        form.reset();
    },
);
function save() {
    form.transform(({ claims_text, ...data }) => ({
        ...data,
        approved_claims: claims_text
            .split('\n')
            .map((line) => line.trim())
            .filter(Boolean),
    })).patch(`/offers/${props.offer.id}/setup${campaignQuery.value}`, {
        preserveScroll: true,
    });
}
function action(value: string) {
    review.version_id = props.offer.version_id;
    review.lock_version = props.offer.lock_version;
    review.action = value;
    review.operation_id = requestId();
    review.post(
        `/offers/${props.offer.id}/setup/review${campaignQuery.value}`,
        {
            preserveScroll: true,
        },
    );
}
</script>

<template>
    <Head :title="`${offer.payload.name} · Offer setup`" />
    <AppWorkspace area="offers" title="Offer setup">
        <template #sidebar>
            <Link
                v-if="return_campaign"
                :href="`/campaigns/${return_campaign.id}`"
                >← Return to campaign</Link
            >
            <Link href="/offers">← All offers</Link>
        </template>
        <main class="setup">
            <p v-if="return_campaign">
                Editing an Offer for {{ return_campaign.name }}. After saving a
                new version, return to the campaign to select it. Existing
                selections do not change automatically.
            </p>
            <header>
                <p class="eyebrow">
                    CONSULTATION / DEMO · VERSION {{ offer.version }}
                </p>
                <h1>{{ offer.payload.name || 'Offer setup' }}</h1>
                <p>
                    Define what you promise, how someone books, and what counts
                    as acceptance.
                </p>
            </header>
            <p v-if="!compatible" role="status">
                This Offer uses asset or human-assisted delivery. Its existing
                contract is preserved; the consultation editor cannot replace
                it.
            </p>
            <p v-if="offer.status === 'active'">
                Pause this Offer before editing its contract. Existing versions
                remain in history.
            </p>
            <form v-if="compatible" class="contract" @submit.prevent="save">
                <fieldset :disabled="!editable || form.processing">
                    <legend>Promise and fit</legend>
                    <label
                        >Name<input
                            v-model="form.name"
                            required
                            maxlength="160"
                    /></label>
                    <label
                        >Promise<textarea
                            v-model="form.promise"
                            required
                            rows="2"
                        />
                    </label>
                    <label
                        >Who it is for<textarea
                            v-model="form.target_audience"
                            required
                            rows="2"
                        />
                    </label>
                    <label
                        >Desired outcome<input
                            v-model="form.desired_outcome"
                            required
                    /></label>
                    <label
                        >Problem or opportunity<textarea
                            v-model="form.problem"
                            required
                            rows="2"
                        />
                    </label>
                    <label
                        >Value delivered<textarea
                            v-model="form.value"
                            required
                            rows="2"
                        />
                    </label>
                    <label
                        >Supporting proof (optional)<textarea
                            v-model="form.proof"
                            rows="2"
                        />
                    </label>
                </fieldset>
                <fieldset :disabled="!editable || form.processing">
                    <legend>Booking and measurement</legend>
                    <label
                        >Requested commitment<input
                            v-model="form.commitment"
                            required
                            placeholder="Book a 30-minute consultation"
                    /></label>
                    <label
                        >Booking destination<input
                            v-model="form.destination_url"
                            type="url"
                            required
                            placeholder="https://…"
                    /></label>
                    <p class="hint">
                        Use an existing HTTPS booking destination. Outboss does
                        not provision or verify a booking integration when you
                        save.
                    </p>
                    <label
                        >Offer acceptance<select
                            v-model="form.acceptance_version_id"
                            required
                        >
                            <option value="" disabled>
                                Choose a workspace measurement
                            </option>
                            <option
                                v-for="definition in definitions"
                                :key="definition.id"
                                :value="definition.id"
                            >
                                {{ definition.name }}
                            </option>
                        </select></label
                    >
                </fieldset>
                <fieldset :disabled="!editable || form.processing">
                    <legend>Claims and authority</legend>
                    <label
                        >Approved claims — one per line<textarea
                            v-model="form.claims_text"
                            required
                            rows="3"
                        />
                    </label>
                    <label class="checkbox"
                        ><input
                            v-model="form.commercial_approved"
                            type="checkbox"
                        />I am authorized to offer this promise and its
                        terms.</label
                    >
                    <label
                        >Terms and authority notes<textarea
                            v-model="form.commercial_notes"
                            rows="2"
                        />
                    </label>
                </fieldset>
                <div v-if="Object.keys(form.errors).length" role="alert">
                    <p v-for="(error, key) in form.errors" :key="key">
                        {{ error }}
                    </p>
                </div>
                <button
                    v-if="editable"
                    type="submit"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'Saving…' : 'Save offer version' }}
                </button>
            </form>
            <section v-if="is_owner && !definitions.length" class="measurement">
                <h2>No active measurements</h2>
                <p>
                    Create and activate the standard “Meeting booked” definition
                    for this workspace. It counts recorded bookings, not
                    qualified meetings.
                </p>
                <button
                    type="button"
                    :disabled="measurement.processing"
                    @click="
                        measurement.post(
                            `/offers/${offer.id}/setup/measurement${campaignQuery}`,
                            { preserveScroll: true },
                        )
                    "
                >
                    Create meeting measurement
                </button>
                <p
                    v-for="(error, key) in measurement.errors"
                    :key="key"
                    role="alert"
                >
                    {{ error }}
                </p>
            </section>
            <section class="review">
                <h2>Review and availability</h2>
                <details>
                    <summary>
                        Full saved contract · version {{ offer.version }}
                    </summary>
                    <pre>{{ JSON.stringify(offer.payload, null, 2) }}</pre>
                </details>
                <p>
                    {{ offer.status }} ·
                    {{ offer.readiness?.state ?? 'Not checked' }}
                </p>
                <ul v-if="offer.readiness?.blockers.length">
                    <li
                        v-for="blocker in offer.readiness.blockers"
                        :key="blocker.code"
                    >
                        {{ blocker.message }}
                    </li>
                </ul>
                <p>
                    Review applies to saved version {{ offer.version }}.
                    Activating an Offer does not approve or launch a campaign.
                </p>
                <p v-if="form.isDirty">
                    Save or discard your edits before reviewing this version.
                </p>
                <div v-if="is_owner" class="actions">
                    <button
                        type="button"
                        :disabled="review.processing || form.isDirty"
                        @click="action('check')"
                    >
                        Check readiness
                    </button>
                    <button
                        v-if="
                            ['draft', 'paused'].includes(offer.status) &&
                            offer.readiness?.state !== 'approved' &&
                            offer.readiness?.state !== 'ready_for_review'
                        "
                        type="button"
                        :disabled="review.processing || form.isDirty"
                        @click="action('submit')"
                    >
                        Submit saved version for review
                    </button>
                    <button
                        v-if="offer.readiness?.state === 'ready_for_review'"
                        type="button"
                        :disabled="review.processing || form.isDirty"
                        @click="action('approve')"
                    >
                        Approve version {{ offer.version }}
                    </button>
                    <button
                        v-if="
                            offer.readiness?.state === 'approved' &&
                            ['draft', 'paused'].includes(offer.status)
                        "
                        type="button"
                        :disabled="review.processing || form.isDirty"
                        @click="action('activate')"
                    >
                        Activate offer
                    </button>
                    <button
                        v-if="offer.status === 'active'"
                        type="button"
                        :disabled="review.processing"
                        @click="action('pause')"
                    >
                        Pause offer
                    </button>
                </div>
                <p
                    v-for="(error, key) in review.errors"
                    :key="key"
                    role="alert"
                >
                    {{ error }}
                </p>
            </section>
        </main>
    </AppWorkspace>
</template>

<style scoped>
.setup {
    padding: 32px;
    max-width: 1000px;
}
h1 {
    font-size: 28px;
    font-weight: 600;
    margin: 10px 0;
}
h2,
legend {
    font-size: 17px;
    font-weight: 600;
}
.eyebrow {
    font-size: 11px;
    letter-spacing: 0.08em;
}
header {
    margin-bottom: 28px;
}
.contract,
fieldset {
    display: grid;
    gap: 18px;
}
fieldset {
    border: 0;
    border-top: 1px solid var(--ob-border);
    padding: 20px 0;
    min-width: 0;
}
label {
    display: grid;
    gap: 8px;
    font-weight: 500;
}
input,
select,
textarea {
    border: 1px solid var(--ob-border);
    padding: 9px 12px;
    border-radius: 6px;
    background: var(--ob-surface);
    width: 100%;
}
.checkbox {
    display: flex;
    align-items: center;
}
.checkbox input {
    width: auto;
}
.hint,
header > p,
.review > p {
    color: var(--ob-muted);
    font-size: 13px;
}
button {
    background: #191918;
    color: white;
    padding: 10px 14px;
    border-radius: 6px;
    justify-self: start;
}
button:disabled {
    opacity: 0.5;
}
input:focus-visible,
select:focus-visible,
textarea:focus-visible,
button:focus-visible {
    outline: 2px solid var(--ob-focus);
    outline-offset: 2px;
}
.review,
.measurement {
    display: grid;
    gap: 14px;
    padding: 24px 0;
    margin-top: 24px;
    border-top: 1px solid var(--ob-border);
}
.actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
pre {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    max-width: 100%;
    font-size: 12px;
}
[role='alert'] {
    color: #a12622;
}
</style>
