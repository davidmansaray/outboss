<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowRight, Play, RefreshCw, Target } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppWorkspace from '@/layouts/AppWorkspace.vue';

type ReadinessItem = { code: string; message: string };

type ObjectiveSummary = {
    href?: string | null;
    id: string;
    status: string;
    name: string;
    description: string | null;
    target: {
        kind: 'count' | 'value';
        count: number | null;
        value: string | null;
        currency: string | null;
    } | null;
    horizon: { starts_at: string; ends_at: string } | null;
    readiness: {
        ready: boolean;
        blockers: ReadinessItem[];
        warnings: ReadinessItem[];
    };
    experiment: {
        id: string;
        name: string;
        status: string;
        arms: number;
        assignments: number;
    } | null;
    latest_run: {
        id: string;
        number: number;
        state: string;
        phase: string;
        updated_at: string | null;
    } | null;
    plan: {
        id: string;
        version: number;
        status: string;
        actions: number;
        href: string;
    } | null;
    start_blockers: ReadinessItem[];
    attention_blockers: ReadinessItem[];
    execution_underway: boolean;
    can_start_run: boolean;
    updated_at: string | null;
};

const props = defineProps<{
    is_owner: boolean;
    objectives: ObjectiveSummary[];
    focus?: string;
    errors?: Record<string, string | string[]>;
}>();

type Filter = 'all' | 'ready' | 'attention' | 'running';

const filter = ref<Filter>('all');
const pendingObjective = ref<string | null>(null);
const refreshing = ref(false);
const runKeys = new Map<string, string>();

const filters: { id: Filter; label: string }[] = [
    { id: 'all', label: 'All objectives' },
    { id: 'ready', label: 'Ready' },
    { id: 'attention', label: 'Needs attention' },
    { id: 'running', label: 'In progress' },
];

const activeRunStates = new Set([
    'planned',
    'running',
    'waiting',
    'needs_review',
    'paused',
]);

const filteredObjectives = computed(() =>
    props.objectives.filter((objective) => {
        if (filter.value === 'ready') {
            return objective.can_start_run;
        }

        if (filter.value === 'attention') {
            return objective.attention_blockers.length > 0;
        }

        if (filter.value === 'running') {
            return (
                objective.latest_run !== null &&
                activeRunStates.has(objective.latest_run.state)
            );
        }

        return true;
    }),
);

const runError = computed(() => {
    const error = props.errors?.run ?? props.errors?.preflight;

    return Array.isArray(error) ? error.join(' ') : (error ?? null);
});

function label(value: string): string {
    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function target(objective: ObjectiveSummary): string {
    if (objective.href) {
        return 'Meeting booked';
    }

    if (!objective.target) {
        return 'Not configured';
    }

    if (objective.target.kind === 'count') {
        const count = objective.target.count ?? 0;

        return count + ' conversion' + (count === 1 ? '' : 's');
    }

    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: objective.target.currency ?? 'USD',
        maximumFractionDigits: 2,
    }).format(Number(objective.target.value ?? 0));
}

function date(value: string | null | undefined): string {
    if (!value) {
        return 'Not set';
    }

    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(new Date(value));
}

function filterCount(id: Filter): number {
    if (id === 'all') {
        return props.objectives.length;
    }

    if (id === 'ready') {
        return props.objectives.filter((objective) => objective.can_start_run)
            .length;
    }

    if (id === 'attention') {
        return props.objectives.filter(
            (objective) => objective.attention_blockers.length > 0,
        ).length;
    }

    return props.objectives.filter(
        (objective) =>
            objective.latest_run !== null &&
            activeRunStates.has(objective.latest_run.state),
    ).length;
}

function runKey(objectiveId: string): string {
    const current = runKeys.get(objectiveId);

    if (current) {
        return current;
    }

    const created = crypto.randomUUID();
    runKeys.set(objectiveId, created);

    return created;
}

function startRun(objective: ObjectiveSummary): void {
    if (!objective.can_start_run || !objective.experiment) {
        return;
    }

    const idempotencyKey = runKey(objective.id);
    pendingObjective.value = objective.id;
    router.post(
        '/objectives/' + objective.id + '/runs',
        {
            gtm_experiment_id: objective.experiment.id,
            idempotency_key: idempotencyKey,
        },
        {
            preserveScroll: true,
            headers: { 'Idempotency-Key': idempotencyKey },
            onSuccess: () => {
                runKeys.delete(objective.id);
            },
            onFinish: () => {
                pendingObjective.value = null;
            },
        },
    );
}

function refresh(): void {
    refreshing.value = true;
    router.reload({
        only: ['objectives'],
        onFinish: () => {
            refreshing.value = false;
        },
    });
}
</script>

<template>
    <Head title="Objectives" />
    <AppWorkspace area="objectives" title="Objectives">
        <template #sidebar>
            <nav class="objective-filters" aria-label="Objective views">
                <button
                    v-for="option in filters"
                    :key="option.id"
                    type="button"
                    :class="{ active: filter === option.id }"
                    :aria-current="filter === option.id ? 'page' : undefined"
                    @click="filter = option.id"
                >
                    <span>{{ option.label }}</span>
                    <small>{{ filterCount(option.id) }}</small>
                </button>
            </nav>
        </template>

        <template #actions>
            <Link
                v-if="is_owner"
                class="refresh-button"
                href="/objectives/create"
                >+ New objective</Link
            >
            <button class="refresh-button" type="button" @click="refresh">
                <RefreshCw
                    :class="{ spinning: refreshing }"
                    aria-hidden="true"
                />
                Refresh status
            </button>
        </template>

        <main class="objective-index" data-test="objective-hub">
            <header class="objective-heading">
                <div>
                    <p class="eyebrow">Strategy / Objectives</p>
                    <h1>Objectives</h1>
                    <p>
                        Track the outcomes you’re working toward and review the
                        plans that support them.
                    </p>
                </div>
                <dl v-if="objectives.length" class="objective-totals">
                    <div>
                        <dt>Ready</dt>
                        <dd>{{ filterCount('ready') }}</dd>
                    </div>
                    <div>
                        <dt>In progress</dt>
                        <dd>{{ filterCount('running') }}</dd>
                    </div>
                    <div>
                        <dt>Needs attention</dt>
                        <dd>{{ filterCount('attention') }}</dd>
                    </div>
                </dl>
            </header>

            <div v-if="runError" class="run-error" role="alert">
                <strong>Plan generation did not start</strong>
                <span>{{ runError }}</span>
            </div>

            <section v-if="filteredObjectives.length" class="objective-list">
                <div class="objective-list-head" aria-hidden="true">
                    <span>Objective</span>
                    <span>Readiness</span>
                    <span>Experiment</span>
                    <span>Run</span>
                    <span>Plan</span>
                    <span />
                </div>

                <article
                    v-for="objective in filteredObjectives"
                    :id="'objective-' + objective.id"
                    :key="objective.id"
                    class="objective-row"
                    :class="{ focused: focus === objective.id }"
                >
                    <div class="objective-primary">
                        <span class="objective-title-line">
                            <Target aria-hidden="true" />
                            <Link v-if="objective.href" :href="objective.href"
                                ><strong>{{ objective.name }}</strong></Link
                            >
                            <strong v-else>{{ objective.name }}</strong>
                        </span>
                        <span>{{ target(objective) }}</span>
                        <small v-if="objective.horizon">
                            {{ date(objective.horizon.starts_at) }}–{{
                                date(objective.horizon.ends_at)
                            }}
                        </small>
                    </div>

                    <div class="objective-cell readiness-cell">
                        <small>Readiness</small>
                        <strong
                            :class="
                                objective.attention_blockers.length
                                    ? 'state-attention'
                                    : objective.execution_underway
                                      ? 'state-running'
                                      : 'state-ready'
                            "
                        >
                            {{
                                objective.attention_blockers.length
                                    ? objective.attention_blockers.length +
                                      ' blocker' +
                                      (objective.attention_blockers.length === 1
                                          ? ''
                                          : 's')
                                    : objective.execution_underway
                                      ? 'Execution underway'
                                      : 'Ready'
                            }}
                        </strong>
                        <span v-if="objective.attention_blockers[0]">
                            {{ objective.attention_blockers[0].message }}
                        </span>
                        <span v-else-if="objective.execution_underway">
                            Run {{ objective.latest_run?.number }} ·
                            {{
                                label(objective.latest_run?.phase ?? 'running')
                            }}
                        </span>
                        <span v-else>Exact inputs are current.</span>
                    </div>

                    <div class="objective-cell">
                        <small>Experiment</small>
                        <strong>{{
                            objective.experiment?.name ?? 'Not set'
                        }}</strong>
                        <span v-if="objective.experiment">
                            {{ objective.experiment.arms }} arm{{
                                objective.experiment.arms === 1 ? '' : 's'
                            }}
                            · {{ label(objective.experiment.status) }}
                        </span>
                        <span v-else>Frozen assignment required</span>
                    </div>

                    <div class="objective-cell">
                        <small>Latest run</small>
                        <strong v-if="objective.latest_run">
                            Run {{ objective.latest_run.number }} ·
                            {{ label(objective.latest_run.state) }}
                        </strong>
                        <strong v-else>Not started</strong>
                        <span v-if="objective.latest_run">
                            {{ label(objective.latest_run.phase) }}
                        </span>
                        <span v-else>No drafting work yet</span>
                    </div>

                    <div class="objective-cell">
                        <small>Current plan</small>
                        <Link v-if="objective.plan" :href="objective.plan.href">
                            Version {{ objective.plan.version }} ·
                            {{ label(objective.plan.status) }}
                        </Link>
                        <span v-if="objective.plan">
                            {{ objective.plan.actions }} exact action{{
                                objective.plan.actions === 1 ? '' : 's'
                            }}
                        </span>
                        <strong v-else>No plan yet</strong>
                    </div>

                    <div class="objective-action">
                        <Link
                            v-if="objective.plan"
                            :href="objective.plan.href"
                            class="review-link"
                        >
                            Review plan
                            <ArrowRight aria-hidden="true" />
                        </Link>
                        <button
                            v-else-if="objective.can_start_run"
                            type="button"
                            :disabled="pendingObjective !== null"
                            @click="startRun(objective)"
                        >
                            <Play aria-hidden="true" />
                            {{
                                pendingObjective === objective.id
                                    ? 'Starting…'
                                    : 'Generate plan'
                            }}
                        </button>
                        <span v-else-if="!is_owner" class="owner-note">
                            Owner action
                        </span>
                        <span v-else class="owner-note">Resolve blockers</span>
                    </div>
                </article>
            </section>

            <section v-else class="empty-state">
                <Target aria-hidden="true" />
                <template v-if="objectives.length">
                    <h2>No objectives in this view</h2>
                    <p>Choose another status filter.</p>
                </template>
                <template v-else>
                    <h2>No Objectives configured yet</h2>
                    <p>
                        Create an Objective to define the outcome and what
                        counts as success. Offers and audiences are selected
                        separately when preparing outreach.
                    </p>
                </template>
            </section>
        </main>
    </AppWorkspace>
</template>

<style scoped>
.objective-index {
    flex: 1;
    min-height: 0;
    overflow: auto;
    background: var(--ob-surface);
    padding: 34px clamp(24px, 4vw, 64px) 72px;
}

.objective-heading {
    display: flex;
    max-width: 1240px;
    align-items: flex-end;
    justify-content: space-between;
    gap: 32px;
    margin: 0 auto 34px;
}

.eyebrow {
    margin: 0;
    color: #756f66;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.objective-heading h1 {
    margin: 5px 0 8px;
    color: var(--ob-ink);
    font-size: clamp(30px, 3vw, 44px);
    font-weight: 650;
    letter-spacing: -0.04em;
    line-height: 1;
}

.objective-heading > div > p:last-child {
    max-width: 620px;
    margin: 0;
    color: var(--ob-muted);
    font-size: 13px;
    line-height: 1.6;
}

.objective-totals {
    display: flex;
    gap: 26px;
    margin: 0;
}

.objective-totals div {
    min-width: 72px;
    border-left: 1px solid var(--ob-border-strong);
    padding-left: 14px;
}

dt,
.objective-cell small,
.objective-list-head {
    color: #777168;
    font-size: 9px;
    font-weight: 680;
    letter-spacing: 0.09em;
    text-transform: uppercase;
}

.objective-totals dd {
    margin: 3px 0 0;
    font-size: 22px;
    font-weight: 650;
}

.objective-list {
    max-width: 1240px;
    margin: 0 auto;
    border-top: 1px solid var(--ob-border-strong);
}

.objective-list-head,
.objective-row {
    display: grid;
    grid-template-columns:
        minmax(220px, 1.35fr) minmax(180px, 1fr) minmax(150px, 0.9fr)
        minmax(150px, 0.9fr) minmax(150px, 0.8fr) minmax(120px, auto);
    gap: 22px;
    align-items: center;
}

.objective-list-head {
    padding: 13px 16px;
}

.objective-row {
    position: relative;
    border-top: 1px solid var(--ob-border);
    padding: 22px 16px;
    transition:
        background 150ms ease,
        transform 150ms ease;
}

.objective-row:hover,
.objective-row:focus-within,
.objective-row.focused {
    z-index: 1;
    background: #faf8f4;
}

.objective-row.focused {
    box-shadow: inset 3px 0 #8b773e;
}

.objective-primary,
.objective-cell {
    display: grid;
    min-width: 0;
    gap: 4px;
}

.objective-title-line {
    display: flex;
    align-items: center;
    gap: 8px;
}

.objective-title-line svg {
    width: 15px;
    color: #8b773e;
}

.objective-primary strong,
.objective-cell strong,
.objective-cell a {
    overflow: hidden;
    color: var(--ob-ink);
    font-size: 12px;
    font-weight: 650;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.objective-primary > span:not(.objective-title-line),
.objective-primary small,
.objective-cell span {
    overflow: hidden;
    color: var(--ob-muted);
    font-size: 10px;
    line-height: 1.45;
    text-overflow: ellipsis;
}

.objective-cell a:hover {
    color: #6f5b25;
}

.readiness-cell .state-ready {
    color: #39613b;
}

.readiness-cell .state-running {
    color: #315a78;
}

.readiness-cell .state-attention {
    color: #8a4a3b;
}

.objective-action {
    display: flex;
    justify-content: flex-end;
}

.objective-action button,
.review-link,
.refresh-button {
    display: inline-flex;
    min-height: 36px;
    align-items: center;
    justify-content: center;
    gap: 7px;
    border: 1px solid #22211e;
    border-radius: 7px;
    background: #22211e;
    color: #fff;
    padding: 8px 11px;
    font-size: 11px;
    font-weight: 650;
    text-decoration: none;
    cursor: pointer;
    transition:
        background 140ms ease,
        color 140ms ease,
        transform 140ms ease;
}

.objective-action button:hover,
.review-link:hover,
.refresh-button:hover {
    transform: translateY(-1px);
}

.objective-action button:disabled {
    cursor: not-allowed;
    opacity: 0.55;
    transform: none;
}

.objective-action svg,
.refresh-button svg {
    width: 13px;
}

.refresh-button {
    border-color: var(--ob-border-strong);
    background: #fff;
    color: var(--ob-ink);
}

.spinning {
    animation: spin 800ms linear infinite;
}

.owner-note {
    color: var(--ob-muted);
    font-size: 10px;
}

.objective-filters {
    display: grid;
    gap: 2px;
}

.objective-filters button {
    display: flex;
    min-height: 38px;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    border: 0;
    border-radius: 7px;
    background: transparent;
    color: #5e5952;
    padding: 0 10px;
    font-size: 12px;
    text-align: left;
    cursor: pointer;
}

.objective-filters button:hover,
.objective-filters button.active {
    background: #f0ede7;
    color: var(--ob-ink);
}

.objective-filters small {
    color: #8a847a;
    font-size: 10px;
}

.run-error {
    display: grid;
    max-width: 1240px;
    gap: 3px;
    margin: 0 auto 22px;
    border: 1px solid #e4bbb4;
    border-radius: 8px;
    background: #fff5f3;
    color: #79392f;
    padding: 13px 15px;
    font-size: 12px;
}

.empty-state {
    display: grid;
    max-width: 620px;
    justify-items: start;
    gap: 8px;
    margin: 90px auto;
    color: var(--ob-muted);
}

.empty-state svg {
    width: 30px;
    color: #8b773e;
}

.empty-state h2,
.empty-state p {
    margin: 0;
}

.empty-state h2 {
    color: var(--ob-ink);
    font-size: 18px;
}

.empty-state p {
    max-width: 520px;
    font-size: 12px;
    line-height: 1.6;
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

@media (prefers-reduced-motion: reduce) {
    .objective-row,
    .objective-action button,
    .review-link,
    .refresh-button {
        transition: none;
    }

    .spinning {
        animation: none;
    }
}

@media (max-width: 1120px) {
    .objective-list-head {
        display: none;
    }

    .objective-row {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .objective-action {
        justify-content: flex-start;
    }
}

@media (max-width: 720px) {
    .objective-index {
        padding: 24px 18px 56px;
    }

    .objective-heading {
        align-items: flex-start;
        flex-direction: column;
    }

    .objective-totals {
        width: 100%;
        justify-content: space-between;
    }

    .objective-row {
        grid-template-columns: 1fr;
        gap: 16px;
        padding-inline: 8px;
    }
}
</style>
