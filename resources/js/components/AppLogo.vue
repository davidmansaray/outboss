<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import AppLogoWordmark from '@/components/AppLogoWordmark.vue';
</script>

<template>
    <div
        class="hidden aspect-square size-8 shrink-0 items-center justify-center text-sidebar-foreground group-data-[collapsible=icon]/sidebar-wrapper:flex"
    >
        <AppLogoIcon class="size-7" />
    </div>
    <AppLogoWordmark
        class="ml-1 h-5 w-auto max-w-[118px] group-data-[collapsible=icon]/sidebar-wrapper:hidden dark:invert"
    />
</template>
