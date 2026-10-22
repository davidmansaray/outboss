<script setup lang="ts">
import { computed, getCurrentInstance } from 'vue';

const instance = getCurrentInstance();
const workspaceName = computed(
    () =>
        instance?.appContext.config.globalProperties.$page?.props?.auth
            ?.workspace?.name ?? 'Workspace',
);
</script>

<template>{{ workspaceName }}</template>
