<?php

return [
    'queue' => env('OUTBOSS_OBJECTIVES_QUEUE', 'objectives'),
    'phase_lease_seconds' => 600,
    'dispatch_retry_seconds' => 30,
    'criteria' => [
        'maximum_depth' => 4,
        'maximum_nodes' => 50,
        'maximum_leaves' => 50,
    ],
    'safety' => [
        'maximum_people' => 100,
        'maximum_accounts' => 100,
        'maximum_messages' => 100,
        'maximum_ai_cost_micros' => 5_000_000,
        'maximum_horizon_days' => 30,
        'maximum_attribution_window_days' => 90,
        'approval_ttl_hours' => 24,
    ],
    'snapshot_preview_ttl_minutes' => 15,
];
