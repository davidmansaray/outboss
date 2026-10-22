<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import WorkspaceOpsSidebar from '@/components/WorkspaceOpsSidebar.vue';
import AppWorkspace from '@/layouts/AppWorkspace.vue';

type DnsValue = string | number | boolean | null | DnsRecord;
type DnsRecord = { [key: string]: DnsValue | DnsValue[] };

type MailboxAccount = {
    id: number;
    mailbox_id: number | null;
    email: string;
    local_part: string;
    display_name: string | null;
    credential_version: number;
    status: string;
    provisioned_at: string | null;
    last_verified_at: string | null;
    last_error_code: string | null;
    last_error_message: string | null;
    mailbox_status: string | null;
    connection_status: string | null;
    sending_paused_at: string | null;
    sending_paused_reason: string | null;
};

type ManagedDomain = {
    id: number;
    domain: string;
    organisational_domain: string;
    domain_kind: string;
    provider: string;
    managed_instance_key: string;
    remote_domain_id: string | null;
    status: string;
    dns_records: DnsRecord;
    registered_at: string | null;
    dns_activated_at: string | null;
    verified_at: string | null;
    last_verified_at: string | null;
    last_error_code: string | null;
    last_error_message: string | null;
    mailbox_accounts: MailboxAccount[];
};

const props = defineProps<{
    domains: ManagedDomain[];
    defaultInstance: string;
    instances: Record<
        string,
        {
            base_url: string | null;
            verify_tls: boolean;
            timeout_seconds: number;
        }
    >;
    canManage: boolean;
}>();

const page = usePage();
const flash = computed(
    () =>
        (page.props.flash as
            { success?: string; error?: string } | undefined) ?? {},
);
const domainForm = reactive({
    domain: '',
    managed_instance_key: props.defaultInstance,
});
const mailboxForms = reactive<
    Record<number, { local_part: string; display_name: string }>
>({});

function mailboxForm(domainId: number) {
    mailboxForms[domainId] ??= { local_part: '', display_name: '' };

    return mailboxForms[domainId];
}

function submitDomain() {
    if (!props.canManage || domainForm.domain.trim() === '') {
        return;
    }

    router.post('/settings/managed-mail/domains', domainForm, {
        preserveScroll: true,
        onSuccess: () => {
            domainForm.domain = '';
        },
    });
}

function verifyDomain(domain: ManagedDomain) {
    if (!props.canManage) {
        return;
    }

    router.post(
        `/settings/managed-mail/domains/${domain.id}/verify`,
        {},
        {
            preserveScroll: true,
        },
    );
}

function removeDomain(domain: ManagedDomain) {
    if (
        props.canManage &&
        window.confirm(
            `Retire ${domain.domain}? This permanently removes it from the mail server and cannot be undone.`,
        )
    ) {
        router.delete(`/settings/managed-mail/domains/${domain.id}`, {
            data: { confirm: true },
            preserveScroll: true,
        });
    }
}

function submitMailbox(domain: ManagedDomain) {
    const form = mailboxForm(domain.id);

    if (!props.canManage || form.local_part.trim() === '') {
        return;
    }

    router.post(`/settings/managed-mail/domains/${domain.id}/mailboxes`, form, {
        preserveScroll: true,
        onSuccess: () => {
            form.local_part = '';
            form.display_name = '';
        },
    });
}

function rotate(account: MailboxAccount) {
    if (props.canManage) {
        router.post(
            `/settings/managed-mail/mailboxes/${account.id}/rotate-credential`,
            {},
            { preserveScroll: true },
        );
    }
}

function suspend(account: MailboxAccount) {
    if (props.canManage && window.confirm(`Suspend ${account.email}?`)) {
        router.post(
            `/settings/managed-mail/mailboxes/${account.id}/suspend`,
            {},
            { preserveScroll: true },
        );
    }
}

function remove(account: MailboxAccount) {
    if (
        props.canManage &&
        window.confirm(`Delete ${account.email}? This cannot be undone.`)
    ) {
        router.delete(`/settings/managed-mail/mailboxes/${account.id}`, {
            data: { confirm: true },
            preserveScroll: true,
        });
    }
}

function label(value: string | null | undefined): string {
    return (value ?? 'unknown').replaceAll('_', ' ');
}

function recordValue(value: unknown): string {
    return typeof value === 'string' ? value : JSON.stringify(value);
}

function recordEntries(records: DnsRecord): [string, unknown][] {
    return Object.entries(records).filter(([key]) => key !== 'verification');
}

function verificationEntries(records: DnsRecord): [string, unknown][] {
    const value = records.verification;

    return value && typeof value === 'object' && !Array.isArray(value)
        ? Object.entries(value)
        : [];
}
</script>

<template>
    <Head title="Managed mail (internal)" />
    <AppWorkspace
        area="managed-mail"
        title="Managed mail (internal)"
        :show-sidebar-title="false"
    >
        <template #sidebar>
            <WorkspaceOpsSidebar
                current="managed-mail"
                :show-internal="canManage"
            />
        </template>
        <div class="pane managed-mail-page" data-test="managed-mail-page">
            <header class="managed-mail-head">
                <div>
                    <p class="eyebrow">Internal operator surface</p>
                    <h1>Managed mail</h1>
                    <p class="hint">
                        Provisioned domains and mailboxes for the controlled
                        pilot. Public mailbox connections remain unchanged.
                    </p>
                </div>
                <a class="btn ghost" href="/settings/mail-experiments">
                    Mail experiments
                </a>
            </header>

            <p v-if="flash.success" class="hint" data-test="flash-success">
                {{ flash.success }}
            </p>
            <p v-if="flash.error" class="bad" data-test="flash-error">
                {{ flash.error }}
            </p>

            <section
                v-if="canManage"
                class="box"
                aria-labelledby="add-domain-title"
            >
                <div>
                    <h2 id="add-domain-title">Add a managed domain</h2>
                    <p class="meta">
                        Outboss will create the remote domain and show public
                        DNS requirements. It will not change DNS for you.
                    </p>
                </div>
                <form class="managed-form" @submit.prevent="submitDomain">
                    <label>
                        Domain
                        <input
                            v-model="domainForm.domain"
                            name="domain"
                            autocomplete="off"
                            placeholder="outreach.example.com"
                            required
                        />
                    </label>
                    <label>
                        Instance
                        <select
                            v-model="domainForm.managed_instance_key"
                            name="managed_instance_key"
                        >
                            <option
                                v-for="(_, key) in instances"
                                :key="key"
                                :value="key"
                            >
                                {{ key }}
                            </option>
                        </select>
                    </label>
                    <button class="btn" type="submit">Add domain</button>
                </form>
            </section>

            <p v-if="domains.length === 0" class="empty">
                No managed domains have been provisioned.
            </p>

            <div v-else class="managed-domain-list">
                <article
                    v-for="domain in domains"
                    :key="domain.id"
                    class="box managed-domain-card"
                    :data-test="`managed-domain-${domain.id}`"
                >
                    <header class="managed-domain-title">
                        <div>
                            <h2>{{ domain.domain }}</h2>
                            <p class="meta">
                                {{ label(domain.domain_kind) }} ·
                                {{ domain.provider }} · instance
                                {{ domain.managed_instance_key }}
                            </p>
                        </div>
                        <span class="status" :class="`status-${domain.status}`">
                            {{ label(domain.status) }}
                        </span>
                    </header>

                    <div
                        v-if="recordEntries(domain.dns_records).length"
                        class="dns-records"
                    >
                        <h3>Public DNS records</h3>
                        <dl>
                            <template
                                v-for="[key, value] in recordEntries(
                                    domain.dns_records,
                                )"
                                :key="key"
                            >
                                <dt>{{ label(key) }}</dt>
                                <dd
                                    :class="{
                                        'zone-file': key === 'zone_file',
                                    }"
                                >
                                    {{ recordValue(value) }}
                                </dd>
                            </template>
                        </dl>
                    </div>
                    <div
                        v-if="verificationEntries(domain.dns_records).length"
                        class="dns-checks"
                    >
                        <h3>Last verification</h3>
                        <span
                            v-for="[key, value] in verificationEntries(
                                domain.dns_records,
                            )"
                            :key="key"
                            class="check"
                            :class="`check-${value}`"
                            >{{ label(key) }}: {{ recordValue(value) }}</span
                        >
                    </div>

                    <p v-if="domain.last_error_message" class="bad">
                        {{ domain.last_error_message }}
                    </p>
                    <div class="actions" v-if="canManage">
                        <button
                            class="btn ghost"
                            type="button"
                            :disabled="domain.status === 'provisioning'"
                            @click="verifyDomain(domain)"
                        >
                            Verify DNS
                        </button>
                        <button
                            class="btn danger"
                            type="button"
                            :disabled="
                                domain.mailbox_accounts.some(
                                    (account) => account.status !== 'deleted',
                                )
                            "
                            @click="removeDomain(domain)"
                        >
                            Retire domain
                        </button>
                    </div>

                    <section
                        class="managed-mailboxes"
                        aria-labelledby="mailboxes-title"
                    >
                        <h3 id="mailboxes-title">Mailboxes</h3>
                        <p
                            v-if="domain.mailbox_accounts.length === 0"
                            class="meta"
                        >
                            No managed mailboxes yet.
                        </p>
                        <div v-else class="managed-mailbox-list">
                            <div
                                v-for="account in domain.mailbox_accounts"
                                :key="account.id"
                                class="managed-mailbox-row"
                                :data-test="`managed-mailbox-${account.id}`"
                            >
                                <div>
                                    <strong>{{ account.email }}</strong>
                                    <p class="meta">
                                        {{
                                            account.display_name ||
                                            'No display name'
                                        }}
                                        · credential v{{
                                            account.credential_version
                                        }}
                                        ·
                                        {{ label(account.status) }}
                                    </p>
                                    <p
                                        v-if="account.last_error_message"
                                        class="bad"
                                    >
                                        {{ account.last_error_message }}
                                    </p>
                                </div>
                                <div v-if="canManage" class="actions">
                                    <button
                                        class="btn ghost"
                                        type="button"
                                        :disabled="account.status === 'deleted'"
                                        @click="rotate(account)"
                                    >
                                        Rotate credential
                                    </button>
                                    <button
                                        class="btn ghost"
                                        type="button"
                                        :disabled="
                                            account.status === 'suspended' ||
                                            account.status === 'deleted'
                                        "
                                        @click="suspend(account)"
                                    >
                                        Suspend
                                    </button>
                                    <button
                                        class="btn danger"
                                        type="button"
                                        @click="remove(account)"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </div>
                        </div>

                        <form
                            v-if="canManage && domain.status === 'active'"
                            class="managed-form mailbox-form"
                            @submit.prevent="submitMailbox(domain)"
                        >
                            <div class="managed-field">
                                <label :for="`mailbox-email-${domain.id}`">
                                    Email address
                                </label>
                                <div class="email-address-control">
                                    <input
                                        :id="`mailbox-email-${domain.id}`"
                                        v-model="
                                            mailboxForm(domain.id).local_part
                                        "
                                        :aria-describedby="`mailbox-email-help-${domain.id}`"
                                        name="local_part"
                                        autocomplete="off"
                                        placeholder="david"
                                        required
                                    />
                                    <span aria-hidden="true">
                                        @{{ domain.domain }}
                                    </span>
                                </div>
                                <p
                                    :id="`mailbox-email-help-${domain.id}`"
                                    class="field-help"
                                >
                                    Enter the part before @{{ domain.domain }}.
                                </p>
                            </div>
                            <div class="managed-field">
                                <label :for="`mailbox-sender-${domain.id}`">
                                    Sender name
                                </label>
                                <input
                                    :id="`mailbox-sender-${domain.id}`"
                                    v-model="
                                        mailboxForm(domain.id).display_name
                                    "
                                    :aria-describedby="`mailbox-sender-help-${domain.id}`"
                                    name="display_name"
                                    autocomplete="off"
                                    placeholder="David at Outboss"
                                />
                                <p
                                    :id="`mailbox-sender-help-${domain.id}`"
                                    class="field-help"
                                >
                                    The name recipients will see in their inbox.
                                </p>
                            </div>
                            <button class="btn" type="submit">
                                Create mailbox
                            </button>
                        </form>
                    </section>
                </article>
            </div>
        </div>
    </AppWorkspace>
</template>

<style scoped>
.managed-mail-page {
    display: grid;
    gap: 18px;
    padding: 26px;
}

.managed-mail-head,
.managed-domain-title,
.managed-mailbox-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
}

.managed-mail-head h1,
.managed-domain-title h2,
.managed-mailboxes h3,
.dns-records h3,
.dns-checks h3,
.box h2 {
    margin: 0;
}

.eyebrow {
    margin: 0 0 4px;
    color: var(--ob-accent);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
}

.managed-domain-list {
    display: grid;
    gap: 16px;
}

.managed-domain-card,
.managed-mailboxes {
    display: grid;
    gap: 16px;
}

.managed-form {
    display: flex;
    align-items: end;
    flex-wrap: wrap;
    gap: 12px;
}

.managed-field {
    display: grid;
    min-width: 180px;
    gap: 5px;
}

.managed-form label {
    color: #4e4942;
    font-size: 12px;
    font-weight: 700;
}

.managed-form input,
.managed-form select {
    min-height: 38px;
    border: 1px solid #d7d1c8;
    border-radius: 7px;
    padding: 0 10px;
    background: #fff;
    color: #25221f;
    font: inherit;
}

.email-address-control {
    display: flex;
    align-items: center;
    min-height: 38px;
    overflow: hidden;
    border: 1px solid #d7d1c8;
    border-radius: 7px;
    background: #fff;
}

.email-address-control:focus-within {
    border-color: var(--ob-accent);
    box-shadow: 0 0 0 2px rgb(91 70 242 / 12%);
}

.email-address-control input {
    min-width: 90px;
    flex: 1;
    border: 0;
    border-radius: 0;
    outline: 0;
}

.email-address-control span {
    padding: 0 10px 0 2px;
    color: #70695f;
    font-size: 12px;
    white-space: nowrap;
}

.field-help {
    margin: 0;
    color: #70695f;
    font-size: 11px;
    font-weight: 400;
}

.dns-records,
.dns-checks {
    border-top: 1px solid var(--ob-border-soft);
    padding-top: 13px;
}

.dns-records dl {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 5px 14px;
    margin: 10px 0 0;
    font-size: 12px;
}

.dns-records dt {
    color: #70695f;
    font-weight: 700;
}

.dns-records dd {
    min-width: 0;
    margin: 0;
    overflow-wrap: anywhere;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}

.dns-records dd.zone-file {
    max-height: 28rem;
    overflow: auto;
    border-radius: 6px;
    padding: 10px;
    background: #f7f5f1;
    white-space: pre-wrap;
}

.dns-checks {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}

.dns-checks h3 {
    width: 100%;
}

.check,
.status {
    border-radius: 999px;
    padding: 4px 9px;
    background: #eeeae4;
    color: #59534c;
    font-size: 11px;
    font-weight: 700;
    text-transform: capitalize;
}

.status-active,
.status-verified,
.check-pass {
    background: #e2f4e9;
    color: #17633a;
}

.status-pending_dns,
.status-provisioning,
.check-unknown {
    background: #fff3d6;
    color: #825f16;
}

.status-failed,
.status-suspended,
.check-missing {
    background: #fde7e7;
    color: #9b2c2c;
}

.managed-mailbox-list {
    display: grid;
    gap: 8px;
}

.managed-mailbox-row {
    border: 1px solid var(--ob-border-soft);
    border-radius: 8px;
    padding: 12px;
}

.managed-mailbox-row strong {
    overflow-wrap: anywhere;
}

.managed-mailbox-row .meta,
.managed-mailbox-row .bad {
    margin: 4px 0 0;
}

@media (max-width: 720px) {
    .managed-mail-page {
        padding: 18px;
    }

    .managed-mail-head,
    .managed-domain-title,
    .managed-mailbox-row {
        flex-direction: column;
    }
}
</style>
