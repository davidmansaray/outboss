<?php

return [
    'queue' => env('OUTBOSS_INTELLIGENCE_QUEUE', 'ai'),
    'research' => [
        // Emergency brake for admitting new paid work. Existing reads,
        // reviews, cancellation, and reconciliation remain available.
        'pause_new' => (bool) env('OUTBOSS_INTELLIGENCE_PAUSE_NEW_RESEARCH', false),
        'max_attempts' => (int) env('OUTBOSS_INTELLIGENCE_MAX_ATTEMPTS', 3),
        'attempt_lease_seconds' => (int) env('OUTBOSS_INTELLIGENCE_ATTEMPT_LEASE_SECONDS', 300),
        'provider_lease_seconds' => (int) env('OUTBOSS_INTELLIGENCE_PROVIDER_LEASE_SECONDS', 960),
        'backoff_seconds' => (int) env('OUTBOSS_INTELLIGENCE_BACKOFF_SECONDS', 30),
        'timeout_seconds' => (int) env('OUTBOSS_INTELLIGENCE_TIMEOUT_SECONDS', 900),
        'max_checkpoint_bytes' => (int) env('OUTBOSS_INTELLIGENCE_MAX_CHECKPOINT_BYTES', 512 * 1024),
        'max_snapshot_bytes' => (int) env('OUTBOSS_INTELLIGENCE_MAX_SNAPSHOT_BYTES', 128 * 1024),
        'max_claims_per_run' => (int) env('OUTBOSS_INTELLIGENCE_MAX_CLAIMS_PER_RUN', 100),
        'retention_batch_size' => (int) env('OUTBOSS_INTELLIGENCE_RETENTION_BATCH_SIZE', 100),
    ],
    'qualification' => [
        'rubric_name' => 'Default account qualification',
        'max_explanation_bytes' => (int) env('OUTBOSS_INTELLIGENCE_MAX_EXPLANATION_BYTES', 4000),
    ],
    'testing' => [
        // The provider registry also requires APP_ENV=testing. This switch
        // cannot enable fake providers in production.
        'fake_providers' => (bool) env('OUTBOSS_TEST_FAKE_INTELLIGENCE_PROVIDERS', false),
        // Deterministic safety/evaluation seam. The adapter ignores this
        // unless both APP_ENV=testing and fake_providers are active.
        'message_drafting_outcome' => env('OUTBOSS_TEST_MESSAGE_DRAFTING_OUTCOME', 'valid'),
    ],
    // Read-only S2 operational visibility.  The report deliberately keeps
    // fixed, low-cardinality buckets so it is safe to ship to a metrics
    // backend without leaking source URLs, domains, identities, prompts or
    // claim text.  Operators may tune alert thresholds without changing the
    // application code.
    'operations' => [
        'max_rows' => (int) env('OUTBOSS_S2_OPERATIONS_MAX_ROWS', 5000),
        'alerts' => [
            'stuck_age_seconds' => (int) env('OUTBOSS_S2_STUCK_AGE_SECONDS', 900),
            'held_budget_age_seconds' => (int) env('OUTBOSS_S2_HELD_BUDGET_AGE_SECONDS', 900),
            'paid_result_loss_count' => (int) env('OUTBOSS_S2_PAID_RESULT_LOSS_THRESHOLD', 1),
            'output_invalid_rate' => (float) env('OUTBOSS_S2_OUTPUT_INVALID_RATE_THRESHOLD', 0.10),
            'manual_review_count' => (int) env('OUTBOSS_S2_MANUAL_REVIEW_THRESHOLD', 1),
            'cost_near_hard_maximum_count' => (int) env('OUTBOSS_S2_COST_NEAR_HARD_MAX_THRESHOLD', 1),
            'retention_backlog_count' => (int) env('OUTBOSS_S2_RETENTION_BACKLOG_THRESHOLD', 1),
            'offer_dependency_failure_count' => (int) env('OUTBOSS_S2_OFFER_DEPENDENCY_THRESHOLD', 1),
            'fulfilment_sla_breach_count' => (int) env('OUTBOSS_S2_FULFILMENT_SLA_THRESHOLD', 1),
            'capacity_exhaustion_count' => (int) env('OUTBOSS_S2_CAPACITY_EXHAUSTION_THRESHOLD', 1),
            'signature_failure_count' => (int) env('OUTBOSS_S2_SIGNATURE_FAILURE_THRESHOLD', 1),
            'conversion_ambiguity_count' => (int) env('OUTBOSS_S2_CONVERSION_AMBIGUITY_THRESHOLD', 1),
            'fence_rejection_count' => (int) env('OUTBOSS_S2_FENCE_REJECTION_THRESHOLD', 1),
        ],
        'cost_near_hard_maximum_ratio' => (float) env('OUTBOSS_S2_COST_NEAR_HARD_MAX_RATIO', 0.90),
    ],
];
