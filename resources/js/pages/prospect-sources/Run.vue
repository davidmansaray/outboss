<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import RegistryStatusPill from '@/components/people-accounts/RegistryStatusPill.vue';
import AppWorkspace from '@/layouts/AppWorkspace.vue';
import { updatedLabel } from '@/lib/peopleAccountsFormatters';
import type { ProspectSource, ProspectSourceRun } from '@/types';

type RunObservation = {
    id: string;
    row_number: number | null;
    entity_type: string;
    disposition: string;
    match_method: string | null;
    safe_error_code: string | null;
    safe_error_message: string | null;
    account_id: number | null;
    person_id: number | null;
    retrieved_at: string | null;
};

type RunDetail = ProspectSourceRun & {
    observations?: { data: RunObservation[]; meta?: Record<string, number> };
};

const props = defineProps<{ source: ProspectSource | null; run: RunDetail }>();
const page = usePage();
const isOwner = computed(() => page.props.auth?.workspace?.role === 'owner');
const commitForm = useForm({});
const retryForm = useForm({});

function commit(): void {
    if (!props.source) {
        return;
    }

    commitForm.post(
        `/prospect-sources/${props.source.id}/runs/${props.run.id}/commit`,
    );
}

function retry(): void {
    if (!props.source) {
        return;
    }

    retryForm.post(
        `/prospect-sources/${props.source.id}/runs/${props.run.id}/retry`,
    );
}
</script>

<template>
    <Head :title="`Source run ${run.id}`" />
    <AppWorkspace area="sources" title="Prospect source run">
        <main class="source-run-page">
            <Link
                class="back-link"
                :href="
                    source
                        ? `/prospect-sources/${source.id}`
                        : '/prospect-sources'
                "
                >← Source detail</Link
            >
            <header class="run-heading">
                <div>
                    <p>Prospect source run</p>
                    <h1>{{ run.id }}</h1>
                    <span>{{
                        updatedLabel(run.finished_at ?? run.created_at ?? null)
                    }}</span>
                </div>
                <RegistryStatusPill :label="run.state" />
            </header>

            <section class="run-funnel" aria-label="Run counters">
                <div>
                    <strong>{{ run.received }}</strong
                    ><span>received</span>
                </div>
                <div>
                    <strong>{{ run.valid }}</strong
                    ><span>valid</span>
                </div>
                <div>
                    <strong>{{ run.accepted }}</strong
                    ><span>accepted</span>
                </div>
                <div>
                    <strong>{{ run.created }}</strong
                    ><span>created</span>
                </div>
                <div>
                    <strong>{{ run.linked }}</strong
                    ><span>linked</span>
                </div>
                <div>
                    <strong>{{ run.suppressed }}</strong
                    ><span>suppressed</span>
                </div>
                <div>
                    <strong>{{ run.rejected }}</strong
                    ><span>rejected</span>
                </div>
            </section>

            <section v-if="isOwner" class="run-actions">
                <button
                    v-if="
                        ![
                            'completed',
                            'failed',
                            'cancelled',
                            'expired',
                        ].includes(run.state)
                    "
                    type="button"
                    @click="commit"
                    :disabled="commitForm.processing"
                >
                    Commit run
                </button>
                <button
                    v-if="['failed', 'partial'].includes(run.state)"
                    type="button"
                    class="secondary"
                    @click="retry"
                    :disabled="retryForm.processing"
                >
                    Retry run
                </button>
                <a
                    :href="`/prospect-sources/${source?.id}/runs/${run.id}/errors.csv`"
                    >Download errors</a
                >
            </section>
            <p v-else class="run-member-note" role="note">
                Members can inspect safe run results. Commit, retry, and error
                exports are owner-only.
            </p>

            <section class="run-detail-card">
                <h2>Observations</h2>
                <p v-if="!run.observations?.data?.length" class="empty-copy">
                    No observations recorded.
                </p>
                <table v-else>
                    <thead>
                        <tr>
                            <th>Row</th>
                            <th>Type</th>
                            <th>Disposition</th>
                            <th>Match</th>
                            <th>Safe error</th>
                            <th>Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="observation in run.observations.data"
                            :key="observation.id"
                        >
                            <td>{{ observation.row_number ?? '—' }}</td>
                            <td>{{ observation.entity_type }}</td>
                            <td>
                                <RegistryStatusPill
                                    :label="observation.disposition"
                                />
                            </td>
                            <td>{{ observation.match_method ?? '—' }}</td>
                            <td>
                                {{
                                    observation.safe_error_code ??
                                    observation.safe_error_message ??
                                    '—'
                                }}
                            </td>
                            <td>
                                {{ updatedLabel(observation.retrieved_at) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </main>
    </AppWorkspace>
</template>

<style scoped>
.source-run-page {
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

.run-heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin: 22px 0;
}

.run-heading p {
    margin: 0;
    color: #6f685f;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.run-heading h1 {
    max-width: 680px;
    margin: 4px 0 6px;
    overflow: hidden;
    color: var(--ob-ink);
    font-size: 25px;
    letter-spacing: -0.04em;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.run-heading span {
    color: #6b655c;
    font-size: 12px;
}

.run-funnel {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 8px;
    margin-bottom: 18px;
}

.run-funnel div,
.run-detail-card {
    border: 1px solid var(--ob-border-soft);
    background: var(--ob-surface);
}
.run-member-note {
    margin: 0 0 14px;
    border: 1px solid var(--ob-border-soft);
    padding: 10px 12px;
    color: #6b655c;
    font-size: 11px;
}

.run-funnel div {
    display: grid;
    gap: 4px;
    padding: 13px;
}
.run-funnel strong {
    color: var(--ob-ink);
    font-size: 20px;
}
.run-funnel span {
    color: #6b655c;
    font-size: 10px;
}

.run-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 14px;
}

.run-actions button {
    min-height: 34px;
    border: 1px solid #27231e;
    border-radius: 7px;
    padding: 0 12px;
    background: #27231e;
    color: #fff;
    font-size: 12px;
}

.run-actions button.secondary {
    background: transparent;
    color: #27231e;
}
.run-actions button:disabled {
    cursor: wait;
    opacity: 0.55;
}
.run-actions a {
    margin-left: auto;
    color: #5b554d;
    font-size: 12px;
}

.run-detail-card {
    padding: 17px;
}
.run-detail-card h2 {
    margin: 0 0 12px;
    color: #3a352e;
    font-size: 14px;
}
.run-detail-card table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}
.run-detail-card th,
.run-detail-card td {
    border-bottom: 1px solid var(--ob-border-soft);
    padding: 9px 7px;
    text-align: left;
}
.run-detail-card th {
    color: #6f685f;
    font-size: 10px;
    text-transform: uppercase;
}
.empty-copy {
    color: #6b655c;
    font-size: 12px;
}

@media (max-width: 820px) {
    .run-funnel {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
}

@media (max-width: 620px) {
    .source-run-page {
        padding: 18px 12px 28px;
    }
    .run-funnel {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
    .run-actions {
        align-items: flex-start;
        flex-wrap: wrap;
    }
    .run-actions a {
        width: 100%;
        margin-left: 0;
    }
}
</style>
