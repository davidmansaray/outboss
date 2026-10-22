<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
const props = defineProps<{ campaignId: number; importId: number }>();
type Row = {
    row_number: number;
    email: string;
    first_name: string | null;
    company: string | null;
    issue: string;
};
const rows = ref<Row[]>([]);
const issue = ref('');
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const loading = ref(false);
const error = ref('');
let requestId = 0;
async function load() {
    const id = ++requestId;
    loading.value = true;
    error.value = '';

    try {
        const response = await fetch(
            `/campaigns/${props.campaignId}/recipient-imports/${props.importId}/rows?page=${page.value}&issue=${issue.value}`,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            },
        );

        if (!response.ok) {
            throw new Error('Failed');
        }

        const data = await response.json();

        if (id !== requestId) {
            return;
        }

        rows.value = data.data;
        lastPage.value = data.meta.last_page;
        total.value = data.meta.total;
    } catch {
        if (id === requestId) {
            error.value = 'Could not load mapped rows. Retry before importing.';
        }
    } finally {
        if (id === requestId) {
            loading.value = false;
        }
    }
}
watch(
    () => props.importId,
    () => {
        if (page.value !== 1) {
            page.value = 1;
        } else {
            void load();
        }
    },
    { immediate: true },
);
watch(issue, () => {
    if (page.value !== 1) {
        page.value = 1;
    } else {
        void load();
    }
});
watch(page, () => void load());
onBeforeUnmount(() => {
    requestId++;
});
</script>
<template>
    <section aria-label="Mapped import rows" class="import-rows">
        <label
            >Show rows
            <select v-model="issue" :disabled="loading">
                <option value="">All results</option>
                <option value="ready">Ready</option>
                <option value="invalid">Invalid email</option>
                <option value="duplicate">Duplicate</option>
                <option value="suppressed">Suppressed</option>
            </select></label
        >
        <p>
            Check personalization before importing. Missing names use “there”;
            missing companies use “your team”. Ready does not guarantee
            deliverability.
        </p>
        <p v-if="error" role="alert">
            {{ error }} <button type="button" @click="load">Retry</button>
        </p>
        <p v-if="loading" role="status">Loading rows…</p>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Row</th>
                        <th>Email</th>
                        <th>First name</th>
                        <th>Company</th>
                        <th>Result</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.row_number">
                        <td>{{ row.row_number }}</td>
                        <td>{{ row.email }}</td>
                        <td>{{ row.first_name || 'there (fallback)' }}</td>
                        <td>{{ row.company || 'your team (fallback)' }}</td>
                        <td>{{ row.issue }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-if="!loading && !error && !rows.length">
            No rows match this result.
        </p>
        <div class="pagination">
            <button
                type="button"
                :disabled="loading || page <= 1"
                @click="page--"
            >
                Previous rows</button
            ><span>{{ total }} rows · Page {{ page }} of {{ lastPage }}</span
            ><button
                type="button"
                :disabled="loading || page >= lastPage"
                @click="page++"
            >
                Next rows
            </button>
        </div>
    </section>
</template>
<style scoped>
.import-rows {
    margin: 16px 0;
    font-size: 12px;
}
.table-scroll {
    max-height: 280px;
    overflow: auto;
}
table {
    width: 100%;
    border-collapse: collapse;
}
th,
td {
    padding: 8px;
    text-align: left;
    border-bottom: 1px solid var(--ob-border);
    overflow-wrap: anywhere;
}
.pagination {
    display: flex;
    gap: 12px;
    justify-content: space-between;
    margin-top: 12px;
}
</style>
