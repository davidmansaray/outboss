<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import RegistryStatusPill from '@/components/people-accounts/RegistryStatusPill.vue';
import { updatedLabel } from '@/lib/peopleAccountsFormatters';
import type { ProspectSource } from '@/types';

const props = withDefaults(
    defineProps<{ source: ProspectSource | null; isOwner?: boolean }>(),
    { isOwner: false },
);
const emit = defineEmits<{ close: []; archived: [] }>();
const archiving = ref(false);
const confirming = ref(false);

const run = computed(() => props.source?.last_run ?? null);
const isArchived = computed(() => props.source?.status === 'archived');

function archive(): void {
    const source = props.source;

    if (!source || archiving.value || isArchived.value) {
        return;
    }

    archiving.value = true;
    router.post(
        `/prospect-sources/${source.id}/archive`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                confirming.value = false;
                emit('archived');
            },
            onFinish: () => {
                archiving.value = false;
            },
        },
    );
}

function kindLabel(kind: string): string {
    return kind
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}
</script>

<template>
    <aside
        v-if="source"
        class="source-inspector"
        aria-labelledby="source-inspector-title"
    >
        <header class="source-inspector-head">
            <div>
                <small>Prospect source</small>
                <h2 id="source-inspector-title">{{ source.display_name }}</h2>
            </div>
            <button
                type="button"
                aria-label="Close source details"
                @click="emit('close')"
            >
                ×
            </button>
        </header>

        <div class="source-inspector-body">
            <div class="source-inspector-pills">
                <RegistryStatusPill :label="source.status" />
                <span>{{ kindLabel(source.kind) }}</span>
            </div>
            <dl class="source-inspector-facts">
                <div>
                    <dt>Runs</dt>
                    <dd>{{ source.run_count ?? (run ? 1 : 0) }}</dd>
                </div>
                <div>
                    <dt>Accepted</dt>
                    <dd>{{ source.accepted_count ?? run?.accepted ?? 0 }}</dd>
                </div>
                <div>
                    <dt>Created</dt>
                    <dd>{{ source.created_count ?? run?.created ?? 0 }}</dd>
                </div>
                <div>
                    <dt>Linked</dt>
                    <dd>{{ source.linked_count ?? run?.linked ?? 0 }}</dd>
                </div>
                <div>
                    <dt>Suppressed</dt>
                    <dd>
                        {{ source.suppressed_count ?? run?.suppressed ?? 0 }}
                    </dd>
                </div>
                <div>
                    <dt>Rejected</dt>
                    <dd>{{ source.rejected_count ?? run?.rejected ?? 0 }}</dd>
                </div>
            </dl>

            <section class="source-inspector-section">
                <h3>Last run</h3>
                <p v-if="!run">No run yet</p>
                <template v-else>
                    <div class="source-run-state">
                        <RegistryStatusPill :label="run.state" />
                    </div>
                    <p v-if="run.finished_at">
                        Finished {{ updatedLabel(run.finished_at) }}
                    </p>
                    <p v-else>
                        Started {{ updatedLabel(run.created_at ?? null) }}
                    </p>
                </template>
            </section>

            <section
                v-if="source.retention_warning || source.restrictions?.length"
                class="source-inspector-section source-warning"
            >
                <h3>Restrictions</h3>
                <p v-if="source.retention_warning">
                    {{ source.retention_warning }}
                </p>
                <p v-if="source.restrictions?.length">
                    {{ source.restrictions.join(' · ') }}
                </p>
            </section>

            <div v-if="isOwner" class="source-inspector-actions">
                <a :href="`/prospect-sources/${source.id}/export`"
                    >Export allowed data</a
                >
                <button
                    v-if="!isArchived && !confirming"
                    type="button"
                    @click="confirming = true"
                >
                    Archive
                </button>
                <template v-else-if="confirming">
                    <span>Stop new runs?</span>
                    <button
                        type="button"
                        :disabled="archiving"
                        @click="archive"
                    >
                        Confirm
                    </button>
                    <button
                        type="button"
                        :disabled="archiving"
                        @click="confirming = false"
                    >
                        Cancel
                    </button>
                </template>
            </div>
            <p v-else class="source-member-note" role="note">
                Members can review source history. Source changes and exports
                are owner-only.
            </p>
        </div>
    </aside>
</template>

<style scoped>
.source-inspector {
    position: relative;
    display: flex;
    width: min(360px, 100%);
    min-height: 0;
    flex-direction: column;
    border-left: 1px solid var(--ob-border-soft);
    background: var(--ob-surface);
}

.source-inspector-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    padding: 23px 20px 18px;
    border-bottom: 1px solid var(--ob-border-soft);
}

.source-inspector-head small {
    color: #6f685f;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.source-inspector-head h2 {
    margin: 4px 0 0;
    color: var(--ob-ink);
    font-size: 21px;
    letter-spacing: -0.03em;
}

.source-inspector-head > button {
    border: 0;
    background: transparent;
    color: #6b655c;
    font-size: 24px;
    line-height: 1;
}

.source-inspector-body {
    overflow: auto;
    padding: 18px 20px 24px;
}

.source-inspector-pills {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 18px;
    color: #6b655c;
    font-size: 11px;
}

.source-inspector-facts {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
    margin: 0;
}

.source-inspector-facts div {
    border: 1px solid var(--ob-border-soft);
    border-radius: 8px;
    padding: 9px;
}

.source-inspector-facts dt {
    color: #6f685f;
    font-size: 10px;
}

.source-inspector-facts dd {
    margin: 3px 0 0;
    color: var(--ob-ink);
    font-size: 17px;
    font-weight: 650;
}

.source-inspector-section {
    margin-top: 21px;
    border-top: 1px solid var(--ob-border-soft);
    padding-top: 16px;
}

.source-inspector-section h3 {
    margin: 0 0 7px;
    color: #3a352e;
    font-size: 12px;
}

.source-inspector-section p {
    margin: 0 0 4px;
    color: #6b655c;
    font-size: 12px;
    line-height: 1.45;
}

.source-warning h3,
.source-warning p {
    color: #8b4c22;
}

.source-run-state {
    margin-bottom: 7px;
}

.source-inspector-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-top: 24px;
}

.source-inspector-actions a,
.source-inspector-actions button {
    min-height: 34px;
    border: 1px solid var(--ob-border);
    border-radius: 7px;
    padding: 0 10px;
    background: transparent;
    color: #4a453e;
    font-size: 11px;
    text-decoration: none;
}

.source-inspector-actions a {
    display: inline-flex;
    align-items: center;
}

.source-inspector-actions button:disabled {
    opacity: 0.55;
}

.source-member-note {
    margin: 24px 0 0;
    border: 1px solid var(--ob-border-soft);
    padding: 10px 11px;
    color: #6b655c;
    font-size: 11px;
    line-height: 1.45;
}
</style>
