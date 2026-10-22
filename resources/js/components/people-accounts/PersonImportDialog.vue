<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { csvTable, guessPersonImportMapping } from '@/lib/parseCsv';
import type { PersonImportReview } from '@/types';

const props = defineProps<{
    open: boolean;
}>();

const emit = defineEmits<{
    close: [];
}>();

const dialog = ref<HTMLElement | null>(null);
const pasteInput = ref<HTMLTextAreaElement | null>(null);
const paste = ref('');
const filename = ref<string | null>(null);
const parseError = ref('');
const headers = ref<string[]>([]);
const rows = ref<Record<string, string>[]>([]);
const mapping = ref({
    email: '',
    name: '',
    job_title: '',
    company: '',
    tags: '',
});
const step = ref<'source' | 'mapping' | 'review'>('source');
const submitting = ref(false);
const page = usePage();
const review = computed(
    () =>
        ((page.props.flash as { personImport?: PersonImportReview } | undefined)
            ?.personImport ?? null) as PersonImportReview | null,
);
const pageErrors = computed(
    () => (page.props.errors ?? {}) as Record<string, string>,
);
const commitable = computed(() => {
    if (!review.value) {
        return 0;
    }

    return review.value.new + review.value.updated + review.value.suppressed;
});

const buckets: { key: keyof PersonImportReview['samples']; label: string }[] = [
    { key: 'new', label: 'New' },
    { key: 'updated', label: 'Updated' },
    { key: 'invalid', label: 'Invalid' },
    { key: 'duplicate', label: 'Duplicate' },
    { key: 'suppressed', label: 'Suppressed' },
];

function reset(): void {
    paste.value = '';
    filename.value = null;
    parseError.value = '';
    headers.value = [];
    rows.value = [];
    mapping.value = {
        email: '',
        name: '',
        job_title: '',
        company: '',
        tags: '',
    };
    submitting.value = false;
    step.value = review.value ? 'review' : 'source';
}

function applyTable(text: string, uploadedName: string | null): void {
    const table = csvTable(text);

    if (!table.rows.length) {
        parseError.value = 'Add at least one data row to import.';
        headers.value = [];
        rows.value = [];

        return;
    }

    parseError.value = '';
    filename.value = uploadedName;
    headers.value = table.headers;
    rows.value = table.rows;
    mapping.value = guessPersonImportMapping(table.headers);
    step.value = 'mapping';
}

function continueFromSource(): void {
    applyTable(paste.value, filename.value);
}

function onFile(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (!file) {
        return;
    }

    void file.text().then((text) => {
        paste.value = text;
        applyTable(text, file.name);
    });
}

function previewImport(): void {
    if (!mapping.value.email || submitting.value) {
        return;
    }

    submitting.value = true;
    router.post(
        '/people/imports/preview',
        {
            rows: rows.value,
            mapping: mapping.value,
            filename: filename.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                step.value = 'review';
                submitting.value = false;
                void nextTick(() => dialog.value?.focus());
            },
            onError: () => {
                submitting.value = false;
            },
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}

function commitImport(): void {
    if (!review.value || submitting.value || commitable.value === 0) {
        return;
    }

    submitting.value = true;
    router.post(
        `/people/imports/${review.value.id}/commit`,
        { expected_mapping_version: review.value.mapping_version },
        {
            onSuccess: () => {
                submitting.value = false;
                emit('close');
            },
            onError: () => {
                submitting.value = false;
            },
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}

function backToSource(): void {
    step.value = 'source';
}

function trapFocus(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        emit('close');

        return;
    }

    if (event.key !== 'Tab' || !dialog.value) {
        return;
    }

    const focusable = Array.from(
        dialog.value.querySelectorAll<HTMLElement>(
            'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
        ),
    );

    if (!focusable.length) {
        return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

function onDocumentKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && props.open) {
        event.preventDefault();
        event.stopPropagation();
        emit('close');
    }
}

watch(
    () => props.open,
    async (open) => {
        if (!open) {
            document.removeEventListener('keydown', onDocumentKeydown, true);

            return;
        }

        reset();
        document.addEventListener('keydown', onDocumentKeydown, true);
        await nextTick();

        if (step.value === 'source') {
            pasteInput.value?.focus();
        } else {
            dialog.value?.focus();
        }
    },
);

watch(review, (next) => {
    if (props.open && next) {
        step.value = 'review';
    }
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onDocumentKeydown, true);
});
</script>

<template>
    <div
        v-if="open"
        class="person-import-back"
        data-test="person-import-dialog"
        @click.self="emit('close')"
    >
        <div
            ref="dialog"
            class="person-import-dialog"
            role="dialog"
            aria-modal="true"
            aria-labelledby="person-import-title"
            tabindex="-1"
            @keydown="trapFocus"
        >
            <div class="person-import-head">
                <div>
                    <p>
                        {{
                            step === 'review'
                                ? 'Validation'
                                : step === 'mapping'
                                  ? 'Column mapping'
                                  : 'Import people'
                        }}
                    </p>
                    <h2 id="person-import-title">
                        {{
                            step === 'review'
                                ? 'Review import'
                                : step === 'mapping'
                                  ? 'Match your columns'
                                  : 'Add people from a CSV'
                        }}
                    </h2>
                </div>
                <button
                    type="button"
                    aria-label="Close person import"
                    :disabled="submitting"
                    @click="emit('close')"
                >
                    <X aria-hidden="true" />
                </button>
            </div>

            <template v-if="step === 'source'">
                <label for="person-import-paste">Paste CSV</label>
                <textarea
                    id="person-import-paste"
                    ref="pasteInput"
                    v-model="paste"
                    rows="8"
                    placeholder="email,name,company&#10;maya@acme.com,Maya Chen,Acme"
                />
                <label class="person-import-file" for="person-import-file">
                    Or upload a CSV
                    <input
                        id="person-import-file"
                        type="file"
                        accept=".csv,text/csv"
                        @change="onFile"
                    />
                </label>
                <p v-if="parseError" class="person-import-error" role="alert">
                    {{ parseError }}
                </p>
                <div class="person-import-actions">
                    <button
                        type="button"
                        class="person-import-ghost"
                        @click="emit('close')"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="person-import-primary"
                        data-test="continue-person-import"
                        :disabled="!paste.trim()"
                        @click="continueFromSource"
                    >
                        Continue
                    </button>
                </div>
            </template>

            <template v-else-if="step === 'mapping'">
                <p class="person-import-help">
                    {{ rows.length }} rows found. Email is required; the other
                    fields are optional.
                </p>
                <label
                    v-for="field in [
                        ['email', 'Email'],
                        ['name', 'Name'],
                        ['job_title', 'Job title'],
                        ['company', 'Company'],
                        ['tags', 'Tags'],
                    ] as const"
                    :key="field[0]"
                    :for="`person-import-map-${field[0]}`"
                >
                    {{ field[1] }}
                    <select
                        :id="`person-import-map-${field[0]}`"
                        v-model="mapping[field[0]]"
                    >
                        <option value="">
                            {{
                                field[0] === 'email'
                                    ? 'Choose a column'
                                    : 'Not mapped'
                            }}
                        </option>
                        <option
                            v-for="header in headers"
                            :key="header"
                            :value="header"
                        >
                            {{ header }}
                        </option>
                    </select>
                </label>
                <p
                    v-if="pageErrors.rows || pageErrors['mapping.email']"
                    class="person-import-error"
                    role="alert"
                >
                    {{ pageErrors.rows || pageErrors['mapping.email'] }}
                </p>
                <div class="person-import-actions">
                    <button
                        type="button"
                        class="person-import-ghost"
                        :disabled="submitting"
                        @click="backToSource"
                    >
                        Back
                    </button>
                    <button
                        type="button"
                        class="person-import-primary"
                        data-test="preview-person-import"
                        :disabled="submitting || !mapping.email"
                        @click="previewImport"
                    >
                        {{ submitting ? 'Checking…' : 'Review import' }}
                    </button>
                </div>
            </template>

            <template v-else>
                <section
                    class="person-import-policy"
                    aria-label="Source policy"
                >
                    <span>Source</span>
                    <strong>{{ review?.source_name }}</strong>
                    <p>{{ review?.policy_summary }}</p>
                </section>
                <div class="person-import-buckets">
                    <div
                        v-for="bucket in buckets"
                        :key="bucket.key"
                        :data-test="`person-import-bucket-${bucket.key}`"
                    >
                        <strong>{{ review?.[bucket.key] ?? 0 }}</strong>
                        <span>{{ bucket.label }}</span>
                    </div>
                </div>
                <p v-if="review?.suppressed" class="person-import-help">
                    Suppressed people stay in the registry and are flagged. They
                    are not enrolled in a campaign.
                </p>
                <section
                    v-for="bucket in buckets"
                    :key="`sample-${bucket.key}`"
                    class="person-import-sample"
                >
                    <h3>
                        {{ bucket.label }}
                        <span>{{
                            review?.samples?.[bucket.key]?.length
                                ? `showing ${review.samples[bucket.key].length}`
                                : 'none'
                        }}</span>
                    </h3>
                    <ul v-if="review?.samples?.[bucket.key]?.length">
                        <li
                            v-for="row in review.samples[bucket.key]"
                            :key="`${bucket.key}-${row.row_number}`"
                        >
                            {{ row.email || '—' }}
                            <small v-if="row.name">{{ row.name }}</small>
                        </li>
                    </ul>
                </section>
                <div class="person-import-actions">
                    <button
                        type="button"
                        class="person-import-ghost"
                        :disabled="submitting"
                        @click="backToSource"
                    >
                        Back
                    </button>
                    <button
                        type="button"
                        class="person-import-primary"
                        data-test="commit-person-import"
                        :disabled="submitting || commitable === 0"
                        @click="commitImport"
                    >
                        {{
                            submitting
                                ? 'Importing…'
                                : `Commit import${commitable ? ` (${commitable})` : ''}`
                        }}
                    </button>
                </div>
            </template>
        </div>
    </div>
</template>

<style scoped>
.person-import-back {
    position: fixed;
    inset: 0;
    z-index: 80;
    display: grid;
    place-items: center;
    padding: 16px;
    background: rgb(26 24 20 / 42%);
}

.person-import-dialog {
    width: min(560px, 100%);
    max-height: calc(100dvh - 32px);
    overflow: auto;
    border: 1px solid #d4cdc2;
    border-radius: 12px;
    padding: 20px;
    background: #fff;
    box-shadow: 0 18px 48px rgb(35 31 25 / 18%);
}

.person-import-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 18px;
}

.person-import-head p {
    margin: 0;
    color: #6f685f;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.person-import-head h2 {
    margin: 4px 0 0;
    font-size: 20px;
    letter-spacing: -0.03em;
}

.person-import-head button {
    display: grid;
    width: 34px;
    height: 34px;
    place-items: center;
    border: 1px solid #d4cdc2;
    border-radius: 8px;
    background: #fff;
    color: #625d54;
}

.person-import-head svg {
    width: 15px;
    height: 15px;
}

.person-import-dialog label {
    display: block;
    margin: 14px 0 6px;
    color: #4e4942;
    font-size: 12px;
    font-weight: 620;
}

.person-import-dialog textarea,
.person-import-dialog select {
    width: 100%;
    border: 1px solid #d4cdc2;
    border-radius: 8px;
    padding: 8px 10px;
    background: #faf9f6;
    color: #1a1814;
}

.person-import-dialog textarea {
    min-height: 160px;
    resize: vertical;
}

.person-import-file {
    display: block;
    margin-top: 12px;
    color: var(--ob-accent-strong);
    font-weight: 580;
}

.person-import-file input {
    display: block;
    margin-top: 6px;
}

.person-import-help,
.person-import-error {
    margin: 8px 0 0;
    font-size: 12px;
}

.person-import-help {
    color: #6b655c;
}

.person-import-error {
    color: #a43c35;
}

.person-import-buckets {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 8px;
}

.person-import-policy {
    margin-bottom: 14px;
    border: 1px solid var(--ob-border);
    border-radius: 8px;
    padding: 11px 12px;
    background: #faf9f6;
}

.person-import-policy span {
    display: block;
    color: #6b655c;
    font-size: 10px;
    font-weight: 650;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.person-import-policy strong {
    display: block;
    margin-top: 3px;
    font-size: 13px;
}

.person-import-policy p {
    margin: 3px 0 0;
    color: #6b655c;
    font-size: 11px;
}

.person-import-buckets div {
    border: 1px solid var(--ob-border);
    border-radius: 8px;
    padding: 10px 8px;
    text-align: center;
}

.person-import-buckets strong {
    display: block;
    font-size: 18px;
}

.person-import-buckets span {
    color: #6b655c;
    font-size: 10px;
    font-weight: 650;
    text-transform: uppercase;
}

.person-import-sample {
    margin-top: 16px;
}

.person-import-sample h3 {
    display: flex;
    justify-content: space-between;
    margin: 0 0 6px;
    font-size: 12px;
}

.person-import-sample h3 span,
.person-import-sample small {
    color: #6b655c;
    font-weight: 500;
}

.person-import-sample ul {
    margin: 0;
    padding: 0;
    list-style: none;
}

.person-import-sample li {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    padding: 5px 0;
    border-bottom: 1px solid var(--ob-border-soft);
    font-size: 12px;
}

.person-import-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 18px;
}

.person-import-ghost,
.person-import-primary {
    min-height: 36px;
    border-radius: 8px;
    padding: 7px 12px;
    font-weight: 580;
}

.person-import-ghost {
    border: 1px solid #d4cdc2;
    background: #fff;
    color: #302c26;
}

.person-import-primary {
    border: 1px solid var(--ob-ink);
    background: var(--ob-ink);
    color: #fff;
}

.person-import-primary:disabled,
.person-import-ghost:disabled {
    opacity: 0.6;
}

@media (max-width: 700px) {
    .person-import-buckets {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .person-import-head button,
    .person-import-ghost,
    .person-import-primary {
        min-height: 44px;
    }
}
</style>
