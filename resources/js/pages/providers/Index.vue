<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Check,
    ChevronRight,
    CircleDollarSign,
    LockKeyhole,
    ShieldCheck,
    X,
} from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import WorkspaceOpsSidebar from '@/components/WorkspaceOpsSidebar.vue';
import AppWorkspace from '@/layouts/AppWorkspace.vue';

type Tab = 'overview' | 'routing' | 'activity';
type ActivityView = 'usage' | 'history';
type ProviderStatus =
    'disconnected' | 'healthy' | 'rate_limited' | 'misconfigured' | 'disabled';
type Provider = {
    id: number | null;
    provider: 'openrouter' | 'tavily';
    displayName: string;
    capability: 'llm' | 'research';
    status: ProviderStatus;
    credentialDisplay: string | null;
    lastTestedAt: string | null;
    lastHealthyAt: string | null;
    retryAfterAt: string | null;
    safeError: { code: string; message: string | null } | null;
    balance: string | null;
    balanceCheckedAt: string | null;
};
type ModelPreset = {
    id: string;
    displayName: string;
    tasks: string[];
    contextTokens: number;
    validatedAt: string;
};
type TaskSetting = {
    task: string;
    displayName: string;
    modelId: string;
    perRunLimitMicros: number;
    policyVersion: number;
    requiresResearch: boolean;
};
type Budget = {
    currency: 'USD';
    monthlyLimitMicros: number;
    tavilyCreditPriceMicros: number;
    committedMicros: number;
    heldMicros: number;
    remainingMicros: number;
    overLimit: boolean;
    budgetBreach: boolean;
    resetsAt: string;
};
type UsageEntry = {
    id: number;
    occurredAt: string;
    provider: string;
    operation: string;
    task: string;
    modelOrEndpoint: string;
    nativeUnit: string;
    nativeUnits: number;
    costMicros: number;
    costBasis: string;
    providerRequestId: string | null;
    estimatedCostMicros: number;
    status: string;
    correlationId: string;
};
type AuditEntry = {
    id: number;
    event: string;
    actorType: string;
    fromState: string | null;
    toState: string | null;
    metadata: Record<string, unknown> | null;
    createdAt: string;
};
type Dialog =
    | { type: 'connect'; provider: Provider }
    | { type: 'manage'; provider: Provider }
    | { type: 'remove'; provider: Provider }
    | { type: 'task'; task: TaskSetting }
    | { type: 'budget' };

const props = defineProps<{
    initialTab: Tab;
    initialActivityView: ActivityView;
    isOwner: boolean;
    workspace: { id: number; name: string };
    providers: Provider[];
    models: ModelPreset[];
    tasks: TaskSetting[];
    budget: Budget;
    usage: UsageEntry[];
    usageFilters: {
        provider: string | null;
        task: string | null;
        status: string | null;
    };
    usagePagination: {
        currentPage: number;
        lastPage: number;
        total: number;
        previousUrl: string | null;
        nextUrl: string | null;
    };
    audit: AuditEntry[];
}>();

const page = usePage();
const activeTab = ref<Tab>(props.initialTab);
const activityView = ref<ActivityView>(props.initialActivityView);
const dialog = ref<Dialog | null>(null);
const dialogPanel = ref<HTMLElement | null>(null);
const dialogTrigger = ref<HTMLElement | null>(null);
const testingId = ref<number | null>(null);
const usageProvider = ref(props.usageFilters.provider ?? '');
const usageTask = ref(props.usageFilters.task ?? '');
const usageStatus = ref(props.usageFilters.status ?? '');
const credentialForm = useForm({ secret: '' });
const taskForm = useForm({ model_id: '', per_run_limit_usd: '' });
const budgetForm = useForm({
    monthly_limit_usd: microsToInput(props.budget.monthlyLimitMicros),
    tavily_credit_price_usd: microsToInput(
        props.budget.tavilyCreditPriceMicros,
        3,
    ),
});

const flash = computed(
    () =>
        (page.props.flash as
            { success?: string; error?: string } | undefined) ?? {},
);
const pageErrors = computed(
    () =>
        (page.props.errors as
            { provider?: string; secret?: string } | undefined) ?? {},
);
const connectedCount = computed(
    () => props.providers.filter((provider) => provider.id !== null).length,
);
const budgetUsed = computed(
    () => props.budget.committedMicros + props.budget.heldMicros,
);
const budgetPercent = computed(() => {
    if (props.budget.monthlyLimitMicros === 0) {
        return 100;
    }

    return Math.min(
        100,
        Math.round((budgetUsed.value / props.budget.monthlyLimitMicros) * 100),
    );
});
const budgetBlocked = computed(
    () =>
        props.budget.overLimit ||
        props.budget.budgetBreach ||
        props.budget.remainingMicros === 0,
);
const tabs = computed(() => {
    return [
        { id: 'overview' as const, label: 'Overview' },
        { id: 'routing' as const, label: 'Routing & limits' },
        { id: 'activity' as const, label: 'Activity' },
    ];
});

function usd(micros: number, decimals = 2): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(micros / 1_000_000);
}

function microsToInput(micros: number, decimals = 2): string {
    return (micros / 1_000_000).toFixed(decimals);
}

function dateTime(value: string | null): string {
    if (!value) {
        return 'Not yet';
    }

    const parsed = new Date(value);

    if (Number.isNaN(parsed.getTime())) {
        return 'Not yet';
    }

    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(parsed);
}

function statusLabel(status: ProviderStatus): string {
    return {
        disconnected: 'Disconnected',
        healthy: 'Healthy',
        rate_limited: 'Rate limited',
        misconfigured: 'Needs attention',
        disabled: 'Disabled',
    }[status];
}

function statusTone(status: ProviderStatus): string {
    if (status === 'healthy') {
        return 'good';
    }

    if (status === 'rate_limited' || status === 'disabled') {
        return 'warn';
    }

    if (status === 'misconfigured') {
        return 'bad';
    }

    return 'neutral';
}

function usageStatusLabel(status: string): string {
    return (
        {
            succeeded: 'Succeeded',
            reconciled: 'Reconciled',
            manual_review: 'Manual review',
        }[status] ?? status.replaceAll('_', ' ')
    );
}

function providerFor(name: string): Provider | undefined {
    return props.providers.find((provider) => provider.provider === name);
}

function taskReady(task: TaskSetting): boolean {
    if (budgetBlocked.value) {
        return false;
    }

    if (providerFor('openrouter')?.status !== 'healthy') {
        return false;
    }

    return (
        !task.requiresResearch || providerFor('tavily')?.status === 'healthy'
    );
}

function presetsFor(task: TaskSetting): ModelPreset[] {
    return props.models.filter((model) => model.tasks.includes(task.task));
}

async function openDialog(next: Dialog, event?: Event): Promise<void> {
    dialogTrigger.value =
        event?.currentTarget instanceof HTMLElement
            ? event.currentTarget
            : null;
    dialog.value = next;
    credentialForm.reset();
    credentialForm.clearErrors();

    if (next.type === 'task') {
        taskForm.model_id = next.task.modelId;
        taskForm.per_run_limit_usd = microsToInput(next.task.perRunLimitMicros);
        taskForm.clearErrors();
    }

    if (next.type === 'budget') {
        budgetForm.monthly_limit_usd = microsToInput(
            props.budget.monthlyLimitMicros,
        );
        budgetForm.tavily_credit_price_usd = microsToInput(
            props.budget.tavilyCreditPriceMicros,
            3,
        );
        budgetForm.clearErrors();
    }

    await nextTick();
    const initialFocus =
        dialogPanel.value?.querySelector<HTMLElement>('[data-autofocus]') ??
        dialogPanel.value?.querySelector<HTMLElement>('button, input, select');
    initialFocus?.focus();
}

async function closeDialog(): Promise<void> {
    dialog.value = null;
    credentialForm.reset();
    await nextTick();
    dialogTrigger.value?.focus();
    dialogTrigger.value = null;
}

function submitCredential(): void {
    const current = dialog.value;

    if (!current || (current.type !== 'connect' && current.type !== 'manage')) {
        return;
    }

    const path =
        current.type === 'connect'
            ? `/settings/providers/${current.provider.provider}/connect`
            : `/settings/providers/${current.provider.id}/rotate`;
    const method = current.type === 'connect' ? 'post' : 'put';
    credentialForm.submit(method, path, {
        preserveScroll: true,
        onSuccess: () => void closeDialog(),
    });
}

function testProvider(provider: Provider): void {
    if (!provider.id) {
        return;
    }

    testingId.value = provider.id;
    router.post(
        `/settings/providers/${provider.id}/test`,
        {},
        {
            preserveScroll: true,
            onFinish: () => (testingId.value = null),
        },
    );
}

function setProviderEnabled(provider: Provider, enabled: boolean): void {
    if (!provider.id) {
        return;
    }

    router.patch(
        `/settings/providers/${provider.id}/status`,
        { enabled },
        { preserveScroll: true, onSuccess: () => void closeDialog() },
    );
}

function removeProvider(): void {
    const current = dialog.value;

    if (current?.type !== 'remove' || !current.provider.id) {
        return;
    }

    router.delete(`/settings/providers/${current.provider.id}`, {
        preserveScroll: true,
        onSuccess: () => void closeDialog(),
    });
}

function submitTask(): void {
    const current = dialog.value;

    if (current?.type !== 'task') {
        return;
    }

    taskForm.put(`/settings/providers/models/${current.task.task}`, {
        preserveScroll: true,
        onSuccess: () => void closeDialog(),
    });
}

function submitBudget(): void {
    budgetForm.put('/settings/providers/budget', {
        preserveScroll: true,
        onSuccess: () => void closeDialog(),
    });
}

function applyUsageFilters(): void {
    router.get(
        '/settings/ai-research/activity',
        {
            provider: usageProvider.value || undefined,
            task: usageTask.value || undefined,
            status: usageStatus.value || undefined,
        },
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

function handleDialogKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        event.preventDefault();
        void closeDialog();

        return;
    }

    if (event.key !== 'Tab' || !dialogPanel.value) {
        return;
    }

    const focusable = Array.from(
        dialogPanel.value.querySelectorAll<HTMLElement>(
            'button:not([disabled]), input:not([disabled]), select:not([disabled]), [href], [tabindex]:not([tabindex="-1"])',
        ),
    );

    if (!focusable.length) {
        return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

function handleGlobalEscape(event: KeyboardEvent): void {
    if (event.key === 'Escape' && dialog.value !== null) {
        void closeDialog();
    }
}

onMounted(() => document.addEventListener('keydown', handleGlobalEscape));
onBeforeUnmount(() =>
    document.removeEventListener('keydown', handleGlobalEscape),
);
</script>

<template>
    <Head title="AI & research" />
    <AppWorkspace
        area="providers"
        title="AI & research"
        :show-sidebar-title="false"
    >
        <template #sidebar>
            <WorkspaceOpsSidebar current="providers" />
        </template>
        <main class="providers-index" :inert="dialog !== null">
            <header class="providers-page-heading">
                <div>
                    <h1>AI & research</h1>
                    <p>
                        Connect the services OutBoss uses for research and
                        writing. Choose task models and set spend limits.
                    </p>
                </div>
                <span v-if="!isOwner" class="provider-status info"
                    >Member view</span
                >
            </header>

            <p v-if="flash.success" class="providers-flash good" role="status">
                <Check aria-hidden="true" /> {{ flash.success }}
            </p>
            <p v-if="flash.error" class="providers-flash bad" role="alert">
                <AlertTriangle aria-hidden="true" /> {{ flash.error }}
            </p>
            <p
                v-if="pageErrors.provider"
                class="providers-flash bad"
                role="alert"
            >
                <AlertTriangle aria-hidden="true" />
                {{ pageErrors.provider }}
            </p>
            <div v-if="!isOwner" class="providers-notice info">
                <LockKeyhole aria-hidden="true" />
                <span
                    ><strong>Read-only workspace access</strong>Only workspace
                    owners can change credentials, models, pricing assumptions,
                    or spending limits.</span
                >
            </div>
            <div
                v-if="budgetBlocked"
                class="providers-notice bad"
                data-test="budget-blocked"
            >
                <AlertTriangle aria-hidden="true" />
                <span
                    ><strong>New AI work is budget-blocked</strong>Existing
                    reservations may finish, but no new service calls will be
                    admitted. Service health is shown separately below.</span
                >
            </div>

            <nav class="providers-tabs" aria-label="AI and research settings">
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    type="button"
                    :class="{ active: activeTab === tab.id }"
                    :aria-current="activeTab === tab.id ? 'page' : undefined"
                    @click="activeTab = tab.id"
                >
                    {{ tab.label }}
                </button>
            </nav>

            <section
                v-if="activeTab === 'overview'"
                class="providers-tab-panel"
            >
                <header class="providers-section-heading">
                    <span>
                        <h2>Services</h2>
                        <p>
                            Keys belong to this workspace, are encrypted, and
                            are never shown again.
                        </p>
                    </span>
                    <small
                        >{{ connectedCount }} of
                        {{ providers.length }} connected</small
                    >
                </header>
                <div class="service-grid">
                    <article
                        v-for="provider in providers"
                        :key="provider.provider"
                        class="service-card"
                        data-test="provider-row"
                    >
                        <header>
                            <span
                                class="provider-mark"
                                :class="provider.provider"
                                >{{
                                    provider.provider === 'openrouter'
                                        ? 'OR'
                                        : 'T'
                                }}</span
                            >
                            <span>
                                <strong>{{ provider.displayName }}</strong>
                                <small>{{
                                    provider.capability === 'llm'
                                        ? 'Language models'
                                        : 'Web research'
                                }}</small>
                            </span>
                            <span
                                class="provider-status"
                                :class="statusTone(provider.status)"
                                >{{ statusLabel(provider.status) }}</span
                            >
                        </header>
                        <dl>
                            <div>
                                <dt>Key</dt>
                                <dd>
                                    {{
                                        provider.credentialDisplay ??
                                        'No key added'
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt>Last checked</dt>
                                <dd>{{ dateTime(provider.lastTestedAt) }}</dd>
                            </div>
                        </dl>
                        <footer v-if="isOwner">
                            <button
                                v-if="provider.id"
                                class="btn ghost"
                                type="button"
                                :disabled="testingId === provider.id"
                                @click="testProvider(provider)"
                            >
                                {{
                                    testingId === provider.id
                                        ? 'Checking…'
                                        : 'Check service'
                                }}
                            </button>
                            <button
                                v-if="provider.id"
                                class="btn ghost"
                                type="button"
                                @click="
                                    openDialog(
                                        { type: 'manage', provider },
                                        $event,
                                    )
                                "
                            >
                                Manage key
                            </button>
                            <button
                                v-else
                                class="btn ghost"
                                type="button"
                                @click="
                                    openDialog(
                                        { type: 'connect', provider },
                                        $event,
                                    )
                                "
                            >
                                Connect
                            </button>
                        </footer>
                    </article>
                </div>
                <header
                    class="providers-section-heading workspace-controls-heading"
                >
                    <span><h2>Workspace controls</h2></span>
                </header>
                <div class="workspace-controls-grid">
                    <section>
                        <h3>Data handling</h3>
                        <dl class="provider-detail">
                            <dt>OpenRouter retention</dt>
                            <dd>Zero retention required</dd>
                            <dt>Collection</dt>
                            <dd>Denied</dd>
                            <dt>Automatic fallback</dt>
                            <dd>Disabled</dd>
                        </dl>
                    </section>
                    <section>
                        <h3>Spending</h3>
                        <strong class="control-value"
                            >{{ usd(budget.remainingMicros) }} available</strong
                        >
                        <p>
                            {{ usd(budget.committedMicros) }} committed ·
                            {{ usd(budget.heldMicros) }} reserved
                        </p>
                        <button
                            type="button"
                            class="text-action"
                            @click="activeTab = 'routing'"
                        >
                            Manage routing & limits →
                        </button>
                    </section>
                </div>
            </section>

            <section
                v-else-if="activeTab === 'routing'"
                class="providers-tab-panel"
            >
                <div class="budget-metrics">
                    <span>
                        <span class="budget-metric-topline">
                            <small>Monthly limit</small>
                            <button
                                v-if="isOwner"
                                class="budget-edit-button"
                                type="button"
                                aria-label="Edit workspace spending limits"
                                @click="openDialog({ type: 'budget' }, $event)"
                            >
                                Edit
                            </button>
                        </span>
                        <strong>{{ usd(budget.monthlyLimitMicros) }}</strong>
                        <em>Resets {{ dateTime(budget.resetsAt) }}</em>
                    </span>
                    <span
                        ><small>Committed</small
                        ><strong>{{ usd(budget.committedMicros) }}</strong
                        ><em>Settled usage</em></span
                    >
                    <span
                        ><small>Reserved</small
                        ><strong>{{ usd(budget.heldMicros) }}</strong
                        ><em>Held for active or uncertain calls</em></span
                    >
                    <span
                        ><small>Available</small
                        ><strong>{{ usd(budget.remainingMicros) }}</strong
                        ><em>For new work</em></span
                    >
                </div>
                <div class="providers-panel">
                    <div class="providers-panel-head">
                        <span
                            ><h2>Task defaults</h2>
                            <p>
                                One approved model and one maximum cost for each
                                task.
                            </p></span
                        ><span class="provider-chip">No fallback</span>
                    </div>
                    <div class="task-list">
                        <article
                            v-for="task in tasks"
                            :key="task.task"
                            class="task-row"
                            data-test="task-row"
                        >
                            <span
                                ><small>Task profile</small
                                ><strong>{{ task.displayName }}</strong
                                ><em>{{
                                    task.requiresResearch
                                        ? 'OpenRouter + Tavily'
                                        : 'OpenRouter'
                                }}</em></span
                            >
                            <span
                                ><small>Default model</small
                                ><strong>{{
                                    models.find(
                                        (model) => model.id === task.modelId,
                                    )?.displayName ?? task.modelId
                                }}</strong></span
                            >
                            <span
                                ><small>Per-task limit</small
                                ><strong class="mono">{{
                                    usd(task.perRunLimitMicros)
                                }}</strong></span
                            >
                            <span class="task-action"
                                ><span
                                    class="provider-status"
                                    :class="taskReady(task) ? 'good' : 'bad'"
                                    >{{
                                        taskReady(task)
                                            ? 'Ready'
                                            : budgetBlocked
                                              ? 'Budget blocked'
                                              : 'Service unavailable'
                                    }}</span
                                ><button
                                    v-if="isOwner"
                                    class="btn ghost"
                                    type="button"
                                    @click="
                                        openDialog(
                                            { type: 'task', task },
                                            $event,
                                        )
                                    "
                                >
                                    Edit
                                </button></span
                            >
                        </article>
                    </div>
                </div>
                <section class="providers-panel spending-policy-panel">
                    <div class="providers-panel-head">
                        <span
                            ><h2>Spending policy</h2>
                            <p>
                                New work must fit both the monthly and per-task
                                limits before a service is called.
                            </p></span
                        >
                    </div>
                    <div class="providers-panel-body">
                        <div
                            class="budget-progress large"
                            :class="{ blocked: budgetBlocked }"
                        >
                            <span :style="{ width: `${budgetPercent}%` }" />
                        </div>
                        <p class="budget-policy">
                            <CircleDollarSign aria-hidden="true" />USD only · no
                            exchange-rate conversion. Tavily is reserved at
                            {{ usd(budget.tavilyCreditPriceMicros, 3) }} per
                            credit.
                        </p>
                    </div>
                </section>
            </section>

            <section
                v-else-if="activeTab === 'activity' && activityView === 'usage'"
                class="providers-tab-panel"
            >
                <nav class="activity-switcher" aria-label="Activity views">
                    <button type="button" class="active" aria-current="page">
                        Usage
                    </button>
                    <button
                        v-if="isOwner"
                        type="button"
                        @click="activityView = 'history'"
                    >
                        Change history
                    </button>
                </nav>
                <div class="providers-panel">
                    <div class="providers-panel-head">
                        <span
                            ><h2>Usage</h2>
                            <p>
                                Service calls and costs. Prompts, queries,
                                responses, and URLs are not stored here.
                            </p></span
                        ><span class="provider-chip"
                            >{{ usagePagination.total }} entries</span
                        >
                    </div>
                    <form
                        v-if="usagePagination.total > 0"
                        class="usage-filters"
                        aria-label="Filter usage"
                        @submit.prevent="applyUsageFilters"
                    >
                        <label
                            ><span>Service</span
                            ><select v-model="usageProvider">
                                <option value="">All services</option>
                                <option value="openrouter">OpenRouter</option>
                                <option value="tavily">Tavily</option>
                            </select></label
                        ><label
                            ><span>Task</span
                            ><select v-model="usageTask">
                                <option value="">All tasks</option>
                                <option value="connection_test">
                                    Connection test
                                </option>
                                <option
                                    v-for="task in tasks"
                                    :key="task.task"
                                    :value="task.task"
                                >
                                    {{ task.displayName }}
                                </option>
                            </select></label
                        ><label
                            ><span>Status</span
                            ><select v-model="usageStatus">
                                <option value="">All statuses</option>
                                <option value="succeeded">Succeeded</option>
                                <option value="reconciled">Reconciled</option>
                                <option value="manual_review">
                                    Manual review
                                </option>
                            </select></label
                        ><button class="btn ghost" type="submit">
                            Apply filters
                        </button>
                    </form>
                    <div v-if="usage.length" class="provider-table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Task / operation</th>
                                    <th>Provider</th>
                                    <th>Native units</th>
                                    <th>Basis</th>
                                    <th>Estimated / settled</th>
                                    <th>Status / correlation</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="entry in usage" :key="entry.id">
                                    <td>{{ dateTime(entry.occurredAt) }}</td>
                                    <td>
                                        <strong>{{
                                            entry.task.replaceAll('_', ' ')
                                        }}</strong
                                        ><small
                                            >{{ entry.operation }} ·
                                            {{ entry.modelOrEndpoint }}</small
                                        >
                                    </td>
                                    <td>{{ entry.provider }}</td>
                                    <td class="mono">
                                        {{ entry.nativeUnits }}
                                        {{ entry.nativeUnit }}
                                    </td>
                                    <td>
                                        {{
                                            entry.costBasis.replaceAll('_', ' ')
                                        }}
                                    </td>
                                    <td class="mono">
                                        {{ usd(entry.estimatedCostMicros, 3) }}
                                        /
                                        {{ usd(entry.costMicros, 3) }}
                                    </td>
                                    <td>
                                        <strong>{{
                                            usageStatusLabel(entry.status)
                                        }}</strong
                                        ><small
                                            class="mono"
                                            :title="entry.correlationId"
                                            >{{
                                                entry.correlationId.slice(0, 8)
                                            }}</small
                                        >
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <nav
                        v-if="usagePagination.lastPage > 1"
                        class="usage-pagination"
                        aria-label="Usage pages"
                    >
                        <Link
                            v-if="usagePagination.previousUrl"
                            class="btn ghost"
                            :href="usagePagination.previousUrl"
                            preserve-scroll
                            >Previous</Link
                        ><span
                            >Page {{ usagePagination.currentPage }} of
                            {{ usagePagination.lastPage }}</span
                        ><Link
                            v-if="usagePagination.nextUrl"
                            class="btn ghost"
                            :href="usagePagination.nextUrl"
                            preserve-scroll
                            >Next</Link
                        >
                    </nav>
                    <div v-else class="providers-empty">
                        <CircleDollarSign aria-hidden="true" /><strong
                            >No activity yet</strong
                        >
                        <p>
                            Usage appears here after a service check or task
                            call.
                        </p>
                    </div>
                </div>
            </section>

            <section v-else class="providers-tab-panel">
                <nav class="activity-switcher" aria-label="Activity views">
                    <button type="button" @click="activityView = 'usage'">
                        Usage
                    </button>
                    <button type="button" class="active" aria-current="page">
                        Change history
                    </button>
                </nav>
                <div class="providers-panel">
                    <div class="providers-panel-head">
                        <span
                            ><h2>Change history</h2>
                            <p>
                                Owner-only record of service keys, task models,
                                pricing, and spending changes.
                            </p></span
                        ><span class="provider-chip">Latest 50</span>
                    </div>
                    <div v-if="audit.length" class="provider-table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>When</th>
                                    <th>Actor</th>
                                    <th>Action</th>
                                    <th>Change</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="entry in audit" :key="entry.id">
                                    <td>{{ dateTime(entry.createdAt) }}</td>
                                    <td>{{ entry.actorType }}</td>
                                    <td>
                                        {{ entry.event.replaceAll('_', ' ') }}
                                    </td>
                                    <td>
                                        {{ entry.fromState || '—' }}
                                        <ChevronRight aria-hidden="true" />
                                        {{ entry.toState || 'Recorded' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-else class="providers-empty">
                        <ShieldCheck aria-hidden="true" /><strong
                            >No changes yet</strong
                        >
                        <p>
                            Credential, model, pricing, budget, and health
                            changes will be recorded here.
                        </p>
                    </div>
                </div>
            </section>
        </main>
    </AppWorkspace>

    <div
        v-if="dialog"
        class="provider-modal-back"
        role="presentation"
        @click.self="closeDialog"
    >
        <section
            ref="dialogPanel"
            class="provider-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="provider-dialog-title"
            @keydown="handleDialogKeydown"
        >
            <header>
                <span
                    ><p class="providers-eyebrow">Workspace control</p>
                    <h2 id="provider-dialog-title">
                        <template v-if="dialog.type === 'connect'"
                            >Connect {{ dialog.provider.displayName }}</template
                        >
                        <template v-else-if="dialog.type === 'manage'"
                            >Manage {{ dialog.provider.displayName }}</template
                        >
                        <template v-else-if="dialog.type === 'remove'"
                            >Remove {{ dialog.provider.displayName }}?</template
                        >
                        <template v-else-if="dialog.type === 'task'"
                            >Edit {{ dialog.task.displayName }}</template
                        >
                        <template v-else>Change workspace limits</template>
                    </h2></span
                ><button
                    class="btn ghost icon"
                    type="button"
                    aria-label="Close"
                    @click="closeDialog"
                >
                    <X aria-hidden="true" />
                </button>
            </header>

            <form
                v-if="dialog.type === 'connect' || dialog.type === 'manage'"
                @submit.prevent="submitCredential"
            >
                <div class="provider-modal-body">
                    <div
                        v-if="dialog.type === 'manage'"
                        class="providers-notice info"
                    >
                        <LockKeyhole aria-hidden="true" /><span
                            ><strong>The saved key stays hidden</strong>Rotating
                            replaces the encrypted workspace credential only
                            after the new key passes validation.</span
                        >
                    </div>
                    <label class="provider-field"
                        ><span>{{
                            dialog.type === 'connect'
                                ? 'API key'
                                : 'New API key'
                        }}</span
                        ><input
                            v-model="credentialForm.secret"
                            data-autofocus
                            type="password"
                            autocomplete="new-password"
                            required
                            minlength="8"
                            placeholder="Paste the service API key"
                        /><small
                            >The credential belongs to {{ workspace.name }} and
                            will never be returned to the browser.</small
                        ><em v-if="credentialForm.errors.secret" role="alert">{{
                            credentialForm.errors.secret
                        }}</em></label
                    >
                    <div v-if="dialog.type === 'manage'" class="manage-actions">
                        <button
                            class="btn ghost"
                            type="button"
                            @click="testProvider(dialog.provider)"
                        >
                            Check service</button
                        ><button
                            class="btn ghost"
                            type="button"
                            @click="
                                setProviderEnabled(
                                    dialog.provider,
                                    dialog.provider.status === 'disabled',
                                )
                            "
                        >
                            {{
                                dialog.provider.status === 'disabled'
                                    ? 'Enable'
                                    : 'Disable'
                            }}</button
                        ><button
                            class="btn danger"
                            type="button"
                            @click="
                                openDialog(
                                    {
                                        type: 'remove',
                                        provider: dialog.provider,
                                    },
                                    $event,
                                )
                            "
                        >
                            Remove from OutBoss
                        </button>
                    </div>
                </div>
                <footer>
                    <button
                        class="btn ghost"
                        type="button"
                        @click="closeDialog"
                    >
                        Cancel</button
                    ><button
                        class="btn"
                        type="submit"
                        :disabled="credentialForm.processing"
                    >
                        {{
                            credentialForm.processing
                                ? 'Validating…'
                                : dialog.type === 'connect'
                                  ? 'Validate and connect'
                                  : 'Rotate key'
                        }}
                    </button>
                </footer>
            </form>

            <form
                v-else-if="dialog.type === 'task'"
                @submit.prevent="submitTask"
            >
                <div class="provider-modal-body">
                    <label class="provider-field"
                        ><span>Curated model</span
                        ><select
                            v-model="taskForm.model_id"
                            data-autofocus
                            required
                        >
                            <option
                                v-for="model in presetsFor(dialog.task)"
                                :key="model.id"
                                :value="model.id"
                            >
                                {{ model.displayName }}
                            </option></select
                        ><small
                            >No fallback is used if the selected model becomes
                            unavailable.</small
                        ></label
                    ><label class="provider-field"
                        ><span>Per-run limit (USD)</span
                        ><input
                            v-model="taskForm.per_run_limit_usd"
                            inputmode="decimal"
                            required
                            pattern="\d+(\.\d{1,6})?"
                        /><em
                            v-if="taskForm.errors.per_run_limit_usd"
                            role="alert"
                            >{{ taskForm.errors.per_run_limit_usd }}</em
                        ></label
                    >
                </div>
                <footer>
                    <button
                        class="btn ghost"
                        type="button"
                        @click="closeDialog"
                    >
                        Cancel</button
                    ><button
                        class="btn"
                        type="submit"
                        :disabled="taskForm.processing"
                    >
                        Save task defaults
                    </button>
                </footer>
            </form>

            <form
                v-else-if="dialog.type === 'budget'"
                @submit.prevent="submitBudget"
            >
                <div class="provider-modal-body">
                    <div class="providers-notice info">
                        <CircleDollarSign aria-hidden="true" /><span
                            ><strong
                                >Existing reservations are never
                                cancelled</strong
                            >Lowering below committed plus held spend blocks new
                            work immediately.</span
                        >
                    </div>
                    <label class="provider-field"
                        ><span>Monthly limit (USD)</span
                        ><input
                            v-model="budgetForm.monthly_limit_usd"
                            data-autofocus
                            inputmode="decimal"
                            required
                            pattern="\d+(\.\d{1,6})?"
                        /><em
                            v-if="budgetForm.errors.monthly_limit_usd"
                            role="alert"
                            >{{ budgetForm.errors.monthly_limit_usd }}</em
                        ></label
                    ><label class="provider-field"
                        ><span>Tavily price per credit (USD)</span
                        ><input
                            v-model="budgetForm.tavily_credit_price_usd"
                            inputmode="decimal"
                            required
                            pattern="\d+(\.\d{1,6})?"
                        /><small
                            >The default is the conservative current PAYG
                            ceiling. A lower contracted rate expands spending
                            authority and requires recent password
                            confirmation.</small
                        ><em
                            v-if="budgetForm.errors.tavily_credit_price_usd"
                            role="alert"
                            >{{ budgetForm.errors.tavily_credit_price_usd }}</em
                        ></label
                    >
                </div>
                <footer>
                    <button
                        class="btn ghost"
                        type="button"
                        @click="closeDialog"
                    >
                        Cancel</button
                    ><button
                        class="btn"
                        type="submit"
                        :disabled="budgetForm.processing"
                    >
                        Save limits
                    </button>
                </footer>
            </form>

            <div v-else class="provider-modal-body">
                <div class="providers-notice bad">
                    <AlertTriangle aria-hidden="true" /><span
                        ><strong>This wipes the local encrypted key</strong
                        >Tasks depending on this service will stop. OutBoss
                        cannot revoke the key in the service account; do that
                        separately.</span
                    >
                </div>
                <footer>
                    <button
                        class="btn ghost"
                        type="button"
                        data-autofocus
                        @click="closeDialog"
                    >
                        Cancel</button
                    ><button
                        class="btn danger"
                        type="button"
                        @click="removeProvider"
                    >
                        Remove from OutBoss
                    </button>
                </footer>
            </div>
        </section>
    </div>
</template>

<style scoped>
.providers-index {
    min-width: 0;
    padding: 36px 40px 64px;
}
:global(.side-providers + .workspace) {
    overflow-x: hidden;
    overflow-y: auto;
}
.providers-page-heading {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 32px;
    padding-bottom: 28px;
    border-bottom: 1px solid var(--ob-border);
}
.providers-eyebrow {
    margin: 0 0 7px;
    color: var(--ob-focus);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}
.providers-page-heading h1 {
    margin: 0;
    color: #1f1c18;
    font-size: 28px;
    font-weight: 650;
    letter-spacing: -0.03em;
}
.providers-page-heading > div > p:last-child {
    max-width: 620px;
    margin: 8px 0 0;
    color: #736c63;
    font-size: 13px;
    line-height: 1.55;
}
.providers-page-heading .btn {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    gap: 7px;
    white-space: nowrap;
}
.providers-page-heading .btn svg {
    width: 15px;
    height: 15px;
    flex: 0 0 15px;
}
.providers-tabs {
    display: flex;
    gap: 3px;
    margin-top: 18px;
    overflow-x: auto;
    border-bottom: 1px solid var(--ob-border-strong);
}
.providers-tabs button {
    min-height: 43px;
    flex: 0 0 auto;
    border: 0;
    border-bottom: 2px solid transparent;
    padding: 0 11px;
    background: transparent;
    color: #6d675e;
    font-size: 12px;
    cursor: pointer;
}
.providers-tabs button.active {
    border-color: var(--ob-accent);
    color: var(--ob-accent-strong);
    font-weight: 650;
}
.providers-tab-panel {
    padding-top: 18px;
}
.providers-section-heading {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 12px;
}
.providers-section-heading h2,
.workspace-controls-grid h3 {
    margin: 0;
    color: #27231e;
    font-size: 15px;
    font-weight: 650;
    letter-spacing: -0.015em;
}
.providers-section-heading p,
.workspace-controls-grid p {
    margin: 4px 0 0;
    color: #736c63;
    font-size: 11px;
    line-height: 1.5;
}
.providers-section-heading > small {
    color: #817a70;
    font-size: 11px;
}
.service-grid,
.workspace-controls-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}
.service-card,
.workspace-controls-grid > section {
    border: 1px solid var(--ob-border-strong);
    border-radius: 12px;
    background: #fff;
}
.service-card {
    display: grid;
    min-height: 206px;
    grid-template-rows: auto 1fr auto;
    padding: 16px;
}
.service-card > header {
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center;
    gap: 10px;
}
.service-card header strong,
.service-card header small {
    display: block;
}
.service-card header small {
    margin-top: 2px;
    color: #817a70;
    font-size: 11px;
}
.service-card dl {
    display: grid;
    align-content: center;
    gap: 8px;
    margin: 14px 0;
}
.service-card dl div {
    display: flex;
    justify-content: space-between;
    gap: 16px;
}
.service-card dt,
.service-card dd {
    margin: 0;
    font-size: 11px;
}
.service-card dt {
    color: #8a8379;
}
.service-card dd {
    color: #39342e;
    text-align: right;
}
.service-card > footer {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding-top: 12px;
    border-top: 1px solid #eee9e2;
}
.workspace-controls-heading {
    margin-top: 26px;
}
.workspace-controls-grid > section {
    padding: 16px;
}
.workspace-controls-grid .provider-detail {
    padding: 14px 0 0;
}
.control-value {
    display: block;
    margin-top: 15px;
    color: #27231e;
    font-size: 20px;
    letter-spacing: -0.03em;
}
.text-action {
    margin-top: 15px;
    border: 0;
    padding: 0;
    background: transparent;
    color: var(--ob-accent);
    font-size: 12px;
    font-weight: 650;
    cursor: pointer;
}
.spending-policy-panel {
    margin-top: 12px;
}
.activity-switcher {
    display: flex;
    gap: 4px;
    margin-bottom: 12px;
}
.activity-switcher button {
    min-height: 34px;
    border: 0;
    border-radius: 7px;
    padding: 0 10px;
    background: transparent;
    color: #6d675e;
    font-size: 11px;
    cursor: pointer;
}
.activity-switcher button.active {
    background: var(--ob-accent-tint);
    color: var(--ob-accent-strong);
    font-weight: 650;
}
.providers-panel {
    overflow: hidden;
    border: 1px solid var(--ob-border-strong);
    border-radius: 12px;
    background: #fff;
}
.providers-panel-head {
    display: flex;
    min-height: 56px;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 10px 14px;
    border-bottom: 1px solid var(--ob-border);
}
.providers-panel-head h2 {
    margin: 0;
    color: #27231e;
    font-size: 15px;
    font-weight: 650;
    letter-spacing: -0.015em;
}
.providers-panel-head p {
    margin: 3px 0 0;
    color: #736c63;
    font-size: 11px;
}
.providers-panel-body {
    padding: 16px;
}
.provider-chip {
    border-radius: 999px;
    padding: 3px 8px;
    background: #efede8;
    color: #655f56;
    font-size: 10px;
    white-space: nowrap;
}
.provider-table-wrap {
    max-width: 100%;
    overflow: auto;
}
table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}
th {
    padding: 8px 11px;
    border-bottom: 1px solid var(--ob-border-strong);
    background: #f5f2ed;
    color: #766f65;
    font-size: 10px;
    font-weight: 650;
    letter-spacing: 0.05em;
    text-align: left;
    text-transform: uppercase;
    white-space: nowrap;
}
td {
    padding: 11px;
    border-bottom: 1px solid var(--ob-border-soft);
    vertical-align: middle;
}
tbody tr:last-child td {
    border-bottom: 0;
}
td strong,
td small {
    display: block;
}
td small {
    margin-top: 2px;
    color: #817a70;
    font-size: 10px;
}
.provider-cell {
    display: flex;
    min-width: 190px;
    align-items: center;
    gap: 10px;
}
.provider-cell > span:last-child {
    min-width: 0;
}
.provider-mark {
    display: grid;
    width: 36px;
    height: 36px;
    flex: 0 0 36px;
    place-items: center;
    border-radius: 9px;
    background: #25221d;
    color: #fff;
    font-size: 10px;
    font-weight: 750;
}
.provider-mark.tavily {
    background: #4264d0;
}
.provider-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border-radius: 999px;
    padding: 4px 8px;
    background: #efede8;
    color: #625d54;
    font-size: 10px;
    font-weight: 650;
    white-space: nowrap;
}
.provider-status::before {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
    content: '';
}
.provider-status.good {
    background: #e7f5ee;
    color: #18794e;
}
.provider-status.warn {
    background: #fff3da;
    color: #9a6500;
}
.provider-status.bad {
    background: #fff0ee;
    color: #b42318;
}
.provider-status.info {
    background: var(--ob-accent-tint-strong);
    color: var(--ob-accent-strong);
}
.provider-actions {
    text-align: right;
    white-space: nowrap;
}
.provider-actions .btn + .btn {
    margin-left: 5px;
}
.provider-actions svg {
    width: 16px;
}
.providers-grid-2 {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
    margin-top: 12px;
}
.provider-detail {
    display: grid;
    grid-template-columns: minmax(110px, 0.8fr) minmax(0, 1.2fr);
    gap: 10px 14px;
    margin: 0;
    padding: 18px;
    font-size: 11px;
}
.provider-detail dt {
    color: #736c63;
}
.provider-detail dd {
    margin: 0;
}
.budget-progress {
    height: 7px;
    margin-top: 8px;
    overflow: hidden;
    border-radius: 99px;
    background: #e2ded6;
}
.budget-progress span {
    display: block;
    height: 100%;
    border-radius: inherit;
    background: var(--ob-accent);
}
.budget-progress.blocked span {
    background: #b42318;
}
.budget-progress.large {
    height: 10px;
    margin: 0;
}
.budget-policy {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin: 14px 0 0;
    color: #6d675e;
    font-size: 11px;
    line-height: 1.45;
}
.budget-policy svg {
    width: 14px;
    flex: 0 0 14px;
}
.providers-notice {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-top: 14px;
    border: 1px solid #cdddec;
    border-radius: 9px;
    padding: 11px 13px;
    background: #ebf2f9;
    color: #294e74;
    font-size: 12px;
    line-height: 1.45;
}
.providers-notice.bad {
    border-color: #edc1bc;
    background: #fff0ee;
    color: #84251d;
}
.providers-notice svg {
    width: 16px;
    flex: 0 0 16px;
}
.providers-notice strong {
    display: block;
    margin-bottom: 2px;
}
.providers-flash {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 14px 0 0;
    font-size: 12px;
}
.providers-flash svg {
    width: 15px;
}
.providers-flash.good {
    color: #18794e;
}
.providers-flash.bad {
    color: #b42318;
}
.task-list {
    display: grid;
    gap: 8px;
    padding: 14px;
}
.task-row {
    display: grid;
    grid-template-columns: minmax(220px, 1.3fr) minmax(170px, 1fr) 120px auto;
    align-items: center;
    gap: 14px;
    border: 1px solid var(--ob-border);
    border-radius: 9px;
    padding: 13px;
}
.task-row > span {
    display: grid;
    gap: 3px;
}
.task-row small {
    color: #817a70;
    font-size: 10px;
}
.task-row strong {
    font-size: 12px;
}
.task-row em {
    color: #817a70;
    font-size: 10px;
    font-style: normal;
}
.task-action {
    display: flex !important;
    grid-auto-flow: column;
    align-items: center;
}
.mono {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}
.budget-metrics {
    display: grid;
    grid-template-columns: repeat(4, minmax(120px, 1fr));
    overflow: hidden;
    margin-bottom: 12px;
    border: 1px solid var(--ob-border-strong);
    border-radius: 12px;
    background: #fff;
}
.budget-metrics > span {
    display: grid;
    gap: 3px;
    padding: 15px 18px;
    border-right: 1px solid var(--ob-border);
}
.budget-metrics > span:last-child {
    border-right: 0;
}
.budget-metric-topline {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}
.budget-edit-button {
    min-height: 24px;
    flex: 0 0 auto;
    border: 1px solid var(--ob-border-strong);
    border-radius: 6px;
    padding: 2px 7px;
    background: #fff;
    color: #4e4942;
    font-size: 10px;
    font-weight: 600;
    white-space: nowrap;
    cursor: pointer;
}
.budget-edit-button:hover {
    border-color: var(--ob-accent-border);
    background: var(--ob-accent-tint-soft);
    color: var(--ob-accent-strong);
}
.budget-edit-button:focus-visible {
    outline: 2px solid var(--ob-focus);
    outline-offset: 2px;
}
.budget-metrics small {
    color: #736c63;
    font-size: 10px;
}
.budget-metrics strong {
    font:
        650 19px ui-monospace,
        SFMono-Regular,
        Menlo,
        monospace;
}
.budget-metrics em {
    color: #817a70;
    font-size: 10px;
    font-style: normal;
}
.usage-filters {
    display: grid;
    grid-template-columns: repeat(3, minmax(130px, 1fr)) auto;
    align-items: end;
    gap: 10px;
    padding: 12px 14px;
    border-bottom: 1px solid var(--ob-border);
    background: #fbfaf7;
}
.usage-filters label {
    display: grid;
    gap: 5px;
    color: #736c63;
    font-size: 10px;
}
.usage-filters select {
    min-height: 36px;
    border: 1px solid #d4cec3;
    border-radius: 8px;
    padding: 6px 9px;
    background: #fff;
    color: #27231e;
    font-size: 11px;
}
.usage-pagination {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
    padding: 12px 14px;
    border-top: 1px solid var(--ob-border);
    color: #736c63;
    font-size: 11px;
}
.providers-empty {
    display: grid;
    justify-items: center;
    gap: 7px;
    padding: 60px 20px;
    color: #736c63;
    text-align: center;
}
.providers-empty svg {
    width: 28px;
    color: var(--ob-focus);
}
.providers-empty strong {
    color: #27231e;
    font-size: 14px;
}
.providers-empty p {
    max-width: 430px;
    margin: 0;
    font-size: 11px;
    line-height: 1.5;
}
td svg {
    width: 13px;
    vertical-align: middle;
}
.provider-modal-back {
    position: fixed;
    inset: 0;
    z-index: 100;
    display: grid;
    place-items: center;
    padding: 18px;
    background: rgb(30 27 23 / 35%);
    backdrop-filter: blur(2px);
}
.provider-modal {
    width: min(540px, 100%);
    max-height: 92vh;
    overflow: auto;
    border: 1px solid #d4cec3;
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 18px 56px rgb(31 28 24 / 18%);
}
.provider-modal > header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 16px 18px;
    border-bottom: 1px solid var(--ob-border);
}
.provider-modal h2 {
    margin: 0;
    color: #27231e;
    font-size: 17px;
}
.provider-modal .icon {
    width: 36px;
    padding: 0;
}
.provider-modal .icon svg {
    width: 17px;
}
.provider-modal-body {
    padding: 18px;
}
.provider-modal footer {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding: 13px 18px;
    border-top: 1px solid var(--ob-border);
    background: #fbfaf7;
}
.provider-field {
    display: grid;
    gap: 6px;
}
.provider-field + .provider-field {
    margin-top: 15px;
}
.provider-field > span {
    color: #625d54;
    font-size: 11px;
    font-weight: 650;
}
.provider-field input,
.provider-field select {
    width: 100%;
    min-height: 42px;
    border: 1px solid #d4cec3;
    border-radius: 8px;
    padding: 8px 10px;
    background: #fff;
    font-size: 12px;
}
.provider-field small {
    color: #817a70;
    font-size: 10px;
    line-height: 1.45;
}
.provider-field em {
    color: #b42318;
    font-size: 11px;
    font-style: normal;
}
.manage-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    margin-top: 18px;
    padding-top: 16px;
    border-top: 1px solid var(--ob-border);
}
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
}
@media (max-width: 1100px) {
    .task-row {
        grid-template-columns: minmax(210px, 1.2fr) minmax(160px, 1fr) auto;
    }
    .task-row > span:nth-child(3) {
        display: none;
    }
}
@media (max-width: 900px) {
    .providers-index {
        padding: 28px 24px 52px;
    }
    .providers-grid-2 {
        grid-template-columns: 1fr;
    }
    .service-grid,
    .workspace-controls-grid {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 620px) {
    .providers-index {
        padding: 22px 16px 42px;
    }
    .providers-page-heading {
        align-items: flex-start;
        flex-direction: column;
        gap: 16px;
    }
    .providers-page-heading .btn {
        width: 100%;
    }
    .providers-tabs {
        margin-top: 14px;
    }
    .providers-panel-head {
        align-items: flex-start;
        flex-direction: column;
    }
    .usage-filters {
        grid-template-columns: 1fr 1fr;
    }
    .budget-metrics {
        grid-template-columns: repeat(2, 1fr);
    }
    .budget-metrics > span:nth-child(2) {
        border-right: 0;
    }
    .budget-metrics > span:nth-child(-n + 2) {
        border-bottom: 1px solid var(--ob-border);
    }
    .task-row {
        grid-template-columns: 1fr auto;
    }
    .task-row > span:nth-child(2),
    .task-row > span:nth-child(3) {
        display: none;
    }
    th:nth-child(3),
    td:nth-child(3) {
        display: none;
    }
    .provider-table-wrap table {
        min-width: 570px;
    }
    .provider-modal-back {
        padding: 10px;
    }
}
@media (prefers-reduced-motion: reduce) {
    * {
        scroll-behavior: auto !important;
        transition-duration: 0.01ms !important;
    }
}
</style>
