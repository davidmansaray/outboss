<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import type { RegistryAccount } from '@/types';

const props = defineProps<{
    open: boolean;
    account: RegistryAccount | null;
}>();

const emit = defineEmits<{
    close: [];
}>();

const nameInput = ref<HTMLElement | null>(null);
const dialog = ref<HTMLElement | null>(null);
const tagDraft = ref('');
const form = useForm({
    name: '',
    website: '',
    notes: '',
    tags: [] as string[],
    confirmed_duplicate: false,
});

const isEdit = () => props.account !== null;

function resetForm(): void {
    form.name = props.account?.name ?? '';
    form.website = props.account?.website ?? '';
    form.notes = props.account?.notes ?? '';
    form.tags = [...(props.account?.tags ?? [])];
    form.confirmed_duplicate = false;
    tagDraft.value = '';
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
        onError: () => nameInput.value?.focus(),
    };

    if (props.account) {
        form.patch(`/accounts/${props.account.id}`, options);

        return;
    }

    form.post('/accounts', options);
}

function submitAnyway(): void {
    form.confirmed_duplicate = true;
    submit();
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
            'button:not([disabled]), input:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
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
        nameInput.value?.focus();
    },
);

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onDocumentKeydown, true);
});
</script>

<template>
    <div v-if="open" class="account-form-back" @click.self="emit('close')">
        <form
            ref="dialog"
            class="account-form-dialog"
            role="dialog"
            aria-modal="true"
            :aria-labelledby="
                isEdit() ? 'edit-account-title' : 'new-account-title'
            "
            @submit.prevent="submit"
            @keydown="trapFocus"
        >
            <div class="account-form-head">
                <div>
                    <p>{{ isEdit() ? 'Edit account' : 'New account' }}</p>
                    <h2
                        :id="
                            isEdit()
                                ? 'edit-account-title'
                                : 'new-account-title'
                        "
                    >
                        {{
                            isEdit()
                                ? 'Update company details'
                                : 'Add a company'
                        }}
                    </h2>
                </div>
                <button
                    type="button"
                    aria-label="Close account form"
                    :disabled="form.processing"
                    @click="emit('close')"
                >
                    <X aria-hidden="true" />
                </button>
            </div>

            <label for="account-name">Name</label>
            <input
                id="account-name"
                ref="nameInput"
                v-model="form.name"
                required
                maxlength="255"
                placeholder="Acme"
                :aria-invalid="form.errors.name ? 'true' : undefined"
            />
            <p v-if="form.errors.name" class="account-form-error" role="alert">
                {{ form.errors.name }}
            </p>

            <label for="account-website">Website</label>
            <input
                id="account-website"
                v-model="form.website"
                maxlength="255"
                placeholder="https://acme.com"
                :aria-invalid="form.errors.website ? 'true' : undefined"
            />
            <p
                v-if="form.errors.website"
                class="account-form-error"
                role="alert"
            >
                {{ form.errors.website }}
            </p>
            <p class="account-form-help">
                The domain is taken from the website and used to prevent
                duplicates.
            </p>

            <label for="account-notes">Notes</label>
            <textarea
                id="account-notes"
                v-model="form.notes"
                maxlength="10000"
                rows="3"
            />
            <p v-if="form.errors.notes" class="account-form-error" role="alert">
                {{ form.errors.notes }}
            </p>

            <label for="account-tags">Tags</label>
            <div class="account-tag-input">
                <span v-for="tag in form.tags" :key="tag" class="account-tag">
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
                    id="account-tags"
                    v-model="tagDraft"
                    maxlength="40"
                    placeholder="Add a tag"
                    @keydown="handleTagKey"
                    @blur="addTag(tagDraft)"
                />
            </div>
            <p v-if="form.errors.tags" class="account-form-error" role="alert">
                {{ form.errors.tags }}
            </p>

            <div class="account-form-actions">
                <button
                    type="button"
                    class="account-form-ghost"
                    :disabled="form.processing"
                    @click="emit('close')"
                >
                    Cancel
                </button>
                <button
                    v-if="form.errors.confirmed_duplicate"
                    type="button"
                    class="account-form-primary"
                    data-test="confirm-duplicate-account"
                    :disabled="form.processing || !form.name.trim()"
                    @click="submitAnyway"
                >
                    {{
                        form.processing
                            ? 'Saving…'
                            : isEdit()
                              ? 'Save anyway'
                              : 'Create anyway'
                    }}
                </button>
                <button
                    v-else
                    type="submit"
                    class="account-form-primary"
                    data-test="submit-account"
                    :disabled="form.processing || !form.name.trim()"
                >
                    {{
                        form.processing
                            ? 'Saving…'
                            : isEdit()
                              ? 'Save account'
                              : 'Create account'
                    }}
                </button>
            </div>
        </form>
    </div>
</template>

<style scoped>
.account-form-back {
    position: fixed;
    inset: 0;
    z-index: 80;
    display: grid;
    place-items: center;
    padding: 16px;
    background: rgb(26 24 20 / 42%);
}

.account-form-dialog {
    width: min(520px, 100%);
    max-height: calc(100dvh - 32px);
    overflow: auto;
    border: 1px solid #d4cdc2;
    border-radius: 12px;
    padding: 20px;
    background: #fff;
    box-shadow: 0 18px 48px rgb(35 31 25 / 18%);
}

.account-form-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 18px;
}

.account-form-head p {
    margin: 0;
    color: #6f685f;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.account-form-head h2 {
    margin: 4px 0 0;
    font-size: 20px;
    letter-spacing: -0.03em;
}

.account-form-head button {
    display: grid;
    width: 34px;
    height: 34px;
    place-items: center;
    border: 1px solid #d4cdc2;
    border-radius: 8px;
    background: #fff;
    color: #625d54;
}

.account-form-head svg {
    width: 15px;
    height: 15px;
}

.account-form-dialog label {
    display: block;
    margin: 14px 0 6px;
    color: #4e4942;
    font-size: 12px;
    font-weight: 620;
}

.account-form-dialog input,
.account-form-dialog textarea {
    width: 100%;
    min-height: 36px;
    border: 1px solid #d4cdc2;
    border-radius: 8px;
    padding: 8px 10px;
    background: #faf9f6;
    color: #1a1814;
}

.account-form-error {
    margin: 6px 0 0;
    color: #a43c35;
    font-size: 12px;
}

.account-form-help {
    margin: 6px 0 0;
    color: #6b655c;
    font-size: 11px;
}

.account-tag-input {
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

.account-tag-input input {
    min-width: 120px;
    flex: 1;
    min-height: 28px;
    border: 0;
    padding: 4px;
    background: transparent;
}

.account-tag {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border-radius: 999px;
    padding: 3px 8px;
    background: var(--ob-border-soft);
    color: #4a453e;
    font-size: 11px;
}

.account-tag button {
    border: 0;
    background: transparent;
    color: #6b655c;
}

.account-form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 18px;
}

.account-form-ghost,
.account-form-primary {
    min-height: 36px;
    border-radius: 8px;
    padding: 7px 12px;
    font-weight: 580;
}

.account-form-ghost {
    border: 1px solid #d4cdc2;
    background: #fff;
    color: #302c26;
}

.account-form-primary {
    border: 1px solid var(--ob-ink);
    background: var(--ob-ink);
    color: #fff;
}

.account-form-primary:disabled,
.account-form-ghost:disabled {
    opacity: 0.6;
}

@media (max-width: 700px) {
    .account-form-head button,
    .account-form-ghost,
    .account-form-primary {
        min-height: 44px;
    }
}
</style>
