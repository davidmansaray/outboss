export type IntelligenceClaimStatus =
    | 'supported'
    | 'inferred'
    | 'conflicting'
    | 'stale'
    | 'unsupported'
    | 'owner_confirmed'
    | 'rejected'
    | string;

export type IntelligenceEvidence = {
    snapshot_id: string | null;
    edge_type: string;
    locator: string | null;
    source_type: string | null;
    url: string | null;
    registrable_domain: string | null;
    state: string | null;
    observed_at: string | null;
    retrieved_at: string | null;
    expires_at: string | null;
    display_classification: string | null;
    llm_use_classification: string | null;
    export_classification: string | null;
    retrieval_policy_hash: string | null;
    provider_operation: {
        id: string;
        provider: string;
        task: string;
        operation: string | null;
        status: string;
        reserved_cost_micros: number;
        actual_cost_micros: number;
        provider_request_id: string | null;
        safe_error_code: string | null;
    } | null;
};

export type IntelligenceClaim = {
    claim_id: string;
    predicate: string;
    value: unknown;
    value_type?: string;
    status: IntelligenceClaimStatus;
    confidence: number;
    observed_at: string | null;
    fresh_until: string | null;
    evidence_ids: string[];
    evidence: IntelligenceEvidence[];
    eligible_for_qualification?: boolean;
    safe_for_message?: boolean;
    source_count?: number;
    metadata?: Record<string, unknown>;
    history?: {
        claim_id: string;
        predicate: string;
        value: unknown;
        value_type: string;
        status: string;
        confidence: number;
        reason: string | null;
        superseded_at: string | null;
        evidence: IntelligenceEvidence[];
    }[];
};

export type IntelligenceStage = {
    id: string;
    stage_key: string;
    stage_order: number;
    state: string;
    entry_predicate: Record<string, unknown>;
    missing_facts: string[];
    expected_cost_micros: number;
    maximum_cost_micros: number;
    safe_error_code: string | null;
    started_at: string | null;
    succeeded_at: string | null;
    failed_at: string | null;
};

export type IntelligenceRun = {
    id: string;
    workspace_id: number;
    account_id: number;
    parent_run_id: string | null;
    state: string;
    state_label: string;
    awaiting_reason: string | null;
    safe_failure_code: string | null;
    current_stage: string | null;
    estimated_cost_micros: number;
    maximum_cost_micros: number;
    reserved_cost_micros: number;
    actual_cost_micros: number;
    currency: string;
    input_hash: string;
    freshness_policy: Record<string, unknown>;
    capability_snapshot: Record<string, unknown>;
    queued_at: string | null;
    started_at: string | null;
    finished_at: string | null;
    cancelled_at: string | null;
    stages: IntelligenceStage[];
};

export type IntelligencePreflight = {
    account_id: number;
    input_hash: string;
    blocked: boolean;
    blockers: { code: string; message: string }[];
    stages: IntelligenceStage[];
    account_snapshot: {
        id: number;
        name: string;
        website: string | null;
        domain_normalized: string | null;
        tags: string[];
        source: string;
    };
    policy_snapshot: {
        source_policy_hash?: string;
        s1_observation_handles?: Record<string, unknown>[];
        [key: string]: unknown;
    };
    capability_snapshot: {
        selected?: string[];
        required?: Record<string, boolean>;
        providers?: Record<
            string,
            { ready?: boolean; credential_status?: string }
        >;
        [key: string]: unknown;
    };
    freshness_policy: Record<string, unknown>;
    estimated_cost_micros: number;
    maximum_cost_micros: number;
    currency: string;
    gtm_profile_version_id: string | null;
    offer_version_id: string | null;
};

export type IntelligenceQualification = {
    id: string;
    account_id: number;
    research_run_id: string;
    outcome: string;
    review_state: string;
    confidence: number;
    calibration_band: string | null;
    missing_facts: string[];
    disqualifiers: string[];
    explanation: string;
    provider_cost_micros: number;
    generated_at: string | null;
    reviewed_at: string | null;
    rubric: {
        id: string;
        version: number;
        dimensions: unknown;
        required_facts: unknown;
        disqualifiers: unknown;
        decision_rules: Record<string, unknown>;
    } | null;
    claims: { claim_id: string; role: string }[];
    reasons_to_contact: {
        id: string;
        why_account: string | null;
        why_offer: string | null;
        why_now: string | null;
        factual_basis: string | null;
        internal_rationale: string | null;
        confidence: number;
        safe_for_message: boolean;
        visibility_label: string;
        sensitive_inference: boolean;
        claim_ids: string[];
    }[];
    review_events: {
        id: string;
        action: string;
        reason: string | null;
        prior_review_state: string | null;
        projected_review_state: string | null;
        created_at: string | null;
        actor: { display_name?: string } | null;
    }[];
};

export type IntelligenceOffer = {
    id: string;
    status: string;
    availability_state: string;
    lock_version: number;
    current_version_id: string | null;
    linked_version_id: string;
    summary: {
        name: string | null;
        promise: string | null;
        target_audience: string | null;
        call_to_action: string | null;
        fulfilment_mode: string | null;
        destination_url: string | null;
    };
    linked_version: {
        id: string;
        version: number;
        schema_version: number;
        content_hash: string;
        summary: IntelligenceOffer['summary'];
    };
    readiness: {
        id: string;
        offer_id: string;
        offer_version_id: string;
        state: string;
        blockers: { code: string; message: string }[];
        evaluated_at: string | null;
        valid_until: string | null;
    } | null;
};

export type IntelligenceConversion = {
    definitions: {
        id: string;
        event_key: string;
        status: string;
        current_version: {
            id: string;
            version: number;
            display_name: string;
            description: string | null;
            subject_type: string;
        } | null;
    }[];
    events: {
        id: string;
        event_key: string | null;
        state: string;
        verification_state: string;
        occurred_at: string | null;
        received_at: string | null;
        attribution_state: string;
    }[];
    event_count: number;
    attribution_state: string;
};

export type AccountIntelligenceAccount = {
    id: number;
    name: string;
    website: string | null;
    domain: string | null;
    notes: string | null;
    tags: string[];
    source: string;
    provenance: {
        primary_source: { id: string; name: string } | null;
        source_count: number;
        last_observed_at: string | null;
        has_conflicts: boolean;
        has_restrictions: boolean;
        source_omission_reason: string | null;
    };
    supplied_by: {
        source: { id: string; name: string } | null;
        source_count: number;
        last_observed_at: string | null;
        has_conflicts: boolean;
        has_restrictions: boolean;
        omission_reason: string | null;
    };
};

export type AccountIntelligencePageProps = {
    account: AccountIntelligenceAccount;
    provenance: AccountIntelligenceAccount['provenance'];
    preflight: IntelligencePreflight;
    researchRun: IntelligenceRun | null;
    researchRuns: IntelligenceRun[];
    intelligence: {
        claims: IntelligenceClaim[];
        by_status: Record<string, IntelligenceClaim[]>;
        status_counts: Record<string, number>;
        conflicts: IntelligenceClaim[];
        missing_facts: string[];
    };
    qualification: IntelligenceQualification | null;
    offer: IntelligenceOffer | null;
    conversion: IntelligenceConversion;
    selectedRunId: string | null;
    isOwner: boolean;
    canWrite: boolean;
};
