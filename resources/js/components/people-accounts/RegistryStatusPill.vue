<script setup lang="ts">
import { computed } from 'vue';
import { statusTone } from '@/lib/peopleAccountsFormatters';

const props = defineProps<{ label: string }>();
const tone = computed(() => statusTone(props.label));
</script>

<template>
    <span class="registry-pill" :class="`tone-${tone}`">{{ label }}</span>
</template>

<style scoped>
.registry-pill {
    display: inline-flex;
    align-items: center;
    border: 1px solid #dedbd6;
    border-radius: 999px;
    padding: 3px 7px;
    background: #f8f7f5;
    color: #3f3d39;
    font-size: 10px;
    font-weight: 620;
    line-height: 1;
    white-space: nowrap;
}

.tone-green,
.tone-blue,
.tone-amber,
.tone-red {
    background: #f8f7f5;
    color: #3f3d39;
}
</style>
