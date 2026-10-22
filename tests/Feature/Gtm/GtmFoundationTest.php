<?php

use App\Domain\Gtm\DTOs\GtmProfileVersionReference;
use App\Domain\Gtm\DTOs\OfferVersionReference;
use App\Domain\Gtm\Exceptions\GtmPayloadValidationException;
use App\Domain\Gtm\Services\ResolveCurrentGtmProfileVersion;
use App\Domain\Gtm\Services\ResolveCurrentOfferVersion;
use App\Domain\Gtm\Support\CanonicalGtmProfilePayload;
use App\Domain\Gtm\Support\CanonicalOfferPayload;
use App\Domain\Gtm\Support\GtmProfilePayloadValidator;
use App\Domain\Gtm\Support\OfferPayloadValidator;
use App\Models\GtmProfile;
use App\Models\GtmProfileResearchAttempt;
use App\Models\GtmProfileResearchDispatch;
use App\Models\GtmProfileResearchPhase;
use App\Models\GtmProfileResearchRun;
use App\Models\GtmProfileVersion;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

it('creates the complete AF-02 GTM schema with aggregate ownership pointers', function () {
    foreach ([
        'gtm_profiles',
        'gtm_profile_versions',
        'gtm_profile_sources',
        'gtm_profile_review_items',
        'offers',
        'offer_versions',
        'workspace_client_operations',
        'gtm_profile_client_operations',
        'offer_client_operations',
        'gtm_profile_research_runs',
        'gtm_profile_research_phases',
        'gtm_profile_research_attempts',
        'gtm_profile_research_dispatches',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }

    expect(Schema::hasColumns('gtm_profiles', [
        'id',
        'workspace_id',
        'current_version_id',
        'current_research_run_id',
        'research_state',
        'lock_version',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('gtm_profile_versions', [
            'id',
            'gtm_profile_id',
            'schema_version',
            'payload',
            'content_hash',
            'actor_type',
            'origin',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('offers', [
            'id',
            'workspace_id',
            'current_version_id',
            'status',
            'lock_version',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('gtm_profile_research_runs', [
            'id',
            'gtm_profile_id',
            'budget_run_id',
            'parent_run_id',
            'website_url_snapshot',
            'base_profile_version_id',
            'input_hash',
        ]))->toBeTrue();
});

it('canonicalizes profile payloads deterministically and enforces the fixed contract', function () {
    $payload = CanonicalGtmProfilePayload::empty();
    $payload['company']['name']['value'] = "  Acme\r\nLabs  ";
    $payload['company']['name']['provenance'] = 'owner_supplied';
    $payload['company']['name']['owner_locked'] = true;
    $payload['company']['name']['updated_at'] = '2026-08-31T12:00:00+00:00';

    $validator = new GtmProfilePayloadValidator;
    $validated = $validator->validate($payload);

    expect($validated['company']['name']['value'])->toBe("Acme\nLabs")
        ->and(CanonicalGtmProfilePayload::hash($payload))->toBe(
            CanonicalGtmProfilePayload::hash(array_reverse($payload)),
        );

    $invalid = $payload;
    $invalid['unknown'] = true;

    expect(fn () => $validator->validate($invalid))
        ->toThrow(ValidationException::class);

    $invalid = $payload;
    $invalid['company']['name']['provenance'] = 'model_made_it_up';

    expect(fn () => $validator->validate($invalid))
        ->toThrow(ValidationException::class);
});

it('canonicalizes and validates Offer payloads, including status-sensitive hashes', function () {
    $payload = [
        'name' => '  Research motion ',
        'promise' => ' Evidence-backed outbound ',
        'target_audience' => ' Founder-led B2B teams ',
        'desired_outcome' => ' Qualified conversations ',
        'call_to_action' => ' Review a shortlist ',
        'notes' => '',
    ];
    $validated = (new OfferPayloadValidator)->validate($payload);

    expect($validated['name'])->toBe('Research motion')
        ->and(CanonicalOfferPayload::hash($payload, 'draft'))
        ->not->toBe(CanonicalOfferPayload::hash($payload, 'active'));

    $payload['unexpected'] = 'nope';
    expect(fn () => (new OfferPayloadValidator)->validate($payload))
        ->toThrow(GtmPayloadValidationException::class);
});

it('resolves only the current version belonging to each aggregate', function () {
    $workspace = Workspace::factory()->create();
    $profile = $workspace->gtmProfile()->firstOrFail();
    $profileVersion = $profile->currentVersion()->firstOrFail();

    $offer = Offer::factory()->create(['workspace_id' => $workspace->id]);
    $offerVersion = OfferVersion::factory()->create(['offer_id' => $offer->id]);
    $offer->forceFill(['current_version_id' => $offerVersion->id])->save();

    $profileReference = (new ResolveCurrentGtmProfileVersion)->resolve($profile->fresh());
    $offerReference = (new ResolveCurrentOfferVersion)->resolve($offer->fresh());

    expect($profileReference)->toBeInstanceOf(GtmProfileVersionReference::class)
        ->and($profileReference->versionId)->toBe($profileVersion->id)
        ->and($profileReference->profileId)->toBe($profile->id)
        ->and($offerReference)->toBeInstanceOf(OfferVersionReference::class)
        ->and($offerReference->versionId)->toBe($offerVersion->id)
        ->and($offerReference->offerId)->toBe($offer->id);

    $profile->forceFill(['current_version_id' => null])->save();
    expect(fn () => (new ResolveCurrentGtmProfileVersion)->resolve($profile->fresh()))
        ->toThrow(ModelNotFoundException::class);
});

it('keeps version rows immutable through the model API', function () {
    $profileVersion = GtmProfileVersion::factory()->create();
    expect(fn () => $profileVersion->update(['version' => 2]))
        ->toThrow(LogicException::class);
    expect(fn () => $profileVersion->delete())
        ->toThrow(LogicException::class);

    $offerVersion = OfferVersion::factory()->create();
    expect(fn () => $offerVersion->update(['version' => 2]))
        ->toThrow(LogicException::class);
});

it('enforces same-aggregate research pointers at the database boundary', function () {
    $firstProfile = GtmProfile::factory()->create();
    $secondProfile = GtmProfile::factory()->create();
    $runAttributes = static fn (string $profileId): array => [
        'gtm_profile_id' => $profileId,
        'state' => 'queued',
        'awaiting_reason' => null,
        'budget_run_id' => null,
        'parent_run_id' => null,
        'lineage_type' => null,
        'website_url_snapshot' => null,
        'base_profile_version_id' => null,
        'input_hash' => hash('sha256', $profileId),
        'initiated_by_user_id' => null,
        'safe_failure_code' => null,
        'queued_at' => now(),
    ];
    $firstRun = GtmProfileResearchRun::query()->create($runAttributes($firstProfile->id));
    $secondRun = GtmProfileResearchRun::query()->create($runAttributes($secondProfile->id));

    expect(fn () => GtmProfileResearchRun::query()->create(array_replace($runAttributes($firstProfile->id), [
        'parent_run_id' => $secondRun->id,
    ])))->toThrow(QueryException::class);

    $firstPhase = GtmProfileResearchPhase::query()->create([
        'research_run_id' => $firstRun->id,
        'phase' => 'extract',
        'status' => 'pending',
    ]);
    $secondPhase = GtmProfileResearchPhase::query()->create([
        'research_run_id' => $secondRun->id,
        'phase' => 'extract',
        'status' => 'pending',
    ]);

    expect(fn () => GtmProfileResearchAttempt::query()->create([
        'phase_id' => $secondPhase->id,
        'research_run_id' => $firstRun->id,
        'attempt_number' => 1,
        'deterministic_idempotency_key' => (string) Str::uuid(),
        'provider_operation_id' => null,
        'input_hash' => hash('sha256', 'cross-phase'),
        'status' => 'planned',
    ]))->toThrow(QueryException::class);

    expect(fn () => GtmProfileResearchDispatch::query()->create([
        'gtm_profile_id' => $secondProfile->id,
        'research_run_id' => $firstRun->id,
        'status' => 'pending',
        'available_at' => now(),
        'publish_attempts' => 0,
    ]))->toThrow(QueryException::class);
});

it('seeds a usable current version in standalone profile and Offer factories', function () {
    $profile = GtmProfile::factory()->create();
    $offer = Offer::factory()->create();

    expect($profile->fresh()->current_version_id)->not->toBeNull()
        ->and($profile->versions()->count())->toBe(1)
        ->and($offer->fresh()->current_version_id)->not->toBeNull()
        ->and($offer->versions()->count())->toBe(1);
});
