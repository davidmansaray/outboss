<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import ObjectiveGoalForm from '@/components/objectives/ObjectiveGoalForm.vue';
import ObjectiveMeasurementForm from '@/components/objectives/ObjectiveMeasurementForm.vue';
import AppWorkspace from '@/layouts/AppWorkspace.vue';
const props = defineProps<{
    return_campaign?: { id: number; name: string } | null;
    objective: {
        id: string;
        name: string;
        description: string | null;
        measurement: string;
        target_count: number | null;
        deadline: string | null;
        lock_version: number;
        version: number;
    };
    can_edit: boolean;
    authority: {
        starts_at: string;
        ends_at: string;
        maximum_people: number;
        maximum_accounts: number;
        maximum_messages: number;
    } | null;
    can_import_authority: boolean;
    legacy_scope_id: string | null;
    definition_version_id: string | null;
    definitions: { id: string; name: string }[];
}>();
const importForm = useForm(
    props.return_campaign ? { from_campaign: props.return_campaign.id } : {},
);
</script>
<template>
    <Head :title="objective.name" />
    <AppWorkspace area="objectives" :title="objective.name">
        <template #sidebar
            ><Link href="/objectives">← All objectives</Link></template
        >
        <main class="goal-page">
            <p v-if="return_campaign">
                <Link :href="`/campaigns/${return_campaign.id}`"
                    >← Back to campaign: {{ return_campaign.name }}</Link
                >
            </p>
            <p>OBJECTIVE · VERSION {{ objective.version }}</p>
            <h1>{{ objective.name }}</h1>
            <p class="intro">Meeting booked · Reusable across campaigns</p>
            <ObjectiveGoalForm
                v-if="can_edit"
                :key="objective.lock_version"
                :objective="objective"
                :return-campaign="return_campaign"
            />
            <p v-else>{{ objective.description }}</p>
            <ObjectiveMeasurementForm
                v-if="can_edit"
                :key="`authority-${objective.lock_version}`"
                :objective="objective"
                :return-campaign="return_campaign"
                :definition-version-id="definition_version_id"
                :definitions="definitions"
            />
            <section v-if="authority" class="legacy-authority">
                <h2>Historical sending allowance</h2>
                <p>
                    These historical limits are read-only. Importing preserves
                    the original window and all committed usage; it does not
                    renew or approve sending.
                </p>
                <p>
                    {{ authority.maximum_people }} people ·
                    {{ authority.maximum_accounts }} accounts ·
                    {{ authority.maximum_messages }} messages
                </p>
                <p>{{ authority.starts_at }} → {{ authority.ends_at }}</p>
                <p v-if="legacy_scope_id">
                    Imported as a shared allowance. Select it in campaign setup.
                </p>
                <button
                    v-else-if="can_import_authority"
                    :disabled="importForm.processing"
                    @click="
                        importForm.post(
                            `/objectives/${objective.id}/import-authority`,
                            { preserveScroll: true },
                        )
                    "
                >
                    {{
                        importForm.processing
                            ? 'Importing…'
                            : 'Import existing allowance'
                    }}
                </button>
                <p
                    v-for="(error, key) in importForm.errors"
                    :key="key"
                    role="alert"
                >
                    {{ error }}
                </p>
            </section>
        </main>
    </AppWorkspace>
</template>
<style scoped>
.goal-page {
    padding: 32px;
}
h1 {
    font-size: 30px;
    font-weight: 600;
    margin: 12px 0;
}
.intro {
    margin-bottom: 28px;
    color: var(--ob-muted);
}
.legacy-authority {
    margin-top: 32px;
    padding-top: 24px;
    border-top: 1px solid var(--ob-border);
    font-size: 13px;
}
.legacy-authority h2 {
    font-size: 18px;
    font-weight: 600;
}
.legacy-authority p {
    margin: 12px 0;
    color: var(--ob-muted);
}
.legacy-authority button {
    text-decoration: underline;
}
</style>
