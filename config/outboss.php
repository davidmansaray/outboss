<?php

return [

    // Release label reported by GET /api/v1/server; "dev" when unset.
    'version' => env('OUTBOSS_VERSION'),

    'campaign_sending_enabled' => (bool) env('CAMPAIGN_SENDING_ENABLED', false),

    'send_rate_per_minute' => (int) env('OUTBOSS_SEND_RATE_PER_MINUTE', 10),

    'tracking_hash_salt' => env('OUTBOSS_TRACKING_HASH_SALT', 'outboss-local-salt'),

    'managed_mail' => [
        'default_instance' => env('STALWART_DEFAULT_INSTANCE', 'internal'),
        // Transport-level aliases are kept for clients that resolve a
        // connection's configured instance after this foundation migration.
        'session_cache_seconds' => (int) env('STALWART_SESSION_CACHE_SECONDS', 60),
        'timeout_seconds' => (int) env('STALWART_INTERNAL_TIMEOUT_SECONDS', 15),
        'verify_tls' => filter_var(env('STALWART_INTERNAL_VERIFY_TLS', true), FILTER_VALIDATE_BOOL),
        // Managed mailbox create/recover/credential persistence is guarded by
        // a database-backed lease. Keep the lease longer than the bounded
        // provider request sequence; a crashed worker then expires safely.
        'provisioning_lock_wait_seconds' => (int) env('STALWART_PROVISIONING_LOCK_WAIT_SECONDS', 30),
        'provisioning_lock_lease_seconds' => (int) env('STALWART_PROVISIONING_LOCK_LEASE_SECONDS', 300),
        // DNS-over-HTTPS is used by production readiness checks so resolver
        // latency is bounded and failures remain an explicit unknown.
        'dns_resolver_url' => env('STALWART_DNS_RESOLVER_URL', 'https://cloudflare-dns.com/dns-query'),
        'dns_timeout_seconds' => (int) env('STALWART_DNS_TIMEOUT_SECONDS', 3),
        // The expected public MX target is an explicit readiness input. It
        // falls back to the instance base host only when no dedicated mail
        // host is configured.
        'reconciliation_consistency_delay_seconds' => (int) env('STALWART_RECONCILIATION_CONSISTENCY_DELAY_SECONDS', 60),
        'reconciliation_retry_seconds' => (int) env('STALWART_RECONCILIATION_RETRY_SECONDS', 30),
        'instances' => [
            'internal' => [
                'base_url' => env('STALWART_INTERNAL_BASE_URL'),
                'mail_host' => env('STALWART_INTERNAL_MAIL_HOST'),
                // Optional override. When omitted, Stalwart's authenticated
                // JMAP session document supplies the current API URL.
                'management_api_url' => env('STALWART_INTERNAL_MANAGEMENT_API_URL'),
                'admin_api_key' => env('STALWART_INTERNAL_ADMIN_API_KEY'),
                'verify_tls' => filter_var(env('STALWART_INTERNAL_VERIFY_TLS', true), FILTER_VALIDATE_BOOL),
                'timeout_seconds' => (int) env('STALWART_INTERNAL_TIMEOUT_SECONDS', 15),
                'outbound_ipv4' => env('STALWART_INTERNAL_OUTBOUND_IPV4'),
                'tls_port' => (int) env('STALWART_INTERNAL_TLS_PORT', 443),
            ],
        ],
    ],

    'sending' => [
        'default_daily_cap' => (int) env('OUTBOSS_DEFAULT_DAILY_CAP', 50),
        'spacing_min_seconds' => (int) env('OUTBOSS_SEND_SPACING_MIN_SECONDS', 45),
        'spacing_max_seconds' => (int) env('OUTBOSS_SEND_SPACING_MAX_SECONDS', 180),
        'domain_hourly_limit' => (int) env('OUTBOSS_DOMAIN_HOURLY_LIMIT', 6),
    ],

    'health' => [
        'window_hours' => (int) env('OUTBOSS_HEALTH_WINDOW_HOURS', 24),
        'hard_bounce_threshold' => (int) env('OUTBOSS_HARD_BOUNCE_THRESHOLD', 3),
        'provider_error_threshold' => (int) env('OUTBOSS_PROVIDER_ERROR_THRESHOLD', 5),
    ],

    'experiments' => [
        'dns_cache_seconds' => (int) env('OUTBOSS_EXPERIMENT_DNS_CACHE_SECONDS', 3600),
        'low_sample_threshold' => (int) env('OUTBOSS_EXPERIMENT_LOW_SAMPLE_THRESHOLD', 30),
        // Fraction of eligible recipients with a provider-reaching first
        // attempt that may end in unknown/manual review before the managed
        // experiment is paused. Keep this conservative by default.
        'unknown_manual_review_rate_threshold' => (float) env('OUTBOSS_EXPERIMENT_UNKNOWN_MANUAL_REVIEW_RATE_THRESHOLD', 0.10),
    ],

    'mobile' => [
        // Exact-match allowlist for the iOS sign-in callback. Anything else is
        // refused before a code is issued.
        'redirect_uris' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('OUTBOSS_MOBILE_REDIRECT_URIS', 'outboss://auth/callback')),
        ))),
        'authorization_code_ttl_seconds' => 60,
        'access_token_ttl_minutes' => 60,
        'refresh_token_ttl_days' => 60,
        // A refresh token reused within this window (a lost response on a bad
        // connection) mints a fresh pair. Later reuse revokes the session.
        'refresh_reuse_grace_seconds' => 30,
        // How far back the inbox changes feed accepts a watermark.
        'changes_retention_days' => 30,
        'changes_overlap_seconds' => 10,
    ],

    'push' => [
        // Push turns on when these values are present and the key is valid.
        // Without them the app still works; /api/v1/me reports push: false.
        'apns' => [
            'key_id' => env('APNS_KEY_ID'),
            'team_id' => env('APNS_TEAM_ID'),
            'bundle_id' => env('APNS_BUNDLE_ID'),
            // Path to the .p8 key, or the key contents in APNS_PRIVATE_KEY.
            'private_key_path' => env('APNS_PRIVATE_KEY_PATH'),
            'private_key' => env('APNS_PRIVATE_KEY'),
            'timeout_seconds' => (int) env('APNS_TIMEOUT_SECONDS', 10),
        ],
        // Replies received longer ago than this (first sync, backfills) never alert.
        'fresh_reply_minutes' => 15,
    ],

    'sync' => [
        'pages_per_job' => (int) env('OUTBOSS_SYNC_PAGES_PER_JOB', 5),
        'zoho' => [
            'lookback_days' => (int) env('OUTBOSS_ZOHO_LOOKBACK_DAYS', 30),
            'page_size' => (int) env('OUTBOSS_ZOHO_PAGE_SIZE', 100),
            'overlap_pages' => (int) env('OUTBOSS_ZOHO_OVERLAP_PAGES', 2),
        ],
    ],

    'zoho_regions' => [
        'us' => [
            'accounts' => 'https://accounts.zoho.com',
            'mail' => 'https://mail.zoho.com',
        ],
        'eu' => [
            'accounts' => 'https://accounts.zoho.eu',
            'mail' => 'https://mail.zoho.eu',
        ],
        'in' => [
            'accounts' => 'https://accounts.zoho.in',
            'mail' => 'https://mail.zoho.in',
        ],
        'au' => [
            'accounts' => 'https://accounts.zoho.com.au',
            'mail' => 'https://mail.zoho.com.au',
        ],
        'jp' => [
            'accounts' => 'https://accounts.zoho.jp',
            'mail' => 'https://mail.zoho.jp',
        ],
        'ca' => [
            'accounts' => 'https://accounts.zohocloud.ca',
            'mail' => 'https://mail.zohocloud.ca',
        ],
        'sa' => [
            'accounts' => 'https://accounts.zoho.sa',
            'mail' => 'https://mail.zoho.sa',
        ],
        'ae' => [
            'accounts' => 'https://accounts.zoho.ae',
            'mail' => 'https://mail.zoho.ae',
        ],
        'cn' => [
            'accounts' => 'https://accounts.zoho.com.cn',
            'mail' => 'https://mail.zoho.com.cn',
        ],
    ],

];
