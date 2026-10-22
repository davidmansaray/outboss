<?php

use App\Domain\Gtm\Enums\OfferStatus;
use App\Domain\Gtm\Enums\OfferVersionOrigin;
use App\Domain\Gtm\Services\ChangeOfferStatus;
use App\Domain\Gtm\Services\CreateOffer;
use App\Domain\Gtm\Services\DeleteOffer;
use App\Domain\Gtm\Services\UpdateOffer;
use App\Models\Offer;
use App\Models\OfferClientOperation;
use App\Models\OfferVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function offerLifecyclePayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'Evidence-backed outbound motion',
        'promise' => 'Build a repeatable evidence-backed outbound motion.',
        'target_audience' => 'Founder-led B2B software teams.',
        'desired_outcome' => 'Qualified conversations with likely buyers.',
        'call_to_action' => 'Review a researched account shortlist.',
        'notes' => 'Keep the first cohort narrow.',
    ], $overrides);
}

/** @param  array<string, string>|null  $overrides */
function makeLifecycleOffer(User $owner, ?array $overrides = [], ?string $operationId = null): Offer
{
    return app(CreateOffer::class)->handle(
        $owner->currentWorkspace(),
        $owner,
        offerLifecyclePayload($overrides ?? []),
        $operationId ?? fake()->uuid(),
    );
}

it('creates one canonical draft version and durably replays create operations', function () {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $payload = offerLifecyclePayload([
        'name' => "  Evidence-backed\r\noutbound motion  ",
        'notes' => "  First line\r\nSecond line  ",
    ]);

    $offer = app(CreateOffer::class)->handle($workspace, $owner, $payload, 'create-offer-1');
    $version = $offer->currentVersion;

    expect($offer->status)->toBe(OfferStatus::Draft)
        ->and($offer->lock_version)->toBe(0)
        ->and($version)->not->toBeNull()
        ->and($version->version)->toBe(1)
        ->and($version->origin)->toBe(OfferVersionOrigin::Created)
        ->and($version->status_snapshot)->toBe(OfferStatus::Draft)
        ->and($version->payload['name'])->toBe("Evidence-backed\noutbound motion")
        ->and($version->payload['notes'])->toBe("First line\nSecond line")
        ->and(OfferVersion::query()->where('offer_id', $offer->id)->count())->toBe(1)
        ->and(OfferClientOperation::query()->where('offer_id', $offer->id)->count())->toBe(1);

    $replayed = app(CreateOffer::class)->handle($workspace, $owner, $payload, 'create-offer-1');

    expect($replayed->id)->toBe($offer->id)
        ->and($replayed->current_version_id)->toBe($offer->current_version_id)
        ->and(Offer::query()->where('workspace_id', $workspace->id)->count())->toBe(1)
        ->and(OfferVersion::query()->where('offer_id', $offer->id)->count())->toBe(1)
        ->and(fn () => app(CreateOffer::class)->handle(
            $workspace,
            $owner,
            offerLifecyclePayload(['promise' => 'A different promise.']),
            'create-offer-1',
        ))->toThrow(RuntimeException::class, 'operation_replay_mismatch');
});

it('writes immutable content versions, handles canonical no-ops, and rejects stale locks', function () {
    $owner = User::factory()->create();
    $offer = makeLifecycleOffer($owner, operationId: 'create-for-update');
    $updatedPayload = offerLifecyclePayload([
        'promise' => 'A sharper promise for a narrower audience.',
    ]);

    $updated = app(UpdateOffer::class)->handle($offer, $owner, $updatedPayload, 0, 'update-offer-1');
    $updated->load('currentVersion');

    expect($updated->lock_version)->toBe(1)
        ->and($updated->currentVersion->version)->toBe(2)
        ->and($updated->currentVersion->origin)->toBe(OfferVersionOrigin::OwnerEdit)
        ->and($updated->currentVersion->status_snapshot)->toBe(OfferStatus::Draft)
        ->and($updated->currentVersion->payload['promise'])->toBe($updatedPayload['promise']);

    $versionCount = OfferVersion::query()->where('offer_id', $offer->id)->count();
    $same = app(UpdateOffer::class)->handle(
        $updated,
        $owner,
        offerLifecyclePayload([
            'promise' => "  A sharper promise for a narrower audience.\r\n",
        ]),
        1,
        'update-offer-no-op',
    );
    $metadata = OfferClientOperation::query()
        ->where('offer_id', $offer->id)
        ->where('client_operation_id', 'update-offer-no-op')
        ->firstOrFail()
        ->getAttribute('response_metadata');
    $noOp = is_array($metadata) ? ($metadata['no_op'] ?? null) : null;

    expect($same->lock_version)->toBe(1)
        ->and($same->current_version_id)->toBe($updated->current_version_id)
        ->and(OfferVersion::query()->where('offer_id', $offer->id)->count())->toBe($versionCount)
        ->and($noOp)->toBeTrue()
        ->and(fn () => app(UpdateOffer::class)->handle(
            $updated,
            $owner,
            offerLifecyclePayload(['notes' => 'A concurrent edit.']),
            0,
            'update-offer-stale',
        ))->toThrow(RuntimeException::class, 'stale_version')
        ->and(fn () => app(UpdateOffer::class)->handle(
            $updated,
            $owner,
            offerLifecyclePayload(['promise' => 'Another promise.']),
            1,
            'update-offer-1',
        ))->toThrow(RuntimeException::class, 'operation_replay_mismatch');
});

it('replays the exact update result after a later update has won', function () {
    $owner = User::factory()->create();
    $offer = makeLifecycleOffer($owner, operationId: 'interleaved-create');
    $firstPayload = offerLifecyclePayload(['notes' => 'Result from operation A.']);
    $secondPayload = offerLifecyclePayload(['notes' => 'Later result from operation B.']);

    $first = app(UpdateOffer::class)->handle($offer, $owner, $firstPayload, 0, 'interleaved-a');
    $second = app(UpdateOffer::class)->handle($first, $owner, $secondPayload, 1, 'interleaved-b');
    $replayed = app(UpdateOffer::class)->handle($offer, $owner, $firstPayload, 0, 'interleaved-a');
    $latest = Offer::query()->with('currentVersion')->whereKey($offer->id)->firstOrFail();

    expect($second->lock_version)->toBe(2)
        ->and($latest->currentVersion?->payload['notes'])->toBe('Later result from operation B.')
        ->and($replayed->lock_version)->toBe(1)
        ->and($replayed->current_version_id)->toBe($first->current_version_id)
        ->and($replayed->currentVersion?->payload['notes'])->toBe('Result from operation A.')
        ->and($replayed->current_version_id)->not->toBe($latest->current_version_id)
        ->and(OfferVersion::query()->where('offer_id', $offer->id)->count())->toBe(3);
});

it('enforces owner identity and prevents cross-workspace service mutation', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    $offer = makeLifecycleOffer($owner, operationId: 'owner-only-create');

    expect(fn () => app(UpdateOffer::class)->handle(
        $offer,
        $otherOwner,
        offerLifecyclePayload(['notes' => 'Should be rejected.']),
        0,
        'other-owner-update',
    ))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteOffer::class)->handle($offer, $otherOwner))
        ->toThrow(AuthorizationException::class);

    $member = User::factory()->create();
    expect(fn () => app(ChangeOfferStatus::class)->handle(
        $offer,
        $member,
        OfferStatus::Active,
        0,
        'member-status',
    ))->toThrow(AuthorizationException::class);
});

it('requires complete content, rejects duplicate active names, and enforces every legal transition', function () {
    $owner = User::factory()->create();
    $first = makeLifecycleOffer($owner, operationId: 'first-active-create');
    $second = makeLifecycleOffer($owner, ['name' => 'Different draft'], 'second-active-create');

    $first = app(ChangeOfferStatus::class)->handle(
        $first,
        $owner,
        OfferStatus::Active,
        0,
        'first-active',
    );

    expect($first->status)->toBe(OfferStatus::Active);
    expect($first->active_name_normalized)->toBe('evidence-backed outbound motion');

    $activeEdit = makeLifecycleOffer($owner, ['name' => 'Another active offer'], 'active-edit-create');
    $activeEdit = app(ChangeOfferStatus::class)->handle(
        $activeEdit,
        $owner,
        OfferStatus::Active,
        0,
        'active-edit-activate',
    );

    expect(fn () => app(UpdateOffer::class)->handle(
        $activeEdit,
        $owner,
        offerLifecyclePayload(['name' => 'Evidence-backed outbound motion']),
        1,
        'active-edit-duplicate',
    ))->toThrow(ValidationException::class);

    $duplicate = app(UpdateOffer::class)->handle(
        $second,
        $owner,
        offerLifecyclePayload(['name' => ' evidence-backed outbound motion ']),
        0,
        'second-name-update',
    );

    expect(fn () => app(ChangeOfferStatus::class)->handle(
        $duplicate,
        $owner,
        OfferStatus::Active,
        1,
        'second-active',
    ))->toThrow(ValidationException::class)
        ->and(fn () => app(ChangeOfferStatus::class)->handle(
            $second,
            $owner,
            OfferStatus::Active,
            0,
            'second-invalid-lock',
        ))->toThrow(RuntimeException::class, 'stale_version');

    $offer = makeLifecycleOffer($owner, ['name' => 'Lifecycle offer'], 'lifecycle-create');
    $directArchive = makeLifecycleOffer($owner, ['name' => 'Direct archive offer'], 'direct-archive-create');
    $directActive = app(ChangeOfferStatus::class)->handle($directArchive, $owner, OfferStatus::Active, 0, 'direct-archive-active');

    expect(fn () => app(ChangeOfferStatus::class)->handle(
        $directActive,
        $owner,
        OfferStatus::Archived,
        1,
        'active-direct-archive',
    ))->toThrow(ValidationException::class);

    $active = app(ChangeOfferStatus::class)->handle($offer, $owner, OfferStatus::Active, 0, 'lifecycle-active');
    $paused = app(ChangeOfferStatus::class)->handle($active, $owner, OfferStatus::Paused, 1, 'lifecycle-paused');
    $archived = app(ChangeOfferStatus::class)->handle($paused, $owner, OfferStatus::Archived, 2, 'lifecycle-archived');
    $restored = app(ChangeOfferStatus::class)->handle($archived, $owner, OfferStatus::Paused, 3, 'lifecycle-restored');

    expect($active->status)->toBe(OfferStatus::Active)
        ->and($paused->status)->toBe(OfferStatus::Paused)
        ->and($paused->active_name_normalized)->toBeNull()
        ->and($archived->status)->toBe(OfferStatus::Archived)
        ->and($restored->status)->toBe(OfferStatus::Paused)
        ->and($restored->lock_version)->toBe(4)
        ->and($restored->currentVersion->from_status)->toBe(OfferStatus::Archived)
        ->and(fn () => app(ChangeOfferStatus::class)->handle(
            makeLifecycleOffer($owner, [
                'name' => '',
                'promise' => '',
                'target_audience' => '',
                'desired_outcome' => '',
                'call_to_action' => '',
            ], 'draft-with-empty-content-create'),
            $owner,
            OfferStatus::Active,
            0,
            'draft-with-empty-content',
        ))->toThrow(ValidationException::class);
});

it('treats a repeated status as a no-op and keeps status snapshots immutable', function () {
    $owner = User::factory()->create();
    $offer = makeLifecycleOffer($owner, operationId: 'status-no-op-create');
    $active = app(ChangeOfferStatus::class)->handle($offer, $owner, OfferStatus::Active, 0, 'status-active');
    $versionCount = OfferVersion::query()->where('offer_id', $offer->id)->count();

    $same = app(ChangeOfferStatus::class)->handle($active, $owner, OfferStatus::Active, 1, 'status-active-no-op');

    expect($same->lock_version)->toBe(1)
        ->and($same->current_version_id)->toBe($active->current_version_id)
        ->and(OfferVersion::query()->where('offer_id', $offer->id)->count())->toBe($versionCount)
        ->and($same->currentVersion->status_snapshot)->toBe(OfferStatus::Active)
        ->and($same->currentVersion->origin)->toBe(OfferVersionOrigin::StatusChange)
        ->and(fn () => $same->currentVersion->update(['payload' => []]))->toThrow(LogicException::class)
        ->and(fn () => $same->currentVersion->delete())->toThrow(LogicException::class);
});

it('deletes only draft aggregates and purges their internal version and operation rows', function () {
    $owner = User::factory()->create();
    $offer = makeLifecycleOffer($owner, operationId: 'delete-draft-create');
    $offer = app(UpdateOffer::class)->handle(
        $offer,
        $owner,
        offerLifecyclePayload(['notes' => 'Draft history to purge.']),
        0,
        'delete-draft-update',
    );

    app(DeleteOffer::class)->handle($offer, $owner);

    expect(Offer::query()->whereKey($offer->id)->exists())->toBeFalse()
        ->and(OfferVersion::query()->where('offer_id', $offer->id)->exists())->toBeFalse()
        ->and(OfferClientOperation::query()->where('offer_id', $offer->id)->exists())->toBeFalse();

    $active = makeLifecycleOffer($owner, operationId: 'delete-active-create');
    $active = app(ChangeOfferStatus::class)->handle($active, $owner, OfferStatus::Active, 0, 'delete-active-status');

    expect(fn () => app(DeleteOffer::class)->handle($active, $owner))
        ->toThrow(RuntimeException::class, 'offer_not_deletable');
});

it('serves owner mutations and member history through the exact HTTP contract', function () {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $payload = offerLifecyclePayload(['name' => 'HTTP offer']);

    $created = $this->actingAs($owner)
        ->postJson(route('offers.store'), $payload + ['client_operation_id' => 'http-create'])
        ->assertCreated()
        ->assertJsonPath('offer.status', 'draft')
        ->assertJsonPath('offer.lock_version', 0)
        ->assertJsonPath('offer.version_count', 1)
        ->assertJsonMissingPath('offer.versions');
    $offerId = (string) $created->json('offer.uuid');

    $updated = $this->actingAs($owner)
        ->patchJson(route('offers.update', $offerId), array_replace($payload, [
            'promise' => 'A saved HTTP promise.',
            'expected_lock_version' => 0,
            'client_operation_id' => 'http-update',
        ]))
        ->assertOk()
        ->assertJsonPath('offer.lock_version', 1)
        ->assertJsonPath('offer.promise', 'A saved HTTP promise.');

    $this->actingAs($owner)
        ->patchJson(route('offers.status.update', $offerId), [
            'status' => 'active',
            'expected_lock_version' => $updated->json('offer.lock_version'),
            'client_operation_id' => 'http-status',
        ])
        ->assertOk()
        ->assertJsonPath('offer.status', 'active')
        ->assertJsonPath('offer.current_version.status_snapshot', 'active');

    $member = User::factory()->create();
    $workspace->memberships()->create([
        'user_id' => $member->id,
        'role' => 'member',
    ]);

    $this->actingAs($member)
        ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $workspace->id])
        ->getJson(route('offers.history', $offerId))
        ->assertOk()
        ->assertJsonPath('offer.uuid', $offerId)
        ->assertJsonCount(3, 'history');

    $this->actingAs($member)
        ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $workspace->id])
        ->patchJson(route('offers.update', $offerId), array_replace($payload, [
            'expected_lock_version' => 2,
            'client_operation_id' => 'member-update',
        ]))
        ->assertForbidden();

    $this->actingAs($member)
        ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $workspace->id])
        ->patchJson(route('offers.status.update', $offerId), [
            'status' => 'paused',
            'expected_lock_version' => 2,
            'client_operation_id' => 'member-status',
        ])
        ->assertForbidden();
});

it('requires and replays the HTTP create operation ID', function () {
    $owner = User::factory()->create();
    $payload = offerLifecyclePayload(['name' => 'Replay-safe HTTP create']);

    $this->actingAs($owner)
        ->postJson(route('offers.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonPath('error.errors.client_operation_id.0', 'The client operation id field is required.');

    $first = $this->actingAs($owner)
        ->postJson(route('offers.store'), $payload + ['client_operation_id' => 'required-http-create'])
        ->assertCreated();
    $replayed = $this->actingAs($owner)
        ->postJson(route('offers.store'), $payload + ['client_operation_id' => 'required-http-create'])
        ->assertCreated();

    expect($replayed->json('offer.uuid'))->toBe($first->json('offer.uuid'))
        ->and($replayed->json('offer.current_version_id'))->toBe($first->json('offer.current_version_id'))
        ->and(Offer::query()->where('workspace_id', $owner->currentWorkspace()->id)->count())->toBe(1);
});

it('returns the originally committed HTTP update when replayed after another save', function () {
    $owner = User::factory()->create();
    $offer = makeLifecycleOffer($owner, operationId: 'http-interleaved-create');
    $firstPayload = offerLifecyclePayload(['notes' => 'HTTP operation A.']);
    $secondPayload = offerLifecyclePayload(['notes' => 'HTTP operation B.']);

    $first = $this->actingAs($owner)
        ->patchJson(route('offers.update', $offer->id), $firstPayload + [
            'expected_lock_version' => 0,
            'client_operation_id' => 'http-interleaved-a',
        ])
        ->assertOk();
    $this->actingAs($owner)
        ->patchJson(route('offers.update', $offer->id), $secondPayload + [
            'expected_lock_version' => 1,
            'client_operation_id' => 'http-interleaved-b',
        ])
        ->assertOk()
        ->assertJsonPath('offer.notes', 'HTTP operation B.')
        ->assertJsonPath('offer.lock_version', 2);

    $replay = $this->actingAs($owner)
        ->patchJson(route('offers.update', $offer->id), $firstPayload + [
            'expected_lock_version' => 0,
            'client_operation_id' => 'http-interleaved-a',
        ])
        ->assertOk()
        ->assertJsonPath('offer.notes', 'HTTP operation A.')
        ->assertJsonPath('offer.lock_version', 1)
        ->assertJsonPath('offer.current_version_id', $first->json('offer.current_version_id'))
        ->assertJsonPath('offer.version_count', 3);

    expect($replay->json('offer.current_version_id'))->not->toBe(
        Offer::query()->whereKey($offer->id)->value('current_version_id'),
    );
});

it('saves partial drafts, preserves omitted fields on patch, and requires completeness only for activation', function () {
    $owner = User::factory()->create();

    $created = $this->actingAs($owner)
        ->postJson(route('offers.store'), [
            'name' => 'Early draft',
            'client_operation_id' => 'partial-http-create',
        ])
        ->assertCreated()
        ->assertJsonPath('offer.name', 'Early draft')
        ->assertJsonPath('offer.promise', '')
        ->assertJsonPath('offer.call_to_action', '')
        ->assertJsonPath('offer.version_count', 1);

    $offerId = (string) $created->json('offer.uuid');
    $updated = $this->actingAs($owner)
        ->patchJson(route('offers.update', $offerId), [
            'promise' => 'A draft promise without the rest yet.',
            'expected_lock_version' => 0,
            'client_operation_id' => 'partial-http-update',
        ])
        ->assertOk()
        ->assertJsonPath('offer.name', 'Early draft')
        ->assertJsonPath('offer.promise', 'A draft promise without the rest yet.')
        ->assertJsonPath('offer.target_audience', '')
        ->assertJsonPath('offer.version_count', 2)
        ->assertJsonMissingPath('offer.versions');

    $this->actingAs($owner)
        ->patchJson(route('offers.status.update', $offerId), [
            'status' => 'active',
            'expected_lock_version' => $updated->json('offer.lock_version'),
            'client_operation_id' => 'partial-http-activate',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.type', 'validation_error')
        ->assertJsonPath(
            'error.errors.target_audience.0',
            'Complete this field before activating the Offer.',
        )
        ->assertJsonPath(
            'error.errors.desired_outcome.0',
            'Complete this field before activating the Offer.',
        )
        ->assertJsonPath(
            'error.errors.call_to_action.0',
            'Complete this field before activating the Offer.',
        )
        ->assertJsonPath(
            'error.errors.status.0',
            'Complete the required Offer content before activating it.',
        );
});

it('loads Offer history only on demand with a bounded opaque cursor', function () {
    $owner = User::factory()->create();
    $offer = makeLifecycleOffer($owner, operationId: 'history-cursor-create');

    foreach (range(1, 4) as $index) {
        $offer = app(UpdateOffer::class)->handle(
            $offer,
            $owner,
            offerLifecyclePayload(['notes' => "History update {$index}"]),
            $index - 1,
            "history-cursor-update-{$index}",
        );
    }

    $first = $this->actingAs($owner)
        ->getJson(route('offers.history', $offer->id).'?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'history')
        ->assertJsonPath('history.0.version', 5)
        ->assertJsonPath('history.1.version', 4)
        ->assertJsonPath('offer.version_count', 5)
        ->assertJsonPath('pagination.has_more', true)
        ->assertJsonPath('pagination.per_page', 2)
        ->assertJsonMissingPath('offer.versions');

    $cursor = $first->json('pagination.next_cursor');
    expect($cursor)->toBeString()->not->toBeEmpty();

    $this->actingAs($owner)
        ->getJson(route('offers.history', $offer->id).'?'.http_build_query([
            'per_page' => 2,
            'cursor' => $cursor,
        ]))
        ->assertOk()
        ->assertJsonCount(2, 'history')
        ->assertJsonPath('history.0.version', 3)
        ->assertJsonPath('history.1.version', 2);
});

it('returns a 404 for foreign offer UUIDs and exposes safe JSON conflicts', function () {
    $owner = User::factory()->create();
    $foreignOwner = User::factory()->create();
    $foreignOffer = makeLifecycleOffer($foreignOwner, operationId: 'foreign-create');

    $this->actingAs($owner)
        ->getJson(route('offers.history', $foreignOffer->id))
        ->assertNotFound();

    $offer = makeLifecycleOffer($owner, operationId: 'conflict-create');
    $stale = $this->actingAs($owner)
        ->patchJson(route('offers.update', $offer->id), offerLifecyclePayload([
            'expected_lock_version' => 1,
            'client_operation_id' => 'conflict-stale',
        ]))
        ->assertStatus(409)
        ->assertJsonPath('error.type', 'conflict')
        ->assertJsonPath('error.code', 'stale_version')
        ->assertJsonPath('error.lock_version', 0)
        ->assertJsonPath('error.current_offer.uuid', $offer->id)
        ->assertJsonPath('error.current_offer.current_version_id', $offer->current_version_id);

    expect($stale->json('error.message'))->toContain('changed elsewhere');
});
