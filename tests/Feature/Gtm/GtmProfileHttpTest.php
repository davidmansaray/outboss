<?php

use App\Domain\Gtm\Enums\GtmProfileReviewStatus;
use App\Domain\Gtm\Enums\GtmProfileReviewType;
use App\Domain\Gtm\Services\ExportGtmProfile;
use App\Domain\Workspaces\Services\ProvisionWorkspace;
use App\Models\GtmProfileResearchDispatch;
use App\Models\GtmProfileResearchRun;
use App\Models\GtmProfileReviewItem;
use App\Models\GtmProfileSource;
use App\Models\GtmProfileVersion;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->owner = User::factory()->withoutWorkspace()->create(['name' => 'HTTP Owner']);
    $this->workspace = app(ProvisionWorkspace::class)->create($this->owner, 'HTTP Workspace');
    $this->profile = $this->workspace->gtmProfile()->firstOrFail();
});

it('updates one profile section with a JSON version response and rejects stale locks', function () {
    $response = $this->actingAs($this->owner)
        ->patchJson(route('gtm-profile.sections.update', 'company'), [
            'payload' => ['category' => 'Evidence-led outbound'],
            'expected_lock_version' => 0,
            'client_operation_id' => 'http-section-1',
        ]);

    $response->assertOk()
        ->assertJsonPath('lock_version', 1)
        ->assertJsonPath('profile.payload.company.category.value', 'Evidence-led outbound')
        ->assertJsonPath('profile.current_version.version', 2);

    $this->actingAs($this->owner)
        ->patchJson(route('gtm-profile.sections.update', 'company'), [
            'payload' => ['category' => 'A stale change'],
            'expected_lock_version' => 0,
            'client_operation_id' => 'http-section-stale',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.type', 'conflict')
        ->assertJsonPath('error.code', 'stale_version')
        ->assertJsonPath('error.lock_version', 1)
        ->assertJsonPath('error.section', 'company')
        ->assertJsonPath('error.current_section.category.value', 'Evidence-led outbound');
});

it('returns the nested ApiError field bag for invalid autosave payloads', function () {
    $this->actingAs($this->owner)
        ->patchJson(route('gtm-profile.sections.update', 'company'), [
            'payload' => ['category' => str_repeat('x', 161)],
            'expected_lock_version' => 0,
            'client_operation_id' => 'invalid-section-payload',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.type', 'validation_error')
        ->assertJsonFragment([
            'company.category' => ['This value is too long.'],
        ]);
});

it('requires website changes to use the dedicated mutation that queues research', function () {
    $this->actingAs($this->owner)
        ->patchJson(route('gtm-profile.sections.update', 'company'), [
            'payload' => ['website_url' => 'https://example.com'],
            'expected_lock_version' => 0,
            'client_operation_id' => 'generic-website-rejected',
        ])
        ->assertUnprocessable()
        ->assertJsonFragment([
            'company.website_url' => [
                'Update the website using the dedicated website action.',
            ],
        ]);

    expect($this->profile->fresh()->lock_version)->toBe(0)
        ->and(GtmProfileVersion::query()->where('gtm_profile_id', $this->profile->id)->count())->toBe(1)
        ->and(GtmProfileResearchRun::query()->where('gtm_profile_id', $this->profile->id)->count())->toBe(0);

    $this->actingAs($this->owner)
        ->putJson(route('gtm-profile.website.update'), [
            'website_url' => 'https://example.com',
            'expected_lock_version' => 0,
            'client_operation_id' => 'dedicated-website-accepted',
        ])
        ->assertOk()
        ->assertJsonPath('profile.payload.company.website_url.value', 'https://example.com')
        ->assertJsonPath('run.state', 'queued');

    expect(GtmProfileResearchRun::query()->where('gtm_profile_id', $this->profile->id)->count())->toBe(1);
});

it('freezes a stale second-owner edit until it is explicitly reapplied to the latest section', function () {
    $secondOwner = User::factory()->withoutWorkspace()->create(['name' => 'Second Owner']);
    $this->workspace->memberships()->create([
        'user_id' => $secondOwner->id,
        'role' => 'owner',
    ]);

    $this->actingAs($this->owner)
        ->patchJson(route('gtm-profile.sections.update', 'company'), [
            'payload' => ['category' => 'First owner category'],
            'expected_lock_version' => 0,
            'client_operation_id' => 'owner-one-change',
        ])
        ->assertOk();

    $stale = $this->actingAs($secondOwner)
        ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $this->workspace->id])
        ->patchJson(route('gtm-profile.sections.update', 'company'), [
            'payload' => ['category' => 'Second owner draft'],
            'expected_lock_version' => 0,
            'client_operation_id' => 'owner-two-stale',
        ]);

    $stale->assertConflict()
        ->assertJsonPath('error.lock_version', 1)
        ->assertJsonPath('error.current_section.category.value', 'First owner category');

    expect($this->profile->fresh()->lock_version)->toBe(1)
        ->and(GtmProfileVersion::query()->where('gtm_profile_id', $this->profile->id)->count())->toBe(2);

    $this->actingAs($secondOwner)
        ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $this->workspace->id])
        ->patchJson(route('gtm-profile.sections.update', 'company'), [
            'payload' => ['category' => 'Second owner draft'],
            'expected_lock_version' => 1,
            'client_operation_id' => 'owner-two-explicit-reapply',
        ])
        ->assertOk()
        ->assertJsonPath('lock_version', 2)
        ->assertJsonPath('profile.payload.company.category.value', 'Second owner draft');
});

it('returns the originally committed response when a lost mutation is replayed after another write', function () {
    $secondOwner = User::factory()->withoutWorkspace()->create(['name' => 'Second HTTP Owner']);
    $this->workspace->memberships()->create([
        'user_id' => $secondOwner->id,
        'role' => 'owner',
    ]);

    $ownerA = $this->actingAs($this->owner)
        ->patchJson(route('gtm-profile.sections.update', 'company'), [
            'payload' => ['category' => 'Owner A category'],
            'expected_lock_version' => 0,
            'client_operation_id' => 'http-lost-owner-a',
        ])
        ->assertOk();
    $ownerAVersion = $ownerA->json('current_version_id');

    $this->actingAs($secondOwner)
        ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $this->workspace->id])
        ->patchJson(route('gtm-profile.sections.update', 'company'), [
            'payload' => ['category' => 'Owner B category'],
            'expected_lock_version' => 1,
            'client_operation_id' => 'http-later-owner-b',
        ])
        ->assertOk()
        ->assertJsonPath('lock_version', 2);

    $replay = $this->actingAs($this->owner)
        ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $this->workspace->id])
        ->patchJson(route('gtm-profile.sections.update', 'company'), [
            'payload' => ['category' => 'Owner A category'],
            'expected_lock_version' => 0,
            'client_operation_id' => 'http-lost-owner-a',
        ]);

    $replay->assertOk()
        ->assertJsonPath('lock_version', 1)
        ->assertJsonPath('current_version_id', $ownerAVersion)
        ->assertJsonPath('profile.payload.company.category.value', 'Owner A category');

    $this->actingAs($this->owner)
        ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $this->workspace->id])
        ->patchJson(route('gtm-profile.sections.update', 'company'), [
            'payload' => ['name' => 'Queued after replay'],
            'expected_lock_version' => 1,
            'client_operation_id' => 'http-owner-a-next-edit',
        ])
        ->assertConflict()
        ->assertJsonPath('error.lock_version', 2)
        ->assertJsonPath('error.current_section.category.value', 'Owner B category');
});

it('allows members to read but not mutate the profile and hides foreign reviews', function () {
    $member = User::factory()->withoutWorkspace()->create();
    $this->workspace->memberships()->create([
        'user_id' => $member->id,
        'role' => 'member',
    ]);
    $foreign = User::factory()->create();
    $foreignProfile = $foreign->currentWorkspace()->gtmProfile()->firstOrFail();
    $foreignReview = GtmProfileReviewItem::factory()->create([
        'gtm_profile_id' => $foreignProfile->id,
        'status' => GtmProfileReviewStatus::Open,
        'type' => GtmProfileReviewType::Conflict,
    ]);

    $this->actingAs($member)
        ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $this->workspace->id])
        ->get(route('gtm-profile.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('profile.uuid', $this->profile->id)
            ->where('isOwner', false));

    $this->actingAs($member)
        ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $this->workspace->id])
        ->patchJson(route('gtm-profile.sections.update', 'company'), [
            'payload' => ['category' => 'Nope'],
            'expected_lock_version' => 0,
            'client_operation_id' => 'member-section',
        ])
        ->assertForbidden();

    $this->actingAs($this->owner)
        ->postJson(route('gtm-profile.review.resolve', $foreignReview->id), [
            'action' => 'keep_owner',
            'expected_lock_version' => 0,
            'client_operation_id' => 'foreign-review',
        ])
        ->assertNotFound();
});

it('answers missing review questions and rejects actions that require a proposal', function () {
    $missing = GtmProfileReviewItem::query()->create([
        'gtm_profile_id' => $this->profile->id,
        'field_path' => 'buyers.target_roles',
        'type' => GtmProfileReviewType::Missing,
        'proposed_value' => null,
        'source_ids' => [],
        'proposed_value_hash' => hash('sha256', 'http-missing-target-role'),
        'status' => GtmProfileReviewStatus::Open,
    ]);

    foreach (['accept_research', 'keep_owner'] as $index => $action) {
        $this->actingAs($this->owner)
            ->postJson(route('gtm-profile.review.resolve', $missing->id), [
                'action' => $action,
                'expected_lock_version' => 0,
                'client_operation_id' => 'http-missing-invalid-'.$index,
            ])
            ->assertUnprocessable()
            ->assertJsonFragment([
                'action' => ['This action is not available for this review question.'],
            ]);
    }

    $this->actingAs($this->owner)
        ->postJson(route('gtm-profile.review.resolve', $missing->id), [
            'action' => 'edit_owner',
            'edited_value' => ['value' => 'VP Revenue'],
            'expected_lock_version' => 0,
            'client_operation_id' => 'http-missing-answer',
        ])
        ->assertOk()
        ->assertJsonPath('lock_version', 1)
        ->assertJsonPath('profile.payload.buyers.target_roles.0.value', 'VP Revenue');

    expect($missing->fresh()->status)->toBe(GtmProfileReviewStatus::Resolved);
});

it('serializes the original review result when its lost response is replayed after another edit', function () {
    $missing = GtmProfileReviewItem::query()->create([
        'gtm_profile_id' => $this->profile->id,
        'field_path' => 'company.category',
        'type' => GtmProfileReviewType::Missing,
        'proposed_value' => null,
        'source_ids' => [],
        'proposed_value_hash' => hash('sha256', 'http-review-replay'),
        'status' => GtmProfileReviewStatus::Open,
    ]);
    $request = [
        'action' => 'edit_owner',
        'edited_value' => ['value' => 'Original answer'],
        'expected_lock_version' => 0,
        'client_operation_id' => 'http-lost-review-response',
    ];

    $original = $this->actingAs($this->owner)
        ->postJson(route('gtm-profile.review.resolve', $missing->id), $request)
        ->assertOk();
    $originalVersion = $original->json('current_version_id');

    $this->actingAs($this->owner)
        ->patchJson(route('gtm-profile.sections.update', 'company'), [
            'payload' => ['name' => 'Later owner edit'],
            'expected_lock_version' => 1,
            'client_operation_id' => 'http-later-edit-after-review',
        ])
        ->assertOk()
        ->assertJsonPath('lock_version', 2);

    $replay = $this->actingAs($this->owner)
        ->postJson(route('gtm-profile.review.resolve', $missing->id), $request)
        ->assertOk()
        ->assertJsonPath('lock_version', 1)
        ->assertJsonPath('current_version_id', $originalVersion)
        ->assertJsonPath('profile.payload.company.category.value', 'Original answer');

    expect($replay->json('profile.payload.company.name.value'))->not->toBe('Later owner edit')
        ->and($this->profile->fresh()->lock_version)->toBe(2);
});

it('normalizes a website, queues research, exposes source/history reads, and exports without caching', function () {
    $this->actingAs($this->owner)
        ->putJson(route('gtm-profile.website.update'), [
            'website_url' => 'https://example.com',
            'expected_lock_version' => 0,
            'client_operation_id' => 'http-website-1',
        ])
        ->assertOk()
        ->assertJsonPath('profile.payload.company.website_url.value', 'https://example.com')
        ->assertJsonPath('profile.lock_version', 1)
        ->assertJsonPath('run.state', 'queued');

    $run = GtmProfileResearchRun::query()
        ->where('gtm_profile_id', $this->profile->id)
        ->firstOrFail();
    expect(GtmProfileResearchDispatch::query()->where('research_run_id', $run->id)->exists())->toBeTrue();

    $this->actingAs($this->owner)
        ->getJson(route('gtm-profile.sources'))
        ->assertOk()
        ->assertJsonPath('source_count', 0)
        ->assertJsonPath('current_version_id', $this->profile->fresh()->current_version_id);

    $this->actingAs($this->owner)
        ->getJson(route('gtm-profile.history'))
        ->assertOk()
        ->assertJsonCount(2, 'history')
        ->assertJsonPath('has_more', false)
        ->assertJsonPath('next_cursor', null);

    $export = $this->actingAs($this->owner)
        ->get(route('gtm-profile.export'));

    $export
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="gtm-profile.json"')
        ->assertJsonPath('profile.uuid', $this->profile->id)
        ->assertJsonPath('profile.payload.company.website_url.value', 'https://example.com');

    expect((string) $export->headers->get('Cache-Control'))->toContain('no-store');
});

it('bounds export collections and reports omitted records', function () {
    // A stale legacy config value cannot drift the serializer or persisted
    // version schema away from the code-owned contract.
    config()->set('gtm.schema_version', 999);

    foreach (range(1, ExportGtmProfile::MAX_VERSIONS) as $_index) {
        GtmProfileVersion::factory()->create(['gtm_profile_id' => $this->profile->id]);
    }
    GtmProfileSource::factory()
        ->count(ExportGtmProfile::MAX_SOURCES + 1)
        ->create(['gtm_profile_id' => $this->profile->id]);
    GtmProfileReviewItem::factory()
        ->count(ExportGtmProfile::MAX_REVIEW_ITEMS + 1)
        ->create(['gtm_profile_id' => $this->profile->id]);

    expect(GtmProfileVersion::query()
        ->where('gtm_profile_id', $this->profile->id)
        ->where('schema_version', '!=', GtmProfileVersion::SCHEMA_VERSION)
        ->exists())->toBeFalse();

    $this->actingAs($this->owner)
        ->getJson(route('gtm-profile.export'))
        ->assertOk()
        ->assertJsonPath('schema_version', GtmProfileVersion::SCHEMA_VERSION)
        ->assertJsonCount(ExportGtmProfile::MAX_VERSIONS, 'versions')
        ->assertJsonCount(ExportGtmProfile::MAX_SOURCES, 'sources')
        ->assertJsonCount(ExportGtmProfile::MAX_REVIEW_ITEMS, 'review_items')
        ->assertJsonPath('collections.versions.total', ExportGtmProfile::MAX_VERSIONS + 1)
        ->assertJsonPath('collections.versions.truncated', true)
        ->assertJsonPath('collections.sources.total', ExportGtmProfile::MAX_SOURCES + 1)
        ->assertJsonPath('collections.sources.truncated', true)
        ->assertJsonPath('collections.review_items.total', ExportGtmProfile::MAX_REVIEW_ITEMS + 1)
        ->assertJsonPath('collections.review_items.truncated', true);
});

it('cursor-paginates and bounds profile history', function () {
    foreach (range(1, 54) as $_index) {
        GtmProfileVersion::factory()->create([
            'gtm_profile_id' => $this->profile->id,
        ]);
    }

    $first = $this->actingAs($this->owner)
        ->getJson(route('gtm-profile.history', ['per_page' => 10]))
        ->assertOk()
        ->assertJsonCount(10, 'history')
        ->assertJsonPath('has_more', true);

    $cursor = $first->json('next_cursor');
    expect($cursor)->toBeString()->not->toBe('');

    $second = $this->actingAs($this->owner)
        ->getJson(route('gtm-profile.history', [
            'per_page' => 10,
            'cursor' => $cursor,
        ]))
        ->assertOk()
        ->assertJsonCount(10, 'history');

    expect(collect($first->json('history'))->pluck('uuid')->intersect(
        collect($second->json('history'))->pluck('uuid'),
    ))->toBeEmpty();

    $this->actingAs($this->owner)
        ->getJson(route('gtm-profile.history', ['per_page' => 500]))
        ->assertOk()
        ->assertJsonCount(50, 'history');
});

it('rejects unsafe website input before creating a new version or run', function () {
    $this->actingAs($this->owner)
        ->putJson(route('gtm-profile.website.update'), [
            'website_url' => 'http://127.0.0.1',
            'expected_lock_version' => 0,
            'client_operation_id' => 'unsafe-website',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.type', 'validation_error');

    expect(GtmProfileVersion::query()->where('gtm_profile_id', $this->profile->id)->count())->toBe(1)
        ->and(GtmProfileResearchRun::query()->where('gtm_profile_id', $this->profile->id)->count())->toBe(0);
});

it('requeues only retryable runs and rejects refresh while a run is active', function () {
    $created = $this->actingAs($this->owner)
        ->putJson(route('gtm-profile.website.update'), [
            'website_url' => 'https://example.com',
            'expected_lock_version' => 0,
            'client_operation_id' => 'research-http-website',
        ])
        ->assertOk();
    $run = GtmProfileResearchRun::query()->findOrFail($created->json('run_uuid'));

    $this->actingAs($this->owner)
        ->postJson(route('gtm-profile.research.store'), [
            'action' => 'refresh',
            'client_operation_id' => 'research-http-2',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'research_in_progress');

    $run->forceFill(['state' => 'failed', 'safe_failure_code' => 'research_failed', 'failed_at' => now()])->save();
    $this->profile->forceFill([
        'research_state' => 'failed',
        'safe_error_code' => 'research_failed',
    ])->save();

    $this->actingAs($this->owner)
        ->postJson(route('gtm-profile.research.store'), [
            'action' => 'retry',
            'client_operation_id' => 'research-http-3',
        ])
        ->assertOk()
        ->assertJsonPath('run_uuid', $run->id)
        ->assertJsonPath('run.state', 'queued');
});

it('starts an explicitly confirmed new run after a paid result is lost', function () {
    $created = $this->actingAs($this->owner)
        ->putJson(route('gtm-profile.website.update'), [
            'website_url' => 'https://example.com',
            'expected_lock_version' => 0,
            'client_operation_id' => 'paid-loss-website',
        ])
        ->assertOk();
    $lostRun = GtmProfileResearchRun::query()->findOrFail($created->json('run_uuid'));
    $lostRun->forceFill([
        'state' => 'failed',
        'safe_failure_code' => 'paid_result_lost',
        'failed_at' => now(),
    ])->save();
    $this->profile->forceFill([
        'research_state' => 'failed',
        'safe_error_code' => 'paid_result_lost',
    ])->save();

    $response = $this->actingAs($this->owner)
        ->postJson(route('gtm-profile.research.store'), [
            'action' => 'retry',
            'client_operation_id' => 'confirmed-paid-loss-new-run',
        ])
        ->assertOk()
        ->assertJsonPath('run.state', 'queued');

    $newRun = GtmProfileResearchRun::query()->findOrFail($response->json('run_uuid'));
    expect($newRun->id)->not->toBe($lostRun->id)
        ->and($newRun->parent_run_id)->toBe($lostRun->id)
        ->and($lostRun->fresh()->getRawOriginal('state'))->toBe('failed')
        ->and(GtmProfileResearchRun::query()->where('gtm_profile_id', $this->profile->id)->count())->toBe(2);
});

it('supersedes an active run when the website changes and replays without duplicating it', function () {
    $first = $this->actingAs($this->owner)
        ->putJson(route('gtm-profile.website.update'), [
            'website_url' => 'https://example.com',
            'expected_lock_version' => 0,
            'client_operation_id' => 'website-change-1',
        ])
        ->assertOk();

    $firstRun = $first->json('run_uuid');
    $firstVersion = $first->json('current_version_id');
    expect($firstRun)->toBeString()
        ->and($firstVersion)->toBeString();

    $changed = $this->actingAs($this->owner)
        ->putJson(route('gtm-profile.website.update'), [
            'website_url' => 'https://example.com/about',
            'expected_lock_version' => 1,
            'client_operation_id' => 'website-change-2',
        ])
        ->assertOk();

    $secondRun = $changed->json('run_uuid');
    $secondVersion = $changed->json('current_version_id');
    expect($secondRun)->toBeString()
        ->and($secondRun)->not->toBe($firstRun)
        ->and(GtmProfileResearchRun::query()->where('gtm_profile_id', $this->profile->id)->count())->toBe(2);

    $this->actingAs($this->owner)
        ->putJson(route('gtm-profile.website.update'), [
            'website_url' => 'https://example.com',
            'expected_lock_version' => 0,
            'client_operation_id' => 'website-change-1',
        ])
        ->assertOk()
        ->assertJsonPath('lock_version', 1)
        ->assertJsonPath('current_version_id', $firstVersion)
        ->assertJsonPath('profile.payload.company.website_url.value', 'https://example.com')
        ->assertJsonPath('run_uuid', $firstRun);

    $live = $this->profile->fresh('currentVersion');
    expect($live->lock_version)->toBe(2)
        ->and($live->current_version_id)->toBe($secondVersion)
        ->and($live->getRawOriginal('current_research_run_id'))->toBe($secondRun)
        ->and(data_get($live->currentVersion->payload, 'company.website_url.value'))->toBe('https://example.com/about')
        ->and(GtmProfileResearchRun::query()->where('gtm_profile_id', $this->profile->id)->count())->toBe(2);

    $this->actingAs($this->owner)
        ->putJson(route('gtm-profile.website.update'), [
            'website_url' => 'https://example.com/about',
            'expected_lock_version' => 1,
            'client_operation_id' => 'website-change-2',
        ])
        ->assertOk()
        ->assertJsonPath('run_uuid', $secondRun);

    expect(GtmProfileResearchRun::query()->where('gtm_profile_id', $this->profile->id)->count())->toBe(2);
});
