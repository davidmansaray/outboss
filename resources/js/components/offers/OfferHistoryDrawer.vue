<script setup lang="ts">
import { AlertCircle, History, LoaderCircle } from '@lucide/vue';
import { onBeforeUnmount, ref, watch } from 'vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { jsonClient, JsonHttpError } from '@/lib/jsonClient';
import type { Offer, OfferVersion } from '@/types/offers';

type HistoryResponse = {
    history: OfferVersion[];
    pagination: {
        next_cursor: string | null;
        has_more: boolean;
        per_page: number;
    };
};

const props = withDefaults(
    defineProps<{
        open: boolean;
        offer?: Offer | null;
    }>(),
    { offer: null },
);

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const versions = ref<OfferVersion[]>([]);
const nextCursor = ref<string | null>(null);
const hasMore = ref(false);
const loading = ref(false);
const loadingMore = ref(false);
const error = ref<string | null>(null);
let requestSequence = 0;
let controller: AbortController | null = null;

function reset(): void {
    versions.value = [];
    nextCursor.value = null;
    hasMore.value = false;
    error.value = null;
}

function errorMessage(caught: unknown): string {
    if (caught instanceof JsonHttpError) {
        return caught.errors.message ?? 'Offer history could not be loaded.';
    }

    return caught instanceof DOMException && caught.name === 'AbortError'
        ? ''
        : 'Offer history could not be loaded. Check your connection and try again.';
}

async function loadHistory(append = false): Promise<void> {
    const offer = props.offer;

    if (!props.open || !offer || (append && !nextCursor.value)) {
        return;
    }

    const sequence = ++requestSequence;
    controller?.abort();
    controller = new AbortController();
    error.value = null;
    loading.value = !append;
    loadingMore.value = append;

    const query = new URLSearchParams({ per_page: '20' });

    if (append && nextCursor.value) {
        query.set('cursor', nextCursor.value);
    }

    try {
        const response = await jsonClient.get<HistoryResponse>(
            `/offers/${encodeURIComponent(offer.uuid)}/history?${query.toString()}`,
            { signal: controller.signal },
        );

        if (sequence !== requestSequence || offer.uuid !== props.offer?.uuid) {
            return;
        }

        versions.value = append
            ? [...versions.value, ...response.history]
            : response.history;
        nextCursor.value = response.pagination.next_cursor;
        hasMore.value = response.pagination.has_more;
    } catch (caught) {
        if (sequence === requestSequence) {
            error.value = errorMessage(caught) || null;
        }
    } finally {
        if (sequence === requestSequence) {
            loading.value = false;
            loadingMore.value = false;
        }
    }
}

watch(
    () => [props.open, props.offer?.uuid] as const,
    ([open, offerId]) => {
        requestSequence++;
        controller?.abort();
        reset();

        if (open && offerId) {
            void loadHistory();
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => controller?.abort());

function versionDate(value?: string): string {
    if (!value) {
        return 'Date unavailable';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? 'Date unavailable'
        : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(
              date,
          );
}
</script>

<template>
    <Sheet :open="open" @update:open="emit('update:open', $event)">
        <SheetContent
            side="right"
            class="offer-history-sheet w-full gap-0 p-0 sm:max-w-lg"
            data-test="offer-history-drawer"
        >
            <SheetHeader class="offer-history-header">
                <span class="offer-history-eyebrow">Immutable versions</span>
                <SheetTitle>{{ offer?.name || 'Offer history' }}</SheetTitle>
                <SheetDescription>
                    Status and content changes stay inspectable without adding
                    performance claims that Outboss cannot yet measure.
                </SheetDescription>
            </SheetHeader>
            <div class="offer-history-content">
                <p
                    v-if="loading"
                    class="offer-history-loading"
                    aria-live="polite"
                >
                    <LoaderCircle aria-hidden="true" />
                    Loading version history…
                </p>
                <div v-else-if="error" class="offer-history-error" role="alert">
                    <AlertCircle aria-hidden="true" />
                    <span>{{ error }}</span>
                    <button type="button" @click="loadHistory()">Retry</button>
                </div>
                <p
                    v-else-if="versions.length === 0"
                    class="offer-history-empty"
                >
                    No saved versions are available for this offer yet.
                </p>
                <div v-else>
                    <p class="offer-history-count">
                        Showing {{ versions.length }} of
                        {{ offer?.version_count ?? versions.length }} versions
                    </p>
                    <div class="offer-history-list">
                        <article
                            v-for="version in versions"
                            :key="version.uuid"
                            class="offer-history-item"
                        >
                            <span class="offer-history-icon" aria-hidden="true">
                                <History />
                            </span>
                            <div>
                                <strong>Version {{ version.version }}</strong>
                                <small>
                                    {{ version.origin || 'Saved update' }} ·
                                    {{ versionDate(version.created_at) }}
                                </small>
                            </div>
                            <span class="offer-history-status">
                                {{ version.status_snapshot }}
                            </span>
                        </article>
                    </div>
                    <button
                        v-if="hasMore"
                        type="button"
                        class="offer-history-more"
                        :disabled="loadingMore"
                        @click="loadHistory(true)"
                    >
                        <LoaderCircle v-if="loadingMore" aria-hidden="true" />
                        {{ loadingMore ? 'Loading…' : 'Load older versions' }}
                    </button>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>

<style scoped>
.offer-history-sheet {
    border-color: var(--ob-border-strong);
    background: #fff;
    color: #29251f;
}

.offer-history-header {
    border-bottom: 1px solid var(--ob-border-strong);
    padding: 25px 26px 20px;
}

.offer-history-eyebrow {
    color: #6b655c;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.11em;
    text-transform: uppercase;
}

.offer-history-header :deep([data-slot='sheet-title']) {
    margin-top: 5px;
    overflow: hidden;
    color: #29251f;
    font-size: 19px;
    letter-spacing: -0.025em;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.offer-history-header :deep([data-slot='sheet-description']) {
    margin-top: 7px;
    color: #6b655c;
    font-size: 11px;
    line-height: 1.45;
}

.offer-history-content {
    min-height: 0;
    flex: 1;
    overflow-y: auto;
    padding: 25px 26px;
}

.offer-history-list {
    border-top: 1px solid var(--ob-border-strong);
}

.offer-history-count {
    margin: 0 0 10px;
    color: #8a847a;
    font-size: 9px;
}

.offer-history-item {
    display: grid;
    grid-template-columns: 28px minmax(0, 1fr) auto;
    align-items: center;
    gap: 11px;
    border-bottom: 1px solid var(--ob-border-strong);
    padding: 14px 0;
}

.offer-history-icon {
    display: grid;
    width: 27px;
    height: 27px;
    place-items: center;
    border-radius: 8px;
    background: #f0ede7;
    color: #6b655c;
}

.offer-history-icon :deep(svg) {
    width: 15px;
    height: 15px;
}

.offer-history-item strong,
.offer-history-item small {
    display: block;
}

.offer-history-item strong {
    color: #29251f;
    font-size: 11px;
    font-weight: 650;
}

.offer-history-item small {
    margin-top: 4px;
    color: #6b655c;
    font-size: 9px;
    text-transform: capitalize;
}

.offer-history-status {
    color: #8a847a;
    font-size: 9px;
    text-transform: capitalize;
}

.offer-history-empty,
.offer-history-loading {
    margin: 0;
    color: #6b655c;
    font-size: 11px;
    line-height: 1.5;
}

.offer-history-loading,
.offer-history-error {
    display: flex;
    align-items: center;
    gap: 8px;
}

.offer-history-loading :deep(svg),
.offer-history-more :deep(svg) {
    width: 14px;
    height: 14px;
    animation: offer-history-spin 800ms linear infinite;
}

.offer-history-error {
    flex-wrap: wrap;
    border: 1px solid #f0c8c3;
    border-radius: 8px;
    padding: 10px;
    background: #fff0ee;
    color: #8e241d;
    font-size: 10px;
}

.offer-history-error :deep(svg) {
    width: 15px;
    height: 15px;
}

.offer-history-error button,
.offer-history-more {
    border: 1px solid var(--ob-border-strong);
    border-radius: 7px;
    padding: 7px 10px;
    background: #fff;
    color: var(--ob-accent-strong);
    font-size: 10px;
    cursor: pointer;
}

.offer-history-error button {
    margin-left: auto;
}

.offer-history-more {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    width: 100%;
    margin-top: 16px;
}

.offer-history-more:disabled {
    cursor: wait;
    opacity: 0.65;
}

@keyframes offer-history-spin {
    to {
        transform: rotate(360deg);
    }
}

@media (max-width: 620px) {
    .offer-history-header,
    .offer-history-content {
        padding-right: 18px;
        padding-left: 18px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .offer-history-loading :deep(svg),
    .offer-history-more :deep(svg) {
        animation: none;
    }
}
</style>
