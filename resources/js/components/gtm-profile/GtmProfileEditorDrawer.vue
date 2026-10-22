<script setup lang="ts">
import { AlertCircle, Check, CircleHelp, LoaderCircle } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useGtmProfileAutosave } from '@/composables/useGtmProfileAutosave';
import {
    displayGtmValue,
    gtmValueProvenance,
    GTM_PROFILE_SECTIONS,
    isGtmProfileRepeatedField,
} from '@/types/gtmProfile';
import type { GtmProfile, GtmProfileSection } from '@/types/gtmProfile';

type RepeatedDraftItem = {
    id?: string;
    value: string;
    tombstoned?: boolean;
};

const UUID_PATTERN =
    /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

const props = withDefaults(
    defineProps<{
        open: boolean;
        profile: GtmProfile;
        section: GtmProfileSection | null;
        isOwner?: boolean;
    }>(),
    { isOwner: false },
);

const emit = defineEmits<{
    'update:open': [value: boolean];
    saved: [response: Record<string, unknown>];
    conflict: [];
    reload: [];
    discarded: [];
}>();

const fieldKeys: Record<GtmProfileSection, Array<[string, string]>> = {
    buyers: [
        ['target_roles', 'Target roles'],
        ['buying_triggers', 'Buying triggers'],
        ['blocking_roles', 'Blocking roles'],
    ],
    company: [
        ['name', 'Company name'],
        ['website_url', 'Company website'],
        ['category', 'Category'],
        ['product_description', 'Product description'],
        ['target_problem', 'Target problem'],
        ['primary_outcome', 'Primary outcome'],
    ],
    market: [
        ['target_segment', 'Target segment'],
        ['geographies', 'Geographies'],
        ['company_traits', 'Company traits'],
        ['excluded_segments', 'Excluded segments'],
        ['timing_thesis', 'Timing thesis'],
    ],
    messaging: [
        ['proof_points', 'Proof points'],
        ['prohibited_claims', 'Prohibited claims'],
        ['brand_voice', 'Brand voice'],
        ['message_constraints', 'Message constraints'],
    ],
    qualification: [
        ['dimensions', 'Qualification dimensions'],
        ['review_min', 'Review threshold'],
        ['contact_min', 'Contact threshold'],
        ['evidence_requirements', 'Evidence requirements'],
        ['business_disqualifiers', 'Business disqualifiers'],
    ],
};

const draft = ref<Record<string, string>>({});
const repeatedDraftItems = ref<Record<string, RepeatedDraftItem[]>>({});
const structuredDraftValues = ref<Record<string, unknown>>({});
const dirtyFields = ref(new Set<string>());
const lockVersion = ref(props.profile.lock_version);
const conflictReviewOpen = ref(false);
const reapplyingConflict = ref(false);

const sectionMeta = computed(
    () =>
        GTM_PROFILE_SECTIONS.find((item) => item.id === props.section) ?? null,
);

function editableValue(value: unknown): string {
    if (Array.isArray(value)) {
        return value
            .filter((item) => !isTombstoned(item))
            .map((item) => displayGtmValue(item))
            .filter(Boolean)
            .join('\n');
    }

    return displayGtmValue(value);
}

function isTombstoned(value: unknown): boolean {
    return (
        typeof value === 'object' &&
        value !== null &&
        'tombstoned' in value &&
        (value as { tombstoned?: unknown }).tombstoned === true
    );
}

function isRepeatedField(key: string): boolean {
    return isGtmProfileRepeatedField(key);
}

function dimensionValue(value: unknown): string {
    if (!Array.isArray(value)) {
        return editableValue(value);
    }

    return value
        .map((item) => {
            if (typeof item !== 'object' || item === null) {
                return displayGtmValue(item);
            }

            const dimension = item as {
                label?: unknown;
                weight?: unknown;
            };
            const label =
                typeof dimension.label === 'string' ? dimension.label : '';
            const weight =
                typeof dimension.weight === 'number' ||
                typeof dimension.weight === 'string'
                    ? ` (${dimension.weight})`
                    : '';

            return `${label}${weight}`.trim();
        })
        .filter(Boolean)
        .join('\n');
}

function normalizeRepeatedItem(value: unknown): RepeatedDraftItem {
    if (typeof value === 'object' && value !== null) {
        const item = value as {
            id?: unknown;
            value?: unknown;
            tombstoned?: unknown;
        };
        const id =
            typeof item.id === 'string' && UUID_PATTERN.test(item.id)
                ? item.id
                : undefined;

        return {
            ...(id ? { id } : {}),
            value: displayGtmValue(item.value),
            tombstoned: item.tombstoned === true,
        };
    }

    return { value: displayGtmValue(value) };
}

function normalizedLine(value: string): string {
    return value.trim();
}

function repeatedPayload(key: string): Array<Record<string, unknown>> {
    const existing = repeatedDraftItems.value[key] ?? [];
    const lines = (draft.value[key] ?? '')
        .split(/\r?\n/)
        .map(normalizedLine)
        .filter(Boolean);
    const used = new Set<number>();
    const result: Array<Record<string, unknown>> = [];

    for (const line of lines) {
        const matchIndex = existing.findIndex(
            (item, index) =>
                !used.has(index) && normalizedLine(item.value) === line,
        );

        if (matchIndex === -1) {
            result.push({ value: line });
            continue;
        }

        used.add(matchIndex);
        const previous = existing[matchIndex];
        result.push({
            ...(previous.id ? { id: previous.id } : {}),
            value: line,
        });
    }

    // Keep removed and previously hidden items in the submitted merge set.
    // The server can then preserve their identity and prevent later research
    // from silently resurrecting an owner deletion.
    existing.forEach((item, index) => {
        if (used.has(index)) {
            return;
        }

        result.push({
            ...(item.id ? { id: item.id } : {}),
            value: item.value,
            tombstoned: true,
        });
    });

    return result;
}

const fields = computed(() => {
    if (!props.section) {
        return [];
    }

    const source = props.profile.payload[props.section];
    const sourceRecord =
        source && typeof source === 'object'
            ? (source as Record<string, unknown>)
            : {};
    const known = fieldKeys[props.section];
    const knownKeys = new Set(known.map(([key]) => key));
    const extra = Object.keys(sourceRecord)
        .filter((key) => !knownKeys.has(key))
        .map((key) => [key, key.replaceAll('_', ' ')] as [string, string]);

    return [...known, ...extra].map(([key, label]) => ({
        key,
        label: label.replace(/\b\w/g, (letter) => letter.toUpperCase()),
        provenance: gtmValueProvenance(sourceRecord[key]),
        value:
            draft.value[key] ??
            (key === 'dimensions'
                ? dimensionValue(sourceRecord[key])
                : editableValue(sourceRecord[key])),
        readOnly: key === 'dimensions',
    }));
});

function initializeDraft(): void {
    if (!props.section) {
        draft.value = {};
        repeatedDraftItems.value = {};
        structuredDraftValues.value = {};
        dirtyFields.value = new Set();

        return;
    }

    const source = props.profile.payload[props.section];
    const sourceRecord =
        source && typeof source === 'object'
            ? (source as Record<string, unknown>)
            : {};
    const next: Record<string, string> = {};
    const nextRepeated: Record<string, RepeatedDraftItem[]> = {};
    const nextStructured: Record<string, unknown> = {};

    for (const [key] of fieldKeys[props.section]) {
        const value = sourceRecord[key];

        if (isRepeatedField(key)) {
            const items = Array.isArray(value)
                ? value.map(normalizeRepeatedItem)
                : [];
            nextRepeated[key] = items;
            next[key] = items
                .filter(
                    (item) =>
                        item.tombstoned !== true &&
                        normalizedLine(item.value) !== '',
                )
                .map((item) => item.value)
                .join('\n');
        } else if (key === 'dimensions') {
            nextStructured[key] = value;
            next[key] = dimensionValue(value);
        } else {
            next[key] = editableValue(value);
        }
    }

    for (const [key, value] of Object.entries(sourceRecord)) {
        if (isRepeatedField(key)) {
            nextRepeated[key] ??= Array.isArray(value)
                ? value.map(normalizeRepeatedItem)
                : [];
            next[key] ??= nextRepeated[key]
                .filter(
                    (item) =>
                        item.tombstoned !== true &&
                        normalizedLine(item.value) !== '',
                )
                .map((item) => item.value)
                .join('\n');
        } else if (key === 'dimensions') {
            nextStructured[key] ??= value;
            next[key] ??= dimensionValue(value);
        } else {
            next[key] ??= editableValue(value);
        }
    }

    draft.value = next;
    repeatedDraftItems.value = nextRepeated;
    structuredDraftValues.value = nextStructured;
    dirtyFields.value = new Set();
}

function sectionPayload(): Record<string, unknown> {
    const payload: Record<string, unknown> = {};

    for (const key of dirtyFields.value) {
        const value = draft.value[key];

        if (value === undefined) {
            continue;
        }

        // Website changes have their own mutation because they also create
        // or supersede the research run. They must never take the generic
        // section PATCH path.
        if (props.section === 'company' && key === 'website_url') {
            continue;
        }

        if (isRepeatedField(key)) {
            payload[key] = repeatedPayload(key);
        } else if (key === 'dimensions') {
            payload[key] = structuredDraftValues.value[key] ?? [];
        } else {
            payload[key] = value;
        }
    }

    return payload;
}

function handleSaved(response: Record<string, unknown>): void {
    const nextLock = response.lock_version ?? response.lockVersion;

    if (typeof nextLock === 'number') {
        lockVersion.value = nextLock;
    }

    emit('saved', response);
}

const autosave = useGtmProfileAutosave({
    expectedLockVersion: () => lockVersion.value,
    onConflict: () => emit('conflict'),
    onSaved: handleSaved,
    profileKey: () => props.profile.uuid,
    section: () => props.section ?? 'company',
    resolveExpectedLockVersionOnSend: true,
});

const websiteAutosave = useGtmProfileAutosave({
    endpoint: '/settings/gtm-profile/website',
    expectedLockVersion: () => lockVersion.value,
    method: 'put',
    onConflict: () => emit('conflict'),
    onSaved: handleSaved,
    payloadKey: 'website_url',
    profileKey: () => props.profile.uuid,
    section: 'company',
    resolveExpectedLockVersionOnSend: true,
});

const saveStatus = computed(() => {
    const statuses = [autosave.status.value, websiteAutosave.status.value];

    if (statuses.includes('conflict')) {
        return 'conflict' as const;
    }

    if (statuses.includes('error')) {
        return 'error' as const;
    }

    if (statuses.includes('saving')) {
        return 'saving' as const;
    }

    if (statuses.includes('dirty')) {
        return 'dirty' as const;
    }

    if (statuses.includes('scheduled')) {
        return 'scheduled' as const;
    }

    if (statuses.includes('saved')) {
        return 'saved' as const;
    }

    return 'idle' as const;
});

const saveError = computed(
    () => websiteAutosave.error.value ?? autosave.error.value,
);
const saveConflict = computed(
    () => websiteAutosave.conflict.value ?? autosave.conflict.value,
);
const responseError = computed(
    () => websiteAutosave.responseError.value ?? autosave.responseError.value,
);
const hasPending = computed(
    () => websiteAutosave.hasPending.value || autosave.hasPending.value,
);
const needsSaveResolution = computed(
    () => saveStatus.value === 'error' || saveStatus.value === 'conflict',
);

watch(
    () => autosave.status.value,
    (status) => {
        if (status === 'saved' && !autosave.hasPending.value) {
            dirtyFields.value = new Set();
        }
    },
);

watch(
    () =>
        [
            props.open,
            props.section,
            props.profile.current_version_id,
            props.profile.lock_version,
        ] as const,
    ([open]) => {
        if (open && !hasPending.value && !needsSaveResolution.value) {
            lockVersion.value = props.profile.lock_version;
            initializeDraft();
        }
    },
    { immediate: true },
);

function updateField(key: string, value: string): void {
    draft.value = { ...draft.value, [key]: value };

    if (!props.isOwner) {
        return;
    }

    if (props.section === 'company' && key === 'website_url') {
        websiteAutosave.schedule(value.trim());

        return;
    }

    dirtyFields.value = new Set(dirtyFields.value).add(key);
    autosave.schedule(sectionPayload());
}

function onFieldInput(key: string, event: Event): void {
    updateField(key, (event.target as HTMLTextAreaElement).value);
}

function fieldError(key: string): string | null {
    if (responseError.value?.status !== 422) {
        return null;
    }

    const section = props.section ?? '';
    const candidates = [
        key,
        `${section}.${key}`,
        `payload.${key}`,
        `payload.${section}.${key}`,
    ];

    for (const [path, message] of Object.entries(responseError.value.errors)) {
        if (
            candidates.includes(path) ||
            candidates.some((candidate) => path.startsWith(`${candidate}.`))
        ) {
            return message;
        }
    }

    return null;
}

function focusFirstInvalid(): void {
    if (responseError.value?.status !== 422) {
        return;
    }

    const invalid = fields.value.find((field) => fieldError(field.key));

    if (invalid) {
        void nextTick(() => {
            document
                .querySelector<HTMLElement>(`[data-gtm-field="${invalid.key}"]`)
                ?.focus();
        });
    }
}

watch(responseError, focusFirstInvalid);

const conflictRows = computed(() => {
    const server = saveConflict.value?.currentSection;

    if (!server) {
        return [];
    }

    return fields.value
        .map((field) => ({
            key: field.key,
            label: field.label,
            local: field.value || 'Empty',
            server: displayGtmValue(server[field.key]) || 'Empty',
        }))
        .filter((row) => row.local !== row.server);
});

const statusLabel = computed(() => {
    switch (saveStatus.value) {
        case 'scheduled':
        case 'dirty':
            return 'Unsaved changes';
        case 'saving':
            return 'Saving…';
        case 'saved':
            return 'Saved just now';
        case 'error':
            return 'Could not save';
        case 'conflict':
            return 'Choose how to resolve this conflict';
        default:
            return 'Changes save automatically';
    }
});

async function requestClose(): Promise<void> {
    if (!props.isOwner || (!hasPending.value && !needsSaveResolution.value)) {
        emit('update:open', false);

        return;
    }

    const flushed = await flushAll();

    if (flushed) {
        emit('update:open', false);
    }
}

function handleOpenChange(open: boolean): void {
    if (open) {
        emit('update:open', true);

        return;
    }

    void requestClose();
}

function discardChanges(): void {
    autosave.discard();
    websiteAutosave.discard();
    conflictReviewOpen.value = false;
    initializeDraft();
}

function discardFromError(): void {
    discardChanges();
    emit('discarded');
}

async function flushAll(): Promise<boolean> {
    const sectionFlushed = await autosave.flush();
    const websiteFlushed = await websiteAutosave.flush();

    return sectionFlushed && websiteFlushed;
}

function retryFailedSave(): void {
    if (websiteAutosave.status.value === 'error') {
        void websiteAutosave.retry();

        return;
    }

    if (autosave.status.value === 'error') {
        void autosave.retry();
    }
}

function reviewConflict(): void {
    conflictReviewOpen.value = true;
    void nextTick(() => {
        document
            .querySelector<HTMLElement>('[data-test="gtm-conflict-reapply"]')
            ?.focus();
    });
}

async function reapplyDraft(): Promise<void> {
    if (reapplyingConflict.value) {
        return;
    }

    reapplyingConflict.value = true;

    try {
        const reapplied =
            websiteAutosave.status.value === 'conflict'
                ? await websiteAutosave.reapplyConflict()
                : await autosave.reapplyConflict();

        if (reapplied) {
            conflictReviewOpen.value = false;
        }
    } finally {
        reapplyingConflict.value = false;
    }
}

defineExpose({
    discard: discardChanges,
    flush: flushAll,
    get hasPending() {
        // Keep failed queues guarded as well. The parent must not switch
        // panels around an unresolved Retry/Discard decision.
        return hasPending.value || needsSaveResolution.value;
    },
});
</script>

<template>
    <Sheet :open="open" @update:open="handleOpenChange">
        <SheetContent
            side="right"
            class="gtm-editor-sheet w-full gap-0 p-0 sm:max-w-xl"
            data-test="gtm-editor-drawer"
        >
            <SheetHeader class="gtm-editor-header">
                <span class="gtm-eyebrow">Profile knowledge</span>
                <SheetTitle>{{
                    sectionMeta?.label ?? 'Profile knowledge'
                }}</SheetTitle>
                <SheetDescription>
                    {{ sectionMeta?.description }}
                </SheetDescription>
            </SheetHeader>

            <div class="gtm-editor-content">
                <div
                    v-if="!isOwner"
                    class="gtm-read-only-note"
                    data-test="gtm-read-only-note"
                >
                    <CircleHelp aria-hidden="true" />
                    <span>Only workspace owners can edit this profile.</span>
                </div>
                <div class="gtm-editor-fields">
                    <label
                        v-for="field in fields"
                        :key="field.key"
                        class="gtm-editor-field"
                    >
                        <span class="gtm-field-label">{{ field.label }}</span>
                        <textarea
                            :id="`gtm-profile-${section}-${field.key}`"
                            :value="field.value"
                            :data-gtm-field="field.key"
                            :disabled="!isOwner || field.readOnly"
                            :readonly="field.readOnly"
                            :rows="field.value.length > 100 ? 4 : 2"
                            :aria-label="field.label"
                            :aria-invalid="
                                fieldError(field.key) ? 'true' : undefined
                            "
                            :aria-describedby="
                                fieldError(field.key)
                                    ? `gtm-profile-${section}-${field.key}-error`
                                    : undefined
                            "
                            @input="onFieldInput(field.key, $event)"
                        />
                        <small
                            v-if="fieldError(field.key)"
                            :id="`gtm-profile-${section}-${field.key}-error`"
                            class="gtm-field-error"
                            role="alert"
                        >
                            {{ fieldError(field.key) }}
                        </small>
                        <small v-if="field.provenance" class="gtm-field-source">
                            {{ field.provenance.replaceAll('_', ' ') }}
                        </small>
                    </label>
                </div>
                <div
                    v-if="saveStatus === 'error' || saveConflict"
                    class="gtm-save-error"
                    role="alert"
                    data-test="gtm-save-error"
                >
                    <AlertCircle aria-hidden="true" />
                    <span>{{ saveError }}</span>
                    <div>
                        <button
                            v-if="!saveConflict"
                            type="button"
                            @click="retryFailedSave"
                        >
                            Retry
                        </button>
                        <button
                            v-if="saveConflict"
                            type="button"
                            @click="emit('reload')"
                        >
                            Reload latest
                        </button>
                        <button
                            v-if="saveConflict?.currentSection"
                            type="button"
                            @click="reviewConflict"
                        >
                            Review and reapply draft
                        </button>
                        <button type="button" @click="discardFromError">
                            Discard
                        </button>
                    </div>
                </div>
                <section
                    v-if="saveConflict && conflictReviewOpen"
                    class="gtm-conflict-review"
                    aria-labelledby="gtm-conflict-review-title"
                    data-test="gtm-conflict-review"
                >
                    <h3 id="gtm-conflict-review-title">
                        Compare your draft with the latest profile
                    </h3>
                    <p>
                        Reapplying creates a new operation against the latest
                        version. It never happens automatically.
                    </p>
                    <dl v-if="conflictRows.length">
                        <div v-for="row in conflictRows" :key="row.key">
                            <dt>{{ row.label }}</dt>
                            <dd>
                                <span>Latest: {{ row.server }}</span>
                                <span>Your draft: {{ row.local }}</span>
                            </dd>
                        </div>
                    </dl>
                    <div class="gtm-conflict-review-actions">
                        <button
                            type="button"
                            :disabled="reapplyingConflict"
                            @click="conflictReviewOpen = false"
                        >
                            Keep reviewing
                        </button>
                        <button
                            type="button"
                            :disabled="reapplyingConflict"
                            data-test="gtm-conflict-reapply"
                            @click="reapplyDraft"
                        >
                            {{
                                reapplyingConflict
                                    ? 'Reapplying…'
                                    : 'Reapply my draft'
                            }}
                        </button>
                    </div>
                </section>
            </div>

            <SheetFooter class="gtm-editor-footer">
                <span class="gtm-save-status" aria-live="polite">
                    <LoaderCircle
                        v-if="saveStatus === 'saving'"
                        class="gtm-save-spinner"
                        aria-hidden="true"
                    />
                    <Check
                        v-else-if="saveStatus === 'saved'"
                        aria-hidden="true"
                    />
                    {{ statusLabel }}
                </span>
                <button
                    type="button"
                    class="gtm-done-button"
                    :disabled="saveStatus === 'saving' && !hasPending"
                    @click="requestClose"
                >
                    Done
                </button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>

<style scoped>
.gtm-editor-sheet {
    border-color: var(--ob-border-strong);
    background: #fff;
    color: #29251f;
}

.gtm-editor-header {
    border-bottom: 1px solid var(--ob-border-strong);
    padding: 25px 26px 20px;
}

.gtm-eyebrow {
    color: #6b655c;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.11em;
    text-transform: uppercase;
}

.gtm-editor-header :deep([data-slot='sheet-title']) {
    margin-top: 5px;
    color: #29251f;
    font-size: 21px;
    letter-spacing: -0.025em;
}

.gtm-editor-header :deep([data-slot='sheet-description']) {
    margin-top: 7px;
    color: #6b655c;
    font-size: 11px;
    line-height: 1.45;
}

.gtm-editor-content {
    min-height: 0;
    flex: 1;
    overflow-y: auto;
    padding: 25px 26px;
}

.gtm-read-only-note {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 23px;
    border: 1px solid var(--ob-border-strong);
    border-radius: 8px;
    padding: 10px 11px;
    background: #fbfaf7;
    color: #6b655c;
    font-size: 10px;
}

.gtm-read-only-note :deep(svg),
.gtm-save-error :deep(svg) {
    flex: 0 0 15px;
    width: 15px;
    height: 15px;
}

.gtm-editor-fields {
    display: grid;
    gap: 21px;
}

.gtm-editor-field {
    display: grid;
    gap: 7px;
}

.gtm-field-label {
    color: #29251f;
    font-size: 10px;
    font-weight: 700;
}

.gtm-editor-field textarea {
    box-sizing: border-box;
    width: 100%;
    min-height: 42px;
    resize: vertical;
    border: 1px solid var(--ob-border-strong);
    border-radius: 7px;
    padding: 10px 11px;
    background: #fff;
    color: #29251f;
    font: inherit;
    font-size: 12px;
    line-height: 1.5;
}

.gtm-editor-field textarea:focus {
    border-color: var(--ob-accent);
    box-shadow: 0 0 0 3px rgb(75 73 69 / 10%);
    outline: 0;
}

.gtm-editor-field textarea:disabled {
    background: #fbfaf7;
    color: #6b655c;
    cursor: default;
}

.gtm-field-source {
    color: #8a847a;
    font-size: 9px;
    text-transform: capitalize;
}

.gtm-field-error {
    color: #b42318;
    font-size: 9px;
    line-height: 1.4;
}

.gtm-save-error {
    display: grid;
    grid-template-columns: 15px minmax(0, 1fr);
    gap: 8px;
    margin-top: 23px;
    border: 1px solid #f0c8c3;
    border-radius: 8px;
    padding: 11px;
    background: #fff0ee;
    color: #8e241d;
    font-size: 10px;
    line-height: 1.45;
}

.gtm-save-error > div {
    display: flex;
    grid-column: 2;
    gap: 12px;
}

.gtm-save-error button {
    border: 0;
    padding: 0;
    background: transparent;
    color: #8e241d;
    font-size: 10px;
    font-weight: 700;
    text-decoration: underline;
    cursor: pointer;
}

.gtm-conflict-review {
    display: grid;
    gap: 13px;
    margin-top: 14px;
    border: 1px solid var(--ob-accent-tint-strong);
    border-radius: 8px;
    padding: 13px;
    background: var(--ob-accent-tint-soft);
}

.gtm-conflict-review h3,
.gtm-conflict-review p,
.gtm-conflict-review dl,
.gtm-conflict-review dd {
    margin: 0;
}

.gtm-conflict-review h3 {
    color: #29251f;
    font-size: 11px;
}

.gtm-conflict-review p,
.gtm-conflict-review dd {
    color: #6b655c;
    font-size: 9px;
    line-height: 1.5;
}

.gtm-conflict-review dl {
    display: grid;
    gap: 10px;
}

.gtm-conflict-review dl > div {
    display: grid;
    gap: 4px;
    border-top: 1px solid var(--ob-accent-tint-strong);
    padding-top: 9px;
}

.gtm-conflict-review dt {
    color: #29251f;
    font-size: 9px;
    font-weight: 700;
}

.gtm-conflict-review dd {
    display: grid;
    gap: 2px;
    overflow-wrap: anywhere;
}

.gtm-conflict-review-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.gtm-conflict-review-actions button {
    min-height: 30px;
    border: 1px solid var(--ob-accent-border);
    border-radius: 7px;
    padding: 6px 9px;
    background: #fff;
    color: var(--ob-accent-strong);
    font-size: 9px;
    font-weight: 700;
    cursor: pointer;
}

.gtm-conflict-review-actions button:last-child {
    border-color: var(--ob-ink);
    background: var(--ob-ink);
    color: #fff;
}

.gtm-conflict-review-actions button:disabled {
    cursor: wait;
    opacity: 0.6;
}

.gtm-editor-footer {
    display: flex;
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    border-top: 1px solid var(--ob-border-strong);
    padding: 15px 26px;
    background: #fbfaf7;
}

.gtm-save-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #6b655c;
    font-size: 9px;
}

.gtm-save-status :deep(svg) {
    width: 13px;
    height: 13px;
    color: #18794e;
}

.gtm-save-spinner {
    animation: gtm-editor-spin 800ms linear infinite;
}

.gtm-done-button {
    min-height: 36px;
    border: 0;
    border-radius: 8px;
    padding: 7px 13px;
    background: var(--ob-ink);
    color: #fff;
    font-size: 11px;
    cursor: pointer;
}

.gtm-done-button:disabled {
    background: #dedad3;
    color: #777168;
    cursor: not-allowed;
}

@keyframes gtm-editor-spin {
    to {
        transform: rotate(360deg);
    }
}

@media (max-width: 620px) {
    .gtm-editor-header,
    .gtm-editor-content,
    .gtm-editor-footer {
        padding-right: 18px;
        padding-left: 18px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .gtm-save-spinner {
        animation: none;
    }
}
</style>
