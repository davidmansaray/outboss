export type GtmProfileSection =
    'company' | 'market' | 'buyers' | 'messaging' | 'qualification';

export type GtmProfileProvenance =
    'direct_source' | 'ai_inferred' | 'owner_supplied' | 'system';

export type GtmProfileValue<T = string> = {
    value: T;
    provenance?: GtmProfileProvenance | string;
    source_ids?: string[];
    owner_locked?: boolean;
    updated_at?: string | null;
    id?: string;
    tombstoned?: boolean;
};

export const GTM_PROFILE_REPEATED_FIELDS = [
    'geographies',
    'company_traits',
    'excluded_segments',
    'target_roles',
    'buying_triggers',
    'blocking_roles',
    'proof_points',
    'prohibited_claims',
    'message_constraints',
    'evidence_requirements',
    'business_disqualifiers',
] as const;

export type GtmProfileRepeatedField =
    (typeof GTM_PROFILE_REPEATED_FIELDS)[number];

export function isGtmProfileRepeatedField(
    field: string,
): field is GtmProfileRepeatedField {
    return (GTM_PROFILE_REPEATED_FIELDS as readonly string[]).includes(field);
}

export type GtmProfilePayload = {
    synthesis?: GtmProfileValue<string> | string;
    company?: Record<string, unknown>;
    market?: Record<string, unknown>;
    buyers?: Record<string, unknown>;
    messaging?: Record<string, unknown>;
    qualification?: Record<string, unknown>;
    [key: string]: unknown;
};

export type GtmResearchStateName =
    | 'not_started'
    | 'awaiting_provider'
    | 'awaiting_budget'
    | 'queued'
    | 'running'
    | 'ready'
    | 'failed'
    | 'cancelled';

export type GtmResearchState = {
    state: GtmResearchStateName;
    awaiting_reason?: 'provider' | 'budget' | null;
    safe_error_code?: string | null;
    last_researched_at?: string | null;
    last_failed_at?: string | null;
    source_count?: number;
    website_url?: string | null;
    stale?: boolean;
    run_uuid?: string | null;
};

export type GtmProfileVersion = {
    uuid: string;
    version: number;
    schema_version?: number;
    content_hash?: string;
    origin?: string;
    created_at?: string;
    actor?: { type?: string; name?: string | null } | null;
    payload?: GtmProfilePayload;
};

export type GtmProfileSource = {
    uuid: string;
    source_type?: string;
    canonical_url: string;
    title?: string | null;
    excerpt?: string | null;
    observed_at?: string | null;
    published_at?: string | null;
    provenance_label?: string | null;
};

export type GtmProfileReviewItem = {
    uuid: string;
    field_path: string;
    type: 'missing' | 'conflict' | 'inferred' | 'stale' | string;
    proposed_value?: unknown;
    proposed_label?: string | null;
    source_ids?: string[];
    status: 'open' | 'resolved' | string;
    question?: string | null;
    explanation?: string | null;
};

export type GtmProfile = {
    uuid: string;
    lock_version: number;
    payload: GtmProfilePayload;
    current_version_id?: string | null;
    current_version?: GtmProfileVersion | null;
    research_state?: GtmResearchState;
    review_items?: GtmProfileReviewItem[];
    sources?: GtmProfileSource[];
};

export type GtmProfileHistoryEntry = GtmProfileVersion & {
    is_current?: boolean;
};

export const GTM_PROFILE_SECTIONS: Array<{
    id: GtmProfileSection;
    label: string;
    description: string;
}> = [
    {
        id: 'company',
        label: 'Company',
        description: 'Identity, problem, product, and outcome.',
    },
    {
        id: 'market',
        label: 'Market',
        description: 'Target segment, timing, geography, and exclusions.',
    },
    {
        id: 'buyers',
        label: 'Buyers',
        description: 'Roles, triggers, and buying-group blockers.',
    },
    {
        id: 'messaging',
        label: 'Proof and messaging',
        description: 'Proof points, voice, and claims Outboss should avoid.',
    },
    {
        id: 'qualification',
        label: 'Qualification',
        description: 'Six fixed dimensions, evidence, and disqualifiers.',
    },
];

export function displayGtmValue(value: unknown): string {
    if (value === null || value === undefined) {
        return '';
    }

    if (typeof value === 'string' || typeof value === 'number') {
        return String(value);
    }

    if (typeof value === 'object' && 'value' in value) {
        return displayGtmValue((value as { value: unknown }).value);
    }

    if (Array.isArray(value)) {
        return value
            .map((item) => displayGtmValue(item))
            .filter(Boolean)
            .join(', ');
    }

    return '';
}

export function gtmValueProvenance(value: unknown): string | null {
    if (
        typeof value !== 'object' ||
        value === null ||
        !('provenance' in value)
    ) {
        return null;
    }

    const provenance = (value as { provenance?: unknown }).provenance;

    return typeof provenance === 'string' ? provenance : null;
}

export function isGtmProfileResearchBusy(
    state: GtmResearchStateName | undefined,
): boolean {
    return state === 'queued' || state === 'running';
}
