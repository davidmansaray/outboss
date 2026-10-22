<?php

return [
    'avatars' => [
        'queue' => env('WORKSPACE_AVATAR_QUEUE', 'low'),
        'connect_timeout_seconds' => 3,
        'timeout_seconds' => 8,
        'max_redirects' => 3,
        'max_html_bytes' => 262_144,
        'max_image_bytes' => 524_288,
        'max_candidates' => 8,
        'missing_retry_days' => (int) env('WORKSPACE_AVATAR_MISSING_RETRY_DAYS', 7),
        'refresh_after_days' => (int) env('WORKSPACE_AVATAR_REFRESH_AFTER_DAYS', 7),
        'user_agent' => 'OutBoss Workspace Avatar Fetcher/1.0',
    ],
];
