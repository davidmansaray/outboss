<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AccountSettingsSidebar from '@/components/AccountSettingsSidebar.vue';
import AppWorkspace from '@/layouts/AppWorkspace.vue';

defineProps<{
    current: 'profile' | 'security' | 'appearance';
    title: string;
    description: string;
}>();

const page = usePage();
</script>

<template>
    <AppWorkspace area="account" :title="title" :show-sidebar-title="false">
        <template #sidebar>
            <AccountSettingsSidebar :current="current" />
        </template>
        <template #crumb>
            <span>{{ page.props.auth.user.name }}</span>
            <b>›</b>
            <strong>{{ title }}</strong>
        </template>
        <main class="account-settings-index">
            <header class="account-settings-heading">
                <h1>{{ title }}</h1>
                <p>{{ description }}</p>
            </header>
            <section class="account-settings-content">
                <slot />
            </section>
        </main>
    </AppWorkspace>
</template>

<style scoped>
.account-settings-index {
    min-width: 0;
    padding: 36px 40px 64px;
}
.account-settings-heading {
    padding-bottom: 22px;
    border-bottom: 1px solid #e3ddd4;
}
.account-settings-heading h1 {
    margin: 0;
    color: #211e19;
    font-size: 28px;
    font-weight: 650;
    letter-spacing: -0.03em;
}
.account-settings-heading p {
    max-width: 620px;
    margin: 8px 0 0;
    color: #746d64;
    font-size: 13px;
    line-height: 1.55;
}
.account-settings-content {
    max-width: 680px;
    padding-top: 26px;
}
:deep(.account-settings-content > * + *) {
    margin-top: 40px;
}
:deep(.account-settings-content input),
:deep(.account-settings-content select) {
    min-height: 40px;
    border-color: #d8d2c9 !important;
    background: #fff !important;
    box-shadow: none !important;
    color: #27231e !important;
}
:deep(.account-settings-content input:focus),
:deep(.account-settings-content select:focus) {
    border-color: var(--ob-focus) !important;
    box-shadow: 0 0 0 3px rgb(75 73 69 / 12%) !important;
}
:deep(.account-settings-content button[data-slot='button']) {
    min-height: 38px;
    border: 1px solid var(--ob-ink);
    background: var(--ob-ink);
    color: #fff;
    box-shadow: none;
}
:deep(.account-settings-content button[data-slot='button']:hover) {
    background: var(--ob-accent-strong);
}
:deep(.account-settings-content [class~='bg-red-50']) {
    border-color: #ecd3ce !important;
    background: #fff7f5 !important;
}
:deep(.account-settings-content [class~='text-red-600']) {
    color: #963f36 !important;
}
:deep(.account-settings-content button[data-variant='destructive']) {
    border-color: #c95850;
    background: #c95850;
    color: #fff;
}
@media (max-width: 900px) {
    .account-settings-index {
        padding: 24px 18px 48px;
    }
}
</style>
