<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    value: string | null | undefined;
    label?: string;
}>();

const text = computed(
    () =>
        props.label ??
        (props.value || 'unknown')
            .replaceAll('_', ' ')
            .replace(/\b\w/g, (letter) => letter.toUpperCase()),
);
</script>

<template>
    <span class="intelligence-status-pill" :data-state="value || 'unknown'">
        <i aria-hidden="true" />
        <span>{{ text }}</span>
    </span>
</template>

<style scoped>
.intelligence-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-height: 24px;
    max-width: 100%;
    padding: 3px 8px;
    border: 1px solid var(--ob-border-strong);
    border-radius: 999px;
    background: var(--ob-accent-tint-soft);
    color: var(--ob-text);
    font-size: 10px;
    font-weight: 650;
    line-height: 1.2;
    white-space: normal;
}

.intelligence-status-pill i {
    width: 6px;
    height: 6px;
    flex: 0 0 auto;
    border-radius: 50%;
    background: #7f786e;
}

.intelligence-status-pill[data-state='completed'],
.intelligence-status-pill[data-state='owner_confirmed'],
.intelligence-status-pill[data-state='approved'],
.intelligence-status-pill[data-state='accepted'],
.intelligence-status-pill[data-state='supported'] {
    border-color: #a8c7ad;
    background: #f1f8f2;
    color: #2c6136;
}

.intelligence-status-pill[data-state='completed'] i,
.intelligence-status-pill[data-state='owner_confirmed'] i,
.intelligence-status-pill[data-state='approved'] i,
.intelligence-status-pill[data-state='accepted'] i,
.intelligence-status-pill[data-state='supported'] i {
    background: #4b8b59;
}

.intelligence-status-pill[data-state='running'],
.intelligence-status-pill[data-state='planned'],
.intelligence-status-pill[data-state='awaiting_provider'],
.intelligence-status-pill[data-state='awaiting_budget'],
.intelligence-status-pill[data-state='ready_for_review'],
.intelligence-status-pill[data-state='inferred'] {
    border-color: #d8c292;
    background: #fff9eb;
    color: #6d5118;
}

.intelligence-status-pill[data-state='running'] i,
.intelligence-status-pill[data-state='planned'] i,
.intelligence-status-pill[data-state='awaiting_provider'] i,
.intelligence-status-pill[data-state='awaiting_budget'] i,
.intelligence-status-pill[data-state='ready_for_review'] i,
.intelligence-status-pill[data-state='inferred'] i {
    background: #b47a18;
}

.intelligence-status-pill[data-state='failed'],
.intelligence-status-pill[data-state='blocked'],
.intelligence-status-pill[data-state='conflicting'],
.intelligence-status-pill[data-state='stale'],
.intelligence-status-pill[data-state='rejected'],
.intelligence-status-pill[data-state='withhold'] {
    border-color: #dbb3ab;
    background: #fff4f1;
    color: #843b2d;
}

.intelligence-status-pill[data-state='failed'] i,
.intelligence-status-pill[data-state='blocked'] i,
.intelligence-status-pill[data-state='conflicting'] i,
.intelligence-status-pill[data-state='stale'] i,
.intelligence-status-pill[data-state='rejected'] i,
.intelligence-status-pill[data-state='withhold'] i {
    background: #bb5544;
}
</style>
