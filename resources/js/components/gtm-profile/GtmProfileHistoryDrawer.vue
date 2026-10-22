<script setup lang="ts">
import { History } from '@lucide/vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type { GtmProfileHistoryEntry } from '@/types/gtmProfile';

withDefaults(
    defineProps<{
        open: boolean;
        history?: GtmProfileHistoryEntry[];
        loading?: boolean;
        error?: string | null;
        hasMore?: boolean;
    }>(),
    {
        history: () => [],
        loading: false,
        error: null,
        hasMore: false,
    },
);

const emit = defineEmits<{
    'update:open': [value: boolean];
    retry: [];
    'load-more': [];
}>();

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
            class="gtm-history-sheet w-full gap-0 p-0 sm:max-w-lg"
            data-test="gtm-history-drawer"
        >
            <SheetHeader class="gtm-history-header">
                <span class="gtm-eyebrow">Immutable versions</span>
                <SheetTitle>Profile history</SheetTitle>
                <SheetDescription>
                    Every accepted owner or research update creates a version.
                </SheetDescription>
            </SheetHeader>
            <div
                class="gtm-history-content"
                :aria-busy="loading ? 'true' : undefined"
            >
                <p
                    v-if="loading && history.length === 0"
                    class="gtm-history-empty"
                    aria-live="polite"
                >
                    Loading profile history…
                </p>
                <div v-else-if="error" class="gtm-history-error" role="alert">
                    <span>{{ error }}</span>
                    <button type="button" @click="emit('retry')">
                        Try again
                    </button>
                </div>
                <p v-else-if="history.length === 0" class="gtm-history-empty">
                    No profile versions are available yet.
                </p>
                <div v-else class="gtm-history-list">
                    <article
                        v-for="entry in history"
                        :key="entry.uuid"
                        class="gtm-history-item"
                        :class="{ current: entry.is_current }"
                    >
                        <span class="gtm-history-icon" aria-hidden="true">
                            <History />
                        </span>
                        <div>
                            <strong>Version {{ entry.version }}</strong>
                            <small>
                                {{ entry.origin || 'Accepted update' }} ·
                                {{ versionDate(entry.created_at) }}
                            </small>
                        </div>
                        <span v-if="entry.is_current" class="gtm-current-label">
                            Current
                        </span>
                    </article>
                </div>
                <button
                    v-if="hasMore"
                    type="button"
                    class="gtm-history-more"
                    :disabled="loading"
                    @click="emit('load-more')"
                >
                    {{ loading ? 'Loading…' : 'Load earlier versions' }}
                </button>
            </div>
        </SheetContent>
    </Sheet>
</template>

<style scoped>
.gtm-history-sheet {
    border-color: var(--ob-border-strong);
    background: #fff;
    color: #29251f;
}

.gtm-history-header {
    border-bottom: 1px solid var(--ob-border-strong);
    padding: 25px 26px 20px;
}

.gtm-eyebrow {
    color: #6b655c;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.11em;
    text-transform: uppercase;
}

.gtm-history-header :deep([data-slot='sheet-title']) {
    margin-top: 5px;
    color: #29251f;
    font-size: 21px;
    letter-spacing: -0.025em;
}

.gtm-history-header :deep([data-slot='sheet-description']) {
    margin-top: 7px;
    color: #6b655c;
    font-size: 11px;
    line-height: 1.45;
}

.gtm-history-content {
    min-height: 0;
    flex: 1;
    overflow-y: auto;
    padding: 25px 26px;
}

.gtm-history-list {
    border-top: 1px solid var(--ob-border-strong);
}

.gtm-history-item {
    display: grid;
    grid-template-columns: 28px minmax(0, 1fr) auto;
    align-items: center;
    gap: 11px;
    border-bottom: 1px solid var(--ob-border-strong);
    padding: 14px 0;
}

.gtm-history-item.current {
    background: #fbfaf7;
}

.gtm-history-icon {
    display: grid;
    width: 27px;
    height: 27px;
    place-items: center;
    border-radius: 8px;
    background: #f0ede7;
    color: #6b655c;
}

.gtm-history-icon :deep(svg) {
    width: 15px;
    height: 15px;
}

.gtm-history-item strong,
.gtm-history-item small {
    display: block;
}

.gtm-history-item strong {
    color: #29251f;
    font-size: 11px;
    font-weight: 650;
}

.gtm-history-item small {
    margin-top: 4px;
    color: #6b655c;
    font-size: 9px;
    text-transform: capitalize;
}

.gtm-current-label {
    color: #18794e;
    font-size: 9px;
    font-weight: 700;
}

.gtm-history-empty {
    margin: 0;
    color: #6b655c;
    font-size: 11px;
    line-height: 1.5;
}

.gtm-history-error {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    border: 1px solid #f0c8c3;
    border-radius: 8px;
    padding: 11px;
    background: #fff0ee;
    color: #8e241d;
    font-size: 10px;
}

.gtm-history-error button,
.gtm-history-more {
    border: 0;
    background: transparent;
    color: inherit;
    font-size: 10px;
    font-weight: 700;
    cursor: pointer;
}

.gtm-history-error button {
    padding: 0;
    text-decoration: underline;
}

.gtm-history-more {
    width: 100%;
    margin-top: 15px;
    border: 1px solid var(--ob-border-strong);
    border-radius: 7px;
    padding: 9px 12px;
    color: var(--ob-accent-strong);
}

.gtm-history-more:disabled {
    cursor: wait;
    opacity: 0.6;
}

@media (max-width: 620px) {
    .gtm-history-header,
    .gtm-history-content {
        padding-right: 18px;
        padding-left: 18px;
    }
}
</style>
