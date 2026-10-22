<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 200 200"
        role="img"
        aria-label="OutBoss"
        :class="className"
        v-bind="$attrs"
    >
        <path
            transform="translate(25)"
            fill="currentColor"
            fill-rule="evenodd"
            d="M83 1q2 0 5 1l56 26q5 2 5 8v128q0 4-4 6l-56 28q-3 2-6 0L4 171q-4-2-4-7V36q0-5 4-7L79 2q3-1 5 0Zm-1 41q-2 0-5 1L40 56q-3 2-3 6v76q0 4 3 6l42 15q3 1 6-1l11-6q3-2 3-6V62q0-4-3-6L85 43q-2-1-3-1Z"
        />
    </svg>
</template>
