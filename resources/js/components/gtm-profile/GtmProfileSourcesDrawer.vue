<script setup lang="ts">
import { ExternalLink } from '@lucide/vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type { GtmProfileSource } from '@/types/gtmProfile';

withDefaults(
    defineProps<{
        open: boolean;
        sources?: GtmProfileSource[];
        sourceCount?: number;
        loading?: boolean;
        error?: string | null;
    }>(),
    {
        sources: () => [],
        sourceCount: 0,
        loading: false,
        error: null,
    },
);

const emit = defineEmits<{
    'update:open': [value: boolean];
    retry: [];
}>();

function sourceDate(value?: string | null): string | null {
    if (!value) {
        return null;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? null
        : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(
              date,
          );
}
</script>

<template>
    <Sheet :open="open" @update:open="emit('update:open', $event)">
        <SheetContent
            side="right"
            class="gtm-source-sheet w-full gap-0 p-0 sm:max-w-xl"
            data-test="gtm-sources-drawer"
        >
            <SheetHeader class="gtm-source-header">
                <span class="gtm-eyebrow">Evidence</span>
                <SheetTitle>Research and sources</SheetTitle>
                <SheetDescription>
                    {{ sourceCount }} sources support the current accepted
                    profile version.
                </SheetDescription>
            </SheetHeader>
            <div
                class="gtm-source-content"
                :aria-busy="loading ? 'true' : undefined"
            >
                <p
                    v-if="loading && sources.length === 0"
                    class="gtm-source-empty"
                    aria-live="polite"
                >
                    Loading current sources…
                </p>
                <div v-else-if="error" class="gtm-source-error" role="alert">
                    <span>{{ error }}</span>
                    <button type="button" @click="emit('retry')">
                        Try again
                    </button>
                </div>
                <p v-else-if="sources.length === 0" class="gtm-source-empty">
                    No source details are available yet. Outboss will keep the
                    profile usable while research is pending.
                </p>
                <div v-else class="gtm-source-list">
                    <article
                        v-for="source in sources"
                        :key="source.uuid"
                        class="gtm-source-item"
                    >
                        <div class="gtm-source-item-heading">
                            <div>
                                <strong>{{
                                    source.title || source.canonical_url
                                }}</strong>
                                <small>
                                    {{ source.source_type || 'Public source' }}
                                    <template
                                        v-if="sourceDate(source.observed_at)"
                                    >
                                        · observed
                                        {{ sourceDate(source.observed_at) }}
                                    </template>
                                </small>
                            </div>
                            <a
                                :href="source.canonical_url"
                                target="_blank"
                                rel="noreferrer"
                                :aria-label="`Open ${source.title || source.canonical_url}`"
                            >
                                <ExternalLink aria-hidden="true" />
                            </a>
                        </div>
                        <p v-if="source.excerpt">{{ source.excerpt }}</p>
                    </article>
                </div>
                <p class="gtm-source-note">
                    Sources remain inspectable on demand. Opening one does not
                    change profile fields or their ownership state.
                </p>
            </div>
        </SheetContent>
    </Sheet>
</template>

<style scoped>
.gtm-source-sheet {
    border-color: var(--ob-border-strong);
    background: #fff;
    color: #29251f;
}

.gtm-source-header {
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

.gtm-source-header :deep([data-slot='sheet-title']) {
    margin-top: 5px;
    color: #29251f;
    font-size: 21px;
    letter-spacing: -0.025em;
}

.gtm-source-header :deep([data-slot='sheet-description']) {
    margin-top: 7px;
    color: #6b655c;
    font-size: 11px;
    line-height: 1.45;
}

.gtm-source-content {
    min-height: 0;
    flex: 1;
    overflow-y: auto;
    padding: 25px 26px;
}

.gtm-source-list {
    border-top: 1px solid var(--ob-border-strong);
}

.gtm-source-item {
    display: grid;
    gap: 10px;
    border-bottom: 1px solid var(--ob-border-strong);
    padding: 16px 0;
}

.gtm-source-item-heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.gtm-source-item-heading strong,
.gtm-source-item-heading small {
    display: block;
}

.gtm-source-item-heading strong {
    color: #29251f;
    font-size: 11px;
    font-weight: 650;
}

.gtm-source-item-heading small {
    margin-top: 4px;
    color: #8a847a;
    font-size: 9px;
    text-transform: capitalize;
}

.gtm-source-item-heading a {
    display: grid;
    flex: 0 0 28px;
    width: 28px;
    height: 28px;
    place-items: center;
    border-radius: 7px;
    color: #6b655c;
}

.gtm-source-item-heading a:hover {
    background: #f0ede7;
    color: var(--ob-accent);
}

.gtm-source-item-heading :deep(svg) {
    width: 15px;
    height: 15px;
}

.gtm-source-item p,
.gtm-source-note,
.gtm-source-empty {
    margin: 0;
    color: #6b655c;
    font-size: 10px;
    line-height: 1.55;
}

.gtm-source-error {
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

.gtm-source-error button {
    border: 0;
    padding: 0;
    background: transparent;
    color: inherit;
    font-size: 10px;
    font-weight: 700;
    text-decoration: underline;
    cursor: pointer;
}

.gtm-source-note {
    margin-top: 22px;
    color: #8a847a;
}

@media (max-width: 620px) {
    .gtm-source-header,
    .gtm-source-content {
        padding-right: 18px;
        padding-left: 18px;
    }
}
</style>
