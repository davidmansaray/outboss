<?php

use App\Domain\Conversions\Services\ActivateConversionDefinition;
use App\Domain\Conversions\Services\CreateConversionDefinition;
use App\Domain\Conversions\Services\IngestConversionEvent;
use App\Domain\Gtm\Enums\OfferStatus;
use App\Domain\Gtm\Services\ChangeOfferStatus;
use App\Domain\Gtm\Services\ConfigureOperationalOffer;
use App\Domain\Gtm\Services\CreateAsset;
use App\Domain\Gtm\Services\CreateFulfilmentCapability;
use App\Domain\Gtm\Services\CreateFulfilmentRequest;
use App\Domain\Gtm\Services\CreateOffer;
use App\Domain\Gtm\Services\EvaluateOfferReadiness;
use App\Domain\Gtm\Services\ReviewOfferVersion;
use App\Models\Account;
use App\Models\Campaign;
use App\Models\ConversionDefinition;
use App\Models\FulfilmentCapacityReservation;
use App\Models\FulfilmentRequest;
use App\Models\Message;
use App\Models\Offer;
use App\Models\OfferReadinessEvaluation;
use App\Models\OfferVersion;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** @return array<string,mixed> */
function s2ConversionDefinitionPayload(array $overrides = []): array
{
    return array_replace([
        'event_key' => 'offer_accepted',
        'display_name' => 'Offer accepted',
        'description' => 'The supplied Account accepted the Offer.',
        'subject_type' => 'Account',
        'allowed_sources' => ['manual', 'api', 'webhook'],
        'property_schema' => [
            'type' => 'object',
            'properties' => [],
            'required' => [],
            'additionalProperties' => false,
        ],
        'allowed_identity_keys' => ['account_id', 'external_identity', 'domain'],
        'dedupe_strategy' => 'source_and_external_id',
        'dedupe_configuration' => [],
        'attribution_window' => ['days' => 30],
        'value_policy' => ['allowed' => false, 'currency' => null],
        'correction_policy' => ['allowed' => true],
        'reversal_policy' => ['allowed' => true],
        'retention_policy' => ['unmatched_identity_days' => 30],
        'export_policy' => ['allowed' => true],
    ], $overrides);
}

function s2ActiveConversionDefinition(User $owner, array $overrides = []): ConversionDefinition
{
    $definition = app(CreateConversionDefinition::class)->handle(
        $owner->currentWorkspace(),
        $owner,
        s2ConversionDefinitionPayload($overrides),
    );

    return app(ActivateConversionDefinition::class)->handle($definition, $owner);
}

/** @return array<string,mixed> */
function s2OperationalOfferPayload(string $conversionDefinitionVersionId, array $fulfilmentOverrides = []): array
{
    return [
        'schema_version' => 2,
        'name' => 'Account intelligence workshop',
        'promise' => 'Turn verified account evidence into a practical next step.',
        'target_audience' => 'Founder-led B2B software companies.',
        'desired_outcome' => 'A reviewed single-account action brief.',
        'call_to_action' => 'Accept the workshop.',
        'notes' => 'S2 keeps all downstream outreach disabled.',
        'proposition' => [
            'problem_or_opportunity' => 'Account research is difficult to verify.',
            'value' => 'A traceable recommendation backed by cited evidence.',
            'proof' => 'Every supported fact retains its source lineage.',
        ],
        'requested_commitment' => 'Book one 30-minute workshop.',
        'cta' => [
            'action' => 'book_workshop',
            'destination_url' => 'https://example.test/workshop',
            'response_route' => 'calendar',
            'required_inputs' => [],
            'acceptance_conversion_definition_version_id' => $conversionDefinitionVersionId,
        ],
        'fulfilment' => array_replace([
            'mode' => 'external_destination',
            'capability_id' => null,
            'asset_version_ids' => [],
            'owner_user_id' => null,
            'response_sla_hours' => null,
            'capacity_units' => 1,
        ], $fulfilmentOverrides),
        'eligibility' => [
            'includes' => ['Supplied B2B software Account'],
            'excludes' => ['Suppressed or policy-restricted Account'],
        ],
        'commercial_authority' => [
            'approved' => true,
            'notes' => 'Approved for the S2 evaluation workspace.',
        ],
        'approved_claims' => ['Workshop outputs are reviewed before use.'],
        'prohibited_claims' => ['Guaranteed pipeline outcome.'],
        'expires_at' => now()->addMonth()->toIso8601String(),
        'agent_parameter_policy' => ['allowed_keys' => []],
    ];
}

function s2DraftOffer(User $owner, string $operationId): Offer
{
    return app(CreateOffer::class)->handle($owner->currentWorkspace(), $owner, [
        'name' => 'Account intelligence workshop',
        'promise' => 'Turn verified account evidence into a practical next step.',
        'target_audience' => 'Founder-led B2B software companies.',
        'desired_outcome' => 'A reviewed single-account action brief.',
        'call_to_action' => 'Accept the workshop.',
        'notes' => 'S2 keeps all downstream outreach disabled.',
    ], $operationId);
}

it('keeps complete V1 Offers compatible without a rollout gate', function () {
    $owner = User::factory()->create();
    $offer = s2DraftOffer($owner, 'legacy-offer-create');

    $active = app(ChangeOfferStatus::class)->handle($offer, $owner, OfferStatus::Active, 0, 'legacy-offer-activate');

    expect($active->status)->toBe(OfferStatus::Active)
        ->and($active->currentVersion->schema_version)->toBe(1);
});

it('requires a current reviewed readiness result before activating an operational Offer', function () {
    $owner = User::factory()->create();
    $definition = s2ActiveConversionDefinition($owner);
    $offer = s2DraftOffer($owner, 'operational-offer-create');
    $payload = s2OperationalOfferPayload($definition->current_version_id);

    $configured = app(ConfigureOperationalOffer::class)->handle($offer, $owner, $payload, 0, 'operational-offer-configure');

    expect(fn () => app(ChangeOfferStatus::class)->handle($configured, $owner, OfferStatus::Active, 1, 'premature-activation'))
        ->toThrow(ValidationException::class);

    $ready = app(EvaluateOfferReadiness::class)->handle($configured);
    expect($ready->state->value)->toBe('ready_for_review')
        ->and($ready->blockers)->toBe([]);

    app(ReviewOfferVersion::class)->handle($configured, $owner, 'submit');
    $approved = app(ReviewOfferVersion::class)->handle($configured, $owner, 'approve');
    $active = app(ChangeOfferStatus::class)->handle($approved, $owner, OfferStatus::Active, 1, 'approved-activation');

    expect($active->status)->toBe(OfferStatus::Active)
        ->and($active->availability_state->value)->toBe('approved')
        ->and($active->currentVersion->schema_version)->toBe(2)
        ->and(OfferReadinessEvaluation::query()->where('offer_id', $offer->id)->count())->toBeGreaterThanOrEqual(4);
});

it('evaluates external, asset, and human fulfilment dependencies', function () {
    $owner = User::factory()->create();
    $definition = s2ActiveConversionDefinition($owner);

    $externalOffer = s2DraftOffer($owner, 'external-create');
    $external = app(ConfigureOperationalOffer::class)->handle(
        $externalOffer,
        $owner,
        s2OperationalOfferPayload($definition->current_version_id),
        0,
        'external-configure',
    );
    expect(app(EvaluateOfferReadiness::class)->handle($external)->state->value)->toBe('ready_for_review');

    $asset = app(CreateAsset::class)->handle($owner->currentWorkspace(), $owner, [
        'title' => 'Account intelligence guide',
        'external_url' => 'https://example.test/account-guide.pdf',
        'media_type' => 'application/pdf',
        'approval_state' => 'approved',
        'availability_state' => 'available',
    ]);
    $assetOffer = s2DraftOffer($owner, 'asset-create');
    $assetPayload = s2OperationalOfferPayload($definition->current_version_id, [
        'mode' => 'existing_asset',
        'asset_version_ids' => [$asset->current_version_id],
    ]);
    $assetConfigured = app(ConfigureOperationalOffer::class)->handle($assetOffer, $owner, $assetPayload, 0, 'asset-configure');
    expect(app(EvaluateOfferReadiness::class)->handle($assetConfigured)->state->value)->toBe('ready_for_review');

    $capability = app(CreateFulfilmentCapability::class)->handle($owner->currentWorkspace(), $owner, [
        'display_name' => 'Owner workshop delivery',
        'mode' => 'human_assisted',
        'status' => 'active',
        'owner_user_id' => $owner->id,
        'response_sla_hours' => 24,
        'capacity_limit' => 2,
        'capacity_window' => 'week',
        'health_state' => 'healthy',
    ]);
    $humanOffer = s2DraftOffer($owner, 'human-create');
    $humanPayload = s2OperationalOfferPayload($definition->current_version_id, [
        'mode' => 'human_assisted',
        'capability_id' => $capability->id,
        'owner_user_id' => $owner->id,
        'response_sla_hours' => 24,
    ]);
    $humanConfigured = app(ConfigureOperationalOffer::class)->handle($humanOffer, $owner, $humanPayload, 0, 'human-configure');

    expect(app(EvaluateOfferReadiness::class)->handle($humanConfigured)->state->value)->toBe('ready_for_review');
});

it('creates one capacity-safe fulfilment request for a duplicated acceptance event without outreach side effects', function () {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $account = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'domain_normalized' => 's2-account.example',
    ]);
    $definition = s2ActiveConversionDefinition($owner);
    $capability = app(CreateFulfilmentCapability::class)->handle($workspace, $owner, [
        'display_name' => 'Owner workshop delivery',
        'mode' => 'human_assisted',
        'status' => 'active',
        'owner_user_id' => $owner->id,
        'response_sla_hours' => 24,
        'capacity_limit' => 1,
        'capacity_window' => 'week',
        'health_state' => 'healthy',
    ]);
    $offer = s2DraftOffer($owner, 'fulfilment-create');
    $configured = app(ConfigureOperationalOffer::class)->handle($offer, $owner, s2OperationalOfferPayload(
        $definition->current_version_id,
        [
            'mode' => 'human_assisted',
            'capability_id' => $capability->id,
            'owner_user_id' => $owner->id,
            'response_sla_hours' => 24,
        ],
    ), 0, 'fulfilment-configure');
    app(ReviewOfferVersion::class)->handle($configured, $owner, 'submit');
    $approved = app(ReviewOfferVersion::class)->handle($configured, $owner, 'approve');
    $active = app(ChangeOfferStatus::class)->handle($approved, $owner, OfferStatus::Active, 1, 'fulfilment-activate');

    $intake = [
        'external_event_id' => 'acceptance-1',
        'properties' => [],
        'identities' => [['type' => 'account_id', 'value' => $account->id]],
    ];
    $first = app(IngestConversionEvent::class)->handle($workspace, $definition, $intake, 'manual', null, $owner);
    $duplicate = app(IngestConversionEvent::class)->handle($workspace, $definition, $intake, 'manual', null, $owner);
    $request = FulfilmentRequest::query()->sole();
    $replayed = app(CreateFulfilmentRequest::class)->handle($active, $duplicate->event);

    expect($first->duplicate)->toBeFalse()
        ->and($duplicate->duplicate)->toBeTrue()
        ->and($request?->id)->toBe($replayed?->id)
        ->and(FulfilmentRequest::query()->count())->toBe(1)
        ->and(FulfilmentCapacityReservation::query()->count())->toBe(1)
        ->and(Campaign::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0)
        ->and(OfferVersion::query()->where('offer_id', $offer->id)->count())->toBe(3);
});
