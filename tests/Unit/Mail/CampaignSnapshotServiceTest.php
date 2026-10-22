<?php

use App\Domain\Mail\Services\CampaignSnapshotService;

it('recreates legacy hashes only while every variation flag is disabled', function () {
    $legacy = [
        'campaign' => [
            'sequence' => [
                'steps' => [[
                    'position' => 1,
                    'subject_template' => 'Legacy {A|B}',
                    'body_template' => 'Hello',
                ]],
            ],
        ],
        'mailbox_topology' => ['mailboxes' => []],
    ];
    $current = $legacy;
    $current['campaign']['sequence']['steps'][0]['subject_spintax_enabled'] = false;
    $current['campaign']['sequence']['steps'][0]['body_spintax_enabled'] = false;
    $snapshots = app(CampaignSnapshotService::class);

    expect($snapshots->legacyHashIfVariationsDisabled($current))->toBe($snapshots->hash($legacy));

    $current['campaign']['sequence']['steps'][0]['body_spintax_enabled'] = true;

    expect($snapshots->legacyHashIfVariationsDisabled($current))->toBeNull();
});
