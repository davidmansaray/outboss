<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import WorkspaceOpsSidebar from '@/components/WorkspaceOpsSidebar.vue';
import AppWorkspace from '@/layouts/AppWorkspace.vue';

type Experiment = {
    id: number;
    name: string;
    hypothesis: string;
    status: string;
    primary_metric: string;
    target_assignments_per_cohort: number | null;
    frozen_at: string | null;
    cohort_count: number;
    assignment_count: number;
};

const props = defineProps<{
    experiments: Experiment[];
    canManage: boolean;
}>();

const page = usePage();
const flash = computed(
    () =>
        (page.props.flash as
            { success?: string; error?: string } | undefined) ?? {},
);
const form = reactive({
    name: '',
    hypothesis: '',
    target_assignments_per_cohort: '',
});

function createExperiment() {
    if (!props.canManage || !form.name.trim() || !form.hypothesis.trim()) {
        return;
    }

    router.post('/settings/mail-experiments', form, {
        preserveScroll: true,
        onSuccess: () => {
            form.name = '';
            form.hypothesis = '';
            form.target_assignments_per_cohort = '';
        },
    });
}

function label(value: string): string {
    return value.replaceAll('_', ' ');
}
</script>

<template>
    <Head title="Mail experiments (internal)" />
    <AppWorkspace
        area="mail-experiments"
        title="Mail experiments (internal)"
        :show-sidebar-title="false"
    >
        <template #sidebar>
            <WorkspaceOpsSidebar
                current="mail-experiments"
                :show-internal="canManage"
            />
        </template>
        <div class="pane experiment-page" data-test="mail-experiments-page">
            <header class="experiment-head">
                <div>
                    <p class="eyebrow">Internal operator surface</p>
                    <h1>Mail experiments</h1>
                    <p class="hint">
                        Prepare deterministic, provider-stratified cohorts and
                        freeze their evidence before launch.
                    </p>
                </div>
                <a class="btn ghost" href="/settings/managed-mail"
                    >Managed mail</a
                >
            </header>

            <p v-if="flash.success" class="hint" data-test="flash-success">
                {{ flash.success }}
            </p>
            <p v-if="flash.error" class="bad" data-test="flash-error">
                {{ flash.error }}
            </p>

            <section
                v-if="canManage"
                class="box"
                aria-labelledby="create-experiment-title"
            >
                <h2 id="create-experiment-title">Create a draft</h2>
                <form
                    class="experiment-form"
                    @submit.prevent="createExperiment"
                >
                    <label>
                        Name
                        <input
                            v-model="form.name"
                            name="name"
                            placeholder="Domain strategy pilot"
                            required
                        />
                    </label>
                    <label class="wide">
                        Hypothesis
                        <textarea
                            v-model="form.hypothesis"
                            name="hypothesis"
                            rows="2"
                            placeholder="State the comparison you intend to test."
                            required
                        />
                    </label>
                    <label>
                        Target per cohort
                        <input
                            v-model="form.target_assignments_per_cohort"
                            name="target_assignments_per_cohort"
                            type="number"
                            min="1"
                            placeholder="Optional"
                        />
                    </label>
                    <button class="btn" type="submit">Create draft</button>
                </form>
            </section>

            <p v-if="experiments.length === 0" class="empty">
                No mail experiments yet.
            </p>
            <div v-else class="experiment-list">
                <article
                    v-for="experiment in experiments"
                    :key="experiment.id"
                    class="box experiment-row"
                >
                    <div>
                        <h2>
                            <Link
                                :href="`/settings/mail-experiments/${experiment.id}`"
                                >{{ experiment.name }}</Link
                            >
                        </h2>
                        <p class="hint">{{ experiment.hypothesis }}</p>
                        <p class="meta">
                            {{ experiment.cohort_count }} cohorts ·
                            {{ experiment.assignment_count }} assignments ·
                            {{ experiment.primary_metric.replaceAll('_', ' ') }}
                        </p>
                    </div>
                    <div class="row-status">
                        <span
                            class="status"
                            :class="`status-${experiment.status}`"
                            >{{ label(experiment.status) }}</span
                        >
                        <Link
                            class="btn ghost"
                            :href="`/settings/mail-experiments/${experiment.id}`"
                            >Open</Link
                        >
                    </div>
                </article>
            </div>
        </div>
    </AppWorkspace>
</template>

<style scoped>
.experiment-page {
    display: grid;
    gap: 18px;
    padding: 26px;
}

.experiment-head,
.experiment-row,
.row-status {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
}

.experiment-head h1,
.experiment-row h2,
.box h2 {
    margin: 0;
}

.experiment-row h2 a {
    color: inherit;
}

.eyebrow {
    margin: 0 0 4px;
    color: var(--ob-accent);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
}

.experiment-form {
    display: flex;
    align-items: end;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 14px;
}

.experiment-form label {
    display: grid;
    min-width: 190px;
    gap: 5px;
    color: #4e4942;
    font-size: 12px;
    font-weight: 700;
}

.experiment-form label.wide {
    flex: 1 1 320px;
}

.experiment-form input,
.experiment-form textarea {
    border: 1px solid #d7d1c8;
    border-radius: 7px;
    padding: 9px 10px;
    background: #fff;
    color: #25221f;
    font: inherit;
}

.status {
    border-radius: 999px;
    padding: 4px 9px;
    background: #eeeae4;
    color: #59534c;
    font-size: 11px;
    font-weight: 700;
    text-transform: capitalize;
}

.status-running {
    background: #e2f4e9;
    color: #17633a;
}

.status-draft,
.status-ready,
.status-paused {
    background: #fff3d6;
    color: #825f16;
}

.status-completed,
.status-aborted {
    background: #eeeae4;
    color: #59534c;
}

@media (max-width: 720px) {
    .experiment-page {
        padding: 18px;
    }

    .experiment-head,
    .experiment-row,
    .row-status {
        flex-direction: column;
    }
}
</style>
