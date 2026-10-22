<script setup lang="ts">
import {
    AlertCircle,
    ArrowRight,
    Check,
    Edit3,
    ShieldCheck,
} from '@lucide/vue';
import { computed, ref, shallowRef, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { jsonClient, JsonHttpError } from '@/lib/jsonClient';
import type { GtmProfileReviewItem } from '@/types/gtmProfile';

type ReviewAction = 'accept_research' | 'keep_owner' | 'edit_owner';

type ReviewMutationBody = {
    action: ReviewAction;
    value?: unknown;
    edited_value?: { value: string };
    client_operation_id: string;
    expected_lock_version: number | undefined;
};

type PendingReviewRequest = {
    itemUuid: string;
    body: ReviewMutationBody;
};

const props = withDefaults(
    defineProps<{
        open: boolean;
        reviewItems?: GtmProfileReviewItem[];
        isOwner?: boolean;
        lockVersion?: number;
        reconcile?: (
            item: GtmProfileReviewItem,
            response: Record<string, unknown>,
        ) => void | Promise<void>;
    }>(),
    {
        reviewItems: () => [],
        isOwner: false,
        lockVersion: undefined,
    },
);

const emit = defineEmits<{
    'update:open': [value: boolean];
    resolved: [item: GtmProfileReviewItem, response: Record<string, unknown>];
}>();

const queue = ref<string[]>([]);
const itemSnapshots = ref<Record<string, GtmProfileReviewItem>>({});
const completedUuids = ref(new Set<string>());
const completedCount = ref(0);
const sessionTotal = ref(0);
const localLockVersion = ref<number | undefined>(props.lockVersion);
const processing = ref(false);
const error = ref<string | null>(null);
const reconciliationPending = ref<{
    item: GtmProfileReviewItem;
    response: Record<string, unknown>;
} | null>(null);
// Keep the body object itself stable so a lost-response retry is byte-for-byte
// the same logical mutation, including its client operation ID.
const pendingRequest = shallowRef<PendingReviewRequest | null>(null);
const ownerAnswer = ref('');
const editingOwnerAnswer = ref(false);

const activeItem = computed(() => {
    const uuid = queue.value[0];

    return uuid ? (itemSnapshots.value[uuid] ?? null) : null;
});
const progressLabel = computed(() =>
    activeItem.value
        ? `Question ${Math.min(completedCount.value + 1, sessionTotal.value)} of ${sessionTotal.value}`
        : 'No open questions',
);
const isMissing = computed(() => activeItem.value?.type === 'missing');
const hasProposal = computed(
    () =>
        activeItem.value?.proposed_value !== null &&
        activeItem.value?.proposed_value !== undefined,
);
const canKeepOwner = computed(
    () => Boolean(activeItem.value) && !isMissing.value,
);
const canAcceptResearch = computed(
    () => canKeepOwner.value && hasProposal.value,
);
const hasFrozenRequest = computed(
    () => pendingRequest.value?.itemUuid === activeItem.value?.uuid,
);
const answerIsValid = computed(() => ownerAnswer.value.trim().length > 0);

function initializeQueue(): void {
    const items = props.reviewItems.filter((item) => item.status === 'open');
    itemSnapshots.value = Object.fromEntries(
        items.map((item) => [item.uuid, item]),
    );
    queue.value = items.map((item) => item.uuid);
    completedUuids.value = new Set();
    completedCount.value = 0;
    sessionTotal.value = items.length;
    localLockVersion.value = props.lockVersion;
    reconciliationPending.value = null;
    pendingRequest.value = null;
}

watch(
    () => props.open,
    (open) => {
        if (open) {
            initializeQueue();
            error.value = null;
        }
    },
    { immediate: true },
);

watch(
    () => activeItem.value?.uuid,
    () => {
        ownerAnswer.value = '';
        editingOwnerAnswer.value = isMissing.value;

        if (
            pendingRequest.value &&
            pendingRequest.value.itemUuid !== activeItem.value?.uuid
        ) {
            pendingRequest.value = null;
        }
    },
    { immediate: true },
);

watch(
    () => props.reviewItems,
    (items) => {
        const open = items.filter((item) => item.status === 'open');
        const snapshots = { ...itemSnapshots.value };

        for (const item of open) {
            snapshots[item.uuid] = item;
        }

        itemSnapshots.value = snapshots;

        if (!props.open) {
            return;
        }

        const queued = new Set(queue.value);
        const additions = open
            .map((item) => item.uuid)
            .filter(
                (uuid) => !queued.has(uuid) && !completedUuids.value.has(uuid),
            );
        queue.value = [...queue.value, ...additions];
        sessionTotal.value = Math.max(
            sessionTotal.value,
            completedCount.value + queue.value.length,
        );
    },
    { deep: true },
);

watch(
    () => props.lockVersion,
    (lockVersion) => {
        if (!processing.value && !reconciliationPending.value) {
            localLockVersion.value = lockVersion;
        }
    },
);

function labelForPath(path: string): string {
    return path
        .split('.')
        .slice(-1)[0]
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function proposedValue(item: GtmProfileReviewItem): string {
    if (item.proposed_label) {
        return item.proposed_label;
    }

    if (Array.isArray(item.proposed_value)) {
        return item.proposed_value.join(', ');
    }

    if (item.proposed_value && typeof item.proposed_value === 'object') {
        const value = (item.proposed_value as { value?: unknown }).value;

        return value === undefined
            ? JSON.stringify(item.proposed_value)
            : String(value);
    }

    return item.proposed_value === undefined || item.proposed_value === null
        ? 'No proposed value'
        : String(item.proposed_value);
}

function operationId(): string {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
        return crypto.randomUUID();
    }

    return `review-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function errorMessage(caught: unknown): string {
    if (caught instanceof JsonHttpError) {
        return (
            caught.errors.message ??
            caught.errors.action ??
            'This review question could not be resolved.'
        );
    }

    return 'This review question could not be resolved. Check your connection and try again.';
}

function isAmbiguousFailure(caught: unknown): boolean {
    return !(caught instanceof JsonHttpError) || caught.status >= 500;
}

function responseLockVersion(response: Record<string, unknown>): number | null {
    if (typeof response.lock_version === 'number') {
        return response.lock_version;
    }

    const responseProfile = response.profile;

    if (
        responseProfile &&
        typeof responseProfile === 'object' &&
        'lock_version' in responseProfile &&
        typeof responseProfile.lock_version === 'number'
    ) {
        return responseProfile.lock_version;
    }

    return null;
}

function finishReconciliation(uuid: string): void {
    queue.value = queue.value.filter((queuedUuid) => queuedUuid !== uuid);
    completedCount.value += 1;
    reconciliationPending.value = null;
    error.value = null;

    if (queue.value.length === 0) {
        emit('update:open', false);
    }
}

async function reconcileResolved(
    item: GtmProfileReviewItem,
    response: Record<string, unknown>,
): Promise<boolean> {
    reconciliationPending.value = { item, response };

    try {
        await props.reconcile?.(item, response);
        finishReconciliation(item.uuid);

        return true;
    } catch {
        error.value =
            'Your answer was saved, but the latest questions could not be loaded. Retry the refresh before continuing.';

        return false;
    }
}

function makeRequest(
    item: GtmProfileReviewItem,
    action: ReviewAction,
    editedValue?: string,
): PendingReviewRequest {
    return {
        itemUuid: item.uuid,
        body: {
            action,
            value:
                action === 'accept_research' ? item.proposed_value : undefined,
            edited_value:
                action === 'edit_owner' && editedValue !== undefined
                    ? { value: editedValue }
                    : undefined,
            client_operation_id: operationId(),
            expected_lock_version: localLockVersion.value,
        },
    };
}

async function sendRequest(request: PendingReviewRequest): Promise<void> {
    const item = activeItem.value;

    if (
        !item ||
        item.uuid !== request.itemUuid ||
        processing.value ||
        !props.isOwner
    ) {
        return;
    }

    pendingRequest.value = request;
    processing.value = true;
    error.value = null;

    try {
        const response = await jsonClient.post<Record<string, unknown>>(
            `/settings/gtm-profile/review/${encodeURIComponent(item.uuid)}`,
            request.body,
        );
        pendingRequest.value = null;
        const nextLockVersion = responseLockVersion(response);

        if (nextLockVersion !== null) {
            localLockVersion.value = nextLockVersion;
        }

        completedUuids.value = new Set(completedUuids.value).add(item.uuid);
        emit('resolved', item, response);
        await reconcileResolved(item, response);
    } catch (caught) {
        if (isAmbiguousFailure(caught)) {
            error.value =
                'The connection ended before the save was confirmed. Retry sends the exact same answer without creating a second resolution.';
        } else {
            pendingRequest.value = null;
            error.value = errorMessage(caught);
        }
    } finally {
        processing.value = false;
    }
}

async function resolve(
    action: 'accept_research' | 'keep_owner',
): Promise<void> {
    const item = activeItem.value;

    if (!item || processing.value || !props.isOwner || hasFrozenRequest.value) {
        return;
    }

    await sendRequest(makeRequest(item, action));
}

async function saveOwnerAnswer(): Promise<void> {
    const item = activeItem.value;

    if (
        !item ||
        processing.value ||
        !props.isOwner ||
        !answerIsValid.value ||
        hasFrozenRequest.value
    ) {
        return;
    }

    await sendRequest(
        makeRequest(item, 'edit_owner', ownerAnswer.value.trim()),
    );
}

async function retryPendingRequest(): Promise<void> {
    const request = pendingRequest.value;

    if (!request || processing.value) {
        return;
    }

    await sendRequest(request);
}

async function retryReconciliation(): Promise<void> {
    const pending = reconciliationPending.value;

    if (!pending || processing.value) {
        return;
    }

    processing.value = true;

    try {
        await reconcileResolved(pending.item, pending.response);
    } finally {
        processing.value = false;
    }
}

function skip(): void {
    if (
        processing.value ||
        reconciliationPending.value ||
        hasFrozenRequest.value
    ) {
        return;
    }

    const skippedUuid = queue.value[0];

    if (skippedUuid) {
        completedUuids.value = new Set(completedUuids.value).add(skippedUuid);
    }

    queue.value = queue.value.slice(1);
    completedCount.value += 1;

    if (queue.value.length === 0) {
        emit('update:open', false);
    }
}

function editActive(): void {
    if (
        !activeItem.value ||
        processing.value ||
        reconciliationPending.value ||
        hasFrozenRequest.value
    ) {
        return;
    }

    editingOwnerAnswer.value = true;
    ownerAnswer.value = '';
}

function cancelOwnerAnswer(): void {
    if (isMissing.value || processing.value || hasFrozenRequest.value) {
        return;
    }

    editingOwnerAnswer.value = false;
    ownerAnswer.value = '';
}

function handleOpenChange(open: boolean): void {
    if (
        !open &&
        (processing.value ||
            reconciliationPending.value ||
            hasFrozenRequest.value)
    ) {
        emit('update:open', true);

        return;
    }

    emit('update:open', open);
}
</script>

<template>
    <Dialog :open="open" @update:open="handleOpenChange">
        <DialogContent
            class="gtm-review-dialog sm:max-w-lg"
            :show-close-button="
                !processing && !reconciliationPending && !hasFrozenRequest
            "
            data-test="gtm-review-dialog"
        >
            <DialogHeader>
                <span class="gtm-review-eyebrow">Profile review</span>
                <DialogTitle>Resolve one open question</DialogTitle>
                <DialogDescription>
                    Answer missing questions or choose how to handle a research
                    proposal. Skipping leaves the question open for later.
                </DialogDescription>
            </DialogHeader>

            <div v-if="activeItem" class="gtm-review-body">
                <div class="gtm-review-progress" aria-live="polite">
                    <span>{{ progressLabel }}</span>
                    <span>{{ activeItem.type }}</span>
                </div>
                <div class="gtm-review-question">
                    <span class="gtm-review-field">
                        {{ labelForPath(activeItem.field_path) }}
                    </span>
                    <h2>
                        {{
                            activeItem.question ||
                            `Is this the right answer for ${labelForPath(activeItem.field_path)}?`
                        }}
                    </h2>
                    <p v-if="activeItem.explanation">
                        {{ activeItem.explanation }}
                    </p>
                </div>
                <div v-if="hasProposal" class="gtm-review-proposal">
                    <div class="gtm-review-proposal-icon" aria-hidden="true">
                        <ShieldCheck />
                    </div>
                    <div>
                        <span>Research proposes</span>
                        <strong>{{ proposedValue(activeItem) }}</strong>
                    </div>
                </div>
                <div
                    v-if="editingOwnerAnswer"
                    class="gtm-review-answer"
                    data-test="gtm-review-owner-answer"
                >
                    <label :for="`gtm-review-answer-${activeItem.uuid}`">
                        {{ isMissing ? 'Your answer' : 'Edit your answer' }}
                    </label>
                    <textarea
                        :id="`gtm-review-answer-${activeItem.uuid}`"
                        v-model="ownerAnswer"
                        rows="3"
                        :disabled="processing || !isOwner || hasFrozenRequest"
                        :placeholder="
                            isMissing
                                ? `Enter ${labelForPath(activeItem.field_path).toLowerCase()}`
                                : 'Enter the answer Outboss should use'
                        "
                        data-test="gtm-review-answer-input"
                    />
                    <p>
                        Saving records this as the owner-approved answer and
                        resolves every open proposal for this field.
                    </p>
                </div>
                <div
                    v-if="error"
                    class="gtm-review-error"
                    role="alert"
                    data-test="gtm-review-error"
                >
                    <AlertCircle aria-hidden="true" />
                    <span>{{ error }}</span>
                    <button
                        v-if="reconciliationPending"
                        type="button"
                        data-test="gtm-review-retry-refresh"
                        :disabled="processing"
                        @click="retryReconciliation"
                    >
                        Retry refresh
                    </button>
                    <button
                        v-else-if="hasFrozenRequest"
                        type="button"
                        data-test="gtm-review-retry-request"
                        :disabled="processing"
                        @click="retryPendingRequest"
                    >
                        Retry same answer
                    </button>
                </div>
            </div>
            <div v-else class="gtm-review-empty">
                <Check aria-hidden="true" />
                <strong>Nothing needs your attention.</strong>
                <p>New research questions will appear here when needed.</p>
            </div>

            <DialogFooter v-if="activeItem" class="gtm-review-footer">
                <button
                    type="button"
                    class="gtm-review-secondary"
                    :disabled="
                        processing ||
                        Boolean(reconciliationPending) ||
                        hasFrozenRequest
                    "
                    @click="skip"
                >
                    Skip
                </button>
                <button
                    v-if="!editingOwnerAnswer && !isMissing"
                    type="button"
                    class="gtm-review-secondary"
                    :disabled="
                        processing ||
                        Boolean(reconciliationPending) ||
                        hasFrozenRequest ||
                        !isOwner
                    "
                    @click="editActive"
                >
                    <Edit3 aria-hidden="true" />
                    Edit mine
                </button>
                <button
                    v-if="editingOwnerAnswer && !isMissing"
                    type="button"
                    class="gtm-review-secondary"
                    :disabled="processing || hasFrozenRequest"
                    @click="cancelOwnerAnswer"
                >
                    Cancel edit
                </button>
                <button
                    v-if="editingOwnerAnswer"
                    type="button"
                    class="gtm-review-primary gtm-review-save-answer"
                    :disabled="
                        processing ||
                        Boolean(reconciliationPending) ||
                        hasFrozenRequest ||
                        !isOwner ||
                        !answerIsValid
                    "
                    data-test="gtm-review-save-answer"
                    @click="saveOwnerAnswer"
                >
                    Save answer
                    <ArrowRight aria-hidden="true" />
                </button>
                <button
                    v-if="!editingOwnerAnswer && canKeepOwner"
                    type="button"
                    class="gtm-review-primary"
                    :disabled="
                        processing ||
                        Boolean(reconciliationPending) ||
                        hasFrozenRequest ||
                        !isOwner
                    "
                    @click="resolve('keep_owner')"
                >
                    Keep mine
                </button>
                <button
                    v-if="!editingOwnerAnswer && canAcceptResearch"
                    type="button"
                    class="gtm-review-primary gtm-review-accept"
                    :disabled="
                        processing ||
                        Boolean(reconciliationPending) ||
                        hasFrozenRequest ||
                        !isOwner
                    "
                    @click="resolve('accept_research')"
                >
                    Accept research
                    <ArrowRight aria-hidden="true" />
                </button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

<style scoped>
.gtm-review-dialog {
    border-color: var(--ob-border-strong);
    background: #fff;
    color: #29251f;
}

.gtm-review-dialog :deep([data-slot='dialog-header']) {
    gap: 8px;
}

.gtm-review-dialog :deep([data-slot='dialog-title']) {
    color: #29251f;
    font-size: 21px;
    letter-spacing: -0.025em;
}

.gtm-review-dialog :deep([data-slot='dialog-description']) {
    color: #6b655c;
    font-size: 11px;
    line-height: 1.5;
}

.gtm-review-eyebrow {
    color: #6b655c;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.11em;
    text-transform: uppercase;
}

.gtm-review-body {
    display: grid;
    gap: 16px;
}

.gtm-review-progress {
    display: flex;
    justify-content: space-between;
    color: #8a847a;
    font-size: 9px;
    text-transform: capitalize;
}

.gtm-review-question {
    border-top: 1px solid var(--ob-border-strong);
    padding-top: 15px;
}

.gtm-review-field {
    color: var(--ob-accent-strong);
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.gtm-review-question h2 {
    margin: 8px 0 0;
    color: #29251f;
    font-size: 16px;
    font-weight: 600;
    line-height: 1.4;
}

.gtm-review-question p {
    margin: 8px 0 0;
    color: #6b655c;
    font-size: 11px;
    line-height: 1.5;
}

.gtm-review-proposal {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    border: 1px solid var(--ob-accent-tint-strong);
    border-radius: 8px;
    padding: 12px;
    background: var(--ob-accent-tint-soft);
}

.gtm-review-proposal-icon {
    display: grid;
    flex: 0 0 26px;
    width: 26px;
    height: 26px;
    place-items: center;
    border-radius: 7px;
    background: var(--ob-accent-tint);
    color: var(--ob-accent-strong);
}

.gtm-review-proposal-icon :deep(svg) {
    width: 14px;
    height: 14px;
}

.gtm-review-proposal span,
.gtm-review-proposal strong {
    display: block;
}

.gtm-review-proposal span {
    color: #6b655c;
    font-size: 9px;
}

.gtm-review-proposal strong {
    margin-top: 4px;
    color: #29251f;
    font-size: 12px;
    font-weight: 650;
    line-height: 1.45;
    white-space: pre-wrap;
}

.gtm-review-answer {
    display: grid;
    gap: 7px;
}

.gtm-review-answer label {
    color: #29251f;
    font-size: 10px;
    font-weight: 700;
}

.gtm-review-answer textarea {
    width: 100%;
    resize: vertical;
    border: 1px solid #cfc9bf;
    border-radius: 7px;
    padding: 9px 10px;
    background: #fff;
    color: #29251f;
    font: inherit;
    font-size: 11px;
    line-height: 1.45;
}

.gtm-review-answer textarea:focus-visible {
    border-color: var(--ob-accent);
    outline: 2px solid rgb(75 73 69 / 20%);
    outline-offset: 1px;
}

.gtm-review-answer p {
    margin: 0;
    color: #6b655c;
    font-size: 9px;
    line-height: 1.45;
}

.gtm-review-error {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    border: 1px solid #f0c8c3;
    border-radius: 8px;
    padding: 10px;
    background: #fff0ee;
    color: #8e241d;
    font-size: 10px;
    line-height: 1.45;
}

.gtm-review-error :deep(svg) {
    flex: 0 0 15px;
    width: 15px;
    height: 15px;
}

.gtm-review-error button {
    flex: 0 0 auto;
    margin-left: auto;
    border: 1px solid currentcolor;
    border-radius: 6px;
    padding: 4px 7px;
    background: transparent;
    color: inherit;
    font-size: 9px;
    font-weight: 700;
}

.gtm-review-empty {
    display: grid;
    justify-items: center;
    gap: 7px;
    padding: 16px 0 6px;
    text-align: center;
}

.gtm-review-empty :deep(svg) {
    width: 24px;
    height: 24px;
    color: #18794e;
}

.gtm-review-empty strong {
    color: #29251f;
    font-size: 12px;
}

.gtm-review-empty p {
    margin: 0;
    color: #6b655c;
    font-size: 10px;
}

.gtm-review-footer {
    flex-wrap: wrap;
    gap: 8px;
}

.gtm-review-footer button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 34px;
    border-radius: 7px;
    padding: 7px 10px;
    font-size: 10px;
    cursor: pointer;
}

.gtm-review-footer button :deep(svg) {
    width: 13px;
    height: 13px;
}

.gtm-review-footer button:disabled {
    cursor: not-allowed;
    opacity: 0.55;
}

.gtm-review-secondary {
    border: 1px solid var(--ob-border-strong);
    background: #fff;
    color: #6b655c;
}

.gtm-review-primary {
    border: 1px solid var(--ob-accent);
    background: #fff;
    color: var(--ob-accent-strong);
}

.gtm-review-accept {
    border-color: var(--ob-ink);
    background: var(--ob-ink);
    color: #fff;
}

@media (max-width: 560px) {
    .gtm-review-footer button {
        flex: 1 1 calc(50% - 8px);
    }
}
</style>
