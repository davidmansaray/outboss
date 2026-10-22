export type RegistryView = 'people' | 'accounts' | 'sources';

export type ProvenanceSummary = {
    primary_source: { id: string; name: string } | null;
    source_count: number;
    last_observed_at: string | null;
    has_conflicts: boolean;
    has_restrictions: boolean;
    source_omission_reason: string | null;
};

export type ReplyState =
    'Interested' | 'Replied' | 'Not now' | 'Awaiting reply' | 'No campaigns';

export type EligibilityState = 'Eligible' | 'Suppressed';

export type CampaignHistory = {
    id: number;
    name: string;
    status: string;
    updated_at: string | null;
};

/** `role` is the person's job title; the field name is kept to limit frontend churn. */
export type RegistryPerson = {
    id: number;
    name: string;
    email: string;
    account_id: number | null;
    account: string | null;
    role: string | null;
    reply: ReplyState;
    eligibility: EligibilityState;
    campaign_label: string;
    campaigns: CampaignHistory[];
    tags: string[];
    notes: string | null;
    source: string;
    provenance?: ProvenanceSummary;
    updated_at: string | null;
};

export type PersonImportReviewRow = {
    row_number: number;
    email: string | null;
    name: string | null;
    company: string | null;
    issue: 'new' | 'updated' | 'invalid' | 'duplicate' | 'suppressed';
};

export type PersonImportReview = {
    id: number;
    source_name: string;
    policy_summary: string;
    mapping_version: number;
    total: number;
    new: number;
    updated: number;
    invalid: number;
    duplicate: number;
    suppressed: number;
    samples: Record<PersonImportReviewRow['issue'], PersonImportReviewRow[]>;
};

export type AccountPersonSummary = Pick<
    RegistryPerson,
    'id' | 'name' | 'email' | 'role' | 'reply'
>;

export type AccountReadiness =
    'Ready' | 'Needs review' | 'Blocked' | 'No people';

export type RegistryAccount = {
    id: number;
    name: string;
    domain: string | null;
    website: string | null;
    notes: string | null;
    tags: string[];
    source: string;
    provenance?: ProvenanceSummary;
    profile: string | null;
    employee_count: number | null;
    people_count: number;
    ready_count: number;
    readiness: AccountReadiness;
    campaigns_count: number;
    people: AccountPersonSummary[];
    updated_at: string | null;
};

export type RegistrySelection =
    | { kind: 'person'; record: RegistryPerson }
    | { kind: 'account'; record: RegistryAccount }
    | { kind: 'source'; record: ProspectSource }
    | null;

export type RegistryFilterGroup = {
    label: string;
    options: { id: string; label: string }[];
};

export type ProspectSourceStatus = 'active' | 'paused' | 'archived';

export type ProspectSourceKind =
    'existing_accounts' | 'csv_import' | 'api_import' | 'manual' | string;

export type SourceRunState =
    | 'draft'
    | 'previewed'
    | 'queued'
    | 'running'
    | 'partial'
    | 'completed'
    | 'failed'
    | 'cancelled'
    | 'expired'
    | 'reconciliation_pending'
    | string;

export type ProspectSourceRun = {
    id: string;
    state: SourceRunState;
    initiator_type?: string | null;
    received: number;
    valid: number;
    accepted: number;
    created: number;
    linked: number;
    duplicate: number;
    conflicted: number;
    suppressed: number;
    rejected: number;
    finished_at?: string | null;
    created_at?: string | null;
};

export type ProspectSource = {
    id: string;
    display_name: string;
    kind: ProspectSourceKind;
    status: ProspectSourceStatus | string;
    name_normalized?: string | null;
    current_version_id?: string | null;
    last_run_id?: string | null;
    last_run?: ProspectSourceRun | null;
    run_count?: number;
    accepted_count?: number;
    created_count?: number;
    linked_count?: number;
    suppressed_count?: number;
    rejected_count?: number;
    restrictions?: string[];
    retention_warning?: string | null;
    created_at?: string | null;
    updated_at?: string | null;
};
