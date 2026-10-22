<script setup lang="ts">
import { AlertCircle, Check, History, LoaderCircle, Trash2 } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watch,
} from 'vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { jsonClient, JsonHttpError } from '@/lib/jsonClient';
import type { JsonErrorBag } from '@/lib/jsonClient';
import type { Offer } from '@/types/offers';

type OfferDraft = {
    name: string;
    promise: string;
    target_audience: string;
    desired_outcome: string;
    call_to_action: string;
    notes: string;
};

type SaveAttempt = {
    body: Record<string, unknown>;
    baseline: OfferDraft;
    draft: OfferDraft;
    offer: Offer | null;
};

type OfferConflict = {
    attempt: SaveAttempt;
    latest: Offer;
};

const draftFields: Array<keyof OfferDraft> = [
    'name',
    'promise',
    'target_audience',
    'desired_outcome',
    'call_to_action',
    'notes',
];

const props = withDefaults(
    defineProps<{
        open: boolean;
        offer?: Offer | null;
        isOwner?: boolean;
    }>(),
    { offer: null, isOwner: false },
);

const emit = defineEmits<{
    'update:open': [value: boolean];
    saved: [response: Record<string, unknown>, keepOpen: boolean];
    delete: [offer: Offer];
    history: [offer: Offer];
    'dirty-change': [dirty: boolean];
}>();

const draft = reactive<OfferDraft>({
    name: '',
    promise: '',
    target_audience: '',
    desired_outcome: '',
    call_to_action: '',
    notes: '',
});
const errors = ref<JsonErrorBag>({});
const formError = ref<string | null>(null);
const saveNotice = ref<string | null>(null);
const processing = ref(false);
const touched = ref(false);
const firstInput = ref<HTMLInputElement | null>(null);
const baseline = ref<OfferDraft>(emptyDraft());
const authoritativeOffer = ref<Offer | null>(null);
const activeAttempt = ref<SaveAttempt | null>(null);
const conflict = ref<OfferConflict | null>(null);
const guardVisible = ref(false);
let guardedAction: (() => void) | null = null;
let afterSaveAction: (() => void) | null = null;

const isNew = computed(() => authoritativeOffer.value === null);
const title = computed(() => (isNew.value ? 'New offer' : 'Edit offer'));
const description = computed(() =>
    isNew.value
        ? 'Write the proposition your future campaigns will use.'
        : 'Keep this proposition separate from the durable GTM profile.',
);
const isDirty = computed(() => !draftsEqual(snapshotDraft(), baseline.value));

function emptyDraft(): OfferDraft {
    return {
        name: '',
        promise: '',
        target_audience: '',
        desired_outcome: '',
        call_to_action: '',
        notes: '',
    };
}

function draftFromOffer(offer: Offer | null | undefined): OfferDraft {
    return {
        name: offer?.name ?? '',
        promise: offer?.promise ?? '',
        target_audience: offer?.target_audience ?? '',
        desired_outcome: offer?.desired_outcome ?? '',
        call_to_action: offer?.call_to_action ?? '',
        notes: offer?.notes ?? '',
    };
}

function snapshotDraft(): OfferDraft {
    return Object.fromEntries(
        draftFields.map((field) => [field, draft[field]]),
    ) as OfferDraft;
}

function assignDraft(value: OfferDraft): void {
    for (const field of draftFields) {
        draft[field] = value[field];
    }
}

function draftsEqual(first: OfferDraft, second: OfferDraft): boolean {
    return draftFields.every((field) => first[field] === second[field]);
}

function resetDraft(): void {
    authoritativeOffer.value = props.offer ?? null;
    const initial = draftFromOffer(authoritativeOffer.value);
    assignDraft(initial);
    baseline.value = initial;
    errors.value = {};
    formError.value = null;
    saveNotice.value = null;
    touched.value = false;
    activeAttempt.value = null;
    conflict.value = null;
    guardVisible.value = false;
    guardedAction = null;
    afterSaveAction = null;
}

watch(
    () => props.open,
    (open, wasOpen) => {
        if (open) {
            if (!wasOpen) {
                resetDraft();
            }

            void nextTick(() => firstInput.value?.focus());
        }
    },
    { immediate: true },
);

watch(
    () => props.offer?.uuid,
    (offerId, previousOfferId) => {
        if (
            props.open &&
            offerId !== previousOfferId &&
            !isDirty.value &&
            activeAttempt.value === null
        ) {
            resetDraft();
        }
    },
);

watch(isDirty, (dirty) => emit('dirty-change', dirty), { immediate: true });

function operationId(): string {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
        return crypto.randomUUID();
    }

    return `offer-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function focusFirstInvalid(): void {
    void nextTick(() => {
        const key = Object.keys(errors.value)[0];
        document
            .querySelector<HTMLElement>(`[data-offer-field="${key}"]`)
            ?.focus();
    });
}

function serverErrors(caught: unknown): JsonErrorBag {
    return caught instanceof JsonHttpError ? caught.errors : {};
}

function requestError(caught: unknown): string {
    if (caught instanceof JsonHttpError) {
        return (
            caught.errors.message ??
            caught.errors.payload ??
            'Offer could not be saved.'
        );
    }

    return 'Offer could not be saved. Check your connection and try again.';
}

function asRecord(value: unknown): Record<string, unknown> | null {
    return typeof value === 'object' && value !== null
        ? (value as Record<string, unknown>)
        : null;
}

function offerFrom(value: unknown): Offer | null {
    const record = asRecord(value);

    return typeof record?.uuid === 'string' ? (record as Offer) : null;
}

function responseOffer(response: Record<string, unknown>): Offer | null {
    return offerFrom(response.offer);
}

function currentOfferFrom(value: unknown): Offer | null {
    const record = asRecord(value);

    if (!record) {
        return null;
    }

    const current = offerFrom(record.current_offer);

    if (current) {
        return current;
    }

    for (const key of ['error', 'conflict', 'data']) {
        const nested = currentOfferFrom(record[key]);

        if (nested) {
            return nested;
        }
    }

    return null;
}

function newAttempt(): SaveAttempt {
    const attemptDraft = snapshotDraft();
    const offer = authoritativeOffer.value;

    return {
        draft: attemptDraft,
        baseline: { ...baseline.value },
        offer,
        body: {
            ...attemptDraft,
            client_operation_id: operationId(),
            ...(offer ? { expected_lock_version: offer.lock_version } : {}),
        },
    };
}

async function save(): Promise<boolean> {
    if (!props.isOwner || processing.value) {
        return false;
    }

    errors.value = {};
    processing.value = true;
    touched.value = true;
    formError.value = null;
    saveNotice.value = null;
    const attempt = (activeAttempt.value ??= newAttempt());

    try {
        const response = attempt.offer
            ? await jsonClient.patch<Record<string, unknown>>(
                  `/offers/${encodeURIComponent(attempt.offer.uuid)}`,
                  attempt.body,
              )
            : await jsonClient.post<Record<string, unknown>>(
                  '/offers',
                  attempt.body,
              );
        const latest = responseOffer(response);
        const editsArrivedWhileSaving = !draftsEqual(
            snapshotDraft(),
            attempt.draft,
        );

        activeAttempt.value = null;
        conflict.value = null;
        errors.value = {};
        formError.value = null;
        authoritativeOffer.value = latest ?? attempt.offer;
        const savedDraft = latest ? draftFromOffer(latest) : attempt.draft;
        baseline.value = savedDraft;

        if (!editsArrivedWhileSaving) {
            assignDraft(savedDraft);
        } else {
            saveNotice.value =
                'The earlier save was recovered. Your later edits are still unsaved.';
        }

        emit('saved', response, editsArrivedWhileSaving);

        if (editsArrivedWhileSaving) {
            if (afterSaveAction) {
                guardedAction = afterSaveAction;
                afterSaveAction = null;
                guardVisible.value = true;
            }

            return true;
        }

        const next = afterSaveAction;
        afterSaveAction = null;

        if (next) {
            next();
        } else {
            emit('update:open', false);
        }

        return true;
    } catch (caught) {
        errors.value = serverErrors(caught);
        formError.value = requestError(caught);

        if (caught instanceof JsonHttpError && caught.status === 409) {
            const latest = currentOfferFrom(caught.data);

            if (latest) {
                conflict.value = { attempt, latest };
                activeAttempt.value = null;
            }
        } else if (caught instanceof JsonHttpError && caught.status === 422) {
            // A validation response proves the operation did not commit. The
            // corrected payload therefore needs a fresh operation ID.
            activeAttempt.value = null;
        }

        if (afterSaveAction) {
            guardedAction = afterSaveAction;
            afterSaveAction = null;
        }

        focusFirstInvalid();

        return false;
    } finally {
        processing.value = false;
    }
}

function guardNavigation(action: () => void): boolean {
    if (processing.value) {
        return false;
    }

    if (!isDirty.value) {
        action();

        return true;
    }

    guardedAction = action;
    guardVisible.value = true;

    return false;
}

function close(): void {
    guardNavigation(() => emit('update:open', false));
}

function keepEditing(): void {
    guardVisible.value = false;
    guardedAction = null;
}

function discardAndContinue(): void {
    const next = guardedAction;
    guardVisible.value = false;
    guardedAction = null;
    assignDraft(baseline.value);
    activeAttempt.value = null;
    conflict.value = null;
    errors.value = {};
    formError.value = null;
    next?.();
}

function saveAndContinue(): void {
    afterSaveAction = guardedAction;
    guardedAction = null;
    guardVisible.value = false;
    void save();
}

function reloadLatest(): void {
    const latest = conflict.value?.latest;

    if (!latest) {
        return;
    }

    authoritativeOffer.value = latest;
    const latestDraft = draftFromOffer(latest);
    baseline.value = latestDraft;
    assignDraft(latestDraft);
    activeAttempt.value = null;
    conflict.value = null;
    errors.value = {};
    formError.value = null;
    saveNotice.value = 'Loaded the latest saved Offer.';
}

function reapplyConflict(): void {
    const currentConflict = conflict.value;

    if (!currentConflict) {
        return;
    }

    const latestDraft = draftFromOffer(currentConflict.latest);
    const reapplied = { ...latestDraft };

    for (const field of draftFields) {
        if (
            currentConflict.attempt.draft[field] !==
            currentConflict.attempt.baseline[field]
        ) {
            reapplied[field] = currentConflict.attempt.draft[field];
        }
    }

    authoritativeOffer.value = currentConflict.latest;
    baseline.value = latestDraft;
    assignDraft(reapplied);
    activeAttempt.value = null;
    conflict.value = null;
    errors.value = {};
    formError.value = null;
    saveNotice.value =
        'Your changes were reapplied over the latest Offer. Review and save again.';
}

function requestDelete(): void {
    if (props.offer && props.offer.status === 'draft' && !processing.value) {
        emit('delete', props.offer);
    }
}

function openHistory(): void {
    if (props.offer && !processing.value) {
        guardNavigation(() => emit('history', props.offer!));
    }
}

function fieldError(key: keyof OfferDraft): string | undefined {
    return errors.value[key];
}

function beforeUnload(event: BeforeUnloadEvent): void {
    if (!props.open || !isDirty.value) {
        return;
    }

    event.preventDefault();
    event.returnValue = '';
}

onMounted(() => window.addEventListener('beforeunload', beforeUnload));
onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload));

defineExpose({ guardNavigation });
</script>

<template>
    <Sheet
        :open="open"
        @update:open="(value) => (value ? emit('update:open', true) : close())"
    >
        <SheetContent
            side="right"
            class="offer-editor-sheet w-full gap-0 p-0 sm:max-w-xl"
            :show-close-button="!processing"
            data-test="offer-editor-drawer"
        >
            <SheetHeader class="offer-editor-header">
                <span class="offer-editor-eyebrow">Offers</span>
                <SheetTitle>{{ title }}</SheetTitle>
                <SheetDescription>{{ description }}</SheetDescription>
                <p
                    v-if="isNew || offer?.status === 'draft'"
                    class="offer-draft-note"
                >
                    Drafts can be saved with incomplete fields. Complete the
                    name, promise, audience, outcome, and call to action before
                    activation.
                </p>
            </SheetHeader>
            <form class="offer-editor-content" @submit.prevent="save">
                <div v-if="!isOwner" class="offer-read-only" role="note">
                    Only workspace owners can change offers. Members can still
                    inspect the proposition and its history.
                </div>
                <div class="offer-editor-fields">
                    <label class="offer-editor-field">
                        <span>Name</span>
                        <input
                            id="offer-field-name"
                            ref="firstInput"
                            v-model="draft.name"
                            data-offer-field="name"
                            type="text"
                            maxlength="160"
                            :disabled="!isOwner"
                            :aria-invalid="
                                fieldError('name') ? 'true' : undefined
                            "
                            :aria-describedby="
                                fieldError('name')
                                    ? 'offer-name-error'
                                    : undefined
                            "
                        />
                        <small
                            v-if="fieldError('name')"
                            id="offer-name-error"
                            role="alert"
                            >{{ fieldError('name') }}</small
                        >
                    </label>
                    <label class="offer-editor-field">
                        <span>Promise</span>
                        <textarea
                            id="offer-field-promise"
                            v-model="draft.promise"
                            data-offer-field="promise"
                            rows="3"
                            maxlength="5000"
                            :disabled="!isOwner"
                            :aria-invalid="
                                fieldError('promise') ? 'true' : undefined
                            "
                            :aria-describedby="
                                fieldError('promise')
                                    ? 'offer-promise-error'
                                    : undefined
                            "
                        />
                        <small
                            v-if="fieldError('promise')"
                            id="offer-promise-error"
                            role="alert"
                            >{{ fieldError('promise') }}</small
                        >
                    </label>
                    <label class="offer-editor-field">
                        <span>Target audience</span>
                        <textarea
                            id="offer-field-target-audience"
                            v-model="draft.target_audience"
                            data-offer-field="target_audience"
                            rows="3"
                            maxlength="5000"
                            :disabled="!isOwner"
                            :aria-invalid="
                                fieldError('target_audience')
                                    ? 'true'
                                    : undefined
                            "
                            :aria-describedby="
                                fieldError('target_audience')
                                    ? 'offer-target-audience-error'
                                    : undefined
                            "
                        />
                        <small
                            v-if="fieldError('target_audience')"
                            id="offer-target-audience-error"
                            role="alert"
                            >{{ fieldError('target_audience') }}</small
                        >
                    </label>
                    <label class="offer-editor-field">
                        <span>Desired outcome</span>
                        <textarea
                            id="offer-field-desired-outcome"
                            v-model="draft.desired_outcome"
                            data-offer-field="desired_outcome"
                            rows="2"
                            maxlength="5000"
                            :disabled="!isOwner"
                            :aria-invalid="
                                fieldError('desired_outcome')
                                    ? 'true'
                                    : undefined
                            "
                            :aria-describedby="
                                fieldError('desired_outcome')
                                    ? 'offer-desired-outcome-error'
                                    : undefined
                            "
                        />
                        <small
                            v-if="fieldError('desired_outcome')"
                            id="offer-desired-outcome-error"
                            role="alert"
                            >{{ fieldError('desired_outcome') }}</small
                        >
                    </label>
                    <label class="offer-editor-field">
                        <span>Call to action</span>
                        <textarea
                            id="offer-field-call-to-action"
                            v-model="draft.call_to_action"
                            data-offer-field="call_to_action"
                            rows="2"
                            maxlength="5000"
                            :disabled="!isOwner"
                            :aria-invalid="
                                fieldError('call_to_action')
                                    ? 'true'
                                    : undefined
                            "
                            :aria-describedby="
                                fieldError('call_to_action')
                                    ? 'offer-call-to-action-error'
                                    : undefined
                            "
                        />
                        <small
                            v-if="fieldError('call_to_action')"
                            id="offer-call-to-action-error"
                            role="alert"
                            >{{ fieldError('call_to_action') }}</small
                        >
                    </label>
                    <label class="offer-editor-field">
                        <span>Notes <em>Optional</em></span>
                        <textarea
                            id="offer-field-notes"
                            v-model="draft.notes"
                            data-offer-field="notes"
                            rows="3"
                            maxlength="2000"
                            :disabled="!isOwner"
                            :aria-invalid="
                                fieldError('notes') ? 'true' : undefined
                            "
                            :aria-describedby="
                                fieldError('notes')
                                    ? 'offer-notes-error'
                                    : undefined
                            "
                        />
                        <small
                            v-if="fieldError('notes')"
                            id="offer-notes-error"
                            role="alert"
                            >{{ fieldError('notes') }}</small
                        >
                    </label>
                </div>
                <div
                    v-if="formError"
                    class="offer-editor-error"
                    role="alert"
                    data-test="offer-editor-error"
                >
                    <AlertCircle aria-hidden="true" />
                    <span>{{ formError }}</span>
                </div>
                <div
                    v-if="conflict"
                    class="offer-conflict-actions"
                    aria-label="Resolve save conflict"
                >
                    <button type="button" @click="reloadLatest">
                        Reload latest
                    </button>
                    <button type="button" @click="reapplyConflict">
                        Reapply my changes
                    </button>
                </div>
                <p
                    v-if="saveNotice"
                    class="offer-save-notice"
                    role="status"
                    data-test="offer-save-notice"
                >
                    {{ saveNotice }}
                </p>
            </form>
            <SheetFooter class="offer-editor-footer">
                <button
                    v-if="offer && offer.status === 'draft' && isOwner"
                    type="button"
                    class="offer-delete-button"
                    :disabled="processing"
                    @click="requestDelete"
                >
                    <Trash2 aria-hidden="true" />
                    Delete draft
                </button>
                <button
                    v-if="offer"
                    type="button"
                    class="offer-history-button"
                    :disabled="processing"
                    @click="openHistory"
                >
                    <History aria-hidden="true" />
                    History
                </button>
                <span class="offer-editor-hint" aria-live="polite">
                    <LoaderCircle
                        v-if="processing"
                        class="offer-spin"
                        aria-hidden="true"
                    />
                    <Check
                        v-else-if="touched && !formError"
                        aria-hidden="true"
                    />
                    {{
                        processing
                            ? 'Saving…'
                            : touched && !formError
                              ? 'Saved'
                              : 'Explicit save'
                    }}
                </span>
                <button
                    type="button"
                    class="offer-cancel-button"
                    :disabled="processing"
                    @click="close"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="offer-save-button"
                    :disabled="processing || !isOwner"
                    @click="save"
                >
                    {{
                        processing
                            ? 'Saving…'
                            : isNew || offer?.status === 'draft'
                              ? 'Save draft'
                              : 'Save changes'
                    }}
                </button>
            </SheetFooter>
            <div
                v-if="guardVisible"
                class="offer-unsaved-backdrop"
                data-test="offer-unsaved-guard"
            >
                <section
                    class="offer-unsaved-dialog"
                    role="alertdialog"
                    aria-modal="true"
                    aria-labelledby="offer-unsaved-title"
                    aria-describedby="offer-unsaved-description"
                >
                    <h3 id="offer-unsaved-title">Unsaved Offer changes</h3>
                    <p id="offer-unsaved-description">
                        Save this draft before leaving, discard the edits, or
                        keep working.
                    </p>
                    <div>
                        <button
                            type="button"
                            class="offer-guard-cancel"
                            :disabled="processing"
                            @click="keepEditing"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            class="offer-guard-discard"
                            :disabled="processing"
                            @click="discardAndContinue"
                        >
                            Discard
                        </button>
                        <button
                            type="button"
                            class="offer-guard-save"
                            :disabled="processing || !isOwner"
                            @click="saveAndContinue"
                        >
                            Save
                        </button>
                    </div>
                </section>
            </div>
        </SheetContent>
    </Sheet>
</template>

<style scoped>
.offer-editor-sheet {
    border-color: var(--ob-border-strong);
    background: #fff;
    color: #29251f;
}

.offer-editor-header {
    border-bottom: 1px solid var(--ob-border-strong);
    padding: 25px 26px 20px;
}

.offer-editor-eyebrow {
    color: #6b655c;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.11em;
    text-transform: uppercase;
}

.offer-editor-header :deep([data-slot='sheet-title']) {
    margin-top: 5px;
    color: #29251f;
    font-size: 21px;
    letter-spacing: -0.025em;
}

.offer-editor-header :deep([data-slot='sheet-description']) {
    margin-top: 7px;
    color: #6b655c;
    font-size: 11px;
    line-height: 1.45;
}

.offer-draft-note {
    margin: 10px 0 0;
    border-left: 2px solid var(--ob-accent-border);
    padding-left: 9px;
    color: #6b655c;
    font-size: 10px;
    line-height: 1.45;
}

.offer-editor-content {
    min-height: 0;
    flex: 1;
    overflow-y: auto;
    padding: 25px 26px;
}

.offer-read-only {
    margin-bottom: 20px;
    border: 1px solid var(--ob-border-strong);
    border-radius: 8px;
    padding: 10px 11px;
    background: #fbfaf7;
    color: #6b655c;
    font-size: 10px;
    line-height: 1.45;
}

.offer-editor-fields {
    display: grid;
    gap: 18px;
}

.offer-editor-field {
    display: grid;
    gap: 7px;
}

.offer-editor-field > span {
    color: #29251f;
    font-size: 10px;
    font-weight: 700;
}

.offer-editor-field em {
    color: #8a847a;
    font-size: 9px;
    font-style: normal;
    font-weight: 400;
}

.offer-editor-field input,
.offer-editor-field textarea {
    box-sizing: border-box;
    width: 100%;
    border: 1px solid var(--ob-border-strong);
    border-radius: 7px;
    padding: 10px 11px;
    background: #fff;
    color: #29251f;
    font: inherit;
    font-size: 12px;
    line-height: 1.45;
}

.offer-editor-field textarea {
    resize: vertical;
}

.offer-editor-field input:focus,
.offer-editor-field textarea:focus {
    border-color: var(--ob-accent);
    box-shadow: 0 0 0 3px rgb(75 73 69 / 10%);
    outline: 0;
}

.offer-editor-field input:disabled,
.offer-editor-field textarea:disabled {
    background: #fbfaf7;
    color: #6b655c;
}

.offer-editor-field small {
    color: #b42318;
    font-size: 9px;
}

.offer-editor-error {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin-top: 20px;
    border: 1px solid #f0c8c3;
    border-radius: 8px;
    padding: 10px;
    background: #fff0ee;
    color: #8e241d;
    font-size: 10px;
    line-height: 1.45;
}

.offer-editor-error :deep(svg) {
    flex: 0 0 15px;
    width: 15px;
    height: 15px;
}

.offer-conflict-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 9px;
}

.offer-conflict-actions button,
.offer-unsaved-dialog button {
    min-height: 34px;
    border: 1px solid var(--ob-accent-border);
    border-radius: 7px;
    padding: 7px 10px;
    background: #fff;
    color: var(--ob-accent-strong);
    font-size: 10px;
    font-weight: 650;
    cursor: pointer;
}

.offer-save-notice {
    margin: 12px 0 0;
    border-left: 2px solid #18794e;
    padding-left: 9px;
    color: #37624f;
    font-size: 10px;
    line-height: 1.45;
}

.offer-editor-footer {
    display: flex;
    flex-direction: row;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    border-top: 1px solid var(--ob-border-strong);
    padding: 15px 26px;
    background: #fbfaf7;
}

.offer-editor-hint {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    flex: 1 1 auto;
    color: #6b655c;
    font-size: 9px;
}

.offer-editor-hint :deep(svg) {
    width: 13px;
    height: 13px;
    color: #18794e;
}

.offer-spin {
    animation: offer-spin 800ms linear infinite;
}

.offer-delete-button,
.offer-history-button,
.offer-cancel-button,
.offer-save-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    min-height: 35px;
    border-radius: 7px;
    padding: 7px 11px;
    font-size: 10px;
    cursor: pointer;
}

.offer-delete-button {
    border: 0;
    background: transparent;
    color: #b42318;
}

.offer-cancel-button {
    border: 1px solid var(--ob-border-strong);
    background: #fff;
    color: #6b655c;
}

.offer-history-button {
    border: 0;
    background: transparent;
    color: #6b655c;
}

.offer-save-button {
    border: 1px solid var(--ob-ink);
    background: var(--ob-ink);
    color: #fff;
}

.offer-delete-button :deep(svg),
.offer-history-button :deep(svg) {
    width: 13px;
    height: 13px;
}

.offer-delete-button:disabled,
.offer-cancel-button:disabled,
.offer-save-button:disabled {
    cursor: not-allowed;
    opacity: 0.55;
}

.offer-unsaved-backdrop {
    position: absolute;
    z-index: 20;
    inset: 0;
    display: grid;
    place-items: center;
    padding: 22px;
    background: rgb(41 37 31 / 38%);
}

.offer-unsaved-dialog {
    width: min(370px, 100%);
    border: 1px solid var(--ob-border-strong);
    border-radius: 10px;
    padding: 20px;
    background: #fff;
    box-shadow: 0 18px 50px rgb(41 37 31 / 18%);
}

.offer-unsaved-dialog h3 {
    margin: 0;
    color: #29251f;
    font-size: 15px;
}

.offer-unsaved-dialog p {
    margin: 8px 0 18px;
    color: #6b655c;
    font-size: 11px;
    line-height: 1.5;
}

.offer-unsaved-dialog > div {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}

.offer-unsaved-dialog .offer-guard-discard {
    border-color: #f0c8c3;
    color: #b42318;
}

.offer-unsaved-dialog .offer-guard-save {
    border-color: var(--ob-ink);
    background: var(--ob-ink);
    color: #fff;
}

@keyframes offer-spin {
    to {
        transform: rotate(360deg);
    }
}

@media (max-width: 620px) {
    .offer-editor-header,
    .offer-editor-content,
    .offer-editor-footer {
        padding-right: 18px;
        padding-left: 18px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .offer-spin {
        animation: none;
    }
}
</style>
