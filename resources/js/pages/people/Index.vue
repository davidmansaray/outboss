<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref } from 'vue';
import AccountFormDialog from '@/components/people-accounts/AccountFormDialog.vue';
import AccountsRegistryTable from '@/components/people-accounts/AccountsRegistryTable.vue';
import PeopleAccountsSidebar from '@/components/people-accounts/PeopleAccountsSidebar.vue';
import PeopleRegistryTable from '@/components/people-accounts/PeopleRegistryTable.vue';
import PersonFormDialog from '@/components/people-accounts/PersonFormDialog.vue';
import PersonImportDialog from '@/components/people-accounts/PersonImportDialog.vue';
import RegistryInspector from '@/components/people-accounts/RegistryInspector.vue';
import RegistryToolbar from '@/components/people-accounts/RegistryToolbar.vue';
import SourceFormDialog from '@/components/people-accounts/SourceFormDialog.vue';
import SourceInspector from '@/components/people-accounts/SourceInspector.vue';
import SourcesRegistryTable from '@/components/people-accounts/SourcesRegistryTable.vue';
import { usePeopleAccountsRegistry } from '@/composables/usePeopleAccountsRegistry';
import AppWorkspace from '@/layouts/AppWorkspace.vue';
import type {
    RegistryAccount,
    RegistryPerson,
    RegistrySelection,
    RegistryView,
    ProspectSource,
} from '@/types';

const props = defineProps<{
    people: RegistryPerson[];
    accounts: RegistryAccount[];
    initialView?: RegistryView;
    sources?: ProspectSource[];
    accountOptions?: { id: number; name: string }[];
    preselect?: {
        kind: 'person' | 'account' | 'source';
        id: number | string;
    } | null;
}>();
const page = usePage();
const isOwner = computed(() => page.props.auth?.workspace?.role === 'owner');

const {
    view,
    search,
    activeFilters,
    selection,
    filterGroups,
    filteredPeople,
    filteredAccounts,
    filteredSources,
    setView,
    toggleFilter,
    clearFilters,
} = usePeopleAccountsRegistry(
    () => props.people,
    () => props.accounts,
    () => props.sources ?? [],
    () => props.initialView,
);

const returnFocus = ref<HTMLElement | null>(null);
const accountFormOpen = ref(false);
const personFormOpen = ref(false);
const importOpen = ref(false);
const sourceFormOpen = ref(false);
const editingAccount = ref<RegistryAccount | null>(null);
const editingPerson = ref<RegistryPerson | null>(null);
const defaultAccountId = ref<number | null>(null);
const accountOptions = computed(
    () =>
        props.accountOptions ??
        props.accounts.map(({ id, name }) => ({ id, name })),
);
const selectedId = computed(() => selection.value?.record.id ?? null);
const pageTitle = computed(() =>
    view.value === 'people'
        ? 'People'
        : view.value === 'accounts'
          ? 'Accounts'
          : 'Sources',
);
const pageCopy = computed(() =>
    view.value === 'people'
        ? 'Find a person, understand their account, and keep their history independent of campaigns.'
        : view.value === 'accounts'
          ? 'Browse company records and open an account to see its people and campaign readiness.'
          : 'Keep imports, selected records, and their outcomes traceable to a durable source.',
);

onMounted(() => {
    const selected = props.preselect;

    if (!selected) {
        return;
    }

    if (selected.kind === 'person') {
        const person = props.people.find(
            (candidate) => candidate.id === selected.id,
        );

        if (person) {
            setView('people');
            selectRecord({ kind: 'person', record: person });
        }

        return;
    }

    if (selected.kind === 'source') {
        const source = (props.sources ?? []).find(
            (candidate) => candidate.id === selected.id,
        );

        if (source) {
            setView('sources');
            selectRecord({ kind: 'source', record: source });
        }

        return;
    }

    const account = props.accounts.find(
        (candidate) => candidate.id === selected.id,
    );

    if (account) {
        setView('accounts');
        selectRecord({ kind: 'account', record: account });
    }
});

function selectRecord(
    next: Exclude<RegistrySelection, null>,
    trigger?: HTMLElement,
): void {
    if (trigger) {
        returnFocus.value = trigger;
    }

    selection.value = next;
}

function selectPerson(person: RegistryPerson, trigger: HTMLElement): void {
    selectRecord({ kind: 'person', record: person }, trigger);
}

function selectAccount(account: RegistryAccount, trigger: HTMLElement): void {
    selectRecord({ kind: 'account', record: account }, trigger);
}

function selectAccountPerson(id: number): void {
    const person = props.people.find((candidate) => candidate.id === id);

    if (person) {
        selectRecord({ kind: 'person', record: person });
    }
}

function selectPersonAccount(id: number): void {
    const account = props.accounts.find((candidate) => candidate.id === id);

    if (account) {
        selectRecord({ kind: 'account', record: account });
    }
}

async function closeInspector(): Promise<void> {
    selection.value = null;
    await nextTick();
    returnFocus.value?.focus({ preventScroll: true });
}

function openCreate(): void {
    if (view.value === 'people') {
        openCreatePerson();

        return;
    }

    if (view.value === 'sources') {
        sourceFormOpen.value = true;

        return;
    }

    openCreateAccount();
}

function openCreateAccount(): void {
    editingAccount.value = null;
    accountFormOpen.value = true;
}

function openCreatePerson(accountId: number | null = null): void {
    editingPerson.value = null;
    defaultAccountId.value = accountId;
    personFormOpen.value = true;
}

function openEdit(): void {
    if (selection.value?.kind === 'person') {
        editingPerson.value = selection.value.record;
        defaultAccountId.value = null;
        personFormOpen.value = true;

        return;
    }

    if (selection.value?.kind === 'account') {
        editingAccount.value = selection.value.record;
        accountFormOpen.value = true;
    }
}

function openAddAccountPerson(): void {
    if (selection.value?.kind !== 'account') {
        return;
    }

    openCreatePerson(selection.value.record.id);
}

function selectSource(source: ProspectSource, trigger: HTMLElement): void {
    selectRecord({ kind: 'source', record: source }, trigger);
}

function closeAccountForm(): void {
    accountFormOpen.value = false;
    editingAccount.value = null;
}

function closePersonForm(): void {
    personFormOpen.value = false;
    editingPerson.value = null;
    defaultAccountId.value = null;
}

function closeSourceForm(): void {
    sourceFormOpen.value = false;
}
</script>

<template>
    <Head :title="pageTitle" />
    <AppWorkspace
        :area="
            view === 'accounts'
                ? 'accounts'
                : view === 'sources'
                  ? 'sources'
                  : 'people'
        "
        :title="pageTitle"
    >
        <template #sidebar>
            <PeopleAccountsSidebar
                :view="view"
                :people-count="people.length"
                :accounts-count="accounts.length"
                :sources-count="(props.sources ?? []).length"
                @change="setView"
            />
        </template>

        <main class="people-accounts-index">
            <header class="registry-page-head">
                <div>
                    <p>Inventory</p>
                    <h1>{{ pageTitle }}</h1>
                    <span>{{ pageCopy }}</span>
                </div>
                <dl>
                    <div>
                        <dt>{{ accounts.length }}</dt>
                        <dd>accounts</dd>
                    </div>
                    <div>
                        <dt>{{ people.length }}</dt>
                        <dd>people</dd>
                    </div>
                    <div>
                        <dt>{{ (props.sources ?? []).length }}</dt>
                        <dd>sources</dd>
                    </div>
                </dl>
            </header>

            <RegistryToolbar
                :view="view"
                :search="search"
                :active-filters="activeFilters"
                :filter-groups="filterGroups"
                :is-owner="isOwner"
                @update:search="search = $event"
                @toggle-filter="toggleFilter"
                @clear-filters="clearFilters"
                @add="openCreate"
                @add-source="sourceFormOpen = true"
                @import="importOpen = true"
            />

            <PeopleRegistryTable
                v-if="view === 'people'"
                :people="filteredPeople"
                :selected-id="selectedId"
                @select="selectPerson"
            />
            <AccountsRegistryTable
                v-else-if="view === 'accounts'"
                :accounts="filteredAccounts"
                :selected-id="selectedId"
                @select="selectAccount"
            />
            <SourcesRegistryTable
                v-else
                :sources="filteredSources"
                :selected-id="
                    selection?.kind === 'source' ? selection.record.id : null
                "
                @select="selectSource"
            />
        </main>

        <RegistryInspector
            :selection="selection?.kind === 'source' ? null : selection"
            @close="closeInspector"
            @select-person="selectAccountPerson"
            @select-account="selectPersonAccount"
            @add-person="openAddAccountPerson"
            @edit="openEdit"
        />
        <SourceInspector
            :source="selection?.kind === 'source' ? selection.record : null"
            :is-owner="isOwner"
            @close="closeInspector"
        />
        <AccountFormDialog
            :open="accountFormOpen"
            :account="editingAccount"
            @close="closeAccountForm"
        />
        <PersonFormDialog
            :open="personFormOpen"
            :person="editingPerson"
            :accounts="accountOptions"
            :default-account-id="defaultAccountId"
            @close="closePersonForm"
        />
        <PersonImportDialog :open="importOpen" @close="importOpen = false" />
        <SourceFormDialog :open="sourceFormOpen" @close="closeSourceForm" />
    </AppWorkspace>
</template>

<style scoped>
.people-accounts-index {
    flex: 1;
    min-height: 0;
    overflow: auto;
    padding: 24px 26px 36px;
    background: var(--ob-surface);
}

.registry-page-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 21px;
}

.registry-page-head p {
    margin: 0;
    color: #6f685f;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.registry-page-head h1 {
    margin: 4px 0 6px;
    color: var(--ob-ink);
    font-size: 32px;
    font-weight: 650;
    letter-spacing: -0.04em;
    line-height: 1.08;
}

.registry-page-head > div > span {
    color: #6b655c;
    line-height: 1.5;
}

.registry-page-head dl {
    display: flex;
    gap: 28px;
    margin: 0;
}

.registry-page-head dl div {
    min-width: 60px;
}

.registry-page-head dt {
    color: #1a1814;
    font-size: 20px;
    font-weight: 700;
    letter-spacing: -0.03em;
}

.registry-page-head dd {
    margin: 1px 0 0;
    color: #6b655c;
    font-size: 10px;
}

:global(.side-people) {
    overflow-y: auto;
}

:global(.side-people .side-title) {
    color: #5f5951;
    font-weight: 600;
}

:global(.top .crumb) {
    display: flex;
    align-items: center;
    gap: 7px;
    color: #6b655c;
    font-size: 12px;
}

:global(.top .crumb strong) {
    color: #1a1814;
    font-weight: 600;
}

@media (max-width: 900px) {
    :global(.side-people) {
        min-height: 0;
        padding: 66px 12px 9px;
    }

    :global(.side-people .side-title) {
        position: absolute;
        top: 20px;
        left: 58px;
        margin: 0;
        color: #29251f;
        font-size: 13px;
        font-weight: 650;
        letter-spacing: -0.01em;
        text-transform: none;
    }

    .people-accounts-index {
        padding: 18px 12px 28px;
    }

    .registry-page-head dl {
        display: none;
    }
}

@media (max-width: 620px) {
    .registry-page-head {
        display: block;
    }
}
</style>
