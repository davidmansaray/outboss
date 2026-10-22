<?php

return [
    'queue' => env('OUTBOSS_GTM_QUEUE', 'ai'),

    'dispatch' => [
        'lease_seconds' => (int) env('OUTBOSS_GTM_DISPATCH_LEASE_SECONDS', 300),
        'retry_seconds' => (int) env('OUTBOSS_GTM_DISPATCH_RETRY_SECONDS', 30),
        'batch_size' => (int) env('OUTBOSS_GTM_DISPATCH_BATCH_SIZE', 100),
    ],

    'limits' => [
        'identity_max_length' => 160,
        'url_max_length' => 2048,
        'assertion_max_length' => 5000,
        'notes_max_length' => 2000,
        'repeated_items_max' => 50,
        'current_version_sources_max' => 100,
        'source_excerpt_bytes_max' => 4096,
        'canonical_payload_bytes_max' => 512 * 1024,
        'open_review_items_max' => 20,
    ],

    'history' => [
        'per_page' => (int) env('OUTBOSS_GTM_HISTORY_PER_PAGE', 20),
        'max_per_page' => 50,
    ],

    'throttles' => [
        // The autosave budget is intentionally separate from deliberate
        // mutations so normal editing cannot exhaust the smaller action pool.
        'autosave_per_minute_per_user' => (int) env('OUTBOSS_GTM_AUTOSAVE_PER_MINUTE_PER_USER', 120),
        'autosave_per_minute_per_workspace' => (int) env('OUTBOSS_GTM_AUTOSAVE_PER_MINUTE_PER_WORKSPACE', 300),
        'mutations_per_minute_per_user' => (int) env('OUTBOSS_GTM_MUTATIONS_PER_MINUTE_PER_USER', 60),
        'mutations_per_minute_per_workspace' => (int) env('OUTBOSS_GTM_MUTATIONS_PER_MINUTE_PER_WORKSPACE', 180),
        'research_per_minute_per_user' => (int) env('OUTBOSS_GTM_RESEARCH_PER_MINUTE_PER_USER', 6),
        'research_per_minute_per_workspace' => (int) env('OUTBOSS_GTM_RESEARCH_PER_MINUTE_PER_WORKSPACE', 12),
        'export_per_minute_per_user' => (int) env('OUTBOSS_GTM_EXPORT_PER_MINUTE_PER_USER', 2),
        'export_per_minute_per_workspace' => (int) env('OUTBOSS_GTM_EXPORT_PER_MINUTE_PER_WORKSPACE', 5),
    ],

    'network' => [
        'dns_cache_seconds' => (int) env('OUTBOSS_GTM_DNS_CACHE_SECONDS', 30),
        'max_dns_cache_entries' => (int) env('OUTBOSS_GTM_MAX_DNS_CACHE_ENTRIES', 256),
        'max_dns_hosts_per_batch' => (int) env('OUTBOSS_GTM_MAX_DNS_HOSTS_PER_BATCH', 25),
        'max_dns_answers_per_host' => (int) env('OUTBOSS_GTM_MAX_DNS_ANSWERS_PER_HOST', 16),
    ],

    /*
    | Fixed dimensions are part of the payload contract. They are deliberately
    | configuration rather than user-editable profile data.
    */
    'qualification' => [
        'dimensions' => [
            ['key' => 'segment_fit', 'label' => 'Segment fit', 'weight' => 25],
            ['key' => 'problem_relevance', 'label' => 'Problem relevance', 'weight' => 20],
            ['key' => 'timing_trigger', 'label' => 'Timing / trigger', 'weight' => 20],
            ['key' => 'buyer_committee_fit', 'label' => 'Buyer committee fit', 'weight' => 15],
            ['key' => 'commercial_feasibility', 'label' => 'Commercial feasibility', 'weight' => 10],
            ['key' => 'evidence_quality_freshness', 'label' => 'Evidence quality / freshness', 'weight' => 10],
        ],
        'review_min' => 50,
        'contact_min' => 75,
    ],

    'research' => [
        'timeout_seconds' => (int) env('OUTBOSS_GTM_RESEARCH_TIMEOUT_SECONDS', 900),
        'backoff_seconds' => (int) env('OUTBOSS_GTM_RESEARCH_BACKOFF_SECONDS', 30),
        'max_attempts' => (int) env('OUTBOSS_GTM_RESEARCH_MAX_ATTEMPTS', 3),
        'attempt_lease_seconds' => (int) env('OUTBOSS_GTM_RESEARCH_ATTEMPT_LEASE_SECONDS', 300),
        'refresh_cooldown_seconds' => (int) env('OUTBOSS_GTM_RESEARCH_REFRESH_COOLDOWN_SECONDS', 3600),
        'max_concurrent_per_workspace' => (int) env('OUTBOSS_GTM_RESEARCH_MAX_CONCURRENT_PER_WORKSPACE', 1),
        'max_sources' => 100,
        'max_context_bytes' => 16 * 1024,
        'max_context_title_bytes' => 512,
        'max_context_excerpt_bytes' => 4096,
        'max_citations_per_field' => 8,
        'max_checkpoint_bytes' => 512 * 1024,
        'max_open_review_items' => 20,
    ],

    'safe_error_codes' => [
        'provider_not_configured',
        'provider_unhealthy',
        'budget_unavailable',
        'research_timed_out',
        'research_failed',
        'dispatch_missing',
        'paid_result_lost',
        'retry_exhausted',
        'stale_version',
        'operation_replay_mismatch',
        'provider_uncertain',
        'invalid_credential',
        'rate_limited',
        'provider_unavailable',
        'privacy_policy_unavailable',
        'model_unavailable',
        'request_rejected',
        'unknown_provider_failure',
        'credential_decrypt_failed',
        'output_invalid',
        'dispatch_failed',
        'initiator_membership_lost',
        'research_superseded',
    ],
];
