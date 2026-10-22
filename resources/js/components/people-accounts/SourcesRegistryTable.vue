<script setup lang="ts">
import { computed } from 'vue';
import RegistryStatusPill from '@/components/people-accounts/RegistryStatusPill.vue';
import { updatedLabel } from '@/lib/peopleAccountsFormatters';
import type { ProspectSource } from '@/types';

const props = defineProps<{
    sources: ProspectSource[];
    selectedId: string | null;
}>();

const emit = defineEmits<{
    select: [source: ProspectSource, trigger: HTMLElement];
}>();

const emptyCopy = computed(() =>
    props.sources.length
        ? null
        : 'Create a source to keep imports and selected records traceable.',
);

function select(source: ProspectSource, event: MouseEvent): void {
    emit('select', source, event.currentTarget as HTMLElement);
}

function kindLabel(kind: string): string {
    return kind
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function runCount(
    source: ProspectSource,
    key: 'accepted' | 'created' | 'linked' | 'suppressed' | 'rejected',
): number {
    const run = source.last_run;

    return source[`${key}_count`] ?? run?.[key] ?? 0;
}
</script>

<template>
    <div class="registry-table-wrap source-table-wrap">
        <table class="registry-table source-table">
            <thead>
                <tr>
                    <th>Source</th>
                    <th>Kind</th>
                    <th>Status</th>
                    <th>Last run</th>
                    <th>Yield</th>
                    <th>Restrictions</th>
                    <th>Updated</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="source in sources"
                    :key="source.id"
                    class="registry-row"
                    :class="{ selected: selectedId === source.id }"
                >
                    <td>
                        <button
                            type="button"
                            class="registry-entity-button row-entity-button"
                            :aria-label="`Open details for ${source.display_name}`"
                            @click="select(source, $event)"
                        >
                            <span class="source-name">{{
                                source.display_name
                            }}</span>
                            <small v-if="source.last_run?.finished_at">
                                {{ updatedLabel(source.last_run.finished_at) }}
                            </small>
                        </button>
                    </td>
                    <td>{{ kindLabel(source.kind) }}</td>
                    <td><RegistryStatusPill :label="source.status" /></td>
                    <td>
                        <RegistryStatusPill
                            v-if="source.last_run"
                            :label="source.last_run.state"
                        />
                        <span v-else>—</span>
                    </td>
                    <td>
                        <strong>{{ runCount(source, 'accepted') }}</strong>
                        <small>
                            {{ runCount(source, 'created') }} created ·
                            {{ runCount(source, 'linked') }} linked
                        </small>
                    </td>
                    <td>
                        <span
                            v-if="source.restrictions?.length"
                            class="source-restriction"
                        >
                            {{ source.restrictions.length }} flagged
                        </span>
                        <span v-else>None</span>
                    </td>
                    <td>{{ updatedLabel(source.updated_at ?? null) }}</td>
                </tr>
            </tbody>
        </table>

        <div v-if="emptyCopy" class="registry-empty">
            <strong>No sources found</strong>
            <span>{{ emptyCopy }}</span>
        </div>
    </div>
</template>

<style scoped>
.source-table {
    min-width: 940px;
}

.source-table td > small,
.source-table td > strong {
    display: block;
}

.source-table td > strong {
    color: #3a352e;
    font-size: 11px;
}

.source-name {
    display: block;
    color: #3a352e;
    font-size: 12px;
    font-weight: 650;
}

.source-restriction {
    color: #8b4c22;
}

@media (max-width: 1280px) {
    .source-table {
        min-width: 0;
    }

    .source-table th:nth-child(n + 6),
    .source-table td:nth-child(n + 6) {
        display: none;
    }
}

@media (max-width: 700px) {
    .source-table {
        min-width: 0;
    }

    .source-table th:nth-child(n + 2),
    .source-table td:nth-child(n + 2) {
        display: none;
    }
}
</style>
