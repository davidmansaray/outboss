<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
    inverse?: boolean;
};

withDefaults(defineProps<Props>(), {
    inverse: false,
});
</script>

<template>
    <img
        :src="
            inverse
                ? '/brand/outboss-wordmark-reverse.svg'
                : '/brand/outboss-wordmark.svg'
        "
        alt="OutBoss"
        :class="className"
        v-bind="$attrs"
    />
</template>
