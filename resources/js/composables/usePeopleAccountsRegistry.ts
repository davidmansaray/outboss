import { computed, ref, toValue, watch } from 'vue';
import type { MaybeRefOrGetter } from 'vue';
import type {
    RegistryAccount,
    RegistryFilterGroup,
    RegistryPerson,
    RegistrySelection,
    RegistryView,
    ProspectSource,
} from '@/types';

const peopleFilterGroups: RegistryFilterGroup[] = [
    {
        label: 'Reply',
        options: [
            { id: 'interested', label: 'Interested' },
            { id: 'replied', label: 'Replied' },
            { id: 'awaiting', label: 'Awaiting reply' },
            { id: 'not-now', label: 'Not now' },
            { id: 'no-campaigns', label: 'No campaigns' },
        ],
    },
    {
        label: 'Eligibility',
        options: [
            { id: 'eligible', label: 'Eligible' },
            { id: 'suppressed', label: 'Suppressed' },
        ],
    },
    {
        label: 'Campaign',
        options: [
            { id: 'in-campaign', label: 'In a campaign' },
            { id: 'no-campaign', label: 'No campaign' },
        ],
    },
    {
        label: 'Account',
        options: [
            { id: 'has-account', label: 'Has an account' },
            { id: 'no-account', label: 'No account' },
        ],
    },
];

const accountFilterGroups: RegistryFilterGroup[] = [
    {
        label: 'Readiness',
        options: [
            { id: 'ready', label: 'Ready' },
            { id: 'review', label: 'Needs review' },
            { id: 'blocked', label: 'Blocked' },
            { id: 'empty', label: 'No people' },
        ],
    },
    {
        label: 'Campaign',
        options: [
            { id: 'in-campaign', label: 'In a campaign' },
            { id: 'no-campaign', label: 'No campaign' },
        ],
    },
    {
        label: 'People',
        options: [
            { id: 'has-people', label: 'Has people' },
            { id: 'no-people', label: 'No people' },
        ],
    },
];

const sourceFilterGroups: RegistryFilterGroup[] = [
    {
        label: 'Status',
        options: [
            { id: 'active', label: 'Active' },
            { id: 'paused', label: 'Paused' },
            { id: 'archived', label: 'Archived' },
        ],
    },
    {
        label: 'Kind',
        options: [
            { id: 'manual', label: 'Manual' },
            { id: 'csv_import', label: 'CSV import' },
            { id: 'api_import', label: 'API import' },
            { id: 'existing_accounts', label: 'Existing records' },
        ],
    },
];

function initialView(): RegistryView {
    if (typeof window === 'undefined') {
        return 'people';
    }

    const url = new URL(window.location.href);
    const requested =
        url.searchParams.get('view') ??
        (url.pathname === '/accounts' ? 'accounts' : 'people');

    return requested === 'accounts' || requested === 'sources'
        ? requested
        : 'people';
}

function matchesGroup(
    active: Set<string>,
    optionIds: string[],
    value: string,
): boolean {
    const selected = optionIds.filter((id) => active.has(id));

    return selected.length === 0 || selected.includes(value);
}

export function usePeopleAccountsRegistry(
    peopleSource: MaybeRefOrGetter<RegistryPerson[]>,
    accountsSource: MaybeRefOrGetter<RegistryAccount[]>,
    sourcesSource: MaybeRefOrGetter<ProspectSource[]> = [],
    requestedView?: MaybeRefOrGetter<RegistryView | undefined>,
) {
    const view = ref<RegistryView>(
        requestedView
            ? (toValue(requestedView) ?? initialView())
            : initialView(),
    );
    const search = ref('');
    const activeFilters = ref<Set<string>>(new Set());
    const selection = ref<RegistrySelection>(null);
    const people = computed(() => toValue(peopleSource));
    const accounts = computed(() => toValue(accountsSource));
    const sources = computed(() => toValue(sourcesSource));

    const filterGroups = computed(() =>
        view.value === 'people'
            ? peopleFilterGroups
            : view.value === 'accounts'
              ? accountFilterGroups
              : sourceFilterGroups,
    );

    const filteredPeople = computed(() => {
        const query = search.value.trim().toLocaleLowerCase();
        const active = activeFilters.value;

        return people.value.filter((person) => {
            const searchable = [
                person.name,
                person.email,
                person.account,
                person.role,
                person.campaign_label,
                ...person.tags,
            ]
                .filter(Boolean)
                .join(' ')
                .toLocaleLowerCase();
            const reply = {
                Interested: 'interested',
                Replied: 'replied',
                'Awaiting reply': 'awaiting',
                'Not now': 'not-now',
                'No campaigns': 'no-campaigns',
            }[person.reply];

            return (
                (!query || searchable.includes(query)) &&
                matchesGroup(
                    active,
                    [
                        'interested',
                        'replied',
                        'awaiting',
                        'not-now',
                        'no-campaigns',
                    ],
                    reply,
                ) &&
                matchesGroup(
                    active,
                    ['eligible', 'suppressed'],
                    person.eligibility.toLocaleLowerCase(),
                ) &&
                matchesGroup(
                    active,
                    ['in-campaign', 'no-campaign'],
                    person.campaigns.length ? 'in-campaign' : 'no-campaign',
                ) &&
                matchesGroup(
                    active,
                    ['has-account', 'no-account'],
                    person.account ? 'has-account' : 'no-account',
                )
            );
        });
    });

    const filteredAccounts = computed(() => {
        const query = search.value.trim().toLocaleLowerCase();
        const active = activeFilters.value;

        return accounts.value.filter((account) => {
            const searchable = [
                account.name,
                account.domain,
                account.website,
                account.profile,
                ...account.tags,
                ...account.people.map((person) => person.name),
            ]
                .filter(Boolean)
                .join(' ')
                .toLocaleLowerCase();
            const readiness = {
                Ready: 'ready',
                'Needs review': 'review',
                Blocked: 'blocked',
                'No people': 'empty',
            }[account.readiness];

            return (
                (!query || searchable.includes(query)) &&
                matchesGroup(
                    active,
                    ['ready', 'review', 'blocked', 'empty'],
                    readiness,
                ) &&
                matchesGroup(
                    active,
                    ['in-campaign', 'no-campaign'],
                    account.campaigns_count ? 'in-campaign' : 'no-campaign',
                ) &&
                matchesGroup(
                    active,
                    ['has-people', 'no-people'],
                    account.people_count ? 'has-people' : 'no-people',
                )
            );
        });
    });

    const filteredSources = computed(() => {
        const query = search.value.trim().toLocaleLowerCase();
        const active = activeFilters.value;

        return sources.value.filter((source) => {
            const searchable = [
                source.display_name,
                source.kind,
                source.status,
                source.retention_warning,
                ...(source.restrictions ?? []),
            ]
                .filter(Boolean)
                .join(' ')
                .toLocaleLowerCase();

            return (
                (!query || searchable.includes(query)) &&
                matchesGroup(
                    active,
                    ['active', 'paused', 'archived'],
                    source.status,
                ) &&
                matchesGroup(
                    active,
                    ['manual', 'csv_import', 'api_import', 'existing_accounts'],
                    source.kind,
                )
            );
        });
    });

    function setView(next: RegistryView): void {
        if (view.value === next) {
            return;
        }

        view.value = next;
        search.value = '';
        activeFilters.value = new Set();
        selection.value = null;
    }

    function toggleFilter(id: string): void {
        const next = new Set(activeFilters.value);

        if (next.has(id)) {
            next.delete(id);
        } else {
            next.add(id);
        }

        activeFilters.value = next;
    }

    if (requestedView) {
        watch(
            () => toValue(requestedView),
            (next) => {
                if (next) {
                    setView(next);
                }
            },
        );
    }

    function clearFilters(): void {
        activeFilters.value = new Set();
    }

    watch(view, (next) => {
        if (typeof window === 'undefined') {
            return;
        }

        const url = new URL(window.location.href);
        url.pathname = next === 'accounts' ? '/accounts' : '/prospects';
        url.searchParams.delete('person');
        url.searchParams.delete('account');
        url.searchParams.delete('source');

        if (next === 'sources') {
            url.searchParams.set('view', next);
        } else {
            url.searchParams.delete('view');
        }

        window.history.replaceState(window.history.state, '', url);
    });

    watch([people, accounts, sources], () => {
        const current = selection.value;

        if (!current) {
            return;
        }

        if (current.kind === 'person') {
            const next = people.value.find(
                (person) => person.id === current.record.id,
            );
            selection.value = next ? { kind: 'person', record: next } : null;

            return;
        }

        if (current.kind === 'account') {
            const next = accounts.value.find(
                (account) => account.id === current.record.id,
            );
            selection.value = next ? { kind: 'account', record: next } : null;

            return;
        }

        const next = sources.value.find(
            (source) => source.id === current.record.id,
        );
        selection.value = next ? { kind: 'source', record: next } : null;
    });

    return {
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
    };
}
