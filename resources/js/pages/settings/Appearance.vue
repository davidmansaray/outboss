<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AccountSettingsShell from '@/components/AccountSettingsShell.vue';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
</script>

<template>
    <Head title="Appearance settings" />
    <AccountSettingsShell
        current="appearance"
        title="Appearance"
        description="Choose how OutBoss looks on this device."
    >
        <div class="space-y-6">
            <Heading
                variant="small"
                title="Theme"
                description="Use a light, dark, or system-matched interface."
            />
            <AppearanceTabs />
        </div>
    </AccountSettingsShell>
</template>
