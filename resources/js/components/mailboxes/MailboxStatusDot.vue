<script setup lang="ts">
defineProps<{
    tone: 'neutral' | 'syncing' | 'complete';
}>();
</script>

<template>
    <i class="status-dot" :class="tone" aria-hidden="true" />
</template>
