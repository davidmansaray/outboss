<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';

export type EnrollablePerson = {
    id: number;
    name: string;
    email: string;
    account: string | null;
    eligibility: 'Eligible' | 'Suppressed';
    enrollable: boolean;
    reason: string | null;
};

type EnrollmentPage = {
    data: EnrollablePerson[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
};

const props = defineProps<{
    campaignId: number;
}>();

const emit = defineEmits<{
    enrolled: [];
}>();

const query = ref('');
const onlyEligible = ref(false);
const page = ref(1);
const people = ref<EnrollablePerson[]>([]);
const meta = ref<EnrollmentPage['meta'] | null>(null);
const selected = ref<number[]>([]);
const loading = ref(false);
const submitting = ref(false);
const error = ref('');
const searchInput = ref<HTMLInputElement | null>(null);
let debounce: ReturnType<typeof setTimeout> | null = null;
let requestId = 0;

const selectedCount = () => selected.value.length;
const selectionLimit = 500;

function toggle(person: EnrollablePerson): void {
    if (!person.enrollable || submitting.value) {
        return;
    }

    if (
        !selected.value.includes(person.id) &&
        selected.value.length >= selectionLimit
    ) {
        return;
    }

    selected.value = selected.value.includes(person.id)
        ? selected.value.filter((id) => id !== person.id)
        : [...selected.value, person.id];
}

async function load(): Promise<void> {
    const current = ++requestId;
    loading.value = true;
    error.value = '';

    const params = new URLSearchParams();
    params.set('page', String(page.value));

    if (query.value.trim() !== '') {
        params.set('query', query.value.trim());
    }

    if (onlyEligible.value) {
        params.set('only_eligible', '1');
    }

    try {
        const response = await fetch(
            `/campaigns/${props.campaignId}/enrollable-people?${params.toString()}`,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            },
        );

        if (!response.ok) {
            throw new Error('Could not load people.');
        }

        const payload = (await response.json()) as EnrollmentPage;

        if (current !== requestId) {
            return;
        }

        people.value = payload.data;
        meta.value = payload.meta;
        // Keep choices made on other pages or searches. Only remove a choice
        // when this response explicitly confirms it is no longer enrollable.
        selected.value = selected.value.filter(
            (id) =>
                !payload.data.some(
                    (person) => person.id === id && !person.enrollable,
                ),
        );
    } catch {
        if (current === requestId) {
            error.value = 'Could not load people from the registry.';
            people.value = [];
            meta.value = null;
        }
    } finally {
        if (current === requestId) {
            loading.value = false;
        }
    }
}

function scheduleLoad(): void {
    if (debounce) {
        clearTimeout(debounce);
    }

    debounce = setTimeout(() => {
        page.value = 1;
        void load();
    }, 300);
}

function submit(): void {
    if (selected.value.length === 0 || submitting.value || loading.value) {
        return;
    }

    error.value = '';
    submitting.value = true;

    router.post(
        `/campaigns/${props.campaignId}/enrollments`,
        { person_ids: selected.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                selected.value = [];
                emit('enrolled');
            },
            onError: (errors) => {
                error.value =
                    errors.person_ids ??
                    Object.values(errors)[0] ??
                    'Could not add the selected people.';
            },
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}

watch(onlyEligible, () => {
    page.value = 1;
    void load();
});

watch(page, () => {
    void load();
});

watch(query, () => {
    scheduleLoad();
});

void load();

onBeforeUnmount(() => {
    if (debounce) {
        clearTimeout(debounce);
    }
});

async function focusSearch(): Promise<void> {
    await nextTick();
    searchInput.value?.focus();
}

defineExpose({ focusSearch });
</script>

<template>
    <div class="enrollment-dialog">
        <label class="enrollment-search-label" for="enrollment-search"
            >Search people</label
        >
        <input
            id="enrollment-search"
            ref="searchInput"
            v-model="query"
            type="search"
            :disabled="submitting"
            placeholder="Search by name, email, or account"
        />
        <label class="enrollment-filter">
            <input
                v-model="onlyEligible"
                class="enrollment-eligible-filter"
                type="checkbox"
                :disabled="submitting"
            />
            Only eligible
        </label>

        <p v-if="error" class="enrollment-error" role="alert">{{ error }}</p>
        <p v-if="loading && !people.length" class="enrollment-help">
            Loading people…
        </p>
        <div
            v-else-if="meta && meta.total === 0 && query.trim() === ''"
            class="enrollment-empty"
        >
            <template v-if="onlyEligible">
                <p>
                    No eligible people found. People may already be enrolled or
                    suppressed.
                </p>
                <button type="button" @click="onlyEligible = false">
                    Show all people
                </button>
            </template>
            <template v-else>
                <p>Import or create people first.</p>
                <Link href="/prospects">Open Prospects</Link>
            </template>
        </div>
        <p v-else-if="meta && meta.total === 0" class="enrollment-help">
            No people match this search.
        </p>
        <ul v-else class="enrollment-list">
            <li v-for="person in people" :key="person.id">
                <label
                    class="enrollment-row"
                    :class="{ disabled: !person.enrollable }"
                >
                    <input
                        type="checkbox"
                        class="enrollment-select"
                        :checked="selected.includes(person.id)"
                        :disabled="
                            !person.enrollable ||
                            submitting ||
                            loading ||
                            (selected.length >= selectionLimit &&
                                !selected.includes(person.id))
                        "
                        :aria-label="`Select ${person.name}`"
                        @change="toggle(person)"
                    />
                    <span class="enrollment-copy">
                        <strong>{{ person.name }}</strong>
                        <small>{{ person.email }}</small>
                    </span>
                    <span class="enrollment-account">{{
                        person.account || '—'
                    }}</span>
                    <span
                        class="enrollment-pill"
                        :class="
                            person.eligibility === 'Suppressed'
                                ? 'tone-negative'
                                : 'tone-positive'
                        "
                    >
                        {{ person.reason || person.eligibility }}
                    </span>
                </label>
            </li>
        </ul>

        <div v-if="meta && meta.last_page > 1" class="enrollment-pager">
            <button
                type="button"
                class="btn ghost"
                :disabled="page <= 1 || loading || submitting"
                @click="page -= 1"
            >
                Previous
            </button>
            <span>Page {{ meta.current_page }} of {{ meta.last_page }}</span>
            <button
                type="button"
                class="btn ghost"
                :disabled="page >= meta.last_page || loading || submitting"
                @click="page += 1"
            >
                Next
            </button>
        </div>

        <p class="enrollment-help">Select up to 500 people per batch.</p>
        <div class="campaign-dialog-actions">
            <span class="enrollment-count" role="status"
                >{{ selectedCount() }} selected across all pages and
                searches</span
            >
            <button
                v-if="selectedCount() > 0"
                class="btn ghost"
                type="button"
                :disabled="submitting"
                @click="selected = []"
            >
                Clear selection
            </button>
            <button
                class="btn"
                type="button"
                :disabled="selectedCount() === 0 || loading || submitting"
                @click="submit"
            >
                {{
                    submitting
                        ? 'Adding…'
                        : `Add ${selectedCount()} ${selectedCount() === 1 ? 'person' : 'people'}`
                }}
            </button>
        </div>
    </div>
</template>

<style scoped>
.enrollment-dialog {
    display: grid;
    gap: 12px;
}
.enrollment-search-label {
    display: block;
    color: #4d4841;
    font-size: 11px;
    font-weight: 620;
}
.enrollment-dialog input[type='search'] {
    width: 100%;
    min-height: 42px;
}
.enrollment-filter {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #4d4841;
    font-size: 11px;
}
.enrollment-help,
.enrollment-error,
.enrollment-empty {
    margin: 0;
    color: #746d64;
    font-size: 12px;
    line-height: 1.5;
}
.enrollment-error {
    color: #a33a31;
}
.enrollment-empty {
    display: grid;
    gap: 8px;
}
.enrollment-empty a {
    color: var(--ob-accent-strong);
}
.enrollment-list {
    display: grid;
    max-height: 320px;
    margin: 0;
    padding: 0;
    overflow: auto;
    border-top: 1px solid #e3ddd4;
    border-bottom: 1px solid #e3ddd4;
    list-style: none;
}
.enrollment-row {
    display: grid;
    min-height: 58px;
    grid-template-columns: 18px minmax(0, 1.4fr) minmax(0, 0.8fr) auto;
    align-items: center;
    gap: 10px;
    padding: 8px 4px;
    border-bottom: 1px solid #efeae3;
    cursor: pointer;
}
.enrollment-row.disabled {
    opacity: 0.65;
    cursor: default;
}
.enrollment-copy {
    display: grid;
    min-width: 0;
    gap: 2px;
}
.enrollment-copy strong,
.enrollment-copy small,
.enrollment-account {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.enrollment-copy strong {
    color: #302c26;
    font-size: 12px;
}
.enrollment-copy small,
.enrollment-account {
    color: #817a71;
    font-size: 10px;
}
.enrollment-pill {
    border-radius: 999px;
    padding: 3px 8px;
    font-size: 9px;
    font-weight: 650;
}
.tone-positive {
    background: #e7f6ee;
    color: #167a57;
}
.tone-negative {
    background: #f8e8e6;
    color: #a33a31;
}
.enrollment-pager {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    color: #817a71;
    font-size: 10px;
}
.enrollment-count {
    margin-right: auto;
    color: #817a71;
    font-size: 11px;
}
.campaign-dialog-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 8px;
}
</style>
