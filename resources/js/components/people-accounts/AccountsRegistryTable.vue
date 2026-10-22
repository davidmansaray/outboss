<script setup lang="ts">
import EntityIdentity from '@/components/people-accounts/EntityIdentity.vue';
import RegistryStatusPill from '@/components/people-accounts/RegistryStatusPill.vue';
import { initials, updatedLabel } from '@/lib/peopleAccountsFormatters';
import type { RegistryAccount } from '@/types';

defineProps<{
    accounts: RegistryAccount[];
    selectedId: string | number | null;
}>();

const emit = defineEmits<{
    select: [account: RegistryAccount, trigger: HTMLElement];
}>();

function select(account: RegistryAccount, event: MouseEvent): void {
    emit('select', account, event.currentTarget as HTMLElement);
}
</script>

<template>
    <div class="registry-table-wrap">
        <table class="registry-table accounts-table">
            <thead>
                <tr>
                    <th>Account</th>
                    <th>Profile</th>
                    <th>People</th>
                    <th>Readiness</th>
                    <th>Campaigns</th>
                    <th>Tags</th>
                    <th>Updated</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="account in accounts"
                    :key="account.id"
                    class="registry-row"
                    :class="{ selected: selectedId === account.id }"
                >
                    <td>
                        <button
                            type="button"
                            class="registry-entity-button row-entity-button"
                            :aria-label="`Open details for ${account.name}`"
                            @click="select(account, $event)"
                        >
                            <EntityIdentity
                                :name="account.name"
                                :secondary="account.domain"
                            />
                        </button>
                    </td>
                    <td>
                        {{ account.profile || 'Profile not available' }}
                        <small v-if="account.employee_count !== null">
                            {{ account.employee_count }} employees
                        </small>
                    </td>
                    <td>
                        <span class="registry-people-stack">
                            <span
                                v-for="person in account.people.slice(0, 3)"
                                :key="person.id"
                                :title="person.name"
                                >{{ initials(person.name) }}</span
                            >
                            <em>{{ account.people_count }}</em>
                        </span>
                    </td>
                    <td>
                        <RegistryStatusPill :label="account.readiness" />
                        <small v-if="account.people_count">
                            {{ account.ready_count }} of
                            {{ account.people_count }} ready
                        </small>
                        <small v-else>—</small>
                    </td>
                    <td>{{ account.campaigns_count }}</td>
                    <td>
                        <span v-if="account.tags.length" class="registry-tags">
                            {{ account.tags.slice(0, 2).join(', ') }}
                            <em v-if="account.tags.length > 2">
                                +{{ account.tags.length - 2 }}
                            </em>
                        </span>
                        <span v-else>—</span>
                    </td>
                    <td>{{ updatedLabel(account.updated_at) }}</td>
                </tr>
            </tbody>
        </table>

        <div v-if="!accounts.length" class="registry-empty">
            <strong>No accounts found</strong>
            <span>Create an account or clear the search and filters.</span>
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

.registry-table td > small {
    display: block;
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
    outline: 0;
}

.registry-table td:has(.registry-entity-button:focus-visible) {
    background: var(--ob-accent-tint-soft);
}

.registry-table td:has(.registry-entity-button:focus-visible):first-child {
    box-shadow: inset 3px 0 var(--ob-accent);
}

.registry-people-stack {
    display: flex;
    align-items: center;
}

.registry-people-stack > span {
    display: grid;
    width: 25px;
    height: 25px;
    margin-left: -6px;
    place-items: center;
    border: 2px solid #fff;
    border-radius: 50%;
    background: #ede8e1;
    font-size: 9px;
    font-weight: 700;
}

.registry-people-stack > span:first-child {
    margin-left: 0;
}

.registry-people-stack em {
    margin-left: 6px;
    color: #6b655c;
    font-size: 10px;
    font-style: normal;
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

.registry-tags {
    color: #4a453e;
    font-size: 11px;
}

.registry-tags em {
    color: #6b655c;
    font-style: normal;
}

@media (max-width: 700px) {
    .registry-entity-button {
        min-height: 44px;
    }
}
</style>
