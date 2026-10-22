<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { computed } from 'vue';
import WorkspaceOpsSidebar from '@/components/WorkspaceOpsSidebar.vue';
import AppWorkspace from '@/layouts/AppWorkspace.vue';

const props = withDefaults(
    defineProps<{
        suppressionsCount?: number;
        isOwner?: boolean;
    }>(),
    { suppressionsCount: 0, isOwner: true },
);

const allExports = [
    {
        key: 'accounts',
        href: '/accounts/export',
        filename: 'accounts.csv',
        title: 'Accounts',
        description:
            'Workspace companies: name, website, domain, notes, tags, source, and people count.',
    },
    {
        key: 'people',
        href: '/people/export',
        filename: 'people.csv',
        title: 'People',
        description:
            'Workspace contacts: name, email, title, account, notes, tags, eligibility, and campaign count.',
    },
    {
        key: 'sources',
        href: '/exports/sources',
        filename: 'prospect-sources.csv',
        title: 'Prospect sources',
        description:
            'Policy-safe source, version, run, membership, and observation summaries without raw provider payloads.',
        ownerOnly: true,
    },
    {
        key: 'integrations',
        href: '/exports/integrations',
        filename: 'integrations.csv',
        title: 'Integration operations',
        description:
            'Provider health, registration, operation, and cost totals without credentials or request bodies.',
        ownerOnly: true,
    },
    {
        key: 'enrollments',
        href: '/exports/enrollments',
        filename: 'enrollments.csv',
        title: 'Enrolment snapshots',
        description:
            'One row per campaign recipient, using the identity stored at enrolment — not the live registry.',
    },
    {
        key: 'suppressions',
        href: '/exports/suppressions',
        filename: 'suppressions.csv',
        title: 'Suppressions',
        description:
            'Addresses that must not be enrolled, with reason and when they were added.',
    },
    {
        key: 'campaign-outcomes',
        href: '/exports/campaign-outcomes',
        filename: 'campaign-outcomes.csv',
        title: 'Campaign outcomes',
        description:
            'Per-campaign totals for queued, sent, replied, interested, bounced, unsubscribed, and needs review.',
    },
] as const;
const exports = computed(() =>
    allExports.filter((item) => !('ownerOnly' in item) || props.isOwner),
);
</script>

<template>
    <Head title="Exports" />
    <AppWorkspace area="data" title="Exports" :show-sidebar-title="false">
        <template #sidebar>
            <WorkspaceOpsSidebar current="data" />
        </template>
        <main class="export-index">
            <header class="export-page-heading">
                <div>
                    <h1>Exports</h1>
                    <p>
                        Download canonical records, source lineage, integration
                        operations, enrolment snapshots, suppressions, and
                        campaign outcomes as CSV. Column definitions live in
                        docs/export-formats.md.
                    </p>
                </div>
                <div class="export-totals">
                    <span
                        ><strong>{{ exports.length }}</strong> files</span
                    >
                    <span
                        ><strong>{{ props.suppressionsCount }}</strong>
                        suppressions</span
                    >
                </div>
            </header>

            <p class="export-note" data-test="export-note">
                Timestamps are ISO 8601 UTC. Formula characters are escaped so
                spreadsheet apps treat cells as data.
            </p>

            <section class="export-list" aria-label="Workspace CSV downloads">
                <div class="export-list-head" aria-hidden="true">
                    <span>File</span>
                    <span>Contains</span>
                    <span />
                </div>
                <a
                    v-for="item in exports"
                    :key="item.key"
                    :href="item.href"
                    class="export-row"
                    :data-test="`export-${item.key}`"
                >
                    <span class="export-row-primary">
                        <strong>{{ item.title }}</strong>
                        <small>{{ item.filename }}</small>
                    </span>
                    <span class="export-row-copy">{{ item.description }}</span>
                    <span class="export-row-action">
                        Download
                        <ArrowRight aria-hidden="true" />
                    </span>
                </a>
            </section>
        </main>
    </AppWorkspace>
</template>

<style scoped>
.export-index {
    min-width: 0;
    padding: 36px 40px 64px;
}

.export-page-heading {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 32px;
    padding-bottom: 28px;
    border-bottom: 1px solid var(--ob-border);
}

.export-eyebrow {
    margin: 0 0 7px;
    color: var(--ob-focus);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.export-page-heading h1 {
    margin: 0;
    color: #1f1c18;
    font-size: 28px;
    font-weight: 650;
    letter-spacing: -0.03em;
}

.export-page-heading > div:first-child > p:last-child {
    max-width: 620px;
    margin: 8px 0 0;
    color: #736c63;
    font-size: 13px;
    line-height: 1.55;
}

.export-totals {
    display: flex;
    gap: 22px;
    color: #736c63;
    font-size: 12px;
    white-space: nowrap;
}

.export-totals strong {
    color: #27231e;
    font-size: 15px;
}

.export-note {
    margin: 18px 0 8px;
    color: #8a8379;
    font-size: 12px;
    line-height: 1.5;
}

.export-list-head,
.export-row {
    display: grid;
    grid-template-columns: minmax(160px, 0.9fr) minmax(220px, 1.6fr) minmax(
            110px,
            0.55fr
        );
    align-items: center;
    gap: 16px;
}

.export-list-head {
    min-height: 46px;
    border-bottom: 1px solid var(--ob-border);
    color: #8a8379;
    font-size: 10px;
    font-weight: 650;
    letter-spacing: 0.07em;
    text-transform: uppercase;
}

.export-row {
    min-height: 86px;
    border-bottom: 1px solid var(--ob-border-soft);
    color: inherit;
    text-decoration: none;
    transition: background 120ms ease;
}

.export-row:hover,
.export-row:focus-visible {
    background: #faf8f4;
}

.export-row-primary,
.export-row-copy {
    min-width: 0;
}

.export-row-primary {
    display: grid;
    gap: 4px;
}

.export-row-primary strong {
    overflow: hidden;
    color: #27231e;
    font-size: 14px;
    font-weight: 640;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.export-row-primary small,
.export-row-copy {
    color: #736c63;
    font-size: 12px;
    line-height: 1.45;
}

.export-row-action {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 7px;
    color: var(--ob-accent-strong);
    font-size: 11px;
    font-weight: 620;
}

.export-row-action svg {
    width: 15px;
    height: 15px;
}

:global(.side-data .side-title) {
    color: #5f5951;
    font-weight: 600;
}

:global(.top .crumb) {
    display: flex;
    align-items: center;
    gap: 7px;
    color: #6b655c;
    font-size: 12px;
}

:global(.top .crumb strong) {
    color: #1a1814;
    font-weight: 600;
}

@media (max-width: 900px) {
    :global(.side-data) {
        position: relative;
        min-height: 0;
        padding: 66px 12px 9px;
    }

    :global(.side-data .side-title) {
        position: absolute;
        top: 20px;
        left: 58px;
        margin: 0;
        color: #29251f;
        font-size: 13px;
        font-weight: 650;
        letter-spacing: -0.01em;
        text-transform: none;
    }

    .export-index {
        padding: 24px 18px 40px;
    }

    .export-totals {
        display: none;
    }
}

@media (max-width: 760px) {
    .export-page-heading {
        align-items: flex-start;
        flex-direction: column;
    }

    .export-list-head {
        display: none;
    }

    .export-row {
        grid-template-columns: 1fr auto;
        gap: 12px;
        padding: 16px 0;
    }

    .export-row-copy {
        display: none;
    }

    .export-row-action {
        font-size: 0;
    }

    .export-row-action svg {
        width: 18px;
        height: 18px;
    }
}
</style>
