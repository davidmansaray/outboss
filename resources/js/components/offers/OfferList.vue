<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight, Inbox } from '@lucide/vue';
import OfferStatusMenu from '@/components/offers/OfferStatusMenu.vue';
import type { Offer, OfferFilter, OfferStatus } from '@/types/offers';

withDefaults(
    defineProps<{
        offers: Offer[];
        filter?: OfferFilter;
        selectedId?: string | null;
        isOwner?: boolean;
        busyId?: string | null;
    }>(),
    { filter: 'all', selectedId: null, isOwner: false, busyId: null },
);

const emit = defineEmits<{
    select: [offer: Offer];
    status: [offer: Offer, status: OfferStatus];
}>();

function selectOffer(offer: Offer): void {
    emit('select', offer);
}

function offerName(offer: Offer): string {
    return offer.name || 'Untitled offer';
}
</script>

<template>
    <section
        v-if="offers.length"
        class="offer-list"
        :aria-label="filter === 'all' ? 'All offers' : `${filter} offers`"
    >
        <div class="offer-list-heading" aria-hidden="true">
            <span>Offer</span>
            <span>Promise and audience</span>
            <span>Status</span>
            <span />
        </div>
        <article
            v-for="offer in offers"
            :key="offer.uuid"
            class="offer-row"
            :class="{ selected: selectedId === offer.uuid }"
        >
            <button
                type="button"
                class="offer-row-main"
                :aria-label="`Open ${offerName(offer)}`"
                @click="selectOffer(offer)"
            >
                <span class="offer-row-title">
                    <strong>{{ offerName(offer) }}</strong>
                    <small>{{
                        offer.updated_at
                            ? `Updated ${new Date(offer.updated_at).toLocaleDateString()}`
                            : 'Not updated yet'
                    }}</small>
                </span>
            </button>
            <button
                type="button"
                class="offer-row-copy"
                @click="selectOffer(offer)"
            >
                <strong>{{
                    offer.promise || 'Promise not written yet'
                }}</strong>
                <small>{{
                    offer.target_audience || 'Audience not defined yet'
                }}</small>
            </button>
            <OfferStatusMenu
                :status="offer.status"
                :is-owner="isOwner"
                :disabled="busyId === offer.uuid"
                @change="emit('status', offer, $event)"
            />
            <Link
                :href="`/offers/${offer.uuid}/setup`"
                class="offer-row-open"
                :aria-label="`Setup and review ${offerName(offer)}`"
                title="Setup and review"
            >
                <ArrowUpRight aria-hidden="true" />
            </Link>
        </article>
    </section>
    <section v-else class="offer-empty" data-test="offer-empty-state">
        <span class="offer-empty-icon" aria-hidden="true"><Inbox /></span>
        <h2>
            {{ filter === 'all' ? 'No offers yet' : `No ${filter} offers` }}
        </h2>
        <p>
            Offers are separate from the GTM profile so propositions can evolve
            without changing durable company knowledge.
        </p>
    </section>
</template>

<style scoped>
.offer-list {
    border-top: 1px solid var(--ob-border-strong);
}

.offer-list-heading,
.offer-row {
    display: grid;
    grid-template-columns: minmax(150px, 0.8fr) minmax(220px, 1.4fr) auto 24px;
    gap: 18px;
    align-items: center;
}

.offer-list-heading {
    min-height: 31px;
    color: #8a847a;
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}

.offer-row {
    min-height: 76px;
    border-bottom: 1px solid var(--ob-border-strong);
    transition: background 140ms ease;
}

.offer-row:hover,
.offer-row.selected {
    background: #fff;
}

.offer-row-main,
.offer-row-copy,
.offer-row-open {
    border: 0;
    background: transparent;
    color: inherit;
    text-align: left;
    cursor: pointer;
}

.offer-row-main,
.offer-row-copy {
    align-self: stretch;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 5px;
    min-width: 0;
    padding: 11px 0;
}

.offer-row-title strong,
.offer-row-title small,
.offer-row-copy strong,
.offer-row-copy small {
    display: block;
}

.offer-row-title strong {
    overflow: hidden;
    color: #29251f;
    font-size: 12px;
    font-weight: 650;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.offer-row-title small,
.offer-row-copy small {
    overflow: hidden;
    color: #8a847a;
    font-size: 9px;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.offer-row-copy strong {
    overflow: hidden;
    color: #6b655c;
    font-size: 10px;
    font-weight: 500;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.offer-row-copy small {
    color: #8a847a;
}

.offer-row-open {
    display: grid;
    width: 24px;
    height: 24px;
    place-items: center;
    color: #8a847a;
}

.offer-row-open:hover,
.offer-row-main:hover strong {
    color: var(--ob-accent-strong);
}

.offer-row-open :deep(svg) {
    width: 14px;
    height: 14px;
}

.offer-empty {
    display: grid;
    justify-items: center;
    max-width: 420px;
    margin: 80px auto;
    text-align: center;
}

.offer-empty-icon {
    display: grid;
    width: 42px;
    height: 42px;
    place-items: center;
    border-radius: 12px;
    background: #f0ede7;
    color: #6b655c;
}

.offer-empty-icon :deep(svg) {
    width: 19px;
    height: 19px;
}

.offer-empty h2 {
    margin: 16px 0 6px;
    color: #29251f;
    font-size: 17px;
    font-weight: 600;
}

.offer-empty p {
    margin: 0;
    color: #6b655c;
    font-size: 11px;
    line-height: 1.55;
}

@media (max-width: 760px) {
    .offer-list-heading {
        display: none;
    }

    .offer-row {
        grid-template-columns: minmax(0, 1fr) auto 24px;
        gap: 10px;
        padding: 4px 0;
    }

    .offer-row-copy {
        grid-column: 1;
        grid-row: 2;
        padding: 0 0 10px;
    }

    .offer-row-main {
        grid-column: 1;
        grid-row: 1;
    }

    .offer-row :deep(.offer-status-trigger),
    .offer-row :deep(.offer-status-badge) {
        grid-column: 2;
        grid-row: 1;
    }

    .offer-row-open {
        grid-column: 3;
        grid-row: 1;
    }
}

@media (prefers-reduced-motion: reduce) {
    .offer-row {
        transition: none;
    }
}
</style>
