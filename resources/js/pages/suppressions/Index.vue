<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import WorkspaceOpsSidebar from '@/components/WorkspaceOpsSidebar.vue';
import AppWorkspace from '@/layouts/AppWorkspace.vue';

type SuppressionRow = {
    id: number;
    email: string;
    reason: string;
    created_at: string | null;
};

defineProps<{
    entries: SuppressionRow[];
}>();

const page = usePage();
const flash = computed(
    () =>
        (page.props.flash as
            { success?: string; error?: string } | undefined) ?? {},
);

const confirming = ref<SuppressionRow | null>(null);
const removingId = ref<number | null>(null);

const reasonLabels: Record<string, string> = {
    unsubscribe: 'Unsubscribed',
    hard_bounce: 'Hard bounce',
    manual: 'Manual',
};

function reasonLabel(reason: string): string {
    return reasonLabels[reason] ?? reason.replaceAll('_', ' ');
}

function addedLabel(value: string | null): string {
    if (!value) {
        return 'Not recorded';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return 'Recently added';
    }

    return new Intl.DateTimeFormat(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(date);
}

function requestRemove(entry: SuppressionRow): void {
    confirming.value = entry;
}

function cancelRemove(): void {
    confirming.value = null;
}

function confirmRemove(): void {
    const entry = confirming.value;

    if (!entry) {
        return;
    }

    removingId.value = entry.id;
    confirming.value = null;
    router.delete(`/settings/suppressions/${entry.id}`, {
        preserveScroll: true,
        onFinish: () => {
            removingId.value = null;
        },
    });
}
</script>

<template>
    <Head title="Suppressions" />
    <AppWorkspace
        area="suppressions"
        title="Suppressions"
        :show-sidebar-title="false"
    >
        <template #sidebar>
            <WorkspaceOpsSidebar current="suppressions" />
        </template>
        <main class="suppressions-index">
            <header class="suppressions-page-heading">
                <div>
                    <h1>Suppressions</h1>
                    <p>
                        Addresses that will not be enrolled in any campaign
                        until you remove them.
                    </p>
                </div>
                <div class="suppressions-totals">
                    <span
                        ><strong>{{ entries.length }}</strong>
                        {{
                            entries.length === 1 ? 'address' : 'addresses'
                        }}</span
                    >
                </div>
            </header>

            <p v-if="flash.success" class="hint">{{ flash.success }}</p>
            <p v-if="flash.error" class="bad">{{ flash.error }}</p>

            <section
                v-if="entries.length"
                class="suppressions-list"
                aria-label="Suppressed addresses"
            >
                <div class="suppressions-list-head" aria-hidden="true">
                    <span>Address</span>
                    <span>Reason</span>
                    <span>Added</span>
                    <span />
                </div>
                <div
                    v-for="entry in entries"
                    :key="entry.id"
                    class="suppressions-row"
                    :data-test="`suppression-${entry.id}`"
                >
                    <span class="suppressions-row-primary">
                        <strong>{{ entry.email }}</strong>
                    </span>
                    <span class="suppressions-row-metric">
                        <small>Reason</small>
                        <span class="suppressions-reason">{{
                            reasonLabel(entry.reason)
                        }}</span>
                    </span>
                    <span class="suppressions-row-metric">
                        <small>Added</small>
                        <span>{{ addedLabel(entry.created_at) }}</span>
                    </span>
                    <span class="suppressions-row-action">
                        <button
                            class="btn ghost"
                            type="button"
                            :disabled="removingId === entry.id"
                            :data-test="`remove-suppression-${entry.id}`"
                            @click="requestRemove(entry)"
                        >
                            Remove
                        </button>
                    </span>
                </div>
            </section>

            <section v-else class="suppressions-empty">
                <p class="suppressions-eyebrow">Workspace list</p>
                <h2>No suppressed addresses</h2>
                <p>
                    Hard bounces and validated unsubscribes appear here. You can
                    still download an empty suppressions CSV from Data & export.
                </p>
            </section>
        </main>
    </AppWorkspace>
    <div
        v-if="confirming"
        class="modal-back"
        role="dialog"
        aria-modal="true"
        aria-labelledby="remove-suppression-title"
        @click.self="cancelRemove"
    >
        <div class="modal">
            <h2 id="remove-suppression-title">
                Remove {{ confirming.email }}?
            </h2>
            <p class="hint">
                They can be imported and enrolled again. This does not delete
                the person from the registry.
            </p>
            <div class="actions">
                <button
                    class="btn ghost"
                    type="button"
                    data-test="cancel-remove-suppression"
                    @click="cancelRemove"
                >
                    Cancel
                </button>
                <button
                    class="btn danger"
                    type="button"
                    data-test="confirm-remove-suppression"
                    @click="confirmRemove"
                >
                    Remove
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.suppressions-index {
    min-width: 0;
    padding: 36px 40px 64px;
}

.suppressions-page-heading {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 32px;
    padding-bottom: 28px;
    border-bottom: 1px solid var(--ob-border);
}

.suppressions-eyebrow {
    margin: 0 0 7px;
    color: var(--ob-focus);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.suppressions-page-heading h1,
.suppressions-empty h2 {
    margin: 0;
    color: #1f1c18;
    font-size: 28px;
    font-weight: 650;
    letter-spacing: -0.03em;
}

.suppressions-page-heading > div:first-child > p:last-child,
.suppressions-empty > p:not(.suppressions-eyebrow) {
    max-width: 620px;
    margin: 8px 0 0;
    color: #736c63;
    font-size: 13px;
    line-height: 1.55;
}

.suppressions-totals {
    display: flex;
    gap: 22px;
    color: #736c63;
    font-size: 12px;
    white-space: nowrap;
}

.suppressions-totals strong {
    color: #27231e;
    font-size: 15px;
}

.suppressions-list {
    margin-top: 8px;
}

.suppressions-list-head,
.suppressions-row {
    display: grid;
    grid-template-columns:
        minmax(200px, 1.4fr) minmax(120px, 0.7fr) minmax(110px, 0.6fr)
        minmax(90px, 0.45fr);
    align-items: center;
    gap: 16px;
}

.suppressions-list-head {
    min-height: 46px;
    border-bottom: 1px solid var(--ob-border);
    color: #8a8379;
    font-size: 10px;
    font-weight: 650;
    letter-spacing: 0.07em;
    text-transform: uppercase;
}

.suppressions-row {
    min-height: 72px;
    border-bottom: 1px solid var(--ob-border-soft);
}

.suppressions-row-primary strong {
    overflow: hidden;
    color: #27231e;
    font-size: 14px;
    font-weight: 640;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.suppressions-row-metric {
    display: grid;
    min-width: 0;
    gap: 4px;
    color: #736c63;
    font-size: 12px;
}

.suppressions-row-metric small {
    display: none;
}

.suppressions-reason {
    display: inline-flex;
    width: fit-content;
    border-radius: 999px;
    padding: 3px 7px;
    background: #efede9;
    color: #665f56;
    font-size: 9px;
    font-weight: 650;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.suppressions-row-action {
    display: flex;
    justify-content: flex-end;
}

.suppressions-empty {
    display: grid;
    min-height: 320px;
    place-content: center;
    justify-items: start;
}

:global(.side-suppressions .side-title) {
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
    :global(.side-suppressions) {
        position: relative;
        min-height: 0;
        padding: 66px 12px 9px;
    }

    :global(.side-suppressions .side-title) {
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

    .suppressions-index {
        padding: 24px 18px 40px;
    }

    .suppressions-totals {
        display: none;
    }
}

@media (max-width: 760px) {
    .suppressions-page-heading {
        align-items: flex-start;
        flex-direction: column;
    }

    .suppressions-list-head {
        display: none;
    }

    .suppressions-row {
        grid-template-columns: 1fr auto;
        gap: 8px 12px;
        padding: 16px 0;
    }

    .suppressions-row-metric {
        display: none;
    }

    .suppressions-row-metric:nth-of-type(2) {
        display: grid;
        grid-column: 1;
    }

    .suppressions-row-action {
        grid-column: 2;
        grid-row: 1 / span 2;
        align-items: center;
    }
}
</style>
