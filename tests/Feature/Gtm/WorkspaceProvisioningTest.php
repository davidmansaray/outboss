<?php

use App\Domain\Gtm\Services\UpdateGtmProfileWebsite;
use App\Domain\Gtm\Support\CanonicalGtmProfilePayload;
use App\Domain\Gtm\Support\PublicWebsiteUrl;
use App\Domain\Workspaces\Services\ProvisionWorkspace;
use App\Models\GtmProfile;
use App\Models\GtmProfileResearchDispatch;
use App\Models\GtmProfileResearchRun;
use App\Models\GtmProfileVersion;
use App\Models\OutreachPolicy;
use App\Models\OutreachPolicyVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceClientOperation;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

it('provisions one profile/version and one durable website dispatch idempotently', function () {
    Queue::fake();

    $user = User::factory()->withoutWorkspace()->create(['name' => 'Profile Owner']);
    $provisioner = app(ProvisionWorkspace::class);

    $workspace = $provisioner->create($user, 'Acme Labs', 'team', 'example.com', 'workspace-op-1');
    $replayed = $provisioner->create($user, 'Acme Labs', 'team', 'https://example.com', 'workspace-op-1');

    $profile = GtmProfile::query()->where('workspace_id', $workspace->id)->firstOrFail();
    $version = GtmProfileVersion::query()->where('gtm_profile_id', $profile->id)->firstOrFail();

    expect($replayed->id)->toBe($workspace->id)
        ->and($workspace->memberships()->where('user_id', $user->id)->where('role', 'owner')->count())->toBe(1)
        ->and(GtmProfile::query()->where('workspace_id', $workspace->id)->count())->toBe(1)
        ->and(GtmProfileVersion::query()->where('gtm_profile_id', $profile->id)->count())->toBe(1)
        ->and(OutreachPolicy::query()->where('workspace_id', $workspace->id)->count())->toBe(1)
        ->and(OutreachPolicyVersion::query()->where('workspace_id', $workspace->id)->count())->toBe(1)
        ->and(data_get($version->payload, 'company.website_url.value'))->toBe('https://example.com')
        ->and(GtmProfileResearchRun::query()->where('gtm_profile_id', $profile->id)->count())->toBe(1)
        ->and(GtmProfileResearchDispatch::query()->where('gtm_profile_id', $profile->id)->count())->toBe(1)
        ->and(WorkspaceClientOperation::query()
            ->where('initiating_user_id', $user->id)
            ->where('client_operation_id', 'workspace-op-1')
            ->count())->toBe(1);
});

it('always redirects workspace creation to its seeded GTM profile', function () {
    Queue::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('workspaces.store'), [
            'name' => 'Always On Workspace',
            'website_url' => 'example.com',
            'client_operation_id' => 'always-on-op',
        ])
        ->assertRedirect(route('gtm-profile.show', absolute: false));

    $workspace = Workspace::query()->where('name', 'Always On Workspace')->firstOrFail();
    $profile = GtmProfile::query()->where('workspace_id', $workspace->id)->firstOrFail();

    expect(GtmProfileVersion::query()->where('gtm_profile_id', $profile->id)->count())->toBe(1)
        ->and(GtmProfileResearchRun::query()->where('gtm_profile_id', $profile->id)->count())->toBe(1)
        ->and(data_get($profile->currentVersion()->firstOrFail()->payload, 'company.website_url.value'))->toBe('https://example.com');
});

it('normalizes and validates website input before a profile mutation', function () {
    $user = User::factory()->withoutWorkspace()->create();
    $workspace = app(ProvisionWorkspace::class)->create($user, 'Website Workspace');
    $profile = $workspace->gtmProfile()->firstOrFail();

    app(UpdateGtmProfileWebsite::class)->handle(
        $profile,
        $user,
        'https://example.com',
        0,
        'website-op',
    );

    expect(data_get($profile->fresh()->currentVersion()->firstOrFail()->payload, 'company.website_url.value'))
        ->toBe('https://example.com');

    expect(fn () => app(UpdateGtmProfileWebsite::class)->handle(
        $profile->fresh(),
        $user,
        'http://example.com',
        1,
        'unsafe-website-op',
    ))->toThrow(ValidationException::class);
});

it('backfills an existing workspace without creating duplicate profile versions', function () {
    $workspace = Workspace::query()->create(['name' => 'Legacy Workspace', 'type' => 'team']);

    $this->artisan('gtm:backfill-profiles')->assertExitCode(0);
    $this->artisan('gtm:backfill-profiles')->assertExitCode(0);

    $profile = GtmProfile::query()->where('workspace_id', $workspace->id)->firstOrFail();
    expect(GtmProfileVersion::query()->where('gtm_profile_id', $profile->id)->count())->toBe(1);
});

it('replays a lost workspace response and returns a controlled conflict when inputs change', function () {
    Queue::fake();
    $user = User::factory()->create();
    $request = [
        'name' => 'Idempotent Workspace',
        'website_url' => 'example.com',
        'client_operation_id' => 'lost-response-operation',
    ];

    $this->actingAs($user)
        ->postJson(route('workspaces.store'), $request)
        ->assertRedirect(route('gtm-profile.show', absolute: false));
    $workspace = Workspace::query()->where('name', 'Idempotent Workspace')->sole();

    $this->actingAs($user)
        ->postJson(route('workspaces.store'), $request)
        ->assertRedirect(route('gtm-profile.show', absolute: false));

    expect(Workspace::query()->where('name', 'Idempotent Workspace')->count())->toBe(1)
        ->and(WorkspaceClientOperation::query()
            ->where('initiating_user_id', $user->id)
            ->where('client_operation_id', 'lost-response-operation')
            ->value('resulting_workspace_id'))->toBe($workspace->id);

    $this->actingAs($user)
        ->postJson(route('workspaces.store'), array_replace($request, [
            'name' => 'Changed after an unknown response',
        ]))
        ->assertConflict()
        ->assertJsonPath('error.type', 'conflict')
        ->assertJsonPath('error.code', 'operation_replay_mismatch')
        ->assertJsonPath(
            'errors.client_operation_id',
            'This creation request was already used with different workspace details.',
        );

    expect(Workspace::query()->where('name', 'Changed after an unknown response')->exists())->toBeFalse();
});

it('replays a completed workspace operation without depending on a later DNS answer', function () {
    Queue::fake();
    $user = User::factory()->withoutWorkspace()->create();
    $provisioner = app(ProvisionWorkspace::class);
    $workspace = $provisioner->create($user, 'DNS-independent replay');
    $website = PublicWebsiteUrl::normalize('127.0.0.1.nip.io');

    expect($website)->toBe('https://127.0.0.1.nip.io')
        ->and(fn () => PublicWebsiteUrl::normalizeAndValidate($website, fresh: true))
        ->toThrow(InvalidArgumentException::class);

    WorkspaceClientOperation::query()->create([
        'initiating_user_id' => $user->id,
        'client_operation_id' => 'dns-changed-after-result',
        'server_fingerprint' => CanonicalGtmProfilePayload::hash([
            'action' => 'workspace.create',
            'initiating_user_id' => (int) $user->id,
            'name' => 'DNS-independent replay',
            'type' => 'team',
            'website_url' => $website,
        ]),
        'resulting_workspace_id' => $workspace->id,
        'redirect_route' => 'gtm-profile.show',
        'redirect_parameters' => [],
        'response_metadata' => ['workspace_id' => $workspace->id],
    ]);

    $replayed = $provisioner->create(
        $user,
        'DNS-independent replay',
        'team',
        $website,
        'dns-changed-after-result',
    );

    expect($replayed->id)->toBe($workspace->id)
        ->and(Workspace::query()->where('name', 'DNS-independent replay')->count())->toBe(1);
});

it('preserves the workspace operation audit without blocking account deletion', function () {
    $user = User::factory()->withoutWorkspace()->create();
    $workspace = app(ProvisionWorkspace::class)->create(
        $user,
        'Account deletion audit',
        'team',
        null,
        'account-deletion-operation',
    );
    $operation = WorkspaceClientOperation::query()
        ->where('initiating_user_id', $user->id)
        ->where('client_operation_id', 'account-deletion-operation')
        ->sole();

    $user->delete();

    expect(User::query()->whereKey($user->id)->exists())->toBeFalse()
        ->and($operation->fresh()?->initiating_user_id)->toBeNull()
        ->and($operation->fresh()?->resulting_workspace_id)->toBe($workspace->id);
});

it('supports a scoped idempotent deployment backfill and fails closed for an invalid target', function () {
    $first = Workspace::query()->create(['name' => 'Legacy One', 'type' => 'team']);
    $second = Workspace::query()->create(['name' => 'Legacy Two', 'type' => 'team']);

    $this->artisan('gtm:backfill-profiles', ['--workspace' => (string) $first->id])
        ->expectsOutputToContain('created 1 GTM profile(s), 0 already provisioned')
        ->assertSuccessful();
    $this->artisan('gtm:backfill-profiles', ['--workspace' => (string) $first->id])
        ->expectsOutputToContain('created 0 GTM profile(s), 1 already provisioned')
        ->assertSuccessful();

    expect($first->gtmProfile()->count())->toBe(1)
        ->and($second->gtmProfile()->count())->toBe(0);

    $this->artisan('gtm:backfill-profiles', ['--workspace' => 'not-a-number'])
        ->expectsOutputToContain('must be a positive numeric workspace ID')
        ->assertFailed();
    $this->artisan('gtm:backfill-profiles', ['--workspace' => '999999999'])
        ->expectsOutputToContain('was not found')
        ->assertFailed();
});
