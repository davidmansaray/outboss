<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{ close: []; created: [] }>();

const name = ref('');
const kind = ref('csv_import');
const submitting = ref(false);
const error = ref('');
const dialog = ref<HTMLElement | null>(null);

const valid = computed(() => name.value.trim().length > 0 && !submitting.value);

watch(
    () => props.open,
    async (open) => {
        if (!open) {
            return;
        }

        name.value = '';
        kind.value = 'csv_import';
        error.value = '';
        await nextTick();
        dialog.value?.querySelector<HTMLInputElement>('input')?.focus();
    },
);

function submit(): void {
    if (!valid.value) {
        return;
    }

    submitting.value = true;
    router.post(
        '/prospect-sources',
        { display_name: name.value.trim(), kind: kind.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                emit('created');
                emit('close');
            },
            onError: (errors) => {
                error.value =
                    Object.values(errors)[0] ??
                    'The source could not be created.';
            },
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}
</script>

<template>
    <div v-if="open" class="source-dialog-back" @click.self="emit('close')">
        <form
            ref="dialog"
            class="source-dialog"
            role="dialog"
            aria-modal="true"
            aria-labelledby="source-dialog-title"
            @submit.prevent="submit"
        >
            <p class="source-dialog-eyebrow">Prospect source</p>
            <h2 id="source-dialog-title">Name this source</h2>
            <p class="source-dialog-copy">
                Give imported or selected prospects a durable, traceable origin.
            </p>
            <label for="source-name">Source name</label>
            <input id="source-name" v-model="name" maxlength="120" required />
            <label for="source-kind">Source kind</label>
            <select id="source-kind" v-model="kind">
                <option value="csv_import">CSV import</option>
                <option value="existing_accounts">Existing records</option>
                <option value="manual">Manual</option>
                <option value="api_import">API import</option>
            </select>
            <p v-if="error" class="source-dialog-error" role="alert">
                {{ error }}
            </p>
            <div class="source-dialog-actions">
                <button type="button" @click="emit('close')">Cancel</button>
                <button type="submit" class="primary" :disabled="!valid">
                    Create source
                </button>
            </div>
        </form>
    </div>
</template>

<style scoped>
.source-dialog-back {
    position: fixed;
    z-index: 50;
    inset: 0;
    display: grid;
    padding: 20px;
    place-items: center;
    background: rgb(32 28 23 / 28%);
}

.source-dialog {
    display: grid;
    width: min(420px, 100%);
    gap: 9px;
    border: 1px solid var(--ob-border);
    border-radius: 12px;
    padding: 22px;
    background: var(--ob-surface);
    box-shadow: 0 18px 56px rgb(35 31 25 / 18%);
}

.source-dialog-eyebrow {
    margin: 0;
    color: #6f685f;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
}

.source-dialog h2 {
    margin: 0;
    color: var(--ob-ink);
    font-size: 23px;
    letter-spacing: -0.03em;
}

.source-dialog-copy {
    margin: 0 0 8px;
    color: #6b655c;
    line-height: 1.45;
}

.source-dialog input,
.source-dialog select {
    min-height: 40px;
    border: 1px solid var(--ob-border);
    border-radius: 7px;
    padding: 0 10px;
    background: #fff;
}

.source-dialog-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 9px;
}

.source-dialog-actions button {
    min-height: 38px;
    border: 1px solid var(--ob-border);
    border-radius: 7px;
    padding: 0 13px;
    background: transparent;
}

.source-dialog-actions button.primary {
    border-color: #27231e;
    background: #27231e;
    color: #fff;
}

.source-dialog-actions button:disabled {
    cursor: not-allowed;
    opacity: 0.55;
}

.source-dialog-error {
    margin: 4px 0 0;
    color: #a04435;
    font-size: 12px;
}
</style>
