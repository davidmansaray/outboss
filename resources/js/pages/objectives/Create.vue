<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import ObjectiveGoalForm from '@/components/objectives/ObjectiveGoalForm.vue';
import AppWorkspace from '@/layouts/AppWorkspace.vue';
</script>
<template>
    <Head title="New objective" />
    <AppWorkspace area="objectives" title="New objective">
        <template #sidebar
            ><Link href="/objectives">← All objectives</Link></template
        >
        <main class="goal-page">
            <p>STRATEGY / OBJECTIVES</p>
            <h1>New objective</h1>
            <p class="intro">
                Define the outcome. Choose Offers and audiences when you build a
                campaign.
            </p>
            <ObjectiveGoalForm />
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
</style>
