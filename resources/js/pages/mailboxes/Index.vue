<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MailboxRow from '@/components/mailboxes/MailboxRow.vue';
import WorkspaceOpsSidebar from '@/components/WorkspaceOpsSidebar.vue';
import { useMailboxSyncPolling } from '@/composables/useMailboxSyncPolling';
import AppWorkspace from '@/layouts/AppWorkspace.vue';
import type { MailboxRow as MailboxRowData } from '@/types/mailbox';

const props = withDefaults(
    defineProps<{
        mailboxes: MailboxRowData[];
        canManageInternal?: boolean;
        returnCampaign?: { id: number; name: string } | null;
    }>(),
    { canManageInternal: false },
);

const page = usePage();
const flash = computed(
    () =>
        (page.props.flash as
            { success?: string; error?: string } | undefined) ?? {},
);

const confirming = ref<MailboxRowData | null>(null);
const removingId = ref<number | null>(null);
const editingReplyIdentity = ref<MailboxRowData | null>(null);
const selectedReplyIdentityKey = ref<string | null>(null);
const savingReplyIdentity = ref(false);
const replyIdentitySaveError = ref<string | null>(null);

const visibleMailboxes = computed(() =>
    props.mailboxes.filter(
        (box) => box.status !== 'disconnected' && box.id !== removingId.value,
    ),
);

const { displayMailboxes, markLocalQueued } =
    useMailboxSyncPolling(visibleMailboxes);

const connectedCount = computed(() => visibleMailboxes.value.length);
function requestSync(box: MailboxRowData) {
    router.post(
        `/mailboxes/${box.id}/sync`,
        {},
        {
            preserveScroll: true,
            onStart: () => markLocalQueued(box.id),
        },
    );
}

function requestReconnect(box: MailboxRowData) {
    router.post(`/mailboxes/${box.id}/reconnect`);
}

function requestDisconnect(box: MailboxRowData) {
    confirming.value = box;
}

function requestReplyIdentity(box: MailboxRowData) {
    editingReplyIdentity.value = box;
    replyIdentitySaveError.value = null;
    selectedReplyIdentityKey.value =
        box.selected_reply_identity_key ??
        box.effective_reply_identity?.key ??
        box.reply_identities?.[0]?.key ??
        null;
}

function cancelReplyIdentity() {
    if (savingReplyIdentity.value) {
        return;
    }

    closeReplyIdentity();
}

function closeReplyIdentity() {
    editingReplyIdentity.value = null;
    selectedReplyIdentityKey.value = null;
    replyIdentitySaveError.value = null;
}

function saveReplyIdentity() {
    const box = editingReplyIdentity.value;
    const key = selectedReplyIdentityKey.value;

    if (!box || !key || savingReplyIdentity.value) {
        return;
    }

    savingReplyIdentity.value = true;
    router.patch(
        `/mailboxes/${box.id}/reply-identity`,
        { identity_key: key },
        {
            preserveScroll: true,
            onSuccess: () => closeReplyIdentity(),
            onError: (errors) => {
                replyIdentitySaveError.value =
                    errors.identity_key ??
                    'Could not save that reply identity. Refresh and try again.';
            },
            onFinish: () => {
                savingReplyIdentity.value = false;
            },
        },
    );
}

function cancelDisconnect() {
    confirming.value = null;
}

function confirmDisconnect() {
    const box = confirming.value;

    if (!box) {
        return;
    }

    removingId.value = box.id;
    confirming.value = null;
    router.delete(`/mailboxes/${box.id}`, {
        preserveState: false,
        onError: () => {
            removingId.value = null;
        },
    });
}
</script>

<template>
    <Head title="Mailboxes" />
    <AppWorkspace
        area="mailboxes"
        title="Mailboxes"
        :show-sidebar-title="false"
    >
        <template #sidebar>
            <WorkspaceOpsSidebar
                current="mailboxes"
                :show-internal="canManageInternal"
            />
        </template>
        <div class="pane mailboxes">
            <p v-if="returnCampaign" class="hint">
                <Link :href="`/campaigns/${returnCampaign.id}?step=sending`"
                    >← Return to {{ returnCampaign.name }}</Link
                >
                · Inbox limit changes affect every campaign using that inbox.
            </p>
            <header class="mailboxes-page-head">
                <div>
                    <h1>Mailboxes</h1>
                    <span
                        >Connect sender identities, review their health, and
                        control campaign limits.</span
                    >
                </div>
                <strong>{{ connectedCount }} connected</strong>
            </header>
            <p v-if="flash.success" class="hint">{{ flash.success }}</p>
            <p v-if="flash.error" class="bad">{{ flash.error }}</p>
            <div id="connect" class="box">
                <div>
                    <strong>Connect an inbox</strong>
                    <div class="meta">
                        Gmail, Microsoft, or Zoho. Replies from every connected
                        mailbox land in Inbox. Zoho Mail needs a paid plan with
                        API access.
                    </div>
                </div>
                <div class="actions">
                    <a class="btn ghost" href="/oauth/gmail/redirect"
                        >Connect Gmail</a
                    >
                    <a class="btn ghost" href="/oauth/microsoft/redirect"
                        >Connect Microsoft</a
                    >
                    <a class="btn ghost" href="/oauth/zoho/redirect"
                        >Connect Zoho</a
                    >
                </div>
            </div>
            <p v-if="visibleMailboxes.length === 0" class="empty">
                No inboxes connected yet.
            </p>
            <div v-else class="mailbox-list">
                <MailboxRow
                    v-for="box in displayMailboxes"
                    :key="box.id"
                    :box="box"
                    @sync="requestSync(box)"
                    @reconnect="requestReconnect(box)"
                    @disconnect="requestDisconnect(box)"
                    @edit-reply-identity="requestReplyIdentity(box)"
                    @update-cap="
                        (value) =>
                            router.patch(
                                `/mailboxes/${box.id}/sending`,
                                { daily_send_cap: value },
                                { preserveScroll: true },
                            )
                    "
                    @resume-sending="
                        router.post(
                            `/mailboxes/${box.id}/resume-sending`,
                            {},
                            { preserveScroll: true },
                        )
                    "
                />
            </div>
        </div>
    </AppWorkspace>
    <div
        v-if="confirming"
        class="modal-back"
        role="dialog"
        aria-modal="true"
        aria-labelledby="disconnect-title"
        @click.self="cancelDisconnect"
    >
        <div class="modal">
            <h2 id="disconnect-title">Disconnect {{ confirming.email }}?</h2>
            <p class="hint">
                This removes the inbox from OutBoss. Existing threads stay in
                Inbox. You can connect it again later.
            </p>
            <div class="actions">
                <button
                    class="btn ghost"
                    type="button"
                    data-test="cancel-disconnect"
                    @click="cancelDisconnect"
                >
                    Cancel
                </button>
                <button
                    class="btn danger"
                    type="button"
                    data-test="confirm-disconnect"
                    @click="confirmDisconnect"
                >
                    Disconnect
                </button>
            </div>
        </div>
    </div>
    <div
        v-if="editingReplyIdentity"
        class="modal-back"
        role="dialog"
        aria-modal="true"
        aria-labelledby="reply-identity-title"
        @click.self="cancelReplyIdentity"
    >
        <div class="modal reply-identity-modal">
            <h2 id="reply-identity-title">Reply identity</h2>
            <p class="hint">
                Choose the From name and address used for new replies from
                {{ editingReplyIdentity.email }}.
            </p>
            <div class="reply-identity-options" role="radiogroup">
                <label
                    v-for="identity in editingReplyIdentity.reply_identities"
                    :key="identity.key"
                    class="reply-identity-option"
                    :class="{
                        selected: selectedReplyIdentityKey === identity.key,
                    }"
                >
                    <input
                        v-model="selectedReplyIdentityKey"
                        type="radio"
                        name="reply-identity"
                        :value="identity.key"
                    />
                    <span class="reply-identity-option-copy">
                        <strong>{{ identity.name || identity.email }}</strong>
                        <span>{{ identity.email }}</span>
                    </span>
                    <span
                        v-if="selectedReplyIdentityKey === identity.key"
                        class="reply-identity-check"
                        aria-hidden="true"
                        >✓</span
                    >
                </label>
            </div>
            <p v-if="replyIdentitySaveError" class="bad">
                {{ replyIdentitySaveError }}
            </p>
            <div class="actions">
                <button
                    class="btn ghost"
                    type="button"
                    data-test="cancel-reply-identity"
                    :disabled="savingReplyIdentity"
                    @click="cancelReplyIdentity"
                >
                    Cancel
                </button>
                <button
                    class="btn"
                    type="button"
                    data-test="save-reply-identity"
                    :disabled="!selectedReplyIdentityKey || savingReplyIdentity"
                    @click="saveReplyIdentity"
                >
                    {{ savingReplyIdentity ? 'Saving…' : 'Save identity' }}
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.mailboxes-page-head {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 24px;
    margin-bottom: 20px;
    padding-bottom: 22px;
    border-bottom: 1px solid #e3ddd4;
}
.mailboxes-page-head h1 {
    margin: 0;
    color: #211e19;
    font-size: 28px;
    letter-spacing: -0.03em;
}
.mailboxes-page-head span {
    display: block;
    max-width: 640px;
    margin-top: 7px;
    color: #746d64;
    font-size: 13px;
}
.mailboxes-page-head > strong {
    color: #24755b;
    font-size: 12px;
}
@media (max-width: 600px) {
    .mailboxes-page-head {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>
