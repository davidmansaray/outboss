export type MailboxSyncPhase =
    'idle' | 'queued' | 'syncing' | 'complete' | 'failed';

export type MailboxStatus = 'connected' | 'needs_reconnect' | 'disconnected';

export type MailboxSyncFeedback = {
    phase: MailboxSyncPhase;
    percent: number | null;
    phase_label: string;
    detail: string;
    button_label: string;
    last_sync_label: string;
    scope_label: string;
    active_scope: 'inbox' | 'sent' | null;
};

export type MailboxReplyIdentity = {
    key: string;
    email: string;
    name: string | null;
    mode: string | null;
};

export type MailboxRow = {
    id: number;
    email: string;
    provider: string;
    status: MailboxStatus;
    error: string | null;
    replies_today: number;
    reply_identities?: MailboxReplyIdentity[];
    selected_reply_identity_key?: string | null;
    effective_reply_identity?: {
        key: string;
        email: string;
        name: string | null;
    } | null;
    reply_identity_error?: string | null;
    sync: MailboxSyncFeedback;
    daily_send_cap?: number | null;
    default_daily_cap?: number;
    sending_paused_at?: string | null;
    sending_paused_reason?: string | null;
};
