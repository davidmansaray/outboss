<script setup lang="ts">
import type { RegistryView } from '@/types';

defineProps<{
    view: RegistryView;
    peopleCount: number;
    accountsCount: number;
    sourcesCount?: number;
}>();

const emit = defineEmits<{
    change: [view: RegistryView];
}>();

const planned = ['Data review', 'Imports', 'Tags'];
</script>

<template>
    <nav class="registry-side-nav" aria-label="People and accounts views">
        <button
            type="button"
            data-registry-view="people"
            :class="{ active: view === 'people' }"
            :aria-current="view === 'people' ? 'page' : undefined"
            @click="emit('change', 'people')"
        >
            <span>People</span><small>{{ peopleCount }}</small>
        </button>
        <button
            type="button"
            data-registry-view="sources"
            :class="{ active: view === 'sources' }"
            :aria-current="view === 'sources' ? 'page' : undefined"
            @click="emit('change', 'sources')"
        >
            <span>Sources</span><small>{{ sourcesCount ?? 0 }}</small>
        </button>
        <button
            type="button"
            data-registry-view="accounts"
            :class="{ active: view === 'accounts' }"
            :aria-current="view === 'accounts' ? 'page' : undefined"
            @click="emit('change', 'accounts')"
        >
            <span>Accounts</span><small>{{ accountsCount }}</small>
        </button>
    </nav>

    <div class="registry-side-divider" />
    <p class="registry-side-kicker">Coming soon</p>
    <div class="registry-planned-nav">
        <button
            v-for="item in planned"
            :key="item"
            type="button"
            disabled
            :aria-label="`${item}, coming soon`"
        >
            <span>{{ item }}</span
            ><em>Soon</em>
        </button>
    </div>
</template>

<style scoped>
.registry-side-nav,
.registry-planned-nav {
    display: grid;
    gap: 2px;
}

.registry-side-nav button,
.registry-planned-nav button {
    display: flex;
    width: 100%;
    min-height: 38px;
    align-items: center;
    gap: 9px;
    border: 0;
    border-radius: 8px;
    padding: 0 10px;
    background: transparent;
    color: #625d54;
    text-align: left;
}

.registry-side-nav button:hover {
    background: rgb(255 253 252 / 58%);
}

.registry-side-nav button:focus-visible {
    outline: 2px solid var(--ob-focus);
    outline-offset: 2px;
}

.registry-side-nav button.active {
    background: var(--ob-accent-tint);
    color: var(--ob-accent-strong);
    font-weight: 620;
}

.registry-side-nav small {
    margin-left: auto;
    color: #6b655c;
    font-size: 11px;
}

.registry-side-divider {
    height: 1px;
    margin: 9px 8px;
    background: var(--ob-border-soft);
}
.registry-side-kicker {
    margin: 7px 10px;
    color: #6f685f;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}
.registry-planned-nav button {
    cursor: default;
}
.registry-planned-nav em {
    margin-left: auto;
    color: #8a847a;
    font-size: 9px;
    font-style: normal;
}

@media (max-width: 900px) {
    .registry-side-nav {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .registry-side-nav button {
        min-height: 44px;
    }

    .registry-side-divider,
    .registry-side-kicker,
    .registry-planned-nav {
        display: none;
    }
}
</style>
