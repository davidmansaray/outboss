<script setup lang="ts">
import { Search } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { RegistryFilterGroup, RegistryView } from '@/types';

defineProps<{
    view: RegistryView;
    search: string;
    activeFilters: Set<string>;
    filterGroups: RegistryFilterGroup[];
    isOwner?: boolean;
}>();

const emit = defineEmits<{
    'update:search': [value: string];
    toggleFilter: [id: string];
    clearFilters: [];
    add: [];
    addSource: [];
    import: [];
}>();

const root = ref<HTMLElement | null>(null);
const open = ref(false);

function handleDocumentClick(event: MouseEvent): void {
    if (!root.value?.contains(event.target as Node)) {
        open.value = false;
    }
}

function handleKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        open.value = false;
    }
}

onMounted(() => {
    document.addEventListener('click', handleDocumentClick);
    document.addEventListener('keydown', handleKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', handleDocumentClick);
    document.removeEventListener('keydown', handleKeydown);
});
</script>

<template>
    <div class="registry-toolbar">
        <label class="registry-search">
            <span class="sr-only">Search {{ view }}</span>
            <Search aria-hidden="true" />
            <input
                type="search"
                :value="search"
                :placeholder="`Search ${view}…`"
                @input="
                    emit(
                        'update:search',
                        ($event.target as HTMLInputElement).value,
                    )
                "
            />
        </label>

        <div ref="root" class="registry-filter">
            <button
                type="button"
                class="registry-button registry-filter-trigger"
                aria-haspopup="true"
                :aria-expanded="open"
                @click="open = !open"
            >
                Filters<span v-if="activeFilters.size">
                    · {{ activeFilters.size }}</span
                >
            </button>
            <div v-if="open" class="registry-filter-menu">
                <fieldset
                    v-for="group in filterGroups"
                    :key="group.label"
                    class="registry-filter-group"
                >
                    <legend>{{ group.label }}</legend>
                    <label
                        v-for="option in group.options"
                        :key="option.id"
                        class="registry-filter-option"
                    >
                        <input
                            type="checkbox"
                            :checked="activeFilters.has(option.id)"
                            @change="emit('toggleFilter', option.id)"
                        />
                        <span>{{ option.label }}</span>
                    </label>
                </fieldset>
                <button
                    v-if="activeFilters.size"
                    type="button"
                    class="registry-clear"
                    @click="emit('clearFilters')"
                >
                    Clear filters
                </button>
            </div>
        </div>

        <a
            v-if="view === 'accounts'"
            class="registry-button"
            href="/accounts/export"
            data-test="export-accounts"
        >
            Export CSV
        </a>
        <a
            v-else-if="view === 'people'"
            class="registry-button"
            href="/people/export"
            data-test="export-people"
        >
            Export CSV
        </a>

        <button
            v-if="view === 'people' && isOwner !== false"
            type="button"
            class="registry-button"
            data-test="import-people"
            @click="emit('import')"
        >
            Import CSV
        </button>

        <button
            v-if="view === 'sources' && isOwner !== false"
            type="button"
            class="registry-button primary"
            data-test="add-source"
            @click="emit('addSource')"
        >
            + Add source
        </button>

        <button
            v-if="view === 'accounts'"
            type="button"
            class="registry-button primary"
            data-test="add-account"
            @click="emit('add')"
        >
            + Add account
        </button>
        <button
            v-else-if="view === 'people'"
            type="button"
            class="registry-button primary"
            data-test="add-person"
            @click="emit('add')"
        >
            + Add person
        </button>
    </div>
</template>

<style scoped>
.registry-toolbar {
    position: relative;
    z-index: 8;
    display: flex;
    min-height: 49px;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    border: 1px solid var(--ob-border-soft);
    border-bottom: 0;
    background: var(--ob-surface);
}

.registry-search {
    display: flex;
    min-width: 210px;
    flex: 1;
    align-items: center;
    gap: 8px;
    border: 1px solid var(--ob-control-border);
    border-radius: 7px;
    padding: 0 10px;
    background: #fbf9f5;
}

.registry-search svg {
    width: 15px;
    color: #8a847a;
}

.registry-search input {
    width: 100%;
    height: 32px;
    border: 0;
    outline: 0;
    background: transparent;
    color: #1a1814;
}

.registry-search:focus-within {
    border-color: var(--ob-focus);
    box-shadow: 0 0 0 3px rgb(75 73 69 / 14%);
}

.registry-button {
    display: inline-flex;
    min-height: 34px;
    align-items: center;
    justify-content: center;
    gap: 4px;
    border: 1px solid #d4cdc2;
    border-radius: 8px;
    padding: 6px 11px;
    background: #fff;
    color: #302c26;
    font-weight: 580;
    text-decoration: none;
    white-space: nowrap;
}

.registry-button:hover:not(:disabled) {
    border-color: #ada69b;
}

.registry-button:focus-visible,
.registry-clear:focus-visible {
    outline: 2px solid var(--ob-focus);
    outline-offset: 2px;
}

.registry-button.primary {
    border-color: var(--ob-ink);
    background: var(--ob-ink);
    color: #fff;
}

.registry-button.primary:disabled {
    border-color: #dedad3;
    background: #dedad3;
    color: #777168;
}

.registry-button.primary:hover:not(:disabled) {
    border-color: #2b2927;
    background: #2b2927;
}

.registry-filter {
    position: relative;
}

.registry-filter-menu {
    position: absolute;
    top: 39px;
    right: 0;
    z-index: 30;
    width: 230px;
    padding: 7px;
    border: 1px solid #d4cdc2;
    border-radius: 9px;
    background: #fff;
    box-shadow: 0 14px 36px rgb(35 31 25 / 15%);
}

.registry-filter-group {
    min-width: 0;
    margin: 0;
    padding: 5px 0 7px;
    border: 0;
}

.registry-filter-group + .registry-filter-group {
    border-top: 1px solid var(--ob-border-soft);
}

.registry-filter-group legend {
    width: 100%;
    padding: 5px 7px 2px;
    color: #6f685f;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.registry-filter-group label {
    display: flex;
    min-height: 34px;
    align-items: center;
    gap: 8px;
    border-radius: 6px;
    padding: 7px;
    cursor: pointer;
}

.registry-filter-group label:hover {
    background: #f6f4ef;
}

.registry-filter-group input {
    width: 15px;
    height: 15px;
    accent-color: var(--ob-accent);
}

.registry-clear {
    width: 100%;
    min-height: 34px;
    border: 0;
    border-top: 1px solid var(--ob-border);
    padding: 8px 7px 4px;
    background: transparent;
    color: #625d54;
    text-align: left;
}

@media (max-width: 700px) {
    .registry-toolbar {
        flex-wrap: wrap;
    }

    .registry-search {
        order: -2;
        min-width: 100%;
    }

    .registry-button.primary {
        order: -1;
        margin-left: auto;
    }

    .registry-button {
        min-height: 44px;
    }

    .registry-filter-menu {
        position: fixed;
        top: auto;
        right: 12px;
        bottom: 12px;
        left: 12px;
        width: auto;
        max-height: calc(100dvh - 24px);
        overflow: auto;
        border-radius: 12px;
    }
}
</style>
