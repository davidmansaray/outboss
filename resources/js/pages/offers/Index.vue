<script lang="ts">
// Closing a managed overlay restores an older Inertia history snapshot and
// remounts this page. Keep this one-shot hand-off at module scope so the new
// page instance can refresh server-backed list data after restoration.
let reloadOffersAfterOverlayClose = false;
</script>

<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { AlertCircle, Plus } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import OfferEditorDrawer from '@/components/offers/OfferEditorDrawer.vue';
import OfferHistoryDrawer from '@/components/offers/OfferHistoryDrawer.vue';
import OfferList from '@/components/offers/OfferList.vue';
import OfferSidebar from '@/components/offers/OfferSidebar.vue';
import AppWorkspace from '@/layouts/AppWorkspace.vue';
import { jsonClient, JsonHttpError } from '@/lib/jsonClient';
import { canTransitionOffer } from '@/types/offers';
import type {
    Offer,
    OfferCounts,
    OfferFilter,
    OfferPagination,
    OfferStatus,
} from '@/types/offers';

type Panel = 'edit' | 'history' | null;
type EditorGuard = {
    guardNavigation: (action: () => void) => boolean;
};
type OffersPageResponse = {
    offers: Offer[];
    pagination: OfferPagination;
    counts: OfferCounts;
    filter: OfferFilter;
};
type InertiaVisitSnapshot = {
    url: URL;
    method?: string;
};

const overlayStateKey = 'outbossOfferOverlay';

const props = withDefaults(
    defineProps<{
        offers: Offer[];
        offerPagination?: OfferPagination;
        offerCounts?: OfferCounts;
        offerFilter?: OfferFilter;
        isOwner?: boolean;
        error?: string | null;
    }>(),
    {
        offerFilter: 'all',
        isOwner: undefined,
        error: null,
    },
);

const page = usePage();
const filter = ref<OfferFilter>(props.offerFilter);
const panel = ref<Panel>(null);
const selectedId = ref<string | null>(null);
const loadedOffers = ref<Offer[]>([...props.offers]);
const pagination = ref<OfferPagination>(
    props.offerPagination ?? {
        next_cursor: null,
        has_more: false,
        per_page: 25,
    },
);
const counts = ref<OfferCounts>(props.offerCounts ?? countsFrom(props.offers));
const busyId = ref<string | null>(null);
const actionError = ref<string | null>(null);
const loadingMore = ref(false);
const editorDirty = ref(false);
const editor = ref<EditorGuard | null>(null);
let restoringOverlayAfterPop = false;
let bypassPopGuard = false;
let pendingPopTarget: { panel: Panel; offer: string | null } | null = null;
let removeNavigationGuard: (() => void) | null = null;
let removeNavigateListener: (() => void) | null = null;
let replayingNavigation = false;
let internalInertiaVisit = false;

const isOwner = computed(
    () => props.isOwner ?? page.props.auth?.workspace?.role === 'owner',
);
const selectedOffer = computed(
    () =>
        loadedOffers.value.find((offer) => offer.uuid === selectedId.value) ??
        null,
);
const visibleOffers = computed(() =>
    filter.value === 'all'
        ? loadedOffers.value
        : loadedOffers.value.filter((offer) => offer.status === filter.value),
);
const filteredTotal = computed(() => counts.value[filter.value]);

watch(
    () => props.offers,
    (offers) => {
        loadedOffers.value = [...offers];
    },
);
watch(
    () => props.offerPagination,
    (value) => {
        if (value) {
            pagination.value = value;
        }
    },
);
watch(
    () => props.offerCounts,
    (value) => {
        counts.value = value ?? countsFrom(props.offers);
    },
);
watch(
    () => props.offerFilter,
    (value) => {
        filter.value = value;
    },
);

function countsFrom(offers: Offer[]): OfferCounts {
    return {
        all: offers.length,
        draft: offers.filter((offer) => offer.status === 'draft').length,
        active: offers.filter((offer) => offer.status === 'active').length,
        paused: offers.filter((offer) => offer.status === 'paused').length,
        archived: offers.filter((offer) => offer.status === 'archived').length,
    };
}
function parsePanel(): { panel: Panel; offer: string | null } {
    if (typeof window === 'undefined') {
        return { panel: null, offer: null };
    }

    const params = new URLSearchParams(window.location.search);
    const requested = params.get('panel');

    return {
        panel:
            requested === 'edit' || requested === 'history' ? requested : null,
        offer: params.get('offer'),
    };
}

function setUrl(
    nextPanel: Panel,
    offerId: string | null = null,
    push = true,
): void {
    if (typeof window === 'undefined') {
        return;
    }

    const url = new URL(window.location.href);
    url.searchParams.delete('panel');
    url.searchParams.delete('offer');

    if (nextPanel) {
        url.searchParams.set('panel', nextPanel);

        if (offerId) {
            url.searchParams.set('offer', offerId);
        }
    }

    const currentState =
        typeof window.history.state === 'object' && window.history.state
            ? window.history.state
            : {};
    const state = { ...currentState } as Record<string, unknown>;

    if (nextPanel) {
        state[overlayStateKey] = true;
    } else {
        delete state[overlayStateKey];
    }

    if (push) {
        window.history.pushState(state, '', url);
    } else {
        window.history.replaceState(state, '', url);
    }
}

function hasManagedOverlayState(): boolean {
    return Boolean(
        typeof window !== 'undefined' &&
        window.history.state?.[overlayStateKey],
    );
}

function applyPanel(next: { panel: Panel; offer: string | null }): void {
    panel.value = next.panel;
    selectedId.value = next.offer;
}

function openEditor(offer: Offer | null = null): void {
    applyPanel({ panel: 'edit', offer: offer?.uuid ?? null });
    setUrl('edit', offer?.uuid ?? null, !hasManagedOverlayState());
}

function openHistory(offer: Offer): void {
    applyPanel({ panel: 'history', offer: offer.uuid });
    setUrl('history', offer.uuid, !hasManagedOverlayState());
}

function closePanel(): void {
    if (hasManagedOverlayState()) {
        window.history.back();

        return;
    }

    applyPanel({ panel: null, offer: null });
    setUrl(null, null, false);
    flushPendingIndexReload();
}

function handlePopState(): void {
    const next = parsePanel();

    if (restoringOverlayAfterPop) {
        restoringOverlayAfterPop = false;
        applyPanel(next);

        return;
    }

    if (bypassPopGuard) {
        bypassPopGuard = false;
        applyPanel(next);

        return;
    }

    if (panel.value === 'edit' && editorDirty.value && editor.value) {
        pendingPopTarget = next;
        const guarded = editor.value.guardNavigation(() => {
            const target = pendingPopTarget;
            pendingPopTarget = null;

            if (hasManagedOverlayState()) {
                bypassPopGuard = true;
                window.history.back();
            } else if (target) {
                bypassPopGuard = false;
                applyPanel(target);
            }
        });

        if (!guarded) {
            restoringOverlayAfterPop = true;
            window.history.forward();

            return;
        }
    }

    applyPanel(next);
}

function handleInertiaBefore(event: Event): boolean | void {
    const visit = (
        event as Event & { detail?: { visit?: InertiaVisitSnapshot } }
    ).detail?.visit;

    if (!visit || internalInertiaVisit) {
        return;
    }

    if (replayingNavigation) {
        replayingNavigation = false;

        return;
    }

    if (panel.value !== 'edit' || !editorDirty.value || !editor.value) {
        return;
    }

    event.preventDefault();

    if ((visit.method ?? 'get').toLowerCase() !== 'get') {
        actionError.value =
            'Save or discard the open Offer draft before continuing.';

        return false;
    }

    editor.value.guardNavigation(() => {
        replayingNavigation = true;
        router.visit(visit.url.toString());
        queueMicrotask(() => {
            replayingNavigation = false;
        });
    });

    return false;
}

function reloadOfferIndex(): void {
    internalInertiaVisit = true;

    try {
        router.reload({
            only: ['offers', 'offerPagination', 'offerCounts'],
        });
    } finally {
        internalInertiaVisit = false;
    }
}

function flushPendingIndexReload(): void {
    if (!reloadOffersAfterOverlayClose || parsePanel().panel !== null) {
        return;
    }

    reloadOffersAfterOverlayClose = false;
    reloadOfferIndex();
}

function changeFilter(next: OfferFilter): void {
    if (next === filter.value) {
        return;
    }

    router.get('/offers', next === 'all' ? {} : { status: next }, {
        only: ['offers', 'offerPagination', 'offerCounts', 'offerFilter'],
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

function operationId(prefix: string): string {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
        return crypto.randomUUID();
    }

    return `${prefix}-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function mutationError(caught: unknown, fallback: string): string {
    if (caught instanceof JsonHttpError) {
        return caught.errors.status ?? caught.errors.message ?? fallback;
    }

    return fallback;
}

async function changeStatus(
    offer: Offer,
    nextStatus: OfferStatus,
): Promise<void> {
    if (
        !isOwner.value ||
        busyId.value ||
        offer.status === nextStatus ||
        !canTransitionOffer(offer.status, nextStatus)
    ) {
        return;
    }

    busyId.value = offer.uuid;
    actionError.value = null;

    try {
        await jsonClient.patch(
            `/offers/${encodeURIComponent(offer.uuid)}/status`,
            {
                status: nextStatus,
                client_operation_id: operationId(`offer-status-${offer.uuid}`),
                expected_lock_version: offer.lock_version,
            },
        );
        reloadOfferIndex();
    } catch (caught) {
        actionError.value = mutationError(
            caught,
            'Offer status could not be changed.',
        );
    } finally {
        busyId.value = null;
    }
}

function upsertOffer(offer: Offer): void {
    const index = loadedOffers.value.findIndex(
        (candidate) => candidate.uuid === offer.uuid,
    );

    if (index === -1) {
        loadedOffers.value = [offer, ...loadedOffers.value];
    } else {
        loadedOffers.value[index] = offer;
        loadedOffers.value = [...loadedOffers.value];
    }
}

function handleSaved(
    response: Record<string, unknown>,
    keepOpen: boolean,
): void {
    const saved = response.offer as Offer | undefined;

    if (saved?.uuid) {
        upsertOffer(saved);
        selectedId.value = saved.uuid;

        if (keepOpen) {
            setUrl('edit', saved.uuid, false);
            reloadOfferIndex();
        } else {
            reloadOffersAfterOverlayClose = true;
        }

        return;
    }

    reloadOfferIndex();
}

async function loadMoreOffers(): Promise<void> {
    const cursor = pagination.value.next_cursor;

    if (!pagination.value.has_more || !cursor || loadingMore.value) {
        return;
    }

    loadingMore.value = true;
    actionError.value = null;

    try {
        const query = new URLSearchParams({
            cursor,
            per_page: String(pagination.value.per_page),
        });

        if (filter.value !== 'all') {
            query.set('status', filter.value);
        }

        const response = await jsonClient.get<OffersPageResponse>(
            `/offers?${query.toString()}`,
        );
        const known = new Set(loadedOffers.value.map((offer) => offer.uuid));
        loadedOffers.value = [
            ...loadedOffers.value,
            ...response.offers.filter((offer) => !known.has(offer.uuid)),
        ];
        pagination.value = response.pagination;
        counts.value = response.counts;
    } catch (caught) {
        actionError.value = mutationError(
            caught,
            'More Offers could not be loaded.',
        );
    } finally {
        loadingMore.value = false;
    }
}

async function deleteDraft(offer: Offer): Promise<void> {
    if (!isOwner.value || offer.status !== 'draft' || busyId.value) {
        return;
    }

    if (
        typeof window !== 'undefined' &&
        !window.confirm(
            `Delete the draft offer “${offer.name || 'Untitled offer'}”?`,
        )
    ) {
        return;
    }

    busyId.value = offer.uuid;
    actionError.value = null;

    try {
        await jsonClient.delete(`/offers/${encodeURIComponent(offer.uuid)}`);
        reloadOffersAfterOverlayClose = true;
        closePanel();
    } catch (caught) {
        actionError.value = mutationError(
            caught,
            'Draft offer could not be deleted.',
        );
    } finally {
        busyId.value = null;
    }
}

onMounted(() => {
    applyPanel(parsePanel());
    window.addEventListener('popstate', handlePopState);
    removeNavigationGuard = router.on('before', handleInertiaBefore);
    removeNavigateListener = router.on('navigate', flushPendingIndexReload);
});

onBeforeUnmount(() => {
    window.removeEventListener('popstate', handlePopState);
    removeNavigationGuard?.();
    removeNavigateListener?.();
});
</script>

<template>
    <Head title="Offers" />
    <AppWorkspace area="offers" title="Offers" :show-sidebar-title="false">
        <template #sidebar>
            <OfferSidebar
                :model-value="filter"
                :offers="loadedOffers"
                :counts="counts"
                @update:model-value="changeFilter"
            />
        </template>
        <template #actions>
            <button
                type="button"
                class="offers-new-button"
                :disabled="!isOwner"
                @click="openEditor()"
            >
                <Plus aria-hidden="true" />
                New offer
            </button>
        </template>
        <main class="offers-page" data-test="offers-page">
            <header class="offers-heading">
                <div>
                    <p class="offers-eyebrow">Build</p>
                    <h1>Offers</h1>
                    <p>
                        Define the promises you can deliver. Maintain Offers
                        independently and reuse them in your outreach.
                    </p>
                </div>
                <div class="offers-summary" aria-label="Offer totals">
                    <span
                        ><strong>{{ counts.all }}</strong> total</span
                    >
                    <span
                        ><strong>{{ counts.active }}</strong> active</span
                    >
                </div>
            </header>
            <div v-if="!isOwner" class="offers-member-note" role="note">
                Members can inspect offers and version history. Only owners can
                save propositions or change their lifecycle.
            </div>
            <div
                v-if="error || actionError"
                class="offers-action-error"
                role="alert"
            >
                <AlertCircle aria-hidden="true" />
                {{ actionError || error }}
            </div>
            <OfferList
                :offers="visibleOffers"
                :filter="filter"
                :selected-id="selectedId"
                :is-owner="isOwner"
                :busy-id="busyId"
                @select="openEditor"
                @status="changeStatus"
            />
            <div v-if="pagination.has_more" class="offers-load-more">
                <button
                    type="button"
                    :disabled="loadingMore"
                    @click="loadMoreOffers"
                >
                    {{ loadingMore ? 'Loading…' : 'Load more offers' }}
                </button>
                <span>
                    {{ visibleOffers.length }} of {{ filteredTotal }} loaded
                </span>
            </div>
        </main>
    </AppWorkspace>

    <OfferEditorDrawer
        ref="editor"
        :open="panel === 'edit'"
        :offer="selectedOffer"
        :is-owner="isOwner"
        @update:open="
            (open) => (open ? openEditor(selectedOffer) : closePanel())
        "
        @saved="handleSaved"
        @dirty-change="(dirty) => (editorDirty = dirty)"
        @delete="deleteDraft"
        @history="openHistory"
    />
    <OfferHistoryDrawer
        :open="panel === 'history'"
        :offer="selectedOffer"
        @update:open="
            (open) => (open ? openHistory(selectedOffer!) : closePanel())
        "
    />
</template>

<style scoped>
.offers-page {
    min-height: 0;
    flex: 1;
    overflow: auto;
    background: var(--ob-surface);
}

.offers-heading {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 24px;
    width: min(980px, calc(100% - 64px));
    margin: 0 auto;
    padding: 44px 0 30px;
    border-bottom: 1px solid var(--ob-border-strong);
}

.offers-eyebrow {
    margin: 0 0 6px;
    color: var(--ob-accent-strong);
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.11em;
    text-transform: uppercase;
}

.offers-heading h1 {
    margin: 0;
    color: #29251f;
    font-size: 28px;
    font-weight: 650;
    letter-spacing: -0.035em;
}

.offers-heading p:last-child {
    max-width: 510px;
    margin: 8px 0 0;
    color: #6b655c;
    font-size: 11px;
    line-height: 1.5;
}

.offers-summary {
    display: flex;
    gap: 14px;
    color: #6b655c;
    font-size: 10px;
    white-space: nowrap;
}

.offers-summary strong {
    color: #29251f;
    font-size: 15px;
    font-weight: 650;
}

.offers-page > .offer-list,
.offers-page > .offer-empty {
    width: min(980px, calc(100% - 64px));
    margin-right: auto;
    margin-left: auto;
}

.offers-page > .offer-empty {
    width: min(980px, calc(100% - 64px));
}

.offers-load-more {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    width: min(980px, calc(100% - 64px));
    margin: 4px auto 32px;
    color: #8a847a;
    font-size: 9px;
}

.offers-load-more button {
    min-height: 34px;
    border: 1px solid var(--ob-accent-border);
    border-radius: 7px;
    padding: 7px 12px;
    background: #fff;
    color: var(--ob-accent-strong);
    font-size: 10px;
    font-weight: 650;
    cursor: pointer;
}

.offers-load-more button:disabled {
    cursor: wait;
    opacity: 0.6;
}

.offers-member-note,
.offers-action-error {
    width: min(980px, calc(100% - 64px));
    margin: 18px auto 0;
    border: 1px solid var(--ob-border-strong);
    border-radius: 8px;
    padding: 10px 11px;
    background: #fff;
    color: #6b655c;
    font-size: 10px;
    line-height: 1.45;
}

.offers-action-error {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    border-color: #f0c8c3;
    background: #fff0ee;
    color: #8e241d;
}

.offers-action-error :deep(svg) {
    flex: 0 0 15px;
    width: 15px;
    height: 15px;
}

.offers-new-button {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    min-height: 31px;
    border: 1px solid var(--ob-ink);
    border-radius: 7px;
    padding: 6px 10px;
    background: var(--ob-ink);
    color: #fff;
    font-size: 10px;
    cursor: pointer;
}

.offers-new-button :deep(svg) {
    width: 14px;
    height: 14px;
}

.offers-new-button:disabled {
    cursor: not-allowed;
    opacity: 0.55;
}

@media (max-width: 760px) {
    .offers-heading,
    .offers-page > .offer-list,
    .offers-page > .offer-empty,
    .offers-load-more,
    .offers-member-note,
    .offers-action-error {
        width: calc(100% - 36px);
    }

    .offers-heading {
        align-items: flex-start;
        flex-direction: column;
        padding-top: 28px;
    }

    .offers-summary {
        width: 100%;
    }
}
</style>
