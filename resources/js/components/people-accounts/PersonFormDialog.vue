<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import type { RegistryPerson } from '@/types';

const props = defineProps<{
    open: boolean;
    person: RegistryPerson | null;
    accounts: { id: number; name: string }[];
    defaultAccountId?: number | null;
}>();

const emit = defineEmits<{
    close: [];
}>();

const emailInput = ref<HTMLElement | null>(null);
const dialog = ref<HTMLElement | null>(null);
const tagDraft = ref('');
const emailTouched = ref(false);
const form = useForm({
    email: '',
    full_name: '',
    job_title: '',
    notes: '',
    tags: [] as string[],
    account_id: null as number | null,
});

const isEdit = () => props.person !== null;

function resetForm(): void {
    form.email = props.person?.email ?? '';
    form.full_name = props.person?.name ?? '';
    form.job_title = props.person?.role ?? '';
    form.notes = props.person?.notes ?? '';
    form.tags = [...(props.person?.tags ?? [])];
    form.account_id =
        props.person?.account_id ?? props.defaultAccountId ?? null;
    tagDraft.value = '';
    emailTouched.value = false;
    form.clearErrors();
}

function addTag(raw: string): void {
    const value = raw.trim().replace(/,$/, '');

    if (!value || form.tags.includes(value) || form.tags.length >= 20) {
        tagDraft.value = '';

        return;
    }

    form.tags = [...form.tags, value.slice(0, 40)];
    tagDraft.value = '';
}

function removeTag(tag: string): void {
    form.tags = form.tags.filter((item) => item !== tag);
}

function handleTagKey(event: KeyboardEvent): void {
    if (event.key === 'Enter' || event.key === ',') {
        event.preventDefault();
        addTag(tagDraft.value);
    }

    if (
        event.key === 'Backspace' &&
        tagDraft.value === '' &&
        form.tags.length
    ) {
        removeTag(form.tags[form.tags.length - 1]);
    }
}

function submit(): void {
    const options = {
        onSuccess: () => emit('close'),
        onError: () => emailInput.value?.focus(),
    };

    if (props.person) {
        form.patch(`/people/${props.person.id}`, options);

        return;
    }

    form.post('/people', options);
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

        resetForm();
        document.addEventListener('keydown', onDocumentKeydown, true);
        await nextTick();
        emailInput.value?.focus();
    },
);

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onDocumentKeydown, true);
});
</script>

<template>
    <div v-if="open" class="person-form-back" @click.self="emit('close')">
        <form
            ref="dialog"
            class="person-form-dialog"
            role="dialog"
            aria-modal="true"
            :aria-labelledby="
                isEdit() ? 'edit-person-title' : 'new-person-title'
            "
            @submit.prevent="submit"
            @keydown="trapFocus"
        >
            <div class="person-form-head">
                <div>
                    <p>{{ isEdit() ? 'Edit person' : 'New person' }}</p>
                    <h2
                        :id="
                            isEdit() ? 'edit-person-title' : 'new-person-title'
                        "
                    >
                        {{
                            isEdit()
                                ? 'Update contact details'
                                : 'Add someone to the registry'
                        }}
                    </h2>
                </div>
                <button
                    type="button"
                    aria-label="Close person form"
                    :disabled="form.processing"
                    @click="emit('close')"
                >
                    <X aria-hidden="true" />
                </button>
            </div>

            <label for="person-email">Email</label>
            <input
                id="person-email"
                ref="emailInput"
                v-model="form.email"
                required
                maxlength="255"
                type="email"
                placeholder="maya@acme.com"
                :aria-invalid="form.errors.email ? 'true' : undefined"
                @input="emailTouched = true"
            />
            <p v-if="form.errors.email" class="person-form-error" role="alert">
                {{ form.errors.email }}
            </p>
            <p
                v-if="isEdit() && emailTouched && form.email !== person?.email"
                class="person-form-help"
            >
                Past campaign history keeps the address that was mailed.
            </p>

            <label for="person-name">Name</label>
            <input
                id="person-name"
                v-model="form.full_name"
                maxlength="255"
                placeholder="Maya Chen"
            />

            <label for="person-title">Job title</label>
            <input
                id="person-title"
                v-model="form.job_title"
                maxlength="255"
                placeholder="Founder"
            />

            <label for="person-account">Account</label>
            <select id="person-account" v-model="form.account_id">
                <option :value="null">No account</option>
                <option
                    v-for="account in accounts"
                    :key="account.id"
                    :value="account.id"
                >
                    {{ account.name }}
                </option>
            </select>
            <p
                v-if="form.errors.account_id"
                class="person-form-error"
                role="alert"
            >
                {{ form.errors.account_id }}
            </p>

            <label for="person-notes">Notes</label>
            <textarea
                id="person-notes"
                v-model="form.notes"
                maxlength="10000"
                rows="3"
            />

            <label for="person-tags">Tags</label>
            <div class="person-tag-input">
                <span v-for="tag in form.tags" :key="tag" class="person-tag">
                    {{ tag }}
                    <button
                        type="button"
                        :aria-label="`Remove ${tag}`"
                        @click="removeTag(tag)"
                    >
                        ×
                    </button>
                </span>
                <input
                    id="person-tags"
                    v-model="tagDraft"
                    maxlength="40"
                    placeholder="Add a tag"
                    @keydown="handleTagKey"
                    @blur="addTag(tagDraft)"
                />
            </div>

            <div class="person-form-actions">
                <button
                    type="button"
                    class="person-form-ghost"
                    :disabled="form.processing"
                    @click="emit('close')"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    class="person-form-primary"
                    data-test="submit-person"
                    :disabled="form.processing || !form.email.trim()"
                >
                    {{
                        form.processing
                            ? 'Saving…'
                            : isEdit()
                              ? 'Save person'
                              : 'Create person'
                    }}
                </button>
            </div>
        </form>
    </div>
</template>

<style scoped>
.person-form-back {
    position: fixed;
    inset: 0;
    z-index: 80;
    display: grid;
    place-items: center;
    padding: 16px;
    background: rgb(26 24 20 / 42%);
}

.person-form-dialog {
    width: min(520px, 100%);
    max-height: calc(100dvh - 32px);
    overflow: auto;
    border: 1px solid #d4cdc2;
    border-radius: 12px;
    padding: 20px;
    background: #fff;
    box-shadow: 0 18px 48px rgb(35 31 25 / 18%);
}

.person-form-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 18px;
}

.person-form-head p {
    margin: 0;
    color: #6f685f;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.person-form-head h2 {
    margin: 4px 0 0;
    font-size: 20px;
    letter-spacing: -0.03em;
}

.person-form-head button {
    display: grid;
    width: 34px;
    height: 34px;
    place-items: center;
    border: 1px solid #d4cdc2;
    border-radius: 8px;
    background: #fff;
    color: #625d54;
}

.person-form-head svg {
    width: 15px;
    height: 15px;
}

.person-form-dialog label {
    display: block;
    margin: 14px 0 6px;
    color: #4e4942;
    font-size: 12px;
    font-weight: 620;
}

.person-form-dialog input,
.person-form-dialog select,
.person-form-dialog textarea {
    width: 100%;
    min-height: 36px;
    border: 1px solid #d4cdc2;
    border-radius: 8px;
    padding: 8px 10px;
    background: #faf9f6;
    color: #1a1814;
}

.person-form-error {
    margin: 6px 0 0;
    color: #a43c35;
    font-size: 12px;
}

.person-form-help {
    margin: 6px 0 0;
    color: #6b655c;
    font-size: 11px;
}

.person-tag-input {
    display: flex;
    min-height: 36px;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    border: 1px solid #d4cdc2;
    border-radius: 8px;
    padding: 6px;
    background: #faf9f6;
}

.person-tag-input input {
    min-width: 120px;
    flex: 1;
    min-height: 28px;
    border: 0;
    padding: 4px;
    background: transparent;
}

.person-tag {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border-radius: 999px;
    padding: 3px 8px;
    background: var(--ob-border-soft);
    color: #4a453e;
    font-size: 11px;
}

.person-tag button {
    border: 0;
    background: transparent;
    color: #6b655c;
}

.person-form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 18px;
}

.person-form-ghost,
.person-form-primary {
    min-height: 36px;
    border-radius: 8px;
    padding: 7px 12px;
    font-weight: 580;
}

.person-form-ghost {
    border: 1px solid #d4cdc2;
    background: #fff;
    color: #302c26;
}

.person-form-primary {
    border: 1px solid var(--ob-ink);
    background: var(--ob-ink);
    color: #fff;
}

.person-form-primary:disabled,
.person-form-ghost:disabled {
    opacity: 0.6;
}

@media (max-width: 700px) {
    .person-form-head button,
    .person-form-ghost,
    .person-form-primary {
        min-height: 44px;
    }
}
</style>
