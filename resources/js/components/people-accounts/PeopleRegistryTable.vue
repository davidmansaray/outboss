<script setup lang="ts">
import EntityIdentity from '@/components/people-accounts/EntityIdentity.vue';
import RegistryStatusPill from '@/components/people-accounts/RegistryStatusPill.vue';
import { updatedLabel } from '@/lib/peopleAccountsFormatters';
import type { RegistryPerson } from '@/types';

defineProps<{
    people: RegistryPerson[];
    selectedId: string | number | null;
}>();

const emit = defineEmits<{
    select: [person: RegistryPerson, trigger: HTMLElement];
}>();

function select(person: RegistryPerson, event: MouseEvent): void {
    emit('select', person, event.currentTarget as HTMLElement);
}
</script>

<template>
    <div class="registry-table-wrap">
        <table class="registry-table people-table">
            <thead>
                <tr>
                    <th>Person</th>
                    <th>Account & role</th>
                    <th>Reply</th>
                    <th>Eligibility</th>
                    <th>Campaign</th>
                    <th>Tags</th>
                    <th>Updated</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="person in people"
                    :key="person.id"
                    class="registry-row"
                    :class="{ selected: selectedId === person.id }"
                >
                    <td>
                        <button
                            type="button"
                            class="registry-entity-button row-entity-button"
                            :aria-label="`Open details for ${person.name}`"
                            @click="select(person, $event)"
                        >
                            <EntityIdentity
                                :name="person.name"
                                :secondary="person.email"
                                person
                            />
                            <span class="registry-mobile-statuses">
                                <RegistryStatusPill :label="person.reply" />
                                <RegistryStatusPill
                                    :label="person.eligibility"
                                />
                            </span>
                        </button>
                    </td>
                    <td>
                        <strong>{{ person.account || 'No account' }}</strong>
                        <small v-if="person.role">{{ person.role }}</small>
                    </td>
                    <td><RegistryStatusPill :label="person.reply" /></td>
                    <td>
                        <RegistryStatusPill :label="person.eligibility" />
                    </td>
                    <td>{{ person.campaign_label }}</td>
                    <td>
                        <template v-if="person.tags.length">
                            <span
                                v-for="tag in person.tags"
                                :key="tag"
                                class="registry-tag"
                                >{{ tag }}</span
                            >
                        </template>
                        <span v-else>—</span>
                    </td>
                    <td>{{ updatedLabel(person.updated_at) }}</td>
                </tr>
            </tbody>
        </table>

        <div v-if="!people.length" class="registry-empty">
            <strong>No people found</strong>
            <span>Try a different search or clear the filters.</span>
        </div>
    </div>
</template>

<style scoped>
.registry-table-wrap {
    overflow: auto;
    overscroll-behavior-inline: contain;
    border: 1px solid var(--ob-border-soft);
    background: var(--ob-surface);
}

.registry-table {
    width: 100%;
    min-width: 920px;
    border-collapse: collapse;
    background: var(--ob-surface);
}

.registry-table th {
    position: sticky;
    top: 0;
    z-index: 2;
    height: 37px;
    padding: 0 12px;
    border-bottom: 1px solid var(--ob-border-soft);
    background: #fbf9f5;
    color: #69635a;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.07em;
    text-align: left;
    text-transform: uppercase;
}

.registry-table td {
    height: 57px;
    padding: 8px 12px;
    border-bottom: 1px solid var(--ob-border-soft);
    color: #4a453e;
    vertical-align: middle;
}

.registry-table tr:last-child td {
    border-bottom: 0;
}

.registry-table tbody tr:hover td,
.registry-table tbody tr.selected td {
    background: var(--ob-accent-tint-soft);
}

.registry-table tbody tr.selected td:first-child {
    box-shadow: inset 2px 0 var(--ob-accent);
}

.registry-table td > strong,
.registry-table td > small {
    display: block;
}

.registry-table td > strong {
    color: #3a352e;
    font-size: 11px;
}

.registry-table td > small {
    margin-top: 2px;
    color: #6b655c;
    font-size: 10px;
}

.registry-entity-button {
    width: 100%;
    min-width: 0;
    border: 0;
    border-radius: 0;
    padding: 0;
    appearance: none;
    background: transparent;
    color: inherit;
    text-align: left;
}

.registry-entity-button:hover :deep(.registry-identity-copy strong) {
    color: var(--ob-accent-strong);
}

.registry-entity-button:focus-visible {
    outline: 2px solid var(--ob-focus);
    outline-offset: 3px;
}
.registry-mobile-statuses {
    display: none;
}

.registry-table td:has(.registry-entity-button:focus-visible) {
    background: var(--ob-accent-tint-soft);
}

.registry-table td:has(.registry-entity-button:focus-visible):first-child {
    box-shadow: inset 3px 0 var(--ob-accent);
}

.registry-tag {
    display: inline-block;
    margin: 2px 3px 2px 0;
    border: 1px solid var(--ob-border);
    border-radius: 5px;
    padding: 2px 5px;
    color: #625c54;
    font-size: 10px;
}

.registry-empty {
    display: flex;
    min-height: 210px;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 7px;
    padding: 28px;
    text-align: center;
}

.registry-empty strong {
    font-size: 13px;
}

.registry-empty span {
    color: #6b655c;
    font-size: 11px;
}

@media (max-width: 700px) {
    .registry-entity-button {
        min-height: 44px;
    }
    .registry-table {
        min-width: 0;
    }
    .registry-table th:nth-child(n + 2),
    .registry-table td:nth-child(n + 2) {
        display: none;
    }
    .registry-table th,
    .registry-table td {
        padding-inline: 10px;
    }
    .registry-mobile-statuses {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
        margin-top: 7px;
    }
}
</style>
