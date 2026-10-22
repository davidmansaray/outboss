<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowRight, Plus, X } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import AppWorkspace from '@/layouts/AppWorkspace.vue';

type CampaignSummary = {
    id: number;
    name: string;
    status: 'draft' | 'active' | 'paused' | 'completed';
    people_count: number;
    steps_count: number;
    mailboxes_count: number;
    sender_emails: string[];
    senders_more: number;
    sent_count: number;
    replies_count: number;
    interested_count: number;
    queued_count: number;
    bounced_count: number;
    unsubscribed_count: number;
    updated_at: string | null;
};

const props = defineProps<{ campaigns: CampaignSummary[] }>();
const filter = ref<'all' | CampaignSummary['status']>('all');
const showCreate = ref(false);
const createTrigger = ref<HTMLButtonElement | null>(null);
const createDialog = ref<HTMLElement | null>(null);
const nameInput = ref<HTMLInputElement | null>(null);
const form = useForm({ name: '' });

const statusOptions = [
    { id: 'all', label: 'All campaigns' },
    { id: 'draft', label: 'Drafts' },
    { id: 'active', label: 'Active' },
    { id: 'paused', label: 'Paused' },
    { id: 'completed', label: 'Completed' },
] as const;

const filteredCampaigns = computed(() =>
    filter.value === 'all'
        ? props.campaigns
        : props.campaigns.filter(
              (campaign) => campaign.status === filter.value,
          ),
);

function statusCount(status: (typeof statusOptions)[number]['id']): number {
    return status === 'all'
        ? props.campaigns.length
        : props.campaigns.filter((campaign) => campaign.status === status)
              .length;
}

function statusLabel(status: CampaignSummary['status'] | 'all'): string {
    return {
        all: 'All',
        draft: 'Draft',
        active: 'Active',
        paused: 'Paused',
        completed: 'Completed',
    }[status];
}

function nextAction(campaign: CampaignSummary): string {
    if (campaign.status === 'draft') {
        return 'Continue setup';
    }

    if (campaign.status === 'paused') {
        return 'Review and resume';
    }

    return campaign.status === 'active' ? 'View campaign' : 'View results';
}

function updatedLabel(value: string | null): string {
    if (!value) {
        return 'Not updated yet';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return 'Recently updated';
    }

    return (
        'Updated ' +
        new Intl.DateTimeFormat(undefined, {
            month: 'short',
            day: 'numeric',
        }).format(date)
    );
}

async function openCreate() {
    form.clearErrors();
    showCreate.value = true;
    await nextTick();
    nameInput.value?.focus();
}

async function closeCreate() {
    showCreate.value = false;
    form.reset();
    form.clearErrors();
    await nextTick();
    createTrigger.value?.focus();
}

function createCampaign() {
    form.post('/campaigns', { onError: () => nameInput.value?.focus() });
}

function keepFocusInDialog(event: KeyboardEvent, dialog: HTMLElement | null) {
    if (event.key !== 'Tab' || !dialog) {
        return;
    }

    const focusable = Array.from(
        dialog.querySelectorAll<HTMLElement>(
            'a[href], button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])',
        ),
    );

    if (!focusable.length) {
        event.preventDefault();
        dialog.focus();

        return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

function handleKeydown(event: KeyboardEvent) {
    if (showCreate.value) {
        keepFocusInDialog(event, createDialog.value);
    }

    if (event.key === 'Escape' && showCreate.value && !form.processing) {
        event.preventDefault();
        void closeCreate();
    }
}

onMounted(() => document.addEventListener('keydown', handleKeydown));
onBeforeUnmount(() => document.removeEventListener('keydown', handleKeydown));
</script>

<template>
    <Head title="Campaigns" />
    <AppWorkspace area="campaigns" title="Campaigns">
        <template #sidebar>
            <nav class="campaign-side-nav" aria-label="Campaign views">
                <button
                    v-for="option in statusOptions"
                    :key="option.id"
                    type="button"
                    :class="{ active: filter === option.id }"
                    :aria-current="filter === option.id ? 'page' : undefined"
                    @click="filter = option.id"
                >
                    <span>{{ option.label }}</span>
                    <small>{{ statusCount(option.id) }}</small>
                </button>
            </nav>
            <div v-if="campaigns.length" class="campaign-recent">
                <p>Recent</p>
                <Link
                    v-for="campaign in campaigns.slice(0, 5)"
                    :key="campaign.id"
                    :href="'/campaigns/' + campaign.id"
                >
                    <span
                        class="campaign-status-dot"
                        :class="'status-' + campaign.status"
                        aria-hidden="true"
                    />
                    <span>{{ campaign.name }}</span>
                </Link>
            </div>
        </template>

        <template #actions>
            <button
                ref="createTrigger"
                class="btn"
                type="button"
                data-test="new-campaign"
                @click="openCreate"
            >
                <Plus aria-hidden="true" />
                New campaign
            </button>
        </template>

        <main class="campaign-index">
            <header class="campaign-page-heading">
                <div>
                    <p class="campaign-eyebrow">Outreach</p>
                    <h1>Campaigns</h1>
                    <p>
                        Prepare an audience, review the sequence, and launch
                        with a safe sending setup.
                    </p>
                </div>
                <div v-if="campaigns.length" class="campaign-totals">
                    <span
                        ><strong>{{ statusCount('active') }}</strong>
                        active</span
                    >
                    <span
                        ><strong>{{ statusCount('draft') }}</strong>
                        drafts</span
                    >
                    <span>
                        <strong>{{
                            campaigns.reduce(
                                (total, campaign) =>
                                    total + campaign.interested_count,
                                0,
                            )
                        }}</strong>
                        interested
                    </span>
                </div>
            </header>

            <section
                v-if="filteredCampaigns.length"
                class="campaign-list"
                :aria-label="
                    filter === 'all'
                        ? 'All campaigns'
                        : statusLabel(filter) + ' campaigns'
                "
            >
                <div class="campaign-list-head" aria-hidden="true">
                    <span>Campaign</span>
                    <span>Audience</span>
                    <span>Sequence</span>
                    <span>Sending</span>
                    <span>Results</span>
                    <span />
                </div>
                <Link
                    v-for="campaign in filteredCampaigns"
                    :key="campaign.id"
                    class="campaign-row"
                    :href="'/campaigns/' + campaign.id"
                >
                    <span class="campaign-row-primary">
                        <span class="campaign-row-title">
                            <strong>{{ campaign.name }}</strong>
                            <span
                                class="campaign-status"
                                :class="'status-' + campaign.status"
                                >{{ statusLabel(campaign.status) }}</span
                            >
                        </span>
                        <small>{{ updatedLabel(campaign.updated_at) }}</small>
                    </span>
                    <span class="campaign-row-metric">
                        <small>Audience</small>
                        <strong>{{ campaign.people_count }}</strong>
                        <span>{{
                            campaign.people_count === 1 ? 'person' : 'people'
                        }}</span>
                    </span>
                    <span class="campaign-row-metric">
                        <small>Sequence</small>
                        <strong>{{ campaign.steps_count }}</strong>
                        <span>{{
                            campaign.steps_count === 1 ? 'email' : 'emails'
                        }}</span>
                    </span>
                    <span class="campaign-row-metric">
                        <small>Sending</small>
                        <strong>{{ campaign.mailboxes_count }}</strong>
                        <span>
                            <template v-if="campaign.sender_emails.length">
                                {{
                                    campaign.sender_emails[0] +
                                    (campaign.sender_emails.length +
                                        campaign.senders_more >
                                    1
                                        ? ' +' +
                                          (campaign.sender_emails.length +
                                              campaign.senders_more -
                                              1)
                                        : '')
                                }}
                            </template>
                            <template v-else>
                                {{
                                    campaign.mailboxes_count === 1
                                        ? 'mailbox'
                                        : 'mailboxes'
                                }}
                            </template>
                        </span>
                    </span>
                    <span
                        class="campaign-row-metric campaign-row-metric-results"
                    >
                        <small>Results</small>
                        <strong>{{ campaign.sent_count }}</strong>
                        <span
                            >{{ campaign.interested_count }} interested ·
                            {{ campaign.replies_count }} replies ·
                            {{ campaign.bounced_count }} bounced ·
                            {{ campaign.unsubscribed_count }}
                            unsubscribed</span
                        >
                    </span>
                    <span class="campaign-row-action">
                        {{ nextAction(campaign) }}
                        <ArrowRight aria-hidden="true" />
                    </span>
                </Link>
            </section>

            <section v-else class="campaign-empty">
                <p class="campaign-eyebrow">
                    {{
                        campaigns.length
                            ? statusLabel(filter)
                            : 'First campaign'
                    }}
                </p>
                <h2>
                    {{
                        campaigns.length
                            ? 'No ' +
                              statusLabel(filter).toLowerCase() +
                              ' campaigns'
                            : 'Turn an audience into conversations'
                    }}
                </h2>
                <p v-if="!campaigns.length">
                    Name your campaign, choose an Objective and Offer, add
                    people, write your email, then configure sending and review
                    before launch.
                </p>
                <button
                    v-if="!campaigns.length"
                    class="btn"
                    type="button"
                    @click="openCreate"
                >
                    Create campaign
                </button>
                <button
                    v-else
                    class="btn ghost"
                    type="button"
                    @click="filter = 'all'"
                >
                    View all campaigns
                </button>
            </section>
        </main>
    </AppWorkspace>

    <div v-if="showCreate" class="modal-back">
        <form
            ref="createDialog"
            class="modal campaign-create-dialog"
            role="dialog"
            aria-modal="true"
            aria-labelledby="create-campaign-title"
            tabindex="-1"
            @submit.prevent="createCampaign"
        >
            <div class="campaign-dialog-head">
                <div>
                    <p class="campaign-eyebrow">New campaign</p>
                    <h2 id="create-campaign-title">Name the campaign</h2>
                </div>
                <button
                    type="button"
                    aria-label="Close new campaign"
                    :disabled="form.processing"
                    @click="closeCreate"
                >
                    <X aria-hidden="true" />
                </button>
            </div>
            <label for="campaign-name">Campaign name</label>
            <input
                id="campaign-name"
                ref="nameInput"
                v-model="form.name"
                required
                maxlength="120"
                placeholder="Q3 Series A founders"
                :aria-invalid="form.errors.name ? 'true' : undefined"
                :aria-describedby="
                    form.errors.name ? 'campaign-name-error' : undefined
                "
            />
            <p
                v-if="form.errors.name"
                id="campaign-name-error"
                class="inline-action-error"
                role="alert"
            >
                {{ form.errors.name }}
            </p>
            <p class="campaign-form-help">
                Use a name that identifies the audience or motion. Nothing sends
                until the launch review passes.
            </p>
            <div class="campaign-dialog-actions">
                <button
                    class="btn ghost"
                    type="button"
                    :disabled="form.processing"
                    @click="closeCreate"
                >
                    Cancel
                </button>
                <button
                    class="btn"
                    type="submit"
                    data-test="create-campaign"
                    :disabled="form.processing || !form.name.trim()"
                >
                    {{ form.processing ? 'Creating…' : 'Create campaign' }}
                </button>
            </div>
        </form>
    </div>
</template>

<style scoped>
.campaign-side-nav {
    display: grid;
    gap: 2px;
    padding: 0 12px 14px;
}
.campaign-side-nav button {
    display: flex;
    min-height: 40px;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    border: 0;
    border-radius: 8px;
    padding: 0 10px;
    background: transparent;
    color: #4e4942;
    font: inherit;
    text-align: left;
    cursor: pointer;
}
.campaign-side-nav button:hover,
.campaign-side-nav button.active {
    background: var(--ob-accent-tint);
    color: var(--ob-accent);
}
.campaign-side-nav small {
    color: #8a8379;
    font-size: 11px;
}
.campaign-recent {
    margin: 0 20px;
    padding-top: 16px;
    border-top: 1px solid #e8e2da;
}
.campaign-recent > p {
    margin: 0 0 8px;
    color: #8a8379;
    font-size: 10px;
    font-weight: 650;
    letter-spacing: 0.1em;
    text-transform: uppercase;
}
.campaign-recent a {
    display: grid;
    min-height: 38px;
    grid-template-columns: 8px minmax(0, 1fr);
    align-items: center;
    gap: 9px;
    color: #4e4942;
    font-size: 12px;
    text-decoration: none;
}
.campaign-recent a span:last-child {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.campaign-status-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #aaa39a;
}
.campaign-status-dot.status-active {
    background: #21a474;
}
.campaign-status-dot.status-paused {
    background: #d69528;
}
.campaign-status-dot.status-draft {
    background: var(--ob-focus);
}
.campaign-status-dot.status-completed {
    background: #4a6fa5;
}
.campaign-index {
    min-width: 0;
    padding: 36px 40px 64px;
}
.campaign-page-heading {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 32px;
    padding-bottom: 28px;
    border-bottom: 1px solid var(--ob-border);
}
.campaign-eyebrow {
    margin: 0 0 7px;
    color: var(--ob-focus);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}
.campaign-page-heading h1,
.campaign-empty h2 {
    margin: 0;
    color: #1f1c18;
    font-size: 28px;
    font-weight: 650;
    letter-spacing: -0.03em;
}
.campaign-page-heading > div:first-child > p:last-child,
.campaign-empty > p:not(.campaign-eyebrow) {
    max-width: 620px;
    margin: 8px 0 0;
    color: #736c63;
    font-size: 13px;
    line-height: 1.55;
}
.campaign-totals {
    display: flex;
    gap: 22px;
    color: #736c63;
    font-size: 12px;
    white-space: nowrap;
}
.campaign-totals strong {
    color: #27231e;
    font-size: 15px;
}
.campaign-list {
    min-width: 0;
}
.campaign-list-head,
.campaign-row {
    display: grid;
    grid-template-columns:
        minmax(200px, 1.6fr) minmax(76px, 0.55fr) minmax(76px, 0.55fr)
        minmax(120px, 0.95fr) minmax(150px, 1.15fr) minmax(120px, 0.75fr);
    align-items: center;
    gap: 16px;
}
.campaign-list-head {
    min-height: 46px;
    border-bottom: 1px solid var(--ob-border);
    color: #8a8379;
    font-size: 10px;
    font-weight: 650;
    letter-spacing: 0.07em;
    text-transform: uppercase;
}
.campaign-row {
    min-height: 86px;
    border-bottom: 1px solid var(--ob-border-soft);
    color: inherit;
    text-decoration: none;
    transition: background 120ms ease;
}
.campaign-row:hover,
.campaign-row:focus-visible {
    background: #faf8f4;
}
.campaign-row-primary,
.campaign-row-metric {
    display: grid;
    min-width: 0;
    gap: 4px;
}
.campaign-row-title {
    display: flex;
    min-width: 0;
    align-items: center;
    gap: 8px;
}
.campaign-row-title strong {
    overflow: hidden;
    color: #27231e;
    font-size: 14px;
    font-weight: 640;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.campaign-row-primary small,
.campaign-row-metric small {
    color: #8a8379;
    font-size: 10px;
}
.campaign-row-metric small {
    display: none;
}
.campaign-row-metric strong {
    color: #27231e;
    font-size: 14px;
    font-weight: 620;
}
.campaign-row-metric > span {
    overflow: hidden;
    color: #736c63;
    font-size: 11px;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.campaign-row-metric-results > span {
    white-space: normal;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}
.campaign-status {
    flex: 0 0 auto;
    border-radius: 999px;
    padding: 3px 7px;
    background: #efede9;
    color: #665f56;
    font-size: 9px;
    font-weight: 650;
}
.campaign-status.status-active {
    background: #e4f7ef;
    color: #167a57;
}
.campaign-status.status-draft {
    background: var(--ob-accent-tint);
    color: var(--ob-accent-strong);
}
.campaign-status.status-paused {
    background: #fff2db;
    color: #925f0d;
}
.campaign-status.status-completed {
    background: #e8eef7;
    color: #35527a;
}
.campaign-row-action {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 7px;
    color: var(--ob-accent-strong);
    font-size: 11px;
    font-weight: 620;
    text-align: right;
}
.campaign-row-action svg,
:deep(.top .btn svg) {
    width: 15px;
    height: 15px;
}
.campaign-empty {
    display: grid;
    min-height: 420px;
    place-content: center;
    justify-items: start;
}
.campaign-empty .btn {
    margin-top: 20px;
}
.campaign-create-dialog {
    max-width: 500px;
}
.campaign-dialog-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 22px;
}
.campaign-dialog-head h2 {
    margin: 0;
    color: #27231e;
    font-size: 20px;
}
.campaign-dialog-head button {
    display: grid;
    width: 40px;
    height: 40px;
    place-items: center;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: #736c63;
    cursor: pointer;
}
.campaign-dialog-head button:hover {
    background: #f2efe9;
}
.campaign-dialog-head svg {
    width: 18px;
    height: 18px;
}
.campaign-create-dialog > label {
    display: block;
    margin-bottom: 7px;
    color: #4e4942;
    font-size: 12px;
    font-weight: 620;
}
.campaign-create-dialog > input {
    width: 100%;
    min-height: 44px;
}
.campaign-form-help {
    margin: 10px 0 0;
    color: #817a70;
    font-size: 11px;
    line-height: 1.5;
}
.campaign-dialog-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 24px;
}
@media (max-width: 1100px) {
    .campaign-index {
        padding: 28px 26px 48px;
    }
    .campaign-list-head {
        display: none;
    }
    .campaign-row {
        grid-template-columns:
            minmax(200px, 1.5fr) repeat(3, minmax(82px, 0.7fr))
            minmax(112px, 0.8fr);
    }
    .campaign-row-metric:nth-of-type(5) {
        display: none;
    }
}
@media (max-width: 760px) {
    :global(.side-campaigns) {
        min-height: 0;
        padding: 58px 12px 10px;
    }
    :global(.side-campaigns .side-title) {
        position: absolute;
        top: 20px;
        left: 58px;
        margin: 0;
        color: #29251f;
        font-size: 13px;
        letter-spacing: normal;
        text-transform: none;
    }
    .campaign-side-nav {
        display: flex;
        gap: 6px;
        padding: 0;
        overflow-x: auto;
        scrollbar-width: none;
    }
    .campaign-side-nav::-webkit-scrollbar {
        display: none;
    }
    .campaign-side-nav button {
        min-width: max-content;
        min-height: 44px;
        gap: 10px;
        padding-inline: 14px;
    }
    .campaign-recent {
        display: none;
    }
    .campaign-index {
        padding: 24px 18px 40px;
    }
    .campaign-page-heading {
        align-items: flex-start;
        flex-direction: column;
    }
    .campaign-totals {
        flex-wrap: wrap;
    }
    .campaign-row {
        grid-template-columns: 1fr auto;
        gap: 12px;
        padding: 16px 0;
    }
    .campaign-row-metric {
        display: none;
    }
    .campaign-row-action {
        font-size: 0;
    }
    .campaign-row-action svg {
        width: 18px;
        height: 18px;
    }
}
</style>
