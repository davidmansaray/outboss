<?php

use App\Domain\Gtm\Services\RefreshGtmProfileResearch;
use App\Domain\Gtm\Services\UpdateGtmProfile;
use App\Domain\Workspaces\Services\ProvisionWorkspace;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\TransientToken;

beforeEach(function () {
    Queue::fake();
});

it('separates a generous autosave pool from deliberate Offer mutations', function () {
    config()->set('gtm.throttles.autosave_per_minute_per_user', 3);
    config()->set('gtm.throttles.autosave_per_minute_per_workspace', 3);
    config()->set('gtm.throttles.mutations_per_minute_per_user', 1);
    config()->set('gtm.throttles.mutations_per_minute_per_workspace', 10);
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->postJson(route('offers.store'), [
            'name' => 'First mutation',
            'client_operation_id' => 'rate-offer-1',
        ])
        ->assertCreated();
    $this->actingAs($owner)
        ->postJson(route('offers.store'), [
            'name' => 'Second mutation',
            'client_operation_id' => 'rate-offer-2',
        ])
        ->assertTooManyRequests();

    $this->actingAs($owner)
        ->patchJson(route('gtm-profile.sections.update', 'company'), [
            'payload' => ['category' => 'First autosave'],
            'expected_lock_version' => 0,
            'client_operation_id' => 'rate-autosave-1',
        ])
        ->assertOk();
    $this->actingAs($owner)
        ->patchJson(route('gtm-profile.sections.update', 'company'), [
            'payload' => ['category' => 'Second autosave'],
            'expected_lock_version' => 1,
            'client_operation_id' => 'rate-autosave-2',
        ])
        ->assertOk();
});

it('accepts Sanctum session tokens while deriving the user rate-limit key', function () {
    config()->set('gtm.throttles.mutations_per_minute_per_user', 2);
    config()->set('gtm.throttles.mutations_per_minute_per_workspace', 2);
    $owner = User::factory()->create();
    $owner->withAccessToken(new TransientToken);

    expect($owner->currentAccessToken())->toBeInstanceOf(TransientToken::class);

    $this->actingAs($owner)
        ->postJson(route('offers.store'), [
            'name' => 'Session-authenticated draft',
            'client_operation_id' => 'session-token-offer',
        ])
        ->assertCreated();
});

it('enforces the shared workspace mutation ceiling across different owners', function () {
    config()->set('gtm.throttles.mutations_per_minute_per_user', 100);
    config()->set('gtm.throttles.mutations_per_minute_per_workspace', 2);
    $first = User::factory()->create();
    $second = User::factory()->create();
    $workspace = $first->currentWorkspace();
    $workspace->memberships()->create([
        'user_id' => $second->id,
        'role' => WorkspaceMembership::ROLE_OWNER,
    ]);
    $session = [User::ACTIVE_WORKSPACE_SESSION_KEY => $workspace->id];

    $this->actingAs($first)
        ->withSession($session)
        ->postJson(route('offers.store'), [
            'name' => 'First owner',
            'client_operation_id' => 'workspace-rate-1',
        ])
        ->assertCreated();
    $this->actingAs($second)
        ->withSession($session)
        ->postJson(route('offers.store'), [
            'name' => 'Second owner',
            'client_operation_id' => 'workspace-rate-2',
        ])
        ->assertCreated();
    $this->actingAs($first)
        ->withSession($session)
        ->postJson(route('offers.store'), [
            'name' => 'Workspace burst',
            'client_operation_id' => 'workspace-rate-3',
        ])
        ->assertTooManyRequests();
});

it('rate limits provider research independently of the domain refresh cooldown', function () {
    config()->set('gtm.throttles.research_per_minute_per_user', 1);
    config()->set('gtm.throttles.research_per_minute_per_workspace', 10);
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->postJson(route('gtm-profile.research.store'), [
            'action' => 'refresh',
            'client_operation_id' => 'research-rate-1',
        ])
        ->assertUnprocessable();
    $this->actingAs($owner)
        ->postJson(route('gtm-profile.research.store'), [
            'action' => 'refresh',
            'client_operation_id' => 'research-rate-2',
        ])
        ->assertTooManyRequests();
});

it('rate limits owner-only profile exports independently of editing', function () {
    config()->set('gtm.throttles.export_per_minute_per_user', 1);
    config()->set('gtm.throttles.export_per_minute_per_workspace', 10);
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->getJson(route('gtm-profile.export'))
        ->assertOk();
    $this->actingAs($owner)
        ->getJson(route('gtm-profile.export'))
        ->assertTooManyRequests();
});

it('rejects another completed research refresh inside the configured cooldown', function () {
    config()->set('gtm.research.refresh_cooldown_seconds', 3600);
    $owner = User::factory()->withoutWorkspace()->create();
    $workspace = app(ProvisionWorkspace::class)->create($owner, 'Cooldown Workspace');
    $profile = $workspace->gtmProfile()->firstOrFail();
    app(UpdateGtmProfile::class)->updateWebsite(
        $profile,
        $owner,
        ['website_url' => 'https://example.com'],
        0,
        'cooldown-website',
    );

    $run = app(RefreshGtmProfileResearch::class)->handle(
        $profile,
        $owner,
        'refresh',
        'cooldown-first',
    );
    $run->forceFill(['state' => 'ready', 'finished_at' => now()])->save();
    $profile->forceFill(['research_state' => 'ready'])->save();

    expect(fn () => app(RefreshGtmProfileResearch::class)->handle(
        $profile->fresh(),
        $owner,
        'refresh',
        'cooldown-second',
    ))->toThrow(RuntimeException::class, 'research_refresh_cooldown');
});
