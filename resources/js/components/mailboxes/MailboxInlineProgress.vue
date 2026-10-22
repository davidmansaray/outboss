<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps<{
    visible: boolean;
    label: string;
    detail: string;
    percent: number | null;
}>();

const STEP_MS = 40;

const displayed = ref(props.percent ?? 0);
let timer: ReturnType<typeof setInterval> | undefined;

watch(
    () => props.percent,
    (target) => {
        if (timer !== undefined) {
            clearInterval(timer);
            timer = undefined;
        }

        if (target === null) {
            return;
        }

        timer = setInterval(() => {
            if (displayed.value < target) {
                displayed.value += 1;
            } else if (displayed.value > target) {
                displayed.value -= 1;
            }

            if (displayed.value === target && timer !== undefined) {
                clearInterval(timer);
                timer = undefined;
            }
        }, STEP_MS);
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    if (timer !== undefined) {
        clearInterval(timer);
    }
});
</script>

<template>
    <div class="inline-progress" v-show="visible">
        <div class="progress-label" aria-live="polite">
            <strong>{{ label }}</strong>
            <span>{{ detail }}</span>
        </div>
        <div class="progress-track">
            <div
                class="progress-bar"
                :class="{ indeterminate: percent === null }"
                :style="
                    percent === null ? undefined : { width: `${displayed}%` }
                "
            />
        </div>
    </div>
</template>
