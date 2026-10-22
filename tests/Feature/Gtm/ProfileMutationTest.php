<?php

use App\Domain\Gtm\Enums\GtmProfileReviewStatus;
use App\Domain\Gtm\Enums\GtmProfileReviewType;
use App\Domain\Gtm\Services\MergeGtmProfileResearch;
use App\Domain\Gtm\Services\ResolveGtmProfileReviewItem;
use App\Domain\Gtm\Services\UpdateGtmProfile;
use App\Domain\Gtm\Support\CanonicalGtmProfilePayload;
use App\Domain\Workspaces\Services\ProvisionWorkspace;
use App\Models\GtmProfileResearchRun;
use App\Models\GtmProfileReviewItem;
use App\Models\GtmProfileVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->owner = User::factory()->withoutWorkspace()->create(['name' => 'GTM Owner']);
    $this->workspace = app(ProvisionWorkspace::class)->create($this->owner, 'Mutation Workspace');
    $this->profile = $this->workspace->gtmProfile()->firstOrFail();
});

it('writes owner changes as immutable versions and treats canonical repeats as no-ops', function () {
    $service = app(UpdateGtmProfile::class);

    $updated = $service->handle(
        $this->profile,
        $this->owner,
        'company',
        ['product_description' => 'A workflow for evidence-backed outbound.'],
        0,
        'profile-op-1',
    );
    $updated->load('currentVersion');

    expect($updated->lock_version)->toBe(1)
        ->and($updated->currentVersion->version)->toBe(2)
        ->and(data_get($updated->currentVersion->payload, 'company.product_description.provenance'))->toBe('owner_supplied')
        ->and(data_get($updated->currentVersion->payload, 'company.product_description.owner_locked'))->toBeTrue();

    $versionCount = GtmProfileVersion::query()->where('gtm_profile_id', $this->profile->id)->count();
    $same = $service->handle(
        $updated,
        $this->owner,
        'company',
        ['product_description' => ['value' => 'A workflow for evidence-backed outbound.']],
        1,
        'profile-op-2',
    );

    expect($same->lock_version)->toBe(1)
        ->and(GtmProfileVersion::query()->where('gtm_profile_id', $this->profile->id)->count())->toBe($versionCount);
});

it('replays a client operation, rejects fingerprint reuse, and rejects stale locks', function () {
    $service = app(UpdateGtmProfile::class);
    $first = $service->handle($this->profile, $this->owner, 'company', ['category' => 'SaaS'], 0, 'replay-op');
    $replay = $service->handle($first, $this->owner, 'company', ['category' => 'SaaS'], 0, 'replay-op');

    expect($replay->current_version_id)->toBe($first->current_version_id)
        ->and(fn () => $service->handle($first, $this->owner, 'company', ['category' => 'Different'], 0, 'replay-op'))
        ->toThrow(RuntimeException::class, 'operation_replay_mismatch')
        ->and(fn () => $service->handle($first, $this->owner, 'company', ['category' => 'Other'], 0, 'new-stale-op'))
        ->toThrow(RuntimeException::class, 'stale_version');
});

it('replays the operation result instead of a later owner write', function () {
    $secondOwner = User::factory()->withoutWorkspace()->create(['name' => 'Second GTM Owner']);
    $this->workspace->memberships()->create([
        'user_id' => $secondOwner->id,
        'role' => 'owner',
    ]);
    $service = app(UpdateGtmProfile::class);

    $ownerAResult = $service->handle(
        $this->profile,
        $this->owner,
        'company',
        ['category' => 'Owner A category'],
        0,
        'lost-response-owner-a',
    );
    $ownerBResult = $service->handle(
        $this->profile->fresh(),
        $secondOwner,
        'company',
        ['category' => 'Owner B category'],
        1,
        'later-owner-b',
    );

    $replay = $service->handle(
        $this->profile->fresh(),
        $this->owner,
        'company',
        ['category' => 'Owner A category'],
        0,
        'lost-response-owner-a',
    );

    expect($replay->lock_version)->toBe(1)
        ->and($replay->current_version_id)->toBe($ownerAResult->current_version_id)
        ->and(data_get($replay->currentVersion->payload, 'company.category.value'))->toBe('Owner A category')
        ->and($this->profile->fresh()->current_version_id)->toBe($ownerBResult->current_version_id)
        ->and(fn () => $service->handle(
            $replay,
            $this->owner,
            'company',
            ['name' => 'Queued after the replay'],
            (int) $replay->lock_version,
            'owner-a-next-edit',
        ))->toThrow(RuntimeException::class, 'stale_version');
});

it('allows only the owner to mutate a profile', function () {
    $member = User::factory()->withoutWorkspace()->create();
    $this->workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);

    expect(fn () => app(UpdateGtmProfile::class)->handle(
        $this->profile,
        $member,
        'company',
        ['category' => 'Nope'],
        0,
        'member-op',
    ))->toThrow(AuthorizationException::class);
});

it('keeps omitted repeated items as locked tombstones and hides them from reads', function () {
    $service = app(UpdateGtmProfile::class);

    $withItems = $service->handle(
        $this->profile,
        $this->owner,
        'market',
        ['geographies' => ['North America', 'Europe']],
        0,
        'profile-repeat-add',
    );
    $withItems->load('currentVersion');

    $withoutEurope = $service->handle(
        $withItems,
        $this->owner,
        'market',
        ['geographies' => ['North America']],
        1,
        'profile-repeat-remove',
    );
    $withoutEurope->load('currentVersion');

    $items = data_get($withoutEurope->currentVersion->payload, 'market.geographies');
    expect($items)->toHaveCount(2)
        ->and(collect($items)->firstWhere('value', 'Europe')['tombstoned'])->toBeTrue();

    $visible = CanonicalGtmProfilePayload::visible($withoutEurope->currentVersion->payload);
    expect($visible['market']['geographies'])->toHaveCount(1)
        ->and($visible['market']['geographies'][0]['value'])->toBe('North America');

    // A later research result with the removed value must not resurrect it.
    $run = GtmProfileResearchRun::factory()->create([
        'gtm_profile_id' => $this->profile->id,
        'state' => 'queued',
        'website_url_snapshot' => null,
        'initiated_by_user_id' => $this->owner->id,
    ]);
    $this->profile->forceFill(['current_research_run_id' => $run->id])->save();

    $merged = app(MergeGtmProfileResearch::class)->handle(
        $withoutEurope,
        $run,
        ['market' => ['geographies' => ['Europe', 'Asia']]],
    );
    $merged->load('currentVersion');
    $mergedItems = data_get($merged->currentVersion->payload, 'market.geographies');

    expect(collect($mergedItems)->pluck('value')->all())->toContain('North America', 'Asia')
        ->and(collect($mergedItems)->firstWhere('value', 'Europe')['tombstoned'])->toBeTrue()
        ->and(CanonicalGtmProfilePayload::visible($merged->currentVersion->payload)['market']['geographies'])
        ->toHaveCount(2);
});

it('preserves repeated ids through insertions and reorders while tombstoning deletions', function () {
    $firstId = '11111111-1111-4111-8111-111111111111';
    $secondId = '22222222-2222-4222-8222-222222222222';
    $service = app(UpdateGtmProfile::class);
    $seeded = $service->handle(
        $this->profile,
        $this->owner,
        'buyers',
        ['target_roles' => [
            ['id' => $firstId, 'value' => 'Founder'],
            ['id' => $secondId, 'value' => 'Head of Growth'],
        ]],
        0,
        'repeat-identity-seed',
    );

    $updated = $service->handle(
        $seeded,
        $this->owner,
        'buyers',
        ['target_roles' => [
            ['value' => 'Chief Revenue Officer'],
            ['id' => $firstId, 'value' => 'Founder'],
            ['id' => $secondId, 'value' => 'Head of Growth', 'tombstoned' => true],
        ]],
        1,
        'repeat-identity-edit',
    );
    $items = collect(data_get($updated->currentVersion->payload, 'buyers.target_roles'));
    $inserted = $items->firstWhere('value', 'Chief Revenue Officer');
    $founder = $items->firstWhere('value', 'Founder');
    $deleted = $items->firstWhere('value', 'Head of Growth');

    expect($items->pluck('value')->all())->toBe([
        'Chief Revenue Officer',
        'Founder',
        'Head of Growth',
    ])
        ->and($inserted['id'])->not->toBeIn([$firstId, $secondId])
        ->and($founder['id'])->toBe($firstId)
        ->and($deleted['id'])->toBe($secondId)
        ->and($deleted['tombstoned'])->toBeTrue()
        ->and($items->pluck('provenance')->unique()->all())->toBe(['owner_supplied'])
        ->and($items->pluck('owner_locked')->unique()->all())->toBe([true]);
});

it('rejects duplicate repeated item ids before writing a profile version', function () {
    $duplicateId = '11111111-1111-4111-8111-111111111111';
    $service = app(UpdateGtmProfile::class);
    $versionCount = GtmProfileVersion::query()
        ->where('gtm_profile_id', $this->profile->id)
        ->count();

    expect(fn () => $service->handle(
        $this->profile,
        $this->owner,
        'buyers',
        ['target_roles' => [
            ['id' => $duplicateId, 'value' => 'Founder'],
            ['id' => $duplicateId, 'value' => 'Head of Revenue'],
        ]],
        0,
        'duplicate-row-id',
    ))->toThrow(ValidationException::class, 'Repeated item IDs must be unique across the profile.');

    expect($this->profile->fresh()->lock_version)->toBe(0)
        ->and(GtmProfileVersion::query()
            ->where('gtm_profile_id', $this->profile->id)
            ->count())->toBe($versionCount);
});

it('rejects a repeated item id reused by another repeated field', function () {
    $duplicateId = '22222222-2222-4222-8222-222222222222';

    expect(fn () => app(UpdateGtmProfile::class)->handle(
        $this->profile,
        $this->owner,
        'buyers',
        [
            'target_roles' => [['id' => $duplicateId, 'value' => 'Founder']],
            'buying_triggers' => [['id' => $duplicateId, 'value' => 'New funding']],
        ],
        0,
        'duplicate-cross-field-id',
    ))->toThrow(ValidationException::class, 'Repeated item IDs must be unique across the profile.');

    expect($this->profile->fresh()->lock_version)->toBe(0);
});

it('resolves research reviews with accept, keep-owner, and edit-owner semantics', function () {
    $accept = GtmProfileReviewItem::query()->create([
        'gtm_profile_id' => $this->profile->id,
        'field_path' => 'company.product_description',
        'type' => GtmProfileReviewType::Conflict,
        'proposed_value' => ['value' => 'Research description'],
        'source_ids' => [],
        'proposed_value_hash' => hash('sha256', 'research-description'),
        'status' => GtmProfileReviewStatus::Open,
    ]);

    $service = app(ResolveGtmProfileReviewItem::class);
    $profile = $service->handle($accept, $this->owner, 'accept_research', null, 0, 'review-op-1');
    expect($profile->lock_version)->toBe(1)
        ->and($accept->fresh()->status)->toBe(GtmProfileReviewStatus::Resolved)
        ->and(data_get($profile->currentVersion()->firstOrFail()->payload, 'company.product_description.provenance'))->toBe('ai_inferred');

    $keep = GtmProfileReviewItem::query()->create([
        'gtm_profile_id' => $this->profile->id,
        'field_path' => 'company.product_description',
        'type' => GtmProfileReviewType::Conflict,
        'proposed_value' => ['value' => 'Another proposal'],
        'source_ids' => [],
        'proposed_value_hash' => hash('sha256', 'another-proposal'),
        'status' => GtmProfileReviewStatus::Open,
    ]);
    $kept = $service->handle($keep, $this->owner, 'keep_owner', null, 1, 'review-op-2');
    expect($kept->lock_version)->toBe(1)
        ->and($keep->fresh()->status)->toBe(GtmProfileReviewStatus::Resolved);

    $edit = GtmProfileReviewItem::query()->create([
        'gtm_profile_id' => $this->profile->id,
        'field_path' => 'company.category',
        'type' => GtmProfileReviewType::Conflict,
        'proposed_value' => ['value' => 'Research category'],
        'source_ids' => [],
        'proposed_value_hash' => hash('sha256', 'research-category'),
        'status' => GtmProfileReviewStatus::Open,
    ]);
    $edited = $service->handle($edit, $this->owner, 'edit_owner', ['value' => 'Owner category'], 1, 'review-op-3');
    expect($edited->lock_version)->toBe(2)
        ->and(data_get($edited->currentVersion()->firstOrFail()->payload, 'company.category.owner_locked'))->toBeTrue();
});

it('requires a real owner answer for missing reviews and rejects proposal actions', function () {
    $missing = GtmProfileReviewItem::query()->create([
        'gtm_profile_id' => $this->profile->id,
        'field_path' => 'buyers.target_roles',
        'type' => GtmProfileReviewType::Missing,
        'proposed_value' => null,
        'source_ids' => [],
        'proposed_value_hash' => hash('sha256', 'missing-target-roles'),
        'status' => GtmProfileReviewStatus::Open,
    ]);
    $service = app(ResolveGtmProfileReviewItem::class);

    expect(fn () => $service->handle(
        $missing,
        $this->owner,
        'accept_research',
        null,
        0,
        'missing-invalid-accept',
    ))->toThrow(ValidationException::class, 'This action is not available for this review question.')
        ->and(fn () => $service->handle(
            $missing,
            $this->owner,
            'keep_owner',
            null,
            0,
            'missing-invalid-keep',
        ))->toThrow(ValidationException::class, 'This action is not available for this review question.')
        ->and(fn () => $service->handle(
            $missing,
            $this->owner,
            'edit_owner',
            ['value' => '   '],
            0,
            'missing-empty-answer',
        ))->toThrow(ValidationException::class, 'Enter an answer for this question.');

    $answered = $service->handle(
        $missing,
        $this->owner,
        'edit_owner',
        ['value' => 'VP Revenue'],
        0,
        'missing-owner-answer',
    );
    $answered->load('currentVersion');

    expect($answered->lock_version)->toBe(1)
        ->and($missing->fresh()->status)->toBe(GtmProfileReviewStatus::Resolved)
        ->and(data_get($answered->currentVersion->payload, 'buyers.target_roles.0.value'))->toBe('VP Revenue')
        ->and(data_get($answered->currentVersion->payload, 'buyers.target_roles.0.owner_locked'))->toBeTrue();
});

it('replays the exact review result after a later owner edit', function () {
    $missing = GtmProfileReviewItem::query()->create([
        'gtm_profile_id' => $this->profile->id,
        'field_path' => 'company.category',
        'type' => GtmProfileReviewType::Missing,
        'proposed_value' => null,
        'source_ids' => [],
        'proposed_value_hash' => hash('sha256', 'missing-company-category'),
        'status' => GtmProfileReviewStatus::Open,
    ]);
    $reviews = app(ResolveGtmProfileReviewItem::class);
    $original = $reviews->handle(
        $missing,
        $this->owner,
        'edit_owner',
        ['value' => 'Owner answer'],
        0,
        'lost-review-response',
    );
    $later = app(UpdateGtmProfile::class)->handle(
        $this->profile->fresh(),
        $this->owner,
        'company',
        ['name' => 'Later owner edit'],
        1,
        'later-owner-edit',
    );

    $replay = $reviews->handle(
        $missing->fresh(),
        $this->owner,
        'edit_owner',
        ['value' => 'Owner answer'],
        0,
        'lost-review-response',
    );

    expect($replay->lock_version)->toBe(1)
        ->and($replay->current_version_id)->toBe($original->current_version_id)
        ->and(data_get($replay->currentVersion->payload, 'company.category.value'))->toBe('Owner answer')
        ->and(data_get($replay->currentVersion->payload, 'company.name.value'))->not->toBe('Later owner edit')
        ->and($this->profile->fresh()->current_version_id)->toBe($later->current_version_id)
        ->and(fn () => app(UpdateGtmProfile::class)->handle(
            $replay,
            $this->owner,
            'company',
            ['product_description' => 'Queued after replay'],
            1,
            'queued-after-review-replay',
        ))->toThrow(RuntimeException::class, 'stale_version');
});

it('merges repeated review proposals without replacing unrelated values, tombstones, or accepted items', function () {
    $northId = '11111111-1111-4111-8111-111111111111';
    $europeId = '22222222-2222-4222-8222-222222222222';
    $legacyId = '33333333-3333-4333-8333-333333333333';
    $profile = app(UpdateGtmProfile::class)->handle(
        $this->profile,
        $this->owner,
        'market',
        ['geographies' => [
            ['id' => $northId, 'value' => 'North America'],
            ['id' => $europeId, 'value' => 'Europe'],
            ['id' => $legacyId, 'value' => 'Legacy market', 'tombstoned' => true],
        ]],
        0,
        'review-list-seed',
    );
    $service = app(ResolveGtmProfileReviewItem::class);
    $review = static function (string $value) use ($profile): GtmProfileReviewItem {
        return GtmProfileReviewItem::query()->create([
            'gtm_profile_id' => $profile->id,
            'field_path' => 'market.geographies',
            'type' => GtmProfileReviewType::Conflict,
            'proposed_value' => ['value' => $value],
            'source_ids' => [],
            'proposed_value_hash' => hash('sha256', 'geography-'.$value),
            'status' => GtmProfileReviewStatus::Open,
        ]);
    };

    $withAsia = $service->handle(
        $review('Asia'),
        $this->owner,
        'accept_research',
        null,
        1,
        'review-list-asia',
    );
    $withAsia->load('currentVersion');
    $asiaId = collect(data_get($withAsia->currentVersion->payload, 'market.geographies'))
        ->firstWhere('value', 'Asia')['id'];

    $latam = $review('LATAM');
    $middleEast = $review('Middle East');
    $afterLatam = $service->handle(
        $latam,
        $this->owner,
        'accept_research',
        null,
        2,
        'review-list-latam',
    );
    $afterLatam->load('currentVersion');
    $latamId = collect(data_get($afterLatam->currentVersion->payload, 'market.geographies'))
        ->firstWhere('value', 'LATAM')['id'];

    expect($middleEast->fresh()->status)->toBe(GtmProfileReviewStatus::Open);

    $afterCanonicalMatch = $service->handle(
        $review('latam'),
        $this->owner,
        'accept_research',
        null,
        3,
        'review-list-latam-canonical',
    );
    $afterMiddleEast = $service->handle(
        $middleEast,
        $this->owner,
        'accept_research',
        null,
        (int) $afterCanonicalMatch->lock_version,
        'review-list-middle-east',
    );
    $afterMiddleEast->load('currentVersion');
    $items = collect(data_get($afterMiddleEast->currentVersion->payload, 'market.geographies'));

    expect($items->whereIn('value', ['North America', 'Europe', 'Legacy market', 'Asia', 'latam', 'Middle East']))
        ->toHaveCount(6)
        ->and($items->firstWhere('value', 'North America')['id'])->toBe($northId)
        ->and($items->firstWhere('value', 'Europe')['id'])->toBe($europeId)
        ->and($items->firstWhere('value', 'Legacy market')['id'])->toBe($legacyId)
        ->and($items->firstWhere('value', 'Legacy market')['tombstoned'])->toBeTrue()
        ->and($items->firstWhere('value', 'Asia')['id'])->toBe($asiaId)
        ->and($items->firstWhere('value', 'latam')['id'])->toBe($latamId)
        ->and($items->filter(fn (array $item): bool => strtolower((string) $item['value']) === 'latam'))->toHaveCount(1);
});
