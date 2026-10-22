<?php

use App\Domain\Api\TokenScope;
use App\Models\Account;
use App\Models\Asset;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\FulfilmentCapability;
use App\Models\FulfilmentRequest;
use App\Models\Message;
use App\Models\Offer;
use App\Models\OutboxEvent;
use App\Models\SendAttempt;
use App\Models\Thread;
use App\Models\User;

it('drives one bounded operational Offer from REST configuration to matched conversion fulfilment', function (): void {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $account = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'REST operational Account',
        'website' => 'https://rest-operational.example',
        'domain_normalized' => 'rest-operational.example',
    ]);
    $token = apiToken($owner, [
        TokenScope::ConversionsRead->value,
        TokenScope::ConversionsWrite->value,
        TokenScope::OffersRead->value,
        TokenScope::OffersWrite->value,
        TokenScope::OffersApprove->value,
    ]);

    $definitionPayload = [
        'event_key' => 'rest_offer_accepted',
        'display_name' => 'REST Offer accepted',
        'description' => 'An Account accepted the operational Offer.',
        'subject_type' => 'Account',
        'allowed_sources' => ['manual'],
        'property_schema' => [
            'type' => 'object',
            'properties' => [],
            'required' => [],
            'additionalProperties' => false,
        ],
        'allowed_identity_keys' => ['account_id'],
        'dedupe_strategy' => 'source_and_external_id',
        'dedupe_configuration' => [],
        'attribution_window' => ['days' => 30],
        'value_policy' => ['allowed' => false, 'currency' => null],
        'correction_policy' => ['allowed' => true],
        'reversal_policy' => ['allowed' => true],
        'retention_policy' => ['unmatched_identity_days' => 30],
        'export_policy' => ['allowed' => true],
    ];
    $definitionResponse = $this->withToken($token)->postJson(
        '/api/v1/conversion-definitions',
        $definitionPayload,
        ['Idempotency-Key' => 'rest-operational-definition-create'],
    )->assertCreated();
    $definitionId = (string) $definitionResponse->json('data.id');
    $definitionVersionId = (string) $definitionResponse->json('data.current_version_id');

    $this->withToken($token)->postJson(
        "/api/v1/conversion-definitions/{$definitionId}/activate",
        ['expected_lock_version' => $definitionResponse->json('data.lock_version')],
        ['Idempotency-Key' => 'rest-operational-definition-activate'],
    )->assertOk()->assertJsonPath('data.status', 'active');

    $assetResponse = $this->withToken($token)->postJson(
        '/api/v1/assets',
        [
            'title' => 'REST operational guide',
            'description' => 'A bounded deliverable kept in the workspace.',
            'external_url' => 'https://rest-operational.example/guide.pdf',
            'media_type' => 'application/pdf',
            'approval_state' => 'approved',
            'availability_state' => 'available',
            'classification' => 'public',
        ],
        ['Idempotency-Key' => 'rest-operational-asset-create'],
    )->assertCreated();
    expect($assetResponse->json('data.current_version.id'))->toBeString();

    $offerResponse = $this->withToken($token)->postJson(
        '/api/v1/offers',
        [
            'name' => 'REST operational workshop',
            'promise' => 'Turn verified Account evidence into a practical next step.',
            'target_audience' => 'Founder-led B2B software companies.',
            'desired_outcome' => 'A reviewed single-Account action brief.',
            'call_to_action' => 'Accept the workshop.',
            'notes' => 'REST S2 keeps outreach disabled.',
            'client_operation_id' => 'rest-operational-offer-create',
        ],
        ['Idempotency-Key' => 'rest-operational-offer-create-request'],
    )->assertCreated();
    $offerId = (string) $offerResponse->json('data.id');

    $operationalPayload = static function (?string $capabilityId) use ($definitionVersionId): array {
        return [
            'schema_version' => 2,
            'name' => 'REST operational workshop',
            'promise' => 'Turn verified Account evidence into a practical next step.',
            'target_audience' => 'Founder-led B2B software companies.',
            'desired_outcome' => 'A reviewed single-Account action brief.',
            'call_to_action' => 'Accept the workshop.',
            'notes' => 'REST S2 keeps outreach disabled.',
            'proposition' => [
                'problem_or_opportunity' => 'Account research is difficult to verify.',
                'value' => 'A traceable recommendation backed by cited evidence.',
                'proof' => 'Every supported fact retains its source lineage.',
            ],
            'requested_commitment' => 'Book one 30-minute workshop.',
            'cta' => [
                'action' => 'book_workshop',
                'destination_url' => 'https://rest-operational.example/workshop',
                'response_route' => 'calendar',
                'required_inputs' => [],
                'acceptance_conversion_definition_version_id' => $definitionVersionId,
            ],
            'fulfilment' => [
                'mode' => 'human_assisted',
                'capability_id' => $capabilityId,
                'asset_version_ids' => [],
                'owner_user_id' => null,
                'response_sla_hours' => null,
                'capacity_units' => 1,
            ],
            'eligibility' => [
                'includes' => ['Supplied B2B software Account'],
                'excludes' => ['Suppressed or policy-restricted Account'],
            ],
            'commercial_authority' => [
                'approved' => true,
                'notes' => 'Approved for the REST S2 evaluation workspace.',
            ],
            'approved_claims' => ['Workshop outputs are reviewed before use.'],
            'prohibited_claims' => ['Guaranteed pipeline outcome.'],
            'expires_at' => now()->addMonth()->toIso8601String(),
            'agent_parameter_policy' => ['allowed_keys' => []],
        ];
    };

    $missingCapabilityResponse = $this->withToken($token)->patchJson(
        "/api/v1/offers/{$offerId}/configure",
        [
            'payload' => $operationalPayload(null),
            'expected_lock_version' => $offerResponse->json('data.lock_version'),
            'client_operation_id' => 'rest-operational-configure-missing-capability',
        ],
        ['Idempotency-Key' => 'rest-operational-configure-missing-capability-request'],
    )->assertOk();

    $this->withToken($token)->getJson("/api/v1/offers/{$offerId}/readiness")
        ->assertSuccessful()
        ->assertJsonPath('data.state', 'incomplete')
        ->assertJsonPath('data.blockers.0.code', 'fulfilment_capability_unavailable');

    $capabilityResponse = $this->withToken($token)->postJson(
        '/api/v1/fulfilment-capabilities',
        [
            'display_name' => 'REST owner workshop delivery',
            'mode' => 'human_assisted',
            'status' => 'active',
            'owner_user_id' => $owner->id,
            'response_sla_hours' => 24,
            'capacity_limit' => 1,
            'capacity_window' => 'week',
            'health_state' => 'healthy',
        ],
        ['Idempotency-Key' => 'rest-operational-capability-create'],
    )->assertCreated();
    $capabilityId = (string) $capabilityResponse->json('data.id');
    expect($capabilityResponse->json('data.health_state'))->toBe('healthy');

    $healthyConfiguration = $this->withToken($token)->patchJson(
        "/api/v1/offers/{$offerId}/configure",
        [
            'payload' => $operationalPayload($capabilityId),
            'expected_lock_version' => $missingCapabilityResponse->json('data.lock_version'),
            'client_operation_id' => 'rest-operational-configure-healthy-capability',
        ],
        ['Idempotency-Key' => 'rest-operational-configure-healthy-capability-request'],
    )->assertOk();

    $this->withToken($token)->getJson("/api/v1/offers/{$offerId}/readiness")
        ->assertSuccessful()
        ->assertJsonPath('data.state', 'ready_for_review')
        ->assertJsonPath('data.blockers', []);

    $this->withToken($token)->postJson(
        "/api/v1/offers/{$offerId}/reviews",
        ['action' => 'submit', 'reason' => 'The operational contract is ready for owner review.'],
        ['Idempotency-Key' => 'rest-operational-offer-submit'],
    )->assertOk()->assertJsonPath('data.readiness.state', 'ready_for_review');

    $this->withToken($token)->postJson(
        "/api/v1/offers/{$offerId}/reviews",
        ['action' => 'approve', 'reason' => 'Approved for the controlled REST flow.'],
        ['Idempotency-Key' => 'rest-operational-offer-approve'],
    )->assertOk()->assertJsonPath('data.readiness.state', 'approved');

    $activeOfferResponse = $this->withToken($token)->postJson(
        "/api/v1/offers/{$offerId}/activate",
        [
            'status' => 'active',
            'expected_lock_version' => $healthyConfiguration->json('data.lock_version'),
            'client_operation_id' => 'rest-operational-offer-activate',
        ],
        ['Idempotency-Key' => 'rest-operational-offer-activate-request'],
    )->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.availability_state', 'approved');
    expect($activeOfferResponse->json('data.current_version.schema_version'))->toBe(2);

    $eventPayload = [
        'definition_id' => $definitionId,
        'source' => 'manual',
        'external_event_id' => 'rest-operational-acceptance-1',
        'properties' => [],
        'identities' => [[
            'type' => 'account_id',
            'value' => (string) $account->id,
        ]],
    ];
    $firstEventResponse = $this->withToken($token)->postJson(
        '/api/v1/conversion-events',
        $eventPayload,
        ['Idempotency-Key' => 'rest-operational-event-first'],
    )->assertCreated()
        ->assertJsonPath('data.duplicate', false)
        ->assertJsonPath('data.event.state', 'matched')
        ->assertJsonPath('data.event.matched_account_id', $account->id);
    $firstEventId = (string) $firstEventResponse->json('data.event.id');

    $this->withToken($token)->postJson(
        '/api/v1/conversion-events',
        $eventPayload,
        ['Idempotency-Key' => 'rest-operational-event-duplicate'],
    )->assertOk()
        ->assertJsonPath('data.duplicate', true)
        ->assertJsonPath('data.event.id', $firstEventId);

    $fulfilmentResponse = $this->withToken($token)->getJson('/api/v1/fulfilment-requests')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.offer_id', $offerId)
        ->assertJsonPath('data.0.conversion_event_id', $firstEventId)
        ->assertJsonPath('data.0.account_id', $account->id)
        ->assertJsonPath('data.0.fulfilment_capability_id', $capabilityId)
        ->assertJsonPath('data.0.state', 'pending');
    expect($fulfilmentResponse->json('data'))->toHaveCount(1)
        ->and(FulfilmentRequest::query()->where('workspace_id', $workspace->id)->count())->toBe(1)
        ->and(Campaign::query()->where('workspace_id', $workspace->id)->count())->toBe(0)
        ->and(CampaignRecipient::query()->where('workspace_id', $workspace->id)->count())->toBe(0)
        ->and(Message::query()->where('workspace_id', $workspace->id)->count())->toBe(0)
        ->and(Thread::query()->where('workspace_id', $workspace->id)->count())->toBe(0)
        ->and(SendAttempt::query()->where('workspace_id', $workspace->id)->count())->toBe(0)
        ->and(OutboxEvent::query()->count())->toBe(0);
});

it('keeps operational REST writes least-privilege and workspace-scoped', function (): void {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $offer = Offer::factory()->create(['workspace_id' => $workspace->id]);
    $foreignOwner = User::factory()->create();
    $foreignOffer = Offer::factory()->create(['workspace_id' => $foreignOwner->currentWorkspace()->id]);
    $readToken = apiToken($owner, [TokenScope::OffersRead->value]);
    $writeToken = apiToken($owner, [TokenScope::OffersWrite->value]);

    $this->withToken($readToken)->getJson("/api/v1/offers/{$offer->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $offer->id);

    $this->withToken($readToken)->postJson(
        '/api/v1/offers',
        ['name' => 'Should be denied', 'client_operation_id' => 'rest-read-only-create'],
        ['Idempotency-Key' => 'rest-read-only-create-request'],
    )->assertForbidden()->assertJsonPath('error.type', 'insufficient_scope');

    $this->withToken($readToken)->getJson("/api/v1/offers/{$foreignOffer->id}")
        ->assertNotFound();

    $this->withToken($writeToken)->postJson(
        "/api/v1/offers/{$offer->id}/reviews",
        ['action' => 'approve'],
        ['Idempotency-Key' => 'write-scope-cannot-approve'],
    )->assertForbidden()->assertJsonPath('error.type', 'insufficient_scope');
});

it('makes session Asset and capability creation retry safe', function (): void {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $assetPayload = [
        'title' => 'Retry-safe guide',
        'external_url' => 'https://example.com/retry-safe.pdf',
        'media_type' => 'application/pdf',
    ];

    $firstAsset = $this->actingAs($owner)->postJson(
        '/s2/assets',
        $assetPayload,
        ['Idempotency-Key' => 'asset-retry-safe'],
    )->assertCreated()->json('data.id');
    $secondAsset = $this->actingAs($owner)->postJson(
        '/s2/assets',
        $assetPayload,
        ['Idempotency-Key' => 'asset-retry-safe'],
    )->assertCreated()->json('data.id');

    expect($secondAsset)->toBe($firstAsset)
        ->and(Asset::query()->where('workspace_id', $workspace->id)->count())->toBe(1);

    $capabilityPayload = [
        'display_name' => 'Retry-safe delivery',
        'mode' => 'human_assisted',
        'owner_user_id' => $owner->id,
    ];
    $firstCapability = $this->actingAs($owner)->postJson(
        '/s2/fulfilment-capabilities',
        $capabilityPayload,
        ['Idempotency-Key' => 'capability-retry-safe'],
    )->assertCreated()->json('data.id');
    $secondCapability = $this->actingAs($owner)->postJson(
        '/s2/fulfilment-capabilities',
        $capabilityPayload,
        ['Idempotency-Key' => 'capability-retry-safe'],
    )->assertCreated()->json('data.id');

    expect($secondCapability)->toBe($firstCapability)
        ->and(FulfilmentCapability::query()->where('workspace_id', $workspace->id)->count())->toBe(1);

    $this->actingAs($owner)->postJson(
        '/s2/assets',
        [...$assetPayload, 'title' => 'Changed retry'],
        ['Idempotency-Key' => 'asset-retry-safe'],
    )->assertConflict()->assertJsonPath('error.code', 'operation_replay_mismatch');
});
