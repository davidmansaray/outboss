<script setup lang="ts">
import { computed } from 'vue';
import { OFFER_STATUSES } from '@/types/offers';
import type { Offer, OfferCounts, OfferFilter } from '@/types/offers';

const props = defineProps<{
    offers: Offer[];
    counts?: OfferCounts;
    modelValue: OfferFilter;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: OfferFilter];
}>();

function countFor(filter: OfferFilter): number {
    if (props.counts) {
        return props.counts[filter];
    }

    return filter === 'all'
        ? props.offers.length
        : props.offers.filter((offer) => offer.status === filter).length;
}

const activeDescription = computed(
    () =>
        OFFER_STATUSES.find((option) => option.id === props.modelValue)
            ?.description,
);
</script>

<template>
    <nav class="offer-side-nav" aria-label="Offer views">
        <p class="offer-side-eyebrow">Offers</p>
        <p class="offer-side-description">{{ activeDescription }}</p>
        <button
            v-for="option in OFFER_STATUSES"
            :key="option.id"
            type="button"
            :class="{ active: modelValue === option.id }"
            :aria-current="modelValue === option.id ? 'page' : undefined"
            @click="emit('update:modelValue', option.id)"
        >
            <span class="offer-filter-dot" :class="`is-${option.id}`" />
            <span>{{ option.label }}</span>
            <small>{{ countFor(option.id) }}</small>
        </button>
    </nav>
</template>

<style scoped>
.offer-side-nav {
    display: grid;
    align-content: start;
    gap: 3px;
    padding: 18px 12px;
}

.offer-side-eyebrow {
    margin: 0;
    color: #29251f;
    font-size: 11px;
    font-weight: 700;
}

.offer-side-description {
    min-height: 27px;
    margin: 3px 0 12px;
    color: #8a847a;
    font-size: 9px;
    line-height: 1.4;
}

.offer-side-nav button {
    display: grid;
    grid-template-columns: 7px minmax(0, 1fr) auto;
    align-items: center;
    gap: 8px;
    min-height: 32px;
    border: 0;
    border-radius: 7px;
    padding: 7px 8px;
    background: transparent;
    color: #6b655c;
    text-align: left;
    font-size: 10px;
    cursor: pointer;
}

.offer-side-nav button:hover,
.offer-side-nav button.active {
    background: var(--ob-accent-tint-strong);
    color: var(--ob-accent-strong);
}

.offer-side-nav button small {
    color: #8a847a;
    font-size: 9px;
}

.offer-side-nav button.active small {
    color: var(--ob-accent-muted);
}

.offer-filter-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #aaa39a;
}

.offer-filter-dot.is-active {
    background: #18794e;
}

.offer-filter-dot.is-paused {
    background: #c18516;
}

.offer-filter-dot.is-archived {
    background: #8a847a;
}

.offer-filter-dot.is-all {
    background: var(--ob-accent-strong);
}

@media (max-width: 900px) {
    .offer-side-nav {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 4px;
        padding: 0 4px 8px;
    }

    .offer-side-eyebrow,
    .offer-side-description {
        grid-column: 1 / -1;
    }

    .offer-side-description {
        min-height: 0;
        margin: 0 0 5px;
    }

    .offer-side-nav button {
        min-height: 44px;
        grid-template-columns: 7px minmax(0, 1fr) auto;
        justify-content: center;
        padding: 6px 5px;
        font-size: 10px;
    }
}
</style>
