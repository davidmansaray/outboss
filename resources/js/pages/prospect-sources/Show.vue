<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import RegistryStatusPill from '@/components/people-accounts/RegistryStatusPill.vue';
import AppWorkspace from '@/layouts/AppWorkspace.vue';
import { updatedLabel } from '@/lib/peopleAccountsFormatters';
import type { ProspectSource, ProspectSourceRun } from '@/types';

type SourceDetail = ProspectSource & {
    versions?: {
        id: string;
        version: number;
        origin: string;
        created_at: string | null;
    }[];
    runs?: ProspectSourceRun[];
};

defineProps<{ source: SourceDetail }>();
const page = usePage();
const isOwner = computed(() => page.props.auth?.workspace?.role === 'owner');
const confirmingArchive = ref(false);
const confirmingDelete = ref(false);
const busy = ref(false);

function archive(sourceId: string): void {
    if (!isOwner.value || busy.value) {
        return;
    }

    busy.value = true;
    router.post(
        `/prospect-sources/${sourceId}/archive`,
        {},
        { onFinish: () => (busy.value = false) },
    );
}

function deleteData(sourceId: string): void {
    if (!isOwner.value || busy.value) {
        return;
    }

    busy.value = true;
    router.delete(`/prospect-sources/${sourceId}/data`, {
        data: { delete_canonical: false },
        onSuccess: () => (confirmingDelete.value = false),
        onFinish: () => (busy.value = false),
    });
}

function kindLabel(kind: string): string {
    return kind
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}
</script>

<template>
    <Head :title="source.display_name" />
    <AppWorkspace area="sources" title="Prospect source">
        <main class="source-detail-page">
            <Link class="back-link" href="/prospect-sources"
                >← All sources</Link
            >
            <header class="source-detail-heading">
                <div>
                    <p>Prospect source</p>
                    <h1>{{ source.display_name }}</h1>
                    <span>{{ kindLabel(source.kind) }}</span>
                </div>
                <RegistryStatusPill :label="source.status" />
            </header>

            <section v-if="isOwner" class="source-detail-actions">
                <a :href="`/prospect-sources/${source.id}/export`"
                    >Export allowed data</a
                >
                <button
                    v-if="source.status !== 'archived' && !confirmingArchive"
                    type="button"
                    @click="confirmingArchive = true"
                >
                    Archive
                </button>
                <template v-else-if="confirmingArchive">
                    <span>Stop all new runs?</span>
                    <button
                        type="button"
                        :disabled="busy"
                        @click="archive(source.id)"
                    >
                        Confirm archive
                    </button>
                    <button
                        type="button"
                        :disabled="busy"
                        @click="confirmingArchive = false"
                    >
                        Cancel
                    </button>
                </template>
                <button
                    v-if="!confirmingDelete"
                    type="button"
                    class="danger"
                    @click="confirmingDelete = true"
                >
                    Delete source data
                </button>
                <template v-else>
                    <span>Queue policy-aware deletion?</span>
                    <button
                        type="button"
                        class="danger"
                        :disabled="busy"
                        @click="deleteData(source.id)"
                    >
                        Confirm deletion
                    </button>
                    <button
                        type="button"
                        :disabled="busy"
                        @click="confirmingDelete = false"
                    >
                        Cancel
                    </button>
                </template>
            </section>
            <p v-else class="source-member-note" role="note">
                This source is read-only for members. Ask a workspace owner to
                archive, export, or delete its data.
            </p>

            <section class="source-funnel" aria-label="Source yield">
                <div>
                    <strong>{{ source.run_count ?? 0 }}</strong
                    ><span>runs</span>
                </div>
                <div>
                    <strong>{{ source.accepted_count ?? 0 }}</strong
                    ><span>accepted</span>
                </div>
                <div>
                    <strong>{{ source.created_count ?? 0 }}</strong
                    ><span>created</span>
                </div>
                <div>
                    <strong>{{ source.linked_count ?? 0 }}</strong
                    ><span>linked</span>
                </div>
                <div>
                    <strong>{{ source.suppressed_count ?? 0 }}</strong
                    ><span>suppressed</span>
                </div>
                <div>
                    <strong>{{ source.rejected_count ?? 0 }}</strong
                    ><span>rejected</span>
                </div>
            </section>

            <section
                v-if="source.restrictions?.length || source.retention_warning"
                class="source-detail-card warning"
            >
                <h2>Policy and retention</h2>
                <p v-if="source.retention_warning">
                    {{ source.retention_warning }}
                </p>
                <p v-if="source.restrictions?.length">
                    {{ source.restrictions.join(' · ') }}
                </p>
            </section>

            <section class="source-detail-card">
                <h2>Run history</h2>
                <p v-if="!source.runs?.length" class="empty-copy">
                    No runs yet.
                </p>
                <table v-else>
                    <thead>
                        <tr>
                            <th>Run</th>
                            <th>State</th>
                            <th>Accepted</th>
                            <th>Created</th>
                            <th>Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="run in source.runs" :key="run.id">
                            <td>
                                <Link
                                    :href="`/prospect-sources/${source.id}/runs/${run.id}`"
                                    >{{ run.id }}</Link
                                >
                            </td>
                            <td><RegistryStatusPill :label="run.state" /></td>
                            <td>{{ run.accepted }}</td>
                            <td>{{ run.created }}</td>
                            <td>
                                {{
                                    updatedLabel(
                                        run.finished_at ??
                                            run.created_at ??
                                            null,
                                    )
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </main>
    </AppWorkspace>
</template>

<style scoped>
.source-detail-page {
    flex: 1;
    min-height: 0;
    overflow: auto;
    padding: 24px 26px 36px;
    background: var(--ob-surface);
}

.back-link {
    color: #6b655c;
    font-size: 12px;
}

.source-detail-heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin: 22px 0;
}

.source-detail-heading p {
    margin: 0;
    color: #6f685f;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.source-detail-heading h1 {
    margin: 4px 0 6px;
    color: var(--ob-ink);
    font-size: 32px;
    letter-spacing: -0.04em;
}

.source-detail-heading span {
    color: #6b655c;
}

.source-funnel {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 8px;
    margin-bottom: 18px;
}

.source-detail-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-bottom: 18px;
}

.source-detail-actions a,
.source-detail-actions button {
    min-height: 34px;
    border: 1px solid var(--ob-border);
    border-radius: 7px;
    padding: 7px 11px;
    background: #fff;
    color: #4a453e;
    font-size: 11px;
    text-decoration: none;
}

.source-detail-actions .danger {
    border-color: #d8a29d;
    color: #9b352f;
}

.source-detail-actions span,
.source-member-note {
    color: #6b655c;
    font-size: 11px;
}

.source-member-note {
    margin: 0 0 18px;
    border: 1px solid var(--ob-border-soft);
    padding: 10px 12px;
}

.source-funnel div,
.source-detail-card {
    border: 1px solid var(--ob-border-soft);
    background: var(--ob-surface);
}

.source-funnel div {
    display: grid;
    gap: 4px;
    padding: 13px;
}

.source-funnel strong {
    color: var(--ob-ink);
    font-size: 20px;
}

.source-funnel span {
    color: #6b655c;
    font-size: 10px;
}

.source-detail-card {
    margin-top: 14px;
    padding: 17px;
}

.source-detail-card h2 {
    margin: 0 0 12px;
    color: #3a352e;
    font-size: 14px;
}

.source-detail-card p {
    margin: 0;
    color: #6b655c;
    font-size: 12px;
}

.source-detail-card.warning {
    border-color: #e3c4a5;
    background: #fff9f1;
}

.source-detail-card.warning p {
    color: #8b4c22;
}

.source-detail-card table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}

.source-detail-card th,
.source-detail-card td {
    border-bottom: 1px solid var(--ob-border-soft);
    padding: 9px 7px;
    text-align: left;
}

.source-detail-card th {
    color: #6f685f;
    font-size: 10px;
    text-transform: uppercase;
}

.source-detail-card td a {
    color: #504a43;
}

.empty-copy {
    padding: 18px 0;
}

@media (max-width: 760px) {
    .source-detail-page {
        padding: 18px 12px 28px;
    }

    .source-funnel {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}
</style>
