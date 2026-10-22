<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import SourceFormDialog from '@/components/people-accounts/SourceFormDialog.vue';
import SourcesRegistryTable from '@/components/people-accounts/SourcesRegistryTable.vue';
import AppWorkspace from '@/layouts/AppWorkspace.vue';
import type { ProspectSource } from '@/types';

const props = defineProps<{ sources: ProspectSource[] }>();
const page = usePage();
const isOwner = computed(() => page.props.auth?.workspace?.role === 'owner');
const sourceFormOpen = ref(false);
const query = ref('');
const filtered = computed(() => {
    const needle = query.value.trim().toLocaleLowerCase();

    return !needle
        ? props.sources
        : props.sources.filter((source) =>
              [source.display_name, source.kind, source.status]
                  .join(' ')
                  .toLocaleLowerCase()
                  .includes(needle),
          );
});
</script>

<template>
    <Head title="Prospect sources" />
    <AppWorkspace area="sources" title="Prospect sources">
        <main class="sources-page">
            <header class="sources-heading">
                <div>
                    <p>Prospects</p>
                    <h1>Sources</h1>
                    <span
                        >Every import and selection keeps its origin and run
                        history.</span
                    >
                </div>
                <button
                    v-if="isOwner"
                    type="button"
                    class="source-primary"
                    @click="sourceFormOpen = true"
                >
                    + Add source
                </button>
            </header>

            <p v-if="!isOwner" class="source-member-note" role="note">
                You can inspect source history. Creating and changing sources is
                limited to workspace owners.
            </p>

            <div class="sources-toolbar">
                <label>
                    <span class="sr-only">Search sources</span>
                    <input
                        v-model="query"
                        type="search"
                        placeholder="Search sources…"
                    />
                </label>
                <Link href="/prospects?view=sources">Open in Prospects</Link>
            </div>

            <SourcesRegistryTable
                :sources="filtered"
                :selected-id="null"
                @select="
                    (source) => $inertia.visit(`/prospect-sources/${source.id}`)
                "
            />
        </main>
        <SourceFormDialog
            :open="sourceFormOpen"
            @close="sourceFormOpen = false"
        />
    </AppWorkspace>
</template>

<style scoped>
.sources-page {
    flex: 1;
    min-height: 0;
    overflow: auto;
    padding: 24px 26px 36px;
    background: var(--ob-surface);
}

.sources-heading,
.sources-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
}

.source-member-note {
    margin: -8px 0 18px;
    border: 1px solid var(--ob-border-soft);
    padding: 10px 12px;
    color: #6b655c;
    font-size: 12px;
}

.sources-heading {
    align-items: flex-start;
    margin-bottom: 22px;
}

.sources-heading p {
    margin: 0;
    color: #6f685f;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.sources-heading h1 {
    margin: 4px 0 6px;
    color: var(--ob-ink);
    font-size: 32px;
    letter-spacing: -0.04em;
}

.sources-heading span {
    color: #6b655c;
}

.source-primary {
    min-height: 38px;
    border: 1px solid #27231e;
    border-radius: 7px;
    padding: 0 13px;
    background: #27231e;
    color: #fff;
}

.sources-toolbar {
    justify-content: flex-start;
    margin-bottom: 0;
    border: 1px solid var(--ob-border-soft);
    border-bottom: 0;
    padding: 8px 10px;
}

.sources-toolbar input {
    min-width: 240px;
    min-height: 36px;
    border: 1px solid var(--ob-border);
    border-radius: 7px;
    padding: 0 10px;
}

.sources-toolbar a {
    margin-left: auto;
    color: #5b554d;
    font-size: 12px;
}

@media (max-width: 620px) {
    .sources-page {
        padding: 18px 12px 28px;
    }

    .sources-heading {
        display: block;
    }

    .source-primary {
        margin-top: 14px;
    }

    .sources-toolbar input {
        min-width: 0;
        width: 100%;
    }
}
</style>
