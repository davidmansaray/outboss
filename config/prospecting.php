<?php

return [
    'schema_version' => 1,
    'limits' => [
        'source_name_max_length' => 160,
        'idempotency_key_max_length' => 160,
        'observations_per_batch' => 5000,
        'observed_values_max_fields' => 40,
        'observed_value_max_length' => 2048,
        'safe_error_max_length' => 500,
        'checkpoint_max_fields' => 20,
    ],
    'system_sources' => [
        'manual' => ['kind' => 'manual', 'name' => 'Manual'],
        'rest_api' => ['kind' => 'api_import', 'name' => 'REST API'],
        'mcp' => ['kind' => 'api_import', 'name' => 'MCP'],
        'legacy_manual' => ['kind' => 'manual', 'name' => 'Legacy manual'],
        'legacy_import' => ['kind' => 'csv_import', 'name' => 'Legacy import'],
        'legacy_campaign_backfill' => ['kind' => 'existing_accounts', 'name' => 'Legacy campaign backfill'],
        'unknown_legacy_source' => ['kind' => 'existing_accounts', 'name' => 'Unknown legacy source'],
    ],
    'field_allowlist' => [
        'account' => ['name', 'website', 'domain_normalized', 'notes', 'tags', 'canonical_account_id', 'legacy_source', 'historical_created_at', 'confirmed_duplicate'],
        'person' => ['full_name', 'first_name', 'email', 'email_address', 'email_normalized', 'job_title', 'notes', 'tags', 'account_id', 'canonical_person_id', 'legacy_source', 'historical_created_at'],
    ],
];
