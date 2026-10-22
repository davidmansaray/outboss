<script setup lang="ts">
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    name: string;
    src: string | null;
}>();

const failed = ref(false);
const showImage = computed(() => Boolean(props.src) && !failed.value);
const initial = computed(() => {
    const first = Array.from(props.name.trim())[0];

    return first ? first.toLocaleUpperCase() : '•';
});

watch(
    () => props.src,
    () => {
        failed.value = false;
    },
);
</script>

<template>
    <span class="workspace-avatar" aria-hidden="true">
        <img
            v-if="showImage"
            :src="src ?? undefined"
            alt=""
            loading="lazy"
            decoding="async"
            @error="failed = true"
        />
        <span v-else class="workspace-avatar-fallback">{{ initial }}</span>
    </span>
</template>
