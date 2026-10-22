<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { AppArea } from '@/types/navigation';
const props = defineProps<{ area: AppArea }>();
const notes: Partial<
    Record<AppArea, { title: string; text: string; href: string; link: string }>
> = {
    accounts: {
        title: 'Company records',
        text: 'Keep account knowledge and people together, independently of any campaign.',
        href: '/prospects',
        link: 'Browse people',
    },
    people: {
        title: 'Reusable prospect inventory',
        text: 'Maintain people here. Choose who receives outreach inside each campaign.',
        href: '/accounts',
        link: 'Browse accounts',
    },
    sources: {
        title: 'Know where records came from',
        text: 'Sources preserve import history, permissions and evidence for your inventory.',
        href: '/accounts',
        link: 'Browse accounts',
    },
    objectives: {
        title: 'Business outcomes',
        text: 'Objectives define success. Campaign execution belongs in Outreach.',
        href: '/campaigns',
        link: 'Open campaigns',
    },
    offers: {
        title: 'Independent commercial promises',
        text: 'Maintain the promise and delivery requirements here, separately from campaign setup.',
        href: '/campaigns',
        link: 'Open campaigns',
    },
    campaigns: {
        title: 'Campaign execution',
        text: 'Manage the audience, messages, mailboxes and launch for each outreach campaign.',
        href: '/offers',
        link: 'Browse Offers',
    },
};
const note = computed(() => notes[props.area]);
</script>

<template>
    <section
        v-if="note"
        class="workspace-context"
        aria-label="Workspace context"
    >
        <h2>{{ note.title }}</h2>
        <p>{{ note.text }}</p>
        <Link :href="note.href"
            >{{ note.link }} <span aria-hidden="true">→</span></Link
        >
    </section>
</template>

<style scoped>
.workspace-context {
    border-top: 1px solid var(--ob-border);
    margin: 28px 8px 0;
    padding-top: 18px;
}
h2 {
    font-size: 11px;
    font-weight: 500;
    color: var(--ob-text);
    margin: 0;
}
p {
    font-size: 11px;
    line-height: 1.7;
    color: var(--ob-muted);
    margin: 9px 0 14px;
}
a {
    font-size: 11px;
    color: var(--ob-text);
    text-decoration: none;
}
a:hover {
    text-decoration: underline;
    text-underline-offset: 3px;
}
a:focus-visible {
    outline: 2px solid var(--ob-focus);
    outline-offset: 3px;
}
@media (max-width: 900px) {
    .workspace-context {
        display: none;
    }
}
</style>
