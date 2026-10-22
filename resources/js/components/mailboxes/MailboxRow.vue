<script setup lang="ts">
import { computed, ref } from 'vue';
import MailboxInlineProgress from '@/components/mailboxes/MailboxInlineProgress.vue';
import MailboxStatusDot from '@/components/mailboxes/MailboxStatusDot.vue';
import MailboxSyncButton from '@/components/mailboxes/MailboxSyncButton.vue';
import type { MailboxRow as MailboxRowData } from '@/types/mailbox';

const props = defineProps<{
    box: MailboxRowData;
}>();

const emit = defineEmits<{
    sync: [];
    reconnect: [];
    disconnect: [];
    editReplyIdentity: [];
    updateCap: [value: number | null];
    resumeSending: [];
    checkDns: [];
}>();

const capInput = ref(
    props.box.daily_send_cap === null || props.box.daily_send_cap === undefined
        ? ''
        : String(props.box.daily_send_cap),
);
const dnsResult = ref<{
    domain: string;
    spf: string;
    dmarc: string;
    dkim: string;
} | null>(null);
const dnsBusy = ref(false);

const sendingPaused = computed(() => Boolean(props.box.sending_paused_at));
const pauseReasonLabel = computed(() => {
    if (props.box.sending_paused_reason === 'provider_error_threshold') {
        return 'Paused after repeated provider errors.';
    }

    if (props.box.sending_paused_reason === 'bounce_threshold') {
        return 'Paused after hard bounces.';
    }

    return 'Sending is paused.';
});

function saveCap() {
    const raw = capInput.value.trim();
    emit('updateCap', raw === '' ? null : Number(raw));
}

async function checkDns() {
    dnsBusy.value = true;

    try {
        const response = await fetch(`/mailboxes/${props.box.id}/auth-check`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            return;
        }

        dnsResult.value = (await response.json()) as {
            domain: string;
            spf: string;
            dmarc: string;
            dkim: string;
        };
        emit('checkDns');
    } finally {
        dnsBusy.value = false;
    }
}

const providerLabel = computed(() => {
    switch (props.box.provider) {
        case 'zoho':
            return 'Zoho';
        case 'gmail':
            return 'Google';
        case 'microsoft':
            return 'Microsoft';
        default:
            return props.box.provider;
    }
});

const phase = computed(() => props.box.sync.phase);
const busy = computed(
    () => phase.value === 'queued' || phase.value === 'syncing',
);
const needsReconnect = computed(() => props.box.status === 'needs_reconnect');
const replyIdentities = computed(() => props.box.reply_identities ?? []);
const replyIdentityError = computed(
    () => props.box.reply_identity_error ?? null,
);
const effectiveReplyIdentity = computed(
    () =>
        props.box.effective_reply_identity ?? {
            key: props.box.selected_reply_identity_key ?? '',
            email: props.box.email,
            name: null,
        },
);
const senderIdentityLabel = computed(() => {
    const identity = effectiveReplyIdentity.value;

    return identity.name && identity.name !== identity.email
        ? `${identity.name} <${identity.email}>`
        : identity.email;
});
const hasIdentitySwitcher = computed(() => replyIdentities.value.length > 1);

const tone = computed<'neutral' | 'syncing' | 'complete'>(() => {
    if (busy.value) {
        return 'syncing';
    }

    if (phase.value === 'complete') {
        return 'complete';
    }

    if (needsReconnect.value || phase.value === 'failed') {
        return 'neutral';
    }

    if (props.box.sync.last_sync_label.startsWith('Last synced')) {
        return 'complete';
    }

    return 'neutral';
});

const connectionLabel = computed(() => {
    if (needsReconnect.value) {
        return 'Needs reconnect';
    }

    if (phase.value === 'complete') {
        return 'Connected · current';
    }

    return 'Connected';
});

const lastSyncDetail = computed(() => {
    if (needsReconnect.value || phase.value === 'failed') {
        return props.box.sync.detail;
    }

    if (phase.value === 'idle') {
        return props.box.sync.detail;
    }

    return props.box.sync.scope_label;
});

const errorText = computed(() => {
    if (props.box.error) {
        return props.box.error;
    }

    if (phase.value === 'failed' && !needsReconnect.value) {
        return props.box.sync.detail;
    }

    return null;
});
</script>

<template>
    <div
        class="mailbox-row"
        :class="{
            syncing: busy,
            complete: phase === 'complete',
        }"
        data-test="mailbox-row"
        :aria-busy="busy ? 'true' : undefined"
    >
        <div class="mailbox-identity">
            <button
                v-if="hasIdentitySwitcher"
                class="mailbox-identity-trigger"
                type="button"
                data-test="mailbox-reply-identity-button"
                :aria-label="`Change sender identity, currently ${senderIdentityLabel}`"
                @click="emit('editReplyIdentity')"
            >
                <span class="mailbox-identity-value">{{
                    senderIdentityLabel
                }}</span>
                <span class="mailbox-identity-caret" aria-hidden="true">⌄</span>
            </button>
            <div v-else class="mailbox-email">{{ senderIdentityLabel }}</div>
            <div class="mailbox-meta">
                <MailboxStatusDot :tone="tone" />
                <span class="mailbox-account-email">{{ box.email }}</span>
                <span>·</span>
                <span>{{ providerLabel }}</span>
                <span>·</span>
                <span>{{ connectionLabel }}</span>
            </div>
            <div v-if="replyIdentityError" class="mailbox-reply-error">
                {{ replyIdentityError }}
            </div>
        </div>
        <div class="mailbox-last-sync">
            <strong>{{ box.sync.last_sync_label }}</strong>
            <span>{{ lastSyncDetail }}</span>
        </div>
        <div class="row-actions">
            <MailboxSyncButton
                v-if="!needsReconnect"
                :phase="phase"
                :label="box.sync.button_label"
                @click="emit('sync')"
            />
            <button
                v-else
                class="sync-button"
                type="button"
                data-test="mailbox-reconnect-button"
                @click="emit('reconnect')"
            >
                Reconnect
            </button>
            <button
                class="btn danger"
                type="button"
                data-test="mailbox-disconnect-button"
                @click="emit('disconnect')"
            >
                Disconnect
            </button>
        </div>
        <MailboxInlineProgress
            :visible="busy"
            :label="box.sync.phase_label"
            :detail="box.sync.detail"
            :percent="box.sync.percent"
        />
        <div v-if="errorText" class="bad mailbox-error">{{ errorText }}</div>
        <div class="mailbox-safety">
            <label class="mailbox-cap">
                <span>Daily campaign cap</span>
                <input
                    v-model="capInput"
                    type="number"
                    min="1"
                    max="1000"
                    :placeholder="String(box.default_daily_cap ?? 50)"
                    data-test="mailbox-daily-cap"
                    @change="saveCap"
                />
                <small>Resets at midnight UTC</small>
            </label>
            <div v-if="sendingPaused" class="mailbox-pause">
                <p data-test="mailbox-sending-paused">{{ pauseReasonLabel }}</p>
                <button
                    class="btn"
                    type="button"
                    data-test="mailbox-resume-sending"
                    @click="emit('resumeSending')"
                >
                    Resume sending
                </button>
            </div>
            <div class="mailbox-dns">
                <button
                    class="btn ghost"
                    type="button"
                    data-test="mailbox-check-dns"
                    :disabled="dnsBusy"
                    @click="checkDns"
                >
                    {{ dnsBusy ? 'Checking…' : 'Check DNS' }}
                </button>
                <div
                    v-if="dnsResult"
                    class="mailbox-dns-results"
                    data-test="mailbox-dns-results"
                >
                    <span>SPF {{ dnsResult.spf }}</span>
                    <span>DMARC {{ dnsResult.dmarc }}</span>
                    <span>DKIM {{ dnsResult.dkim }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
