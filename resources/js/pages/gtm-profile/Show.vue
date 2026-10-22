<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Download, Ellipsis, History, RefreshCw, Search } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    shallowRef,
} from 'vue';
import GtmProfileEditorDrawer from '@/components/gtm-profile/GtmProfileEditorDrawer.vue';
import GtmProfileHistoryDrawer from '@/components/gtm-profile/GtmProfileHistoryDrawer.vue';
import GtmProfileOverview from '@/components/gtm-profile/GtmProfileOverview.vue';
import GtmProfileReviewDialog from '@/components/gtm-profile/GtmProfileReviewDialog.vue';
import GtmProfileSourcesDrawer from '@/components/gtm-profile/GtmProfileSourcesDrawer.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import WorkspaceOpsSidebar from '@/components/WorkspaceOpsSidebar.vue';
import { useGtmProfileResearchPolling } from '@/composables/useGtmProfileResearchPolling';
import AppWorkspace from '@/layouts/AppWorkspace.vue';
import { jsonClient, JsonHttpError } from '@/lib/jsonClient';
import type {
    GtmProfile,
    GtmProfileHistoryEntry,
    GtmProfileReviewItem,
    GtmProfileSection,
    GtmProfileSource,
    GtmResearchState,
} from '@/types/gtmProfile';

type Panel = 'edit' | 'sources' | 'history' | 'review' | null;

type SourcesResponse = {
    sources: GtmProfileSource[];
    source_count: number;
    current_version_id: string;
};

type HistoryResponse = {
    history: GtmProfileHistoryEntry[];
    current_version_id: string;
    next_cursor?: string | null;
    has_more?: boolean;
};

type InertiaVisitSnapshot = {
    url: URL;
    method?: string;
    only?: string[];
};

type PendingResearchRequest = {
    action: 'retry' | 'refresh';
    body: {
        action: 'retry' | 'refresh';
        client_operation_id: string;
    };
};

const OVERLAY_HISTORY_STATE_KEY = '__outbossGtmProfileOverlay';

const props = defineProps<{
    profile: GtmProfile;
    researchState?: GtmResearchState;
    reviewItems?: GtmProfileReviewItem[];
    sources?: GtmProfileSource[];
    history?: GtmProfileHistoryEntry[];
    activeOffersCount?: number;
    isOwner?: boolean;
}>();

const page = usePage();
const panel = ref<Panel>(null);
const editorSection = ref<GtmProfileSection | null>(null);
const busyAction = ref<'retry' | 'refresh' | null>(null);
const actionError = ref<string | null>(null);
const editorRef = ref<InstanceType<typeof GtmProfileEditorDrawer> | null>(null);
const guardedBack = ref(false);
const panelTransitionBusy = ref(false);
const localSources = ref<GtmProfileSource[]>(props.sources ?? []);
const localHistory = ref<GtmProfileHistoryEntry[]>(props.history ?? []);
const sourcesLoaded = ref(props.sources !== undefined);
const historyLoaded = ref(props.history !== undefined);
const sourcesLoading = ref(false);
const historyLoading = ref(false);
const sourcesError = ref<string | null>(null);
const historyError = ref<string | null>(null);
const historyCursor = ref<string | null>(null);
const historyHasMore = ref(false);
const profileRefreshNeeded = ref(false);
const terminalRefreshBlocked = ref(false);
const terminalRefreshRetryable = ref(false);
const terminalRefreshMessage = ref<string | null>(null);
const pendingResearchRequest = shallowRef<PendingResearchRequest | null>(null);
let removeNavigationGuard: (() => void) | null = null;
let replayingNavigation = false;
let localOverlayEntry: string | null = null;
let overlayEntrySequence = 0;

const resolvedResearchState = computed<GtmResearchState>(
    () =>
        props.researchState ??
        props.profile.research_state ?? {
            state: 'not_started',
            source_count: 0,
            website_url: null,
        },
);
const reviewItems = computed(
    () => props.reviewItems ?? props.profile.review_items ?? [],
);
const isOwner = computed(
    () => props.isOwner ?? page.props.auth?.workspace?.role === 'owner',
);
const profileStatusLabel = computed(() => {
    if (
        resolvedResearchState.value.state === 'queued' ||
        resolvedResearchState.value.state === 'running'
    ) {
        return 'Researching';
    }

    if (resolvedResearchState.value.state === 'failed') {
        return 'Needs attention';
    }

    if (resolvedResearchState.value.state === 'not_started') {
        return 'Not started';
    }

    return resolvedResearchState.value.stale ? 'Needs refresh' : 'Saved';
});

const researchPolling = useGtmProfileResearchPolling(resolvedResearchState, {
    onTerminal: handleResearchTerminal,
});

function requestError(caught: unknown, fallback: string): string {
    if (caught instanceof JsonHttpError) {
        return caught.errors.message ?? fallback;
    }

    return fallback;
}

function reloadProfileSnapshot(): Promise<void> {
    return new Promise((resolve, reject) => {
        let settled = false;
        const succeed = (): void => {
            if (!settled) {
                settled = true;
                resolve();
            }
        };
        const fail = (message: string): void => {
            if (!settled) {
                settled = true;
                reject(new Error(message));
            }
        };

        router.reload({
            only: [
                'profile',
                'researchState',
                'reviewItems',
                'activeOffersCount',
            ],
            preserveUrl: true,
            onSuccess: succeed,
            onError: () => fail('The profile refresh failed.'),
            onCancel: () => fail('The profile refresh was cancelled.'),
        });
    });
}

async function refreshNeededProfileSnapshot(): Promise<boolean> {
    try {
        await reloadProfileSnapshot();
        profileRefreshNeeded.value = false;
        actionError.value = null;

        return true;
    } catch {
        actionError.value =
            'The profile was saved, but the latest profile could not be loaded. Refresh and try again.';

        return false;
    }
}

function invalidateSupplementalData(): void {
    localSources.value = [];
    localHistory.value = [];
    sourcesLoaded.value = false;
    historyLoaded.value = false;
    sourcesError.value = null;
    historyError.value = null;
    historyCursor.value = null;
    historyHasMore.value = false;
}

async function loadSources(force = false): Promise<void> {
    if (sourcesLoading.value || (sourcesLoaded.value && !force)) {
        return;
    }

    sourcesLoading.value = true;
    sourcesError.value = null;

    try {
        const response = await jsonClient.get<SourcesResponse>(
            '/settings/gtm-profile/sources',
        );
        localSources.value = response.sources;
        sourcesLoaded.value = true;
    } catch (caught) {
        sourcesError.value = requestError(
            caught,
            'Source details could not be loaded. Try again.',
        );
    } finally {
        sourcesLoading.value = false;
    }
}

async function loadHistory(force = false): Promise<void> {
    if (historyLoading.value || (historyLoaded.value && !force)) {
        return;
    }

    historyLoading.value = true;
    historyError.value = null;

    try {
        const response = await jsonClient.get<HistoryResponse>(
            '/settings/gtm-profile/history?per_page=20',
        );
        localHistory.value = response.history;
        historyCursor.value = response.next_cursor ?? null;
        historyHasMore.value = response.has_more ?? false;
        historyLoaded.value = true;
    } catch (caught) {
        historyError.value = requestError(
            caught,
            'Profile history could not be loaded. Try again.',
        );
    } finally {
        historyLoading.value = false;
    }
}

async function loadMoreHistory(): Promise<void> {
    if (historyLoading.value || !historyHasMore.value || !historyCursor.value) {
        return;
    }

    historyLoading.value = true;
    historyError.value = null;

    try {
        const query = new URLSearchParams({
            cursor: historyCursor.value,
            per_page: '20',
        });
        const response = await jsonClient.get<HistoryResponse>(
            `/settings/gtm-profile/history?${query.toString()}`,
        );
        const existing = new Set(localHistory.value.map((entry) => entry.uuid));
        localHistory.value = [
            ...localHistory.value,
            ...response.history.filter((entry) => !existing.has(entry.uuid)),
        ];
        historyCursor.value = response.next_cursor ?? null;
        historyHasMore.value = response.has_more ?? false;
    } catch (caught) {
        historyError.value = requestError(
            caught,
            'More profile history could not be loaded. Try again.',
        );
    } finally {
        historyLoading.value = false;
    }
}

function ensurePanelData(panelName: Panel): void {
    if (panelName === 'sources') {
        void loadSources();
    } else if (panelName === 'history') {
        void loadHistory();
    }
}

function parsePanel(): { panel: Panel; section: GtmProfileSection | null } {
    if (typeof window === 'undefined') {
        return { panel: null, section: null };
    }

    const params = new URLSearchParams(window.location.search);
    const requested = params.get('panel');
    const allowed: Panel[] = ['edit', 'sources', 'history', 'review'];
    const selected = allowed.includes(requested as Panel)
        ? (requested as Panel)
        : null;
    const requestedSection = params.get('section');
    const sections: GtmProfileSection[] = [
        'company',
        'market',
        'buyers',
        'messaging',
        'qualification',
    ];

    return {
        panel: selected,
        section: sections.includes(requestedSection as GtmProfileSection)
            ? (requestedSection as GtmProfileSection)
            : null,
    };
}

function setUrl(
    panelName: Panel,
    section?: GtmProfileSection | null,
    push = true,
): void {
    if (typeof window === 'undefined') {
        return;
    }

    const url = new URL(window.location.href);
    url.searchParams.delete('panel');
    url.searchParams.delete('section');

    if (panelName) {
        url.searchParams.set('panel', panelName);

        if (panelName === 'edit' && section) {
            url.searchParams.set('section', section);
        }
    }

    const existingState =
        window.history.state && typeof window.history.state === 'object'
            ? (window.history.state as Record<string, unknown>)
            : {};
    const state = { ...existingState };
    const ownsCurrentOverlay =
        localOverlayEntry !== null &&
        existingState[OVERLAY_HISTORY_STATE_KEY] === localOverlayEntry;

    if (push) {
        // Transitions inside an overlay (for example Review -> Edit mine)
        // stay in the one entry this page owns. Only opening from the base
        // page pushes a new browser-history entry.
        if (panelName && ownsCurrentOverlay) {
            window.history.replaceState(state, '', url);

            return;
        }

        if (panelName) {
            overlayEntrySequence += 1;
            localOverlayEntry = `gtm-overlay-${Date.now()}-${overlayEntrySequence}`;
            state[OVERLAY_HISTORY_STATE_KEY] = localOverlayEntry;
        } else {
            localOverlayEntry = null;
            delete state[OVERLAY_HISTORY_STATE_KEY];
        }

        window.history.pushState(state, '', url);

        return;
    }

    if (!panelName) {
        localOverlayEntry = null;
        delete state[OVERLAY_HISTORY_STATE_KEY];
    }

    window.history.replaceState(state, '', url);
}

function ownsCurrentOverlayEntry(): boolean {
    return (
        localOverlayEntry !== null &&
        window.history.state?.[OVERLAY_HISTORY_STATE_KEY] === localOverlayEntry
    );
}

function syncOverlayEntryOwnership(): void {
    if (!ownsCurrentOverlayEntry()) {
        localOverlayEntry = null;
    }
}

function applyPanel(next: {
    panel: Panel;
    section: GtmProfileSection | null;
}): void {
    panel.value = next.panel;
    editorSection.value =
        next.panel === 'edit' ? (next.section ?? 'company') : null;
}

function currentPanel(): { panel: Panel; section: GtmProfileSection | null } {
    return { panel: panel.value, section: editorSection.value };
}

function samePanel(
    first: { panel: Panel; section: GtmProfileSection | null },
    second: { panel: Panel; section: GtmProfileSection | null },
): boolean {
    return first.panel === second.panel && first.section === second.section;
}

function needsEditorFlush(next: {
    panel: Panel;
    section: GtmProfileSection | null;
}): boolean {
    return (
        panel.value === 'edit' &&
        !samePanel(currentPanel(), next) &&
        Boolean(editorRef.value?.hasPending)
    );
}

async function transitionPanel(
    next: { panel: Panel; section: GtmProfileSection | null },
    push = true,
): Promise<boolean> {
    if (panelTransitionBusy.value) {
        return false;
    }

    if (samePanel(currentPanel(), next)) {
        ensurePanelData(next.panel);

        return true;
    }

    if (!needsEditorFlush(next)) {
        const leftEditor = panel.value === 'edit' && next.panel !== 'edit';
        applyPanel(next);
        setUrl(next.panel, next.section, push);
        ensurePanelData(next.panel);

        if (leftEditor && profileRefreshNeeded.value) {
            await refreshNeededProfileSnapshot();
        }

        return true;
    }

    panelTransitionBusy.value = true;

    try {
        const flushed = await editorRef.value?.flush();

        if (!flushed) {
            return false;
        }

        applyPanel(next);
        setUrl(next.panel, next.section, push);
        ensurePanelData(next.panel);

        if (profileRefreshNeeded.value) {
            await refreshNeededProfileSnapshot();
        }

        return true;
    } finally {
        panelTransitionBusy.value = false;
    }
}

function openPanel(
    next: Panel,
    section: GtmProfileSection | null = null,
): void {
    void transitionPanel({ panel: next, section });
}

function closePanel(): void {
    if (ownsCurrentOverlayEntry()) {
        // Consume the entry created when this page opened the overlay. A
        // replace here would leave two adjacent base-page entries, making the
        // user's next Back appear to do nothing.
        window.history.back();

        return;
    }

    // A directly loaded ?panel= deep link was not pushed by this component,
    // so closing it is a replace and must not navigate away from the page.
    void transitionPanel({ panel: null, section: null }, false);
}

async function handlePopState(): Promise<void> {
    const next = parsePanel();
    syncOverlayEntryOwnership();

    if (guardedBack.value) {
        guardedBack.value = false;
        applyPanel(next);
        ensurePanelData(next.panel);

        if (profileRefreshNeeded.value) {
            await refreshNeededProfileSnapshot();
        }

        return;
    }

    if (!needsEditorFlush(next)) {
        const leftEditor = panel.value === 'edit' && next.panel !== 'edit';
        applyPanel(next);
        ensurePanelData(next.panel);

        if (leftEditor && profileRefreshNeeded.value) {
            await refreshNeededProfileSnapshot();
        }

        return;
    }

    // A popstate has already moved the browser to the target entry. Push the
    // active editor URL back on top while the save queue settles, then replay
    // exactly one Back on success. A failed flush leaves the editor and its
    // Retry/Discard controls visible.
    const active = currentPanel();
    setUrl(active.panel, active.section, true);
    const flushed = await editorRef.value?.flush();

    if (flushed) {
        guardedBack.value = true;
        window.history.back();
    }
}

function clientOperationId(prefix: string): string {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
        return crypto.randomUUID();
    }

    return `${prefix}-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function isAmbiguousMutationFailure(caught: unknown): boolean {
    return !(caught instanceof JsonHttpError) || caught.status >= 500;
}

async function requestResearch(action: 'retry' | 'refresh'): Promise<void> {
    if (!isOwner.value || busyAction.value) {
        return;
    }

    if (
        pendingResearchRequest.value &&
        pendingResearchRequest.value.action !== action
    ) {
        actionError.value =
            'Retry or resolve the previous research request before starting a different one.';

        return;
    }

    const request =
        pendingResearchRequest.value ??
        ({
            action,
            body: {
                action,
                client_operation_id: clientOperationId(`research-${action}`),
            },
        } satisfies PendingResearchRequest);

    busyAction.value = action;
    actionError.value = null;
    pendingResearchRequest.value = request;
    let committed = false;

    try {
        await jsonClient.post('/settings/gtm-profile/research', request.body);
        committed = true;
        pendingResearchRequest.value = null;
        invalidateSupplementalData();
        await reloadProfileSnapshot();
    } catch (caught) {
        if (committed) {
            actionError.value =
                'Research started, but the latest status could not be loaded. Refresh the page to see its progress.';
        } else if (isAmbiguousMutationFailure(caught)) {
            actionError.value =
                'The connection ended before research was confirmed. Retry sends the exact same request and operation ID.';
        } else {
            pendingResearchRequest.value = null;
            actionError.value =
                caught instanceof JsonHttpError
                    ? (caught.errors.message ??
                      'Research could not be started.')
                    : 'Research could not be started. Check your connection and try again.';
        }
    } finally {
        busyAction.value = null;
    }
}

function handleSaved(): void {
    // Keep editable props stable while the drawer is open. The mutation
    // response already advanced its local lock; refresh the read model only
    // after the queue settles and the editor is left.
    profileRefreshNeeded.value = true;
    invalidateSupplementalData();
    void nextTick(retryPendingTerminalIfEditorSettled);
}

async function retryPendingTerminalIfEditorSettled(): Promise<void> {
    if (
        !researchPolling.terminalRefreshNeeded.value ||
        editorRef.value?.hasPending
    ) {
        return;
    }

    await researchPolling.retryTerminal();
}

async function handleEditorReload(): Promise<void> {
    editorRef.value?.discard();
    profileRefreshNeeded.value = false;
    invalidateSupplementalData();

    try {
        await reloadProfileSnapshot();
        actionError.value = null;

        if (researchPolling.terminalRefreshNeeded.value) {
            await retryPendingTerminalIfEditorSettled();
        } else {
            terminalRefreshBlocked.value = false;
            terminalRefreshRetryable.value = false;
            terminalRefreshMessage.value = null;
        }
    } catch {
        profileRefreshNeeded.value = true;
        actionError.value =
            'The latest profile could not be loaded. Refresh and try again.';
    }
}

function handleEditorDiscarded(): void {
    profileRefreshNeeded.value = false;
    void retryPendingTerminalIfEditorSettled();
}

async function handleReviewResolved(): Promise<void> {
    invalidateSupplementalData();
    await reloadProfileSnapshot();
}

async function handleResearchTerminal(): Promise<void> {
    terminalRefreshBlocked.value = false;
    terminalRefreshRetryable.value = false;
    terminalRefreshMessage.value = null;

    if (editorRef.value?.hasPending) {
        const flushed = await editorRef.value.flush();

        if (!flushed) {
            terminalRefreshBlocked.value = true;
            terminalRefreshRetryable.value = true;
            terminalRefreshMessage.value =
                'Research is complete. Resolve the save message in the open editor before loading the newly researched profile.';
            actionError.value =
                'Research finished, but your draft still needs a Retry, reload, or discard decision before the profile can refresh.';

            throw new Error('profile editor refresh blocked');
        }
    }

    const refreshSources = sourcesLoaded.value;
    const refreshHistory = historyLoaded.value;

    try {
        await reloadProfileSnapshot();
        profileRefreshNeeded.value = false;
        terminalRefreshBlocked.value = false;
        terminalRefreshRetryable.value = false;
        terminalRefreshMessage.value = null;
        actionError.value = null;
    } catch (caught) {
        profileRefreshNeeded.value = true;
        terminalRefreshBlocked.value = true;
        terminalRefreshRetryable.value = true;
        terminalRefreshMessage.value =
            'Research finished, but the latest profile could not be loaded. Retry the refresh; your completed research is preserved.';
        actionError.value = terminalRefreshMessage.value;

        throw caught;
    }

    await Promise.all([
        refreshSources ? loadSources(true) : Promise.resolve(),
        refreshHistory ? loadHistory(true) : Promise.resolve(),
    ]);
}

async function retryTerminalRefresh(): Promise<void> {
    await researchPolling.retryTerminal();
}

function addWebsite(): void {
    openPanel('edit', 'company');
}

function handleReviewEdit(section: GtmProfileSection): void {
    openPanel('edit', section);
}

function hasProtectedEditorState(): boolean {
    return panel.value === 'edit' && Boolean(editorRef.value?.hasPending);
}

function handleBeforeUnload(event: BeforeUnloadEvent): void {
    if (!hasProtectedEditorState()) {
        return;
    }

    event.preventDefault();
    event.returnValue = '';
}

function isResearchStateReload(visit: InertiaVisitSnapshot): boolean {
    return (
        visit.url.pathname === window.location.pathname &&
        visit.only?.length === 1 &&
        visit.only[0] === 'researchState'
    );
}

function handleInertiaBefore(event: Event): boolean | void {
    const visit = (
        event as Event & { detail?: { visit?: InertiaVisitSnapshot } }
    ).detail?.visit;

    if (!visit || isResearchStateReload(visit)) {
        return;
    }

    if (replayingNavigation) {
        replayingNavigation = false;

        return;
    }

    if (!hasProtectedEditorState()) {
        return;
    }

    event.preventDefault();

    if ((visit.method ?? 'get').toLowerCase() !== 'get') {
        actionError.value =
            'Resolve or discard the open profile draft before continuing.';

        return false;
    }

    void (async () => {
        const flushed = await editorRef.value?.flush();

        if (!flushed) {
            actionError.value =
                'The profile draft could not be saved. Use Retry, reload latest, or Discard before leaving.';

            return;
        }

        replayingNavigation = true;
        router.visit(visit.url.toString());
    })();

    return false;
}

onMounted(() => {
    applyPanel(parsePanel());
    ensurePanelData(panel.value);
    window.addEventListener('popstate', handlePopState);
    window.addEventListener('beforeunload', handleBeforeUnload);
    removeNavigationGuard = router.on('before', handleInertiaBefore);
});

onBeforeUnmount(() => {
    window.removeEventListener('popstate', handlePopState);
    window.removeEventListener('beforeunload', handleBeforeUnload);
    removeNavigationGuard?.();
});
</script>

<template>
    <Head title="GTM profile" />
    <AppWorkspace
        area="gtm-profile"
        title="GTM profile"
        :show-sidebar-title="false"
    >
        <template #sidebar>
            <WorkspaceOpsSidebar current="gtm-profile" />
        </template>

        <template #actions>
            <div class="gtm-page-actions">
                <span class="gtm-profile-status" aria-live="polite">
                    <span
                        class="gtm-profile-status-dot"
                        :class="{
                            working:
                                resolvedResearchState.state === 'queued' ||
                                resolvedResearchState.state === 'running',
                            warning:
                                resolvedResearchState.stale ||
                                resolvedResearchState.state === 'failed',
                        }"
                        aria-hidden="true"
                    />
                    {{ profileStatusLabel }}
                </span>
                <span v-if="actionError" class="gtm-action-error" role="alert">
                    {{ actionError }}
                </span>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            class="gtm-more-button"
                            aria-label="More actions"
                            title="More actions"
                        >
                            <Ellipsis aria-hidden="true" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="gtm-overflow-menu">
                        <DropdownMenuLabel>Profile actions</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem @select="openPanel('sources')">
                            <Search aria-hidden="true" />
                            View sources
                        </DropdownMenuItem>
                        <DropdownMenuItem @select="openPanel('history')">
                            <History aria-hidden="true" />
                            View history
                        </DropdownMenuItem>
                        <DropdownMenuItem v-if="isOwner" as-child>
                            <a href="/settings/gtm-profile/export" download>
                                <Download aria-hidden="true" />
                                Export JSON
                            </a>
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="
                                isOwner &&
                                resolvedResearchState.state === 'ready'
                            "
                            :disabled="busyAction !== null"
                            @select="requestResearch('refresh')"
                        >
                            <RefreshCw
                                aria-hidden="true"
                                :class="{
                                    'gtm-spin': busyAction === 'refresh',
                                }"
                            />
                            Refresh research
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-else-if="
                                isOwner &&
                                (resolvedResearchState.state === 'failed' ||
                                    resolvedResearchState.state ===
                                        'cancelled') &&
                                resolvedResearchState.safe_error_code !==
                                    'paid_result_lost'
                            "
                            :disabled="busyAction !== null"
                            @select="requestResearch('retry')"
                        >
                            <RefreshCw
                                aria-hidden="true"
                                :class="{ 'gtm-spin': busyAction === 'retry' }"
                            />
                            Retry research
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </template>

        <div
            v-if="terminalRefreshBlocked"
            class="gtm-terminal-refresh-alert"
            role="alert"
            data-test="gtm-terminal-refresh-blocked"
        >
            <span>{{ terminalRefreshMessage }}</span>
            <button
                v-if="terminalRefreshRetryable"
                type="button"
                :disabled="researchPolling.isHandlingTerminal.value"
                data-test="gtm-terminal-refresh-retry"
                @click="retryTerminalRefresh"
            >
                Retry refresh
            </button>
        </div>
        <GtmProfileOverview
            :profile="profile"
            :research-state="resolvedResearchState"
            :review-items="reviewItems"
            :active-offers-count="activeOffersCount"
            :is-owner="isOwner"
            @edit="openPanel('edit', $event)"
            @review="openPanel('review')"
            @sources="openPanel('sources')"
            @add-website="addWebsite"
            @retry-research="requestResearch('retry')"
            @refresh-research="requestResearch('refresh')"
        />
    </AppWorkspace>

    <GtmProfileEditorDrawer
        ref="editorRef"
        :open="panel === 'edit'"
        :profile="profile"
        :section="editorSection"
        :is-owner="isOwner"
        @update:open="
            (open) => (open ? openPanel('edit', editorSection) : closePanel())
        "
        @saved="handleSaved"
        @reload="handleEditorReload"
        @discarded="handleEditorDiscarded"
    />
    <GtmProfileSourcesDrawer
        :open="panel === 'sources'"
        :sources="localSources"
        :source-count="
            resolvedResearchState.source_count ?? localSources.length
        "
        :loading="sourcesLoading"
        :error="sourcesError"
        @update:open="(open) => (open ? openPanel('sources') : closePanel())"
        @retry="loadSources(true)"
    />
    <GtmProfileHistoryDrawer
        :open="panel === 'history'"
        :history="localHistory"
        :loading="historyLoading"
        :error="historyError"
        :has-more="historyHasMore"
        @update:open="(open) => (open ? openPanel('history') : closePanel())"
        @retry="loadHistory(true)"
        @load-more="loadMoreHistory"
    />
    <GtmProfileReviewDialog
        :open="panel === 'review'"
        :review-items="reviewItems"
        :lock-version="profile.lock_version"
        :is-owner="isOwner"
        :reconcile="handleReviewResolved"
        @update:open="(open) => (open ? openPanel('review') : closePanel())"
        @edit="handleReviewEdit"
    />
</template>

<style scoped>
.gtm-page-actions {
    display: flex;
    align-items: center;
    gap: 7px;
}

.gtm-profile-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #6b655c;
    font-size: 9px;
    white-space: nowrap;
}

.gtm-profile-status-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #18794e;
}

.gtm-profile-status-dot.working {
    background: var(--ob-accent-strong);
}

.gtm-profile-status-dot.warning {
    background: #c18516;
}

.gtm-more-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    min-height: 30px;
    border: 1px solid transparent;
    border-radius: 7px;
    padding: 5px 8px;
    background: transparent;
    color: #6b655c;
    font-size: 10px;
    cursor: pointer;
}

.gtm-more-button:hover {
    border-color: var(--ob-border-strong);
    background: #fff;
    color: #29251f;
}

.gtm-more-button :deep(svg) {
    width: 14px;
    height: 14px;
}

.gtm-more-button {
    width: 30px;
    padding: 0;
}

.gtm-action-error {
    max-width: 210px;
    overflow: hidden;
    color: #b42318;
    font-size: 9px;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.gtm-terminal-refresh-alert {
    margin: 18px clamp(18px, 4vw, 42px) 0;
    border: 1px solid #f0c8c3;
    border-radius: 8px;
    padding: 10px 12px;
    background: #fff0ee;
    color: #8e241d;
    font-size: 10px;
    line-height: 1.45;
}

.gtm-terminal-refresh-alert button {
    margin-left: 8px;
    border: 0;
    padding: 0;
    background: transparent;
    color: inherit;
    font-size: inherit;
    font-weight: 700;
    text-decoration: underline;
    text-underline-offset: 2px;
    cursor: pointer;
}

.gtm-terminal-refresh-alert button:disabled {
    cursor: wait;
    opacity: 0.6;
}

.gtm-spin {
    animation: gtm-spin 800ms linear infinite;
}

@keyframes gtm-spin {
    to {
        transform: rotate(360deg);
    }
}

@media (max-width: 760px) {
    .gtm-more-button {
        display: inline-flex;
    }
}

@media (prefers-reduced-motion: reduce) {
    .gtm-spin {
        animation: none;
    }
}
</style>
