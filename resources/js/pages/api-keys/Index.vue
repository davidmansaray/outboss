<script setup lang="ts">
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import { useClipboard } from '@vueuse/core';
import { computed, nextTick, ref, watch } from 'vue';
import ApiKeyController from '@/actions/App/Http/Controllers/Settings/ApiKeyController';
import WorkspaceOpsSidebar from '@/components/WorkspaceOpsSidebar.vue';
import AppWorkspace from '@/layouts/AppWorkspace.vue';

type ScopeOption = {
    value: string;
    label: string;
    description: string;
};

type ScopeGroup = {
    name: string;
    scopes: ScopeOption[];
};

type ApiKey = {
    id: number;
    name: string;
    scopes: string[];
    display_prefix: string | null;
    created_at: string | null;
    last_used_at: string | null;
    expires_at: string | null;
};

const props = defineProps<{
    keys: ApiKey[];
    scopeGroups: ScopeGroup[];
    readOnlyScopes: string[];
    plainTextToken: string | null;
}>();

const page = usePage();
const flash = computed(
    () =>
        (page.props.flash as
            { success?: string; error?: string } | undefined) ?? {},
);

const { copy: copyToken, copied: tokenCopied } = useClipboard();
const { copy: copyMcpConfig, copied: mcpConfigCopied } = useClipboard();
const selectedScopes = ref<string[]>([]);
const confirming = ref<ApiKey | null>(null);
const revokingId = ref<number | null>(null);
const revealPanel = ref<HTMLElement | null>(null);
const revokeDialog = ref<HTMLElement | null>(null);
const revokeCancelButton = ref<HTMLButtonElement | null>(null);
const revokeTrigger = ref<HTMLElement | null>(null);

watch(
    () => props.plainTextToken,
    async (token) => {
        if (!token) {
            return;
        }

        await nextTick();
        revealPanel.value?.scrollIntoView({
            behavior: 'smooth',
            block: 'start',
        });
    },
);

const mcpConfigJson = computed(() => {
    if (!props.plainTextToken) {
        return '';
    }

    const origin = typeof window === 'undefined' ? '' : window.location.origin;

    return JSON.stringify(
        {
            mcpServers: {
                outboss: {
                    url: `${origin}/mcp`,
                    headers: {
                        Authorization: `Bearer ${props.plainTextToken}`,
                    },
                },
            },
        },
        null,
        2,
    );
});

const scopeLabels = computed(() => {
    const labels: Record<string, string> = {};

    for (const group of props.scopeGroups) {
        for (const scope of group.scopes) {
            labels[scope.value] = scope.label;
        }
    }

    return labels;
});

const allReadOnlySelected = computed(
    () =>
        props.readOnlyScopes.length > 0 &&
        props.readOnlyScopes.every((scope) =>
            selectedScopes.value.includes(scope),
        ),
);

function toggleScope(scope: string, checked: boolean): void {
    if (checked) {
        if (!selectedScopes.value.includes(scope)) {
            selectedScopes.value = [...selectedScopes.value, scope];
        }

        return;
    }

    selectedScopes.value = selectedScopes.value.filter(
        (value) => value !== scope,
    );
}

function applyReadOnly(): void {
    selectedScopes.value = [...props.readOnlyScopes];
}

function formatDate(value: string | null, empty: string): string {
    if (!value) {
        return empty;
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return empty;
    }

    return new Intl.DateTimeFormat(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(date);
}

function scopeLabel(scope: string): string {
    return scopeLabels.value[scope] ?? scope;
}

async function requestRevoke(key: ApiKey, event: Event): Promise<void> {
    revokeTrigger.value =
        event.currentTarget instanceof HTMLElement ? event.currentTarget : null;
    confirming.value = key;

    await nextTick();
    revokeCancelButton.value?.focus();
}

async function cancelRevoke(): Promise<void> {
    confirming.value = null;

    await nextTick();
    revokeTrigger.value?.focus();
    revokeTrigger.value = null;
}

function handleRevokeDialogKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        event.preventDefault();
        void cancelRevoke();

        return;
    }

    if (event.key !== 'Tab' || !revokeDialog.value) {
        return;
    }

    const focusable = Array.from(
        revokeDialog.value.querySelectorAll<HTMLElement>(
            'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
        ),
    );

    if (focusable.length === 0) {
        event.preventDefault();

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

function confirmRevoke(): void {
    const key = confirming.value;

    if (!key) {
        return;
    }

    revokingId.value = key.id;
    void cancelRevoke();
    router.delete(ApiKeyController.destroy.url(key.id), {
        preserveScroll: true,
        onFinish: () => {
            revokingId.value = null;
        },
    });
}
</script>

<template>
    <Head title="API keys" />
    <AppWorkspace area="api-keys" title="API keys" :show-sidebar-title="false">
        <template #sidebar>
            <WorkspaceOpsSidebar current="api-keys" />
        </template>
        <main class="api-keys-index">
            <header class="api-keys-page-heading">
                <div>
                    <h1>API keys</h1>
                    <p>
                        Personal access tokens for agents and integrations.
                        Sending and launching are optional scopes.
                    </p>
                </div>
                <div class="api-keys-totals">
                    <span
                        ><strong>{{ keys.length }}</strong>
                        {{ keys.length === 1 ? 'key' : 'keys' }}</span
                    >
                </div>
            </header>

            <p v-if="flash.success" class="hint">{{ flash.success }}</p>
            <p v-if="flash.error" class="bad">{{ flash.error }}</p>

            <section
                v-if="plainTextToken"
                ref="revealPanel"
                class="api-key-reveal"
                data-test="api-key-reveal"
            >
                <p class="api-keys-eyebrow">Copy once</p>
                <h2>Copy your API key now</h2>
                <p>
                    This is the only time the full key will be shown. Store it
                    somewhere safe — you will not be able to see it again.
                </p>
                <div class="api-key-copy-row">
                    <input
                        type="text"
                        readonly
                        :value="plainTextToken"
                        data-test="api-key-plaintext"
                    />
                    <button
                        class="btn ghost"
                        type="button"
                        data-test="copy-api-key"
                        @click="copyToken(plainTextToken)"
                    >
                        {{ tokenCopied ? 'Copied' : 'Copy' }}
                    </button>
                </div>

                <div class="api-key-mcp" data-test="mcp-connect-panel">
                    <strong>Connect your AI agent</strong>
                    <p>
                        Paste this into Cursor or Claude Desktop. The token is
                        included — treat the snippet like the key. Setup for
                        Claude, Cursor, ChatGPT, and n8n is in docs/mcp.md.
                    </p>
                    <div class="api-key-copy-row">
                        <pre data-test="mcp-config-json">{{
                            mcpConfigJson
                        }}</pre>
                        <button
                            class="btn ghost"
                            type="button"
                            data-test="copy-mcp-config"
                            @click="copyMcpConfig(mcpConfigJson)"
                        >
                            {{ mcpConfigCopied ? 'Copied' : 'Copy' }}
                        </button>
                    </div>
                </div>
            </section>

            <section
                v-if="keys.length"
                class="api-keys-list"
                aria-label="API keys"
            >
                <div class="api-keys-list-head" aria-hidden="true">
                    <span>Key</span>
                    <span>Last used</span>
                    <span>Expires</span>
                    <span />
                </div>
                <div
                    v-for="key in keys"
                    :key="key.id"
                    class="api-keys-row"
                    data-test="api-key-row"
                >
                    <span class="api-keys-row-primary">
                        <strong>{{ key.name }}</strong>
                        <small data-test="api-key-prefix">{{
                            key.display_prefix
                        }}</small>
                        <span class="api-keys-scopes">
                            <span
                                v-for="scope in key.scopes"
                                :key="scope"
                                class="api-keys-scope"
                                >{{ scopeLabel(scope) }}</span
                            >
                        </span>
                    </span>
                    <span class="api-keys-row-metric">
                        <small>Last used</small>
                        <span>{{ formatDate(key.last_used_at, 'Never') }}</span>
                    </span>
                    <span class="api-keys-row-metric">
                        <small>Expires</small>
                        <span>{{ formatDate(key.expires_at, 'Never') }}</span>
                    </span>
                    <span class="api-keys-row-action">
                        <button
                            class="btn ghost"
                            type="button"
                            :disabled="revokingId === key.id"
                            data-test="revoke-api-key"
                            @click="requestRevoke(key, $event)"
                        >
                            Revoke
                        </button>
                    </span>
                </div>
            </section>

            <section v-else class="api-keys-empty">
                <p class="api-keys-eyebrow">Workspace keys</p>
                <h2>No API keys yet</h2>
                <p>
                    Create a key to let an agent or integration act on your
                    behalf. Choose only the scopes it needs.
                </p>
            </section>

            <section class="api-key-create" aria-labelledby="create-key-title">
                <div class="api-key-create-head">
                    <p class="api-keys-eyebrow">New key</p>
                    <h2 id="create-key-title">Create an API key</h2>
                    <p>
                        Choose only the scopes this key needs. Leave sending and
                        launching unchecked unless the integration must send
                        email.
                    </p>
                </div>

                <Form
                    v-bind="ApiKeyController.store.form()"
                    class="api-key-form"
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                    @success="selectedScopes = []"
                >
                    <div class="api-key-details">
                        <div class="api-key-field">
                            <label for="name">Name</label>
                            <input
                                id="name"
                                name="name"
                                required
                                maxlength="120"
                                placeholder="e.g. Reporting agent"
                                data-test="api-key-name"
                                :aria-invalid="errors.name ? 'true' : undefined"
                            />
                            <p v-if="errors.name" class="bad" role="alert">
                                {{ errors.name }}
                            </p>
                        </div>
                        <div class="api-key-field">
                            <label for="expires_at">Expiry date</label>
                            <input
                                id="expires_at"
                                type="date"
                                name="expires_at"
                                data-test="api-key-expires-at"
                            />
                            <p class="api-key-field-hint">Optional</p>
                            <p
                                v-if="errors.expires_at"
                                class="bad"
                                role="alert"
                            >
                                {{ errors.expires_at }}
                            </p>
                        </div>
                    </div>

                    <div class="api-key-scopes-head">
                        <label>Scopes</label>
                        <button
                            class="btn ghost"
                            type="button"
                            data-test="select-read-only-scopes"
                            @click="applyReadOnly"
                        >
                            {{
                                allReadOnlySelected
                                    ? 'Read-only selected'
                                    : 'Read-only'
                            }}
                        </button>
                    </div>
                    <p class="api-key-form-help">
                        Sending and launching scopes are separate, so a key can
                        read data without sending email.
                    </p>

                    <input
                        v-for="scope in selectedScopes"
                        :key="scope"
                        type="hidden"
                        name="scopes[]"
                        :value="scope"
                    />

                    <div class="api-key-scope-groups">
                        <fieldset
                            v-for="group in scopeGroups"
                            :key="group.name"
                            class="api-key-scope-group"
                        >
                            <legend>{{ group.name }}</legend>
                            <label
                                v-for="scope in group.scopes"
                                :key="scope.value"
                                class="api-key-scope-option"
                            >
                                <input
                                    type="checkbox"
                                    :checked="
                                        selectedScopes.includes(scope.value)
                                    "
                                    @change="
                                        toggleScope(
                                            scope.value,
                                            ($event.target as HTMLInputElement)
                                                .checked,
                                        )
                                    "
                                />
                                <span>
                                    <strong>{{ scope.label }}</strong>
                                    <small>{{ scope.description }}</small>
                                </span>
                            </label>
                        </fieldset>
                    </div>
                    <p v-if="errors.scopes" class="bad" role="alert">
                        {{ errors.scopes }}
                    </p>

                    <div class="api-key-form-actions">
                        <button
                            class="btn"
                            type="submit"
                            :disabled="
                                processing || selectedScopes.length === 0
                            "
                            data-test="create-api-key"
                        >
                            {{ processing ? 'Creating…' : 'Create key' }}
                        </button>
                    </div>
                </Form>
            </section>
        </main>
    </AppWorkspace>
    <div
        v-if="confirming"
        class="modal-back"
        role="dialog"
        aria-modal="true"
        aria-labelledby="revoke-api-key-title"
        aria-describedby="revoke-api-key-description"
        @click.self="cancelRevoke"
        @keydown="handleRevokeDialogKeydown"
    >
        <div ref="revokeDialog" class="modal">
            <h2 id="revoke-api-key-title">Revoke {{ confirming.name }}?</h2>
            <p id="revoke-api-key-description" class="hint">
                Anything using this key will stop working immediately. You
                cannot recover the token.
            </p>
            <div class="actions">
                <button
                    ref="revokeCancelButton"
                    class="btn ghost"
                    type="button"
                    @click="cancelRevoke"
                >
                    Cancel
                </button>
                <button
                    class="btn danger"
                    type="button"
                    :disabled="revokingId === confirming.id"
                    data-test="confirm-revoke-api-key"
                    @click="confirmRevoke"
                >
                    Revoke key
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.api-keys-index {
    min-width: 0;
    padding: 36px 40px 64px;
}

:global(.side-api-keys + .workspace) {
    overflow-x: hidden;
    overflow-y: auto;
}

.api-keys-page-heading {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 32px;
    padding-bottom: 28px;
    border-bottom: 1px solid var(--ob-border);
}

.api-keys-eyebrow {
    margin: 0 0 7px;
    color: var(--ob-focus);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.api-keys-page-heading h1,
.api-keys-empty h2,
.api-key-reveal h2,
.api-key-create-head h2 {
    margin: 0;
    color: #1f1c18;
    font-size: 28px;
    font-weight: 650;
    letter-spacing: -0.03em;
}

.api-key-reveal h2,
.api-key-create-head h2,
.api-keys-empty h2 {
    font-size: 22px;
}

.api-keys-page-heading > div:first-child > p:last-child,
.api-keys-empty > p:not(.api-keys-eyebrow),
.api-key-reveal > p,
.api-key-create-head > p,
.api-key-mcp > p {
    max-width: 620px;
    margin: 8px 0 0;
    color: #736c63;
    font-size: 13px;
    line-height: 1.55;
}

.api-keys-totals {
    display: flex;
    gap: 22px;
    color: #736c63;
    font-size: 12px;
    white-space: nowrap;
}

.api-keys-totals strong {
    color: #27231e;
    font-size: 15px;
}

.api-key-reveal,
.api-key-create {
    margin-top: 28px;
    padding: 28px;
    border: 1px solid var(--ob-border);
    border-radius: 12px;
    background: #fff;
}

.api-key-reveal {
    background: #faf8f4;
}

.api-key-copy-row {
    display: flex;
    align-items: stretch;
    gap: 8px;
    margin-top: 16px;
}

.api-key-copy-row input,
.api-key-copy-row pre {
    min-width: 0;
    flex: 1;
    margin: 0;
    border: 1px solid var(--ob-border);
    border-radius: 8px;
    background: #fff;
    color: #1a1814;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 12px;
}

.api-key-copy-row input {
    min-height: 44px;
    padding: 0 12px;
}

.api-key-copy-row pre {
    max-height: 220px;
    overflow: auto;
    padding: 12px;
    line-height: 1.5;
}

.api-key-mcp {
    margin-top: 22px;
    padding-top: 18px;
    border-top: 1px solid #e8e2da;
}

.api-key-mcp strong {
    color: #27231e;
    font-size: 13px;
    font-weight: 640;
}

.api-keys-list {
    margin-top: 8px;
}

.api-keys-list-head,
.api-keys-row {
    display: grid;
    grid-template-columns:
        minmax(220px, 1.6fr) minmax(110px, 0.55fr) minmax(110px, 0.55fr)
        minmax(90px, 0.4fr);
    align-items: center;
    gap: 16px;
}

.api-keys-list-head {
    min-height: 46px;
    border-bottom: 1px solid var(--ob-border);
    color: #8a8379;
    font-size: 10px;
    font-weight: 650;
    letter-spacing: 0.07em;
    text-transform: uppercase;
}

.api-keys-row {
    min-height: 86px;
    border-bottom: 1px solid var(--ob-border-soft);
}

.api-keys-row-primary {
    display: grid;
    min-width: 0;
    gap: 6px;
}

.api-keys-row-primary strong {
    overflow: hidden;
    color: #27231e;
    font-size: 14px;
    font-weight: 640;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.api-keys-row-primary small {
    color: #8a8379;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 11px;
}

.api-keys-scopes {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}

.api-keys-scope {
    display: inline-flex;
    width: fit-content;
    border-radius: 999px;
    padding: 3px 7px;
    background: #efede9;
    color: #665f56;
    font-size: 9px;
    font-weight: 650;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.api-keys-row-metric {
    display: grid;
    min-width: 0;
    gap: 4px;
    color: #736c63;
    font-size: 12px;
}

.api-keys-row-metric small {
    display: none;
}

.api-keys-row-action {
    display: flex;
    justify-content: flex-end;
}

.api-keys-empty {
    display: grid;
    min-height: 170px;
    align-content: center;
    justify-items: start;
}

.api-key-create-head {
    margin-bottom: 26px;
    padding-bottom: 24px;
    border-bottom: 1px solid var(--ob-border-soft);
}

.api-key-form {
    display: grid;
    gap: 10px;
}

.api-key-field label,
.api-key-scopes-head label {
    color: #4e4942;
    font-size: 12px;
    font-weight: 620;
}

.api-key-details {
    display: grid;
    grid-template-columns: minmax(0, 1.6fr) minmax(220px, 0.6fr);
    gap: 18px;
    margin-bottom: 16px;
}

.api-key-field {
    position: relative;
    display: grid;
    gap: 8px;
}

.api-key-field input {
    width: 100%;
    min-height: 44px;
    border: 1px solid var(--ob-border);
    border-radius: 8px;
    background: #fff;
    padding: 0 12px;
    color: #1a1814;
    font: inherit;
}

.api-key-field-hint {
    position: absolute;
    top: 3px;
    right: 0;
    margin: 0;
    color: #958d82;
    font-size: 10px;
}

.api-key-scopes-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.api-key-form-help {
    margin: 0 0 8px;
    color: #817a70;
    font-size: 11px;
    line-height: 1.5;
}

.api-key-scope-groups {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 12px;
}

.api-key-scope-group {
    display: grid;
    grid-template-columns: 1fr;
    align-content: start;
    gap: 2px 12px;
    margin: 0;
    border: 1px solid #e8e2da;
    border-radius: 10px;
    padding: 10px 12px 8px;
}

.api-key-scope-group legend {
    padding: 0 4px;
    color: #4e4942;
    font-size: 12px;
    font-weight: 640;
}

.api-key-scope-option {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 7px 2px;
    cursor: pointer;
}

.api-key-scope-option input {
    flex: 0 0 16px;
    width: 16px;
    height: 16px;
    margin-top: 3px;
}

.api-key-scope-option span {
    min-width: 0;
}

.api-key-scope-option strong {
    display: block;
    color: #27231e;
    font-size: 13px;
    font-weight: 620;
}

.api-key-scope-option small {
    display: block;
    color: #736c63;
    font-size: 12px;
    line-height: 1.4;
}

.api-key-form-actions {
    display: flex;
    justify-content: flex-end;
    padding-top: 18px;
    border-top: 1px solid var(--ob-border-soft);
    margin-top: 8px;
}

:global(.side-api-keys .side-title) {
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
    :global(.side-api-keys) {
        position: relative;
        min-height: 0;
        padding: 66px 12px 9px;
    }

    :global(.side-api-keys .side-title) {
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

    .api-keys-index {
        padding: 24px 18px 40px;
    }

    .api-keys-totals {
        display: none;
    }
}

@media (min-width: 901px) and (max-width: 1100px) {
    .api-key-details,
    .api-key-scope-groups {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 760px) {
    .api-keys-page-heading {
        align-items: flex-start;
        flex-direction: column;
    }

    .api-keys-list-head {
        display: none;
    }

    .api-keys-row {
        grid-template-columns: 1fr auto;
        gap: 8px 12px;
        padding: 16px 0;
    }

    .api-keys-row-metric {
        display: none;
    }

    .api-keys-row-action {
        grid-column: 2;
        grid-row: 1;
        align-items: flex-start;
    }

    .api-key-copy-row {
        flex-direction: column;
    }

    .api-key-scope-group {
        grid-template-columns: 1fr;
    }

    .api-key-details,
    .api-key-scope-groups {
        grid-template-columns: 1fr;
    }

    .api-key-create {
        padding: 22px 18px;
    }
}
</style>
