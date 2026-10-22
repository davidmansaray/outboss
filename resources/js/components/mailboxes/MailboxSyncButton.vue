<script setup lang="ts">
import { computed } from 'vue';
import type { MailboxSyncPhase } from '@/types/mailbox';

const props = defineProps<{
    phase: MailboxSyncPhase;
    label: string;
    disabled?: boolean;
}>();

const emit = defineEmits<{
    click: [];
}>();

const busy = computed(
    () => props.phase === 'queued' || props.phase === 'syncing',
);

function onClick() {
    if (props.disabled || busy.value) {
        return;
    }

    emit('click');
}
</script>

<template>
    <button
        class="sync-button"
        :class="{
            syncing: busy,
            complete: phase === 'complete',
        }"
        type="button"
        data-test="mailbox-sync-button"
        :disabled="disabled || busy"
        :aria-busy="busy ? 'true' : undefined"
        @click="onClick"
    >
        <span v-if="busy" class="spinner" aria-hidden="true" />
        <span v-else-if="phase === 'complete'" class="check" aria-hidden="true"
            >✓</span
        >
        <span aria-live="polite">{{ label }}</span>
    </button>
</template>
