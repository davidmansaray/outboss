<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Check,
    Mail,
    MoreHorizontal,
    PanelRightClose,
    PanelRightOpen,
} from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import EmailFrame from '@/components/EmailFrame.vue';
import AppWorkspace from '@/layouts/AppWorkspace.vue';

type ThreadRow = {
    id: number;
    subject: string;
    from: string | null;
    snippet: string | null;
    mailbox: string | null;
    unread: boolean;
    classification: string;
    state: string;
    campaign_recipient_id: number | null;
    campaign_id: number | null;
    campaign_name: string | null;
    person: { id: number; name: string; title: string | null } | null;
    account: { id: number; name: string } | null;
    recipient_status: string | null;
    participant_email?: string | null;
    last_message_at?: string | null;
};

type FilterMenu = 'campaign' | 'mailbox' | null;

type ThreadMessage = {
    id: number;
    from: string | null;
    direction: 'inbound' | 'outbound';
    body: string | null;
    html: string | null;
    at: string | null;
    preview?: boolean;
};

type ThreadDetail = ThreadRow & {
    has_inbound: boolean;
    messages: ThreadMessage[];
};

const props = defineProps<{
    folder: string;
    mailbox: number | null;
    campaign: string;
    threads: { data: ThreadRow[] };
    counts: Record<string, number>;
    campaigns: { id: number; name: string }[];
    mailboxes: {
        id: number;
        email_address: string;
        status: string;
        effective_reply_identity?: {
            email: string;
            name: string | null;
        } | null;
    }[];
    replyPreview?: boolean;
}>();

const selected = ref<number | null>(null);
const detail = ref<ThreadDetail | null>(null);
const detailsOpen = ref(false);
const reply = ref('');
const replyError = ref<string | null>(null);
const threadActionError = ref<string | null>(null);
const threadScroll = ref<HTMLElement | null>(null);
const threadLoading = ref(false);
const threadError = ref<string | null>(null);
const contextTrigger = ref<HTMLButtonElement | null>(null);
const mailboxFilterTrigger = ref<HTMLButtonElement | null>(null);
const campaignFilterTrigger = ref<HTMLButtonElement | null>(null);
const open = ref<FilterMenu>(null);
const replyDrafts = new Map<number, string>();
const pendingReplyThreads = ref(new Set<number>());
const latestReplyOperations = new Map<number, number>();
const pendingThreadActions = ref(new Set<number>());
const latestThreadActionOperations = new Map<number, number>();
let threadRequest = 0;
let threadActionOperation = 0;
let replyOperation = 0;

const replySending = computed(
    () =>
        selected.value !== null &&
        pendingReplyThreads.value.has(selected.value),
);
const threadActionPending = computed(
    () =>
        selected.value !== null &&
        pendingThreadActions.value.has(selected.value),
);

function shouldPreferPlainText(message: ThreadMessage): boolean {
    return Boolean(detail.value?.classification === 'bounce' && message.body);
}

function setPendingThread(
    threads: typeof pendingReplyThreads,
    id: number,
    pending: boolean,
) {
    const next = new Set(threads.value);

    if (pending) {
        next.add(id);
    } else {
        next.delete(id);
    }

    threads.value = next;
}

const primaryFolders = [
    { id: 'all_open', label: 'Inbox' },
    { id: 'leads', label: 'Leads' },
    { id: 'all_replies', label: 'All replies' },
];

const historyFolders = [
    { id: 'closed', label: 'Closed' },
    { id: 'sent', label: 'Sent' },
];

const automatedReplyFolders = [
    { id: 'ooo', label: 'Out of office' },
    { id: 'delivery', label: 'Delivery issues' },
    { id: 'opt_outs', label: 'Opt-outs' },
];

const allFolders = [
    ...primaryFolders,
    ...historyFolders,
    ...automatedReplyFolders,
];
const currentFolderLabel = computed(
    () => allFolders.find((item) => item.id === props.folder)?.label ?? 'Inbox',
);
const automatedReplyCount = computed(
    () =>
        (props.counts.ooo ?? 0) +
        (props.counts.delivery ?? 0) +
        (props.counts.opt_outs ?? 0),
);
const automatedReplySelected = computed(() =>
    automatedReplyFolders.some((item) => item.id === props.folder),
);

const campaignLabel = computed(() => {
    if (props.campaign === 'all') {
        return 'All campaigns';
    }

    if (props.campaign === 'none') {
        return 'No campaign';
    }

    return (
        props.campaigns.find(
            (item) => String(item.id) === String(props.campaign),
        )?.name ?? 'Campaign'
    );
});

const mailboxLabel = computed(() => {
    if (!props.mailbox) {
        return 'All inboxes';
    }

    return (
        props.mailboxes.find((box) => box.id === props.mailbox)
            ?.email_address ?? 'Inbox'
    );
});

const hasConnectedMailbox = computed(() =>
    props.mailboxes.some((box) => box.status === 'connected'),
);
const hasScopeFilters = computed(
    () => props.mailbox !== null || props.campaign !== 'all',
);

function go(
    next: { folder?: string; mailbox?: number | null; campaign?: string } = {},
) {
    const folder = next.folder ?? props.folder;
    const mailbox = next.mailbox !== undefined ? next.mailbox : props.mailbox;
    const campaign = next.campaign ?? props.campaign;
    const query: Record<string, string | number> = { folder };

    if (mailbox) {
        query.mailbox = mailbox;
    }

    if (campaign && campaign !== 'all') {
        query.campaign = campaign;
    }

    open.value = null;
    router.get('/unibox', query, { preserveState: true, preserveScroll: true });
}

function toggle(menu: Exclude<FilterMenu, null>) {
    open.value = open.value === menu ? null : menu;
}

function closeMenus() {
    open.value = null;
}

function storeReplyDraft(id: number | null, value: string) {
    if (id === null) {
        return;
    }

    if (value.trim()) {
        replyDrafts.set(id, value);
    } else {
        replyDrafts.delete(id);
    }
}

async function openThread(id: number) {
    const request = ++threadRequest;
    const switchingThreads = selected.value !== id;

    if (switchingThreads) {
        storeReplyDraft(selected.value, reply.value);
        reply.value = replyDrafts.get(id) ?? '';
    }

    selected.value = id;
    detail.value = null;
    detailsOpen.value = false;
    threadError.value = null;
    threadActionError.value = null;
    replyError.value = null;
    threadLoading.value = true;

    try {
        const response = await fetch(`/threads/${id}`, {
            headers: { Accept: 'application/json' },
        });

        if ('ok' in response && !response.ok) {
            throw new Error('Thread request failed');
        }

        const payload = await response.json();

        if (!payload.thread) {
            throw new Error('Thread response was empty');
        }

        if (request === threadRequest) {
            detail.value = payload.thread;
        }
    } catch {
        if (request === threadRequest) {
            threadError.value =
                'We could not load this conversation. Try again.';
        }
    } finally {
        if (request === threadRequest) {
            threadLoading.value = false;
        }
    }
}

function closeThread() {
    storeReplyDraft(selected.value, reply.value);
    threadRequest += 1;
    selected.value = null;
    reply.value = '';
    detail.value = null;
    detailsOpen.value = false;
    threadLoading.value = false;
    threadError.value = null;
    threadActionError.value = null;
    replyError.value = null;
}

function toggleDetails() {
    detailsOpen.value = !detailsOpen.value;
}

async function closeDetails() {
    detailsOpen.value = false;
    await nextTick();
    contextTrigger.value?.focus();
}

function classify(
    action: 'interested' | 'replied' | 'not_now' | 'wrong_person',
) {
    if (!detail.value || threadActionPending.value) {
        return;
    }

    const id = detail.value.id;
    const operation = ++threadActionOperation;
    latestThreadActionOperations.set(id, operation);
    setPendingThread(pendingThreadActions, id, true);
    threadActionError.value = null;

    router.post(
        `/threads/${id}/classification`,
        { action },
        {
            onSuccess: () => {
                if (
                    selected.value === id &&
                    latestThreadActionOperations.get(id) === operation
                ) {
                    void openThread(id);
                }
            },
            onError: () => {
                if (
                    selected.value === id &&
                    latestThreadActionOperations.get(id) === operation
                ) {
                    threadActionError.value =
                        'We could not update this conversation. Try again.';
                }
            },
            onFinish: () => {
                if (latestThreadActionOperations.get(id) === operation) {
                    latestThreadActionOperations.delete(id);
                    setPendingThread(pendingThreadActions, id, false);
                }
            },
        },
    );
}

function applyThreadAction(
    action: 'treat_as_reply' | 'remove_bad_address' | 'remove_from_campaign',
) {
    if (!detail.value || threadActionPending.value) {
        return;
    }

    const id = detail.value.id;
    const operation = ++threadActionOperation;
    latestThreadActionOperations.set(id, operation);
    setPendingThread(pendingThreadActions, id, true);
    threadActionError.value = null;

    router.post(
        `/threads/${id}/classification`,
        { action },
        {
            onSuccess: () => {
                if (
                    selected.value === id &&
                    latestThreadActionOperations.get(id) === operation
                ) {
                    void openThread(id);
                }
            },
            onError: () => {
                if (
                    selected.value === id &&
                    latestThreadActionOperations.get(id) === operation
                ) {
                    threadActionError.value =
                        'We could not update this conversation. Try again.';
                }
            },
            onFinish: () => {
                if (latestThreadActionOperations.get(id) === operation) {
                    latestThreadActionOperations.delete(id);
                    setPendingThread(pendingThreadActions, id, false);
                }
            },
        },
    );
}

function markDone() {
    if (!detail.value || threadActionPending.value) {
        return;
    }

    const id = detail.value.id;
    const operation = ++threadActionOperation;

    latestThreadActionOperations.set(id, operation);
    setPendingThread(pendingThreadActions, id, true);
    threadActionError.value = null;

    router.post(
        `/threads/${id}/done`,
        {},
        {
            onSuccess: () => {
                if (
                    selected.value === id &&
                    latestThreadActionOperations.get(id) === operation
                ) {
                    closeThread();
                }
            },
            onError: () => {
                if (
                    selected.value === id &&
                    latestThreadActionOperations.get(id) === operation
                ) {
                    threadActionError.value =
                        'We could not close this conversation. Try again.';
                }
            },
            onFinish: () => {
                if (latestThreadActionOperations.get(id) === operation) {
                    latestThreadActionOperations.delete(id);
                    setPendingThread(pendingThreadActions, id, false);
                }
            },
        },
    );
}

function messageTime(value: string | null): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(date);
}

function activityTime(value: string | null | undefined): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const now = new Date();
    const startOfToday = new Date(
        now.getFullYear(),
        now.getMonth(),
        now.getDate(),
    );
    const startOfDate = new Date(
        date.getFullYear(),
        date.getMonth(),
        date.getDate(),
    );
    const days = Math.round(
        (startOfToday.getTime() - startOfDate.getTime()) / 86_400_000,
    );

    if (days === 0) {
        return new Intl.DateTimeFormat(undefined, {
            hour: 'numeric',
            minute: '2-digit',
        }).format(date);
    }

    if (days === 1) {
        return 'Yesterday';
    }

    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
    }).format(date);
}

function initials(value: string | null): string {
    if (!value) {
        return '?';
    }

    return value
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}

function sendReply() {
    if (
        !selected.value ||
        !detail.value ||
        !reply.value.trim() ||
        replySending.value
    ) {
        return;
    }

    const id = selected.value;
    const body = reply.value.trim();

    if (props.replyPreview) {
        detail.value.messages.push({
            id: -Date.now(),
            from: currentMailbox.value
                ? senderLabel(currentMailbox.value)
                : (current.value?.mailbox ?? 'You'),
            direction: 'outbound',
            body,
            html: null,
            at: new Date().toISOString(),
            preview: true,
        });
        replyDrafts.delete(id);
        reply.value = '';

        void nextTick(() => {
            if (threadScroll.value) {
                threadScroll.value.scrollTop = threadScroll.value.scrollHeight;
            }
        });

        return;
    }

    const operation = ++replyOperation;

    latestReplyOperations.set(id, operation);
    setPendingThread(pendingReplyThreads, id, true);
    replyError.value = null;
    replyDrafts.delete(id);
    reply.value = '';

    router.post(
        `/threads/${id}/reply`,
        {
            body,
            client_operation_id: crypto.randomUUID(),
        },
        {
            onSuccess: () => {
                if (latestReplyOperations.get(id) === operation) {
                    replyDrafts.delete(id);
                }

                if (
                    selected.value === id &&
                    latestReplyOperations.get(id) === operation
                ) {
                    void openThread(id);
                }
            },
            onError: () => {
                if (latestReplyOperations.get(id) === operation) {
                    replyDrafts.set(id, body);
                }

                if (
                    selected.value === id &&
                    latestReplyOperations.get(id) === operation
                ) {
                    reply.value = body;
                    replyError.value = 'Your reply was not sent. Try again.';
                }
            },
            onFinish: () => {
                if (latestReplyOperations.get(id) === operation) {
                    latestReplyOperations.delete(id);
                    setPendingThread(pendingReplyThreads, id, false);
                }
            },
        },
    );
}

const current = computed(() =>
    props.threads.data.find((thread) => thread.id === selected.value),
);
const currentMailbox = computed(
    () =>
        props.mailboxes.find(
            (box) => box.email_address === current.value?.mailbox,
        ) ?? props.mailboxes.find((box) => box.id === props.mailbox),
);

function senderLabel(mailbox: (typeof props.mailboxes)[number]): string {
    const identity = mailbox.effective_reply_identity;

    if (!identity) {
        return mailbox.email_address;
    }

    return identity.name
        ? `${identity.name} <${identity.email}>`
        : identity.email;
}

function leadStatus(thread: ThreadRow): string {
    const recipientLabels: Record<string, string> = {
        active: 'Active',
        blocked_mailbox: 'Mailbox blocked',
        bounced: 'Delivery issue',
        completed: 'Completed',
        interested: 'Interested',
        not_now: 'Not now',
        paused: 'Paused',
        queued: 'Queued',
        removed: 'Removed',
        replied: 'Open',
        unsubscribed: 'Opted out',
        wrong_person: 'Wrong person',
    };

    if (thread.recipient_status && recipientLabels[thread.recipient_status]) {
        return recipientLabels[thread.recipient_status];
    }

    const stateLabels: Record<string, string> = {
        done: 'Closed',
        needs_action: 'New reply',
        open: 'Open',
        out_of_office: 'Out of office',
        problem: 'Needs review',
    };

    return stateLabels[thread.state] ?? 'Open';
}

function leadStatusTone(
    thread: ThreadRow,
): 'positive' | 'attention' | 'muted' | 'negative' | 'neutral' {
    if (thread.recipient_status === 'interested') {
        return 'positive';
    }

    if (
        thread.recipient_status === 'bounced' ||
        thread.recipient_status === 'unsubscribed' ||
        thread.state === 'problem'
    ) {
        return 'negative';
    }

    if (
        thread.recipient_status === 'not_now' ||
        thread.recipient_status === 'wrong_person' ||
        thread.recipient_status === 'removed' ||
        thread.state === 'done'
    ) {
        return 'muted';
    }

    if (thread.state === 'needs_action') {
        return 'attention';
    }

    return 'neutral';
}

function handleKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        if (open.value) {
            const trigger =
                open.value === 'mailbox'
                    ? mailboxFilterTrigger.value
                    : campaignFilterTrigger.value;
            closeMenus();
            void nextTick(() => trigger?.focus());

            return;
        }

        if (detailsOpen.value) {
            event.preventDefault();
            void closeDetails();
        }
    }
}

onMounted(() => {
    document.addEventListener('click', closeMenus);
    document.addEventListener('keydown', handleKeydown);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', closeMenus);
    document.removeEventListener('keydown', handleKeydown);
});

watch(
    () => props.threads.data,
    (threads) => {
        if (threads.some((thread) => thread.id === selected.value)) {
            return;
        }

        selected.value = null;
        detail.value = null;
        detailsOpen.value = false;
        threadLoading.value = false;
        threadError.value = null;
    },
);
</script>

<template>
    <Head title="Inbox" />
    <AppWorkspace area="unibox" title="Inbox">
        <template #actions>
            <Link
                v-if="!hasConnectedMailbox"
                class="btn"
                href="/settings/mailboxes"
                >Connect inbox</Link
            >
        </template>
        <template #sidebar>
            <div class="unibox-side-nav">
                <div class="unibox-view-groups">
                    <nav class="unibox-primary-nav" aria-label="Lead views">
                        <button
                            v-for="item in primaryFolders"
                            :key="item.id"
                            class="nav"
                            :class="{ active: folder === item.id }"
                            :aria-current="
                                folder === item.id ? 'page' : undefined
                            "
                            @click="go({ folder: item.id })"
                        >
                            <span>{{ item.label }}</span>
                            <span class="count">{{
                                counts[item.id] ?? 0
                            }}</span>
                        </button>
                    </nav>
                    <div class="unibox-history">
                        <span class="unibox-history-label">History</span>
                        <nav
                            class="unibox-history-nav"
                            aria-label="Inbox history"
                        >
                            <button
                                v-for="item in historyFolders"
                                :key="item.id"
                                class="nav"
                                :class="{ active: folder === item.id }"
                                :aria-current="
                                    folder === item.id ? 'page' : undefined
                                "
                                @click="go({ folder: item.id })"
                            >
                                <span>{{ item.label }}</span>
                                <span class="count">{{
                                    counts[item.id] ?? 0
                                }}</span>
                            </button>
                        </nav>
                    </div>
                </div>
                <details
                    class="unibox-exceptions"
                    :class="{ active: automatedReplySelected }"
                    :open="automatedReplySelected || undefined"
                >
                    <summary>
                        <span>Automated replies</span>
                        <span class="count exception-count">{{
                            automatedReplyCount
                        }}</span>
                    </summary>
                    <button
                        v-for="item in automatedReplyFolders"
                        :key="item.id"
                        class="nav exception-nav"
                        :class="{ active: folder === item.id }"
                        :aria-current="folder === item.id ? 'page' : undefined"
                        @click="go({ folder: item.id })"
                    >
                        <span>{{ item.label }}</span>
                        <span class="count">{{ counts[item.id] ?? 0 }}</span>
                    </button>
                </details>
            </div>
        </template>
        <div
            class="inbox"
            :class="{
                'detail-open': selected !== null,
                'conversation-details-open': detailsOpen,
            }"
        >
            <div class="list">
                <div class="list-heading">
                    <div>
                        <h1>{{ currentFolderLabel }}</h1>
                        <span>{{ counts[folder] ?? 0 }} conversations</span>
                    </div>
                    <button
                        v-if="hasScopeFilters"
                        class="list-reset"
                        type="button"
                        @click="go({ mailbox: null, campaign: 'all' })"
                    >
                        Reset scope
                    </button>
                    <span v-else class="list-order">Recent activity</span>
                </div>
                <div
                    v-if="mailboxes.length > 0"
                    class="list-toolbar"
                    @click.stop
                >
                    <div class="dd">
                        <button
                            ref="mailboxFilterTrigger"
                            class="dd-btn"
                            :class="{ on: mailbox !== null }"
                            type="button"
                            :title="`Mailbox: ${mailboxLabel}`"
                            aria-controls="mailbox-filter-options"
                            :aria-expanded="open === 'mailbox'"
                            @click="toggle('mailbox')"
                        >
                            <span class="filter-copy">
                                <small>Mailbox</small>
                                <span class="val">{{ mailboxLabel }}</span>
                            </span>
                            <svg
                                class="chev"
                                viewBox="0 0 10 10"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.6"
                            >
                                <path d="M2 3.5 5 6.5 8 3.5" />
                            </svg>
                        </button>
                        <div
                            v-if="open === 'mailbox'"
                            id="mailbox-filter-options"
                            class="popover"
                        >
                            <button
                                type="button"
                                :class="{ on: mailbox === null }"
                                @click="go({ mailbox: null })"
                            >
                                All inboxes
                            </button>
                            <button
                                v-for="box in mailboxes"
                                :key="box.id"
                                type="button"
                                :class="{ on: mailbox === box.id }"
                                @click="go({ mailbox: box.id })"
                            >
                                {{ box.email_address }}
                            </button>
                        </div>
                    </div>
                    <div class="dd">
                        <button
                            ref="campaignFilterTrigger"
                            class="dd-btn"
                            :class="{ on: campaign !== 'all' }"
                            type="button"
                            :title="`Campaign: ${campaignLabel}`"
                            aria-controls="campaign-filter-options"
                            :aria-expanded="open === 'campaign'"
                            @click="toggle('campaign')"
                        >
                            <span class="filter-copy">
                                <small>Campaign</small>
                                <span class="val">{{ campaignLabel }}</span>
                            </span>
                            <svg
                                class="chev"
                                viewBox="0 0 10 10"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.6"
                            >
                                <path d="M2 3.5 5 6.5 8 3.5" />
                            </svg>
                        </button>
                        <div
                            v-if="open === 'campaign'"
                            id="campaign-filter-options"
                            class="popover"
                        >
                            <button
                                type="button"
                                :class="{ on: campaign === 'all' }"
                                @click="go({ campaign: 'all' })"
                            >
                                All campaigns
                            </button>
                            <button
                                v-for="item in campaigns"
                                :key="item.id"
                                type="button"
                                :class="{
                                    on: String(campaign) === String(item.id),
                                }"
                                @click="go({ campaign: String(item.id) })"
                            >
                                {{ item.name }}
                            </button>
                            <button
                                type="button"
                                :class="{ on: campaign === 'none' }"
                                @click="go({ campaign: 'none' })"
                            >
                                No campaign
                            </button>
                        </div>
                    </div>
                </div>
                <div v-if="!hasConnectedMailbox" class="empty">
                    <p>
                        Connect a mailbox to start reading replies in one place.
                    </p>
                    <Link class="btn" href="/settings/mailboxes"
                        >Connect inbox</Link
                    >
                </div>
                <p v-else-if="threads.data.length === 0" class="empty">
                    {{
                        folder === 'sent'
                            ? 'No sent conversations.'
                            : 'No threads match these filters.'
                    }}
                </p>
                <button
                    v-for="thread in threads.data"
                    :key="thread.id"
                    class="thread"
                    :class="{ sel: selected === thread.id }"
                    :aria-current="selected === thread.id ? 'true' : undefined"
                    @click="openThread(thread.id)"
                >
                    <div class="who">
                        <span
                            ><span v-if="thread.unread" class="dot" />{{
                                thread.from
                            }}</span
                        >
                        <span class="thread-time">{{
                            activityTime(thread.last_message_at)
                        }}</span>
                    </div>
                    <div class="sub">{{ thread.subject }}</div>
                    <div class="thread-snippet">{{ thread.snippet }}</div>
                    <div class="thread-context">
                        <span
                            v-if="folder === 'leads'"
                            class="lead-status"
                            :class="`status-${leadStatusTone(thread)}`"
                            >{{ leadStatus(thread) }}</span
                        >
                        <span v-if="folder === 'leads'">·</span>
                        <span>{{ thread.campaign_name ?? 'No campaign' }}</span>
                    </div>
                </button>
            </div>
            <div class="read" :aria-busy="threadLoading">
                <div
                    v-if="threadLoading"
                    class="thread-state"
                    role="status"
                    aria-live="polite"
                >
                    <span class="thread-state-spinner" aria-hidden="true" />
                    <strong>Loading conversation</strong>
                    <span>Getting the latest messages…</span>
                </div>
                <div
                    v-else-if="threadError"
                    class="thread-state thread-state-error"
                    role="alert"
                >
                    <strong>Conversation unavailable</strong>
                    <span>{{ threadError }}</span>
                    <button
                        v-if="selected"
                        class="btn ghost"
                        type="button"
                        @click="openThread(selected)"
                    >
                        Try again
                    </button>
                </div>
                <div v-else-if="!detail" class="empty">
                    <div class="inbox-empty-state">
                        <span aria-hidden="true">↗</span>
                        <strong>Choose a reply to review</strong>
                        <small>
                            Select a conversation. Its history and reply actions
                            will appear here.
                        </small>
                    </div>
                </div>
                <template v-else>
                    <div class="read-head continuous-read-head">
                        <button
                            class="mobile-thread-back"
                            type="button"
                            @click="closeThread"
                        >
                            ← Conversations
                        </button>
                        <div class="conversation-title-row">
                            <h2>{{ detail.subject }}</h2>
                            <button
                                v-if="!detailsOpen"
                                ref="contextTrigger"
                                class="conversation-details-trigger"
                                type="button"
                                aria-label="Open context"
                                aria-controls="context-panel"
                                :aria-expanded="detailsOpen"
                                @click="toggleDetails"
                            >
                                <PanelRightOpen aria-hidden="true" />
                                <span>Context</span>
                            </button>
                        </div>
                        <div class="conversation-meta">
                            <span class="conversation-channel">
                                <Mail aria-hidden="true" /> Email
                            </span>
                            <span
                                class="conversation-lead-status"
                                :class="`status-${leadStatusTone(detail)}`"
                                >{{ leadStatus(detail) }}</span
                            >
                            <span>{{ detail.messages.length }} messages</span>
                            <span aria-hidden="true">·</span>
                            <span>{{ detail.mailbox }}</span>
                        </div>
                    </div>
                    <div
                        ref="threadScroll"
                        class="thread-scroll continuous-thread"
                    >
                        <template
                            v-for="(message, index) in detail.messages"
                            :key="message.id"
                        >
                            <div
                                v-if="
                                    index === detail.messages.length - 1 &&
                                    detail.messages.length > 1
                                "
                                class="latest-reply-marker"
                            >
                                Latest reply
                            </div>
                            <article
                                class="conversation-message"
                                :class="'message-' + message.direction"
                            >
                                <header class="message-header">
                                    <span
                                        class="message-avatar"
                                        :class="{
                                            you:
                                                message.direction ===
                                                'outbound',
                                        }"
                                        >{{
                                            message.direction === 'outbound'
                                                ? 'You'
                                                : initials(message.from)
                                        }}</span
                                    >
                                    <span class="message-sender">
                                        <strong>{{
                                            message.direction === 'outbound'
                                                ? 'You'
                                                : message.from
                                        }}</strong>
                                        <small>{{
                                            message.direction === 'outbound'
                                                ? 'from ' + detail.mailbox
                                                : 'to ' + detail.mailbox
                                        }}</small>
                                    </span>
                                    <span
                                        v-if="message.preview"
                                        class="preview-reply-badge"
                                        >Preview</span
                                    >
                                    <time :datetime="message.at ?? undefined">{{
                                        messageTime(message.at)
                                    }}</time>
                                </header>
                                <div class="message-content">
                                    <EmailFrame
                                        v-if="
                                            message.html &&
                                            !shouldPreferPlainText(message)
                                        "
                                        :html="message.html"
                                    />
                                    <p v-else>{{ message.body }}</p>
                                </div>
                            </article>
                        </template>
                    </div>
                    <div
                        v-if="!current?.mailbox"
                        class="composer reply-composer"
                    >
                        <p class="hint">
                            This inbox was disconnected, so you cannot reply
                            from it.
                        </p>
                    </div>
                    <div v-else class="composer reply-composer">
                        <div class="reply-from">
                            <span>Reply from</span>
                            <strong>{{
                                currentMailbox
                                    ? senderLabel(currentMailbox)
                                    : current?.mailbox
                            }}</strong>
                        </div>
                        <textarea
                            v-model="reply"
                            aria-label="Reply"
                            :disabled="replySending"
                            placeholder="Write a reply…"
                            @keydown.meta.enter.prevent="sendReply"
                            @keydown.ctrl.enter.prevent="sendReply"
                        />
                        <div class="reply-actions">
                            <span
                                v-if="replyError"
                                class="inline-action-error"
                                role="alert"
                                >{{ replyError }}</span
                            >
                            <span
                                v-else-if="replyPreview"
                                class="preview-reply-note"
                                >Preview only · no email will be sent</span
                            >
                            <span v-else>⌘ Enter to send</span>
                            <button
                                class="btn"
                                :disabled="!reply.trim() || replySending"
                                @click="sendReply"
                            >
                                {{ replySending ? 'Sending…' : 'Send reply' }}
                            </button>
                        </div>
                    </div>
                </template>
            </div>
            <aside
                v-if="detail"
                id="context-panel"
                class="conversation-details"
                :class="{ open: detailsOpen }"
                :aria-hidden="!detailsOpen"
                :inert="!detailsOpen"
                aria-label="Context"
            >
                <div class="conversation-details-head">
                    <strong>Context</strong>
                    <button
                        type="button"
                        aria-label="Close context"
                        @click="closeDetails"
                    >
                        <PanelRightClose aria-hidden="true" />
                    </button>
                </div>
                <div class="conversation-details-scroll">
                    <section class="conversation-detail-section">
                        <span class="conversation-detail-label">Contact</span>
                        <div class="conversation-person">
                            <span class="message-avatar">{{
                                initials(detail.from)
                            }}</span>
                            <span>
                                <strong>{{ detail.from }}</strong>
                                <small>{{
                                    detail.participant_email ??
                                    'Email correspondent'
                                }}</small>
                            </span>
                        </div>
                        <div
                            v-if="detail.person || detail.account"
                            class="conversation-registry-chips"
                        >
                            <Link
                                v-if="detail.person"
                                class="registry-chip"
                                :href="'/prospects?person=' + detail.person.id"
                                >{{ detail.person.name }}</Link
                            >
                            <Link
                                v-if="detail.account"
                                class="registry-chip"
                                :href="
                                    '/prospects?account=' + detail.account.id
                                "
                                >{{ detail.account.name }}</Link
                            >
                        </div>
                    </section>
                    <section
                        v-if="
                            detail.has_inbound && detail.campaign_recipient_id
                        "
                        class="conversation-detail-section"
                        :aria-busy="threadActionPending"
                    >
                        <span class="conversation-detail-label"
                            >Qualification</span
                        >
                        <div class="conversation-classification">
                            <button
                                type="button"
                                :disabled="threadActionPending"
                                :class="{
                                    active:
                                        detail.recipient_status ===
                                        'interested',
                                }"
                                @click="classify('interested')"
                            >
                                Interested
                            </button>
                            <button
                                type="button"
                                :disabled="threadActionPending"
                                :class="{
                                    active:
                                        detail.recipient_status === 'replied',
                                }"
                                @click="classify('replied')"
                            >
                                Replied
                            </button>
                            <button
                                type="button"
                                :disabled="threadActionPending"
                                :class="{
                                    active:
                                        detail.recipient_status === 'not_now',
                                }"
                                @click="classify('not_now')"
                            >
                                Not now
                            </button>
                            <button
                                type="button"
                                :disabled="threadActionPending"
                                :class="{
                                    active:
                                        detail.recipient_status ===
                                        'wrong_person',
                                }"
                                @click="classify('wrong_person')"
                            >
                                Wrong person
                            </button>
                        </div>
                        <p
                            v-if="threadActionError"
                            class="inline-action-error"
                            role="alert"
                        >
                            {{ threadActionError }}
                        </p>
                    </section>
                    <section class="conversation-detail-section">
                        <span class="conversation-detail-label">Source</span>
                        <dl class="conversation-source">
                            <div>
                                <dt>Campaign</dt>
                                <dd>
                                    <Link
                                        v-if="detail.campaign_id"
                                        :href="
                                            '/campaigns/' + detail.campaign_id
                                        "
                                        >{{ detail.campaign_name }}</Link
                                    >
                                    <span v-else>No campaign</span>
                                </dd>
                            </div>
                            <div>
                                <dt>Inbox</dt>
                                <dd>{{ detail.mailbox }}</dd>
                            </div>
                        </dl>
                    </section>
                    <section class="conversation-detail-section">
                        <button
                            class="conversation-done"
                            type="button"
                            :disabled="threadActionPending"
                            @click="markDone"
                        >
                            <Check aria-hidden="true" />
                            {{
                                threadActionPending
                                    ? 'Updating…'
                                    : 'Mark conversation done'
                            }}
                        </button>
                        <details class="conversation-more">
                            <summary>
                                <MoreHorizontal aria-hidden="true" />
                                More actions
                            </summary>
                            <button
                                v-if="detail.classification === 'out_of_office'"
                                type="button"
                                :disabled="threadActionPending"
                                @click="applyThreadAction('treat_as_reply')"
                            >
                                Treat as real reply
                            </button>
                            <button
                                type="button"
                                :disabled="threadActionPending"
                                @click="applyThreadAction('remove_bad_address')"
                            >
                                Remove — bad address
                            </button>
                            <button
                                type="button"
                                :disabled="threadActionPending"
                                @click="
                                    applyThreadAction('remove_from_campaign')
                                "
                            >
                                Remove from campaign
                            </button>
                        </details>
                    </section>
                </div>
            </aside>
        </div>
    </AppWorkspace>
</template>
