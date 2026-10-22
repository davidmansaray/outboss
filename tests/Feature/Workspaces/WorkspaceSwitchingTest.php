<?php

use App\Domain\Api\TokenScope;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;

it('creates a workspace, makes the creator its owner, and activates it', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('workspaces.store'), ['name' => '  Outboss Labs  '])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('gtm-profile.show'));

    $workspace = Workspace::query()->where('name', 'Outboss Labs')->sole();

    expect($workspace->type)->toBe('team')
        ->and($workspace->isOwner($user))->toBeTrue()
        ->and(session(User::ACTIVE_WORKSPACE_SESSION_KEY))->toBe($workspace->id)
        ->and($user->currentWorkspace()->is($workspace))->toBeTrue();
});

it('rejects a blank workspace name after normalization', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('unibox.index'))
        ->post(route('workspaces.store'), ['name' => '   '])
        ->assertRedirect(route('unibox.index'))
        ->assertSessionHasErrors('name');

    expect($user->workspaces()->count())->toBe(1);
});

it('switches only to a workspace the user belongs to', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['type' => 'team']);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceMembership::ROLE_OWNER,
    ]);
    $foreignWorkspace = Workspace::factory()->create(['type' => 'team']);

    $currentUrl = route('prospects.index', [
        'view' => 'accounts',
        'search' => 'Acme',
    ]);

    $this->actingAs($user)
        ->from($currentUrl)
        ->post(route('workspaces.switch', $workspace->id))
        ->assertRedirect($currentUrl);

    expect(session(User::ACTIVE_WORKSPACE_SESSION_KEY))->toBe($workspace->id)
        ->and($user->currentWorkspace()->is($workspace))->toBeTrue();

    $this->actingAs($user)
        ->post(route('workspaces.switch', $foreignWorkspace->id))
        ->assertForbidden();

    expect(session(User::ACTIVE_WORKSPACE_SESSION_KEY))->toBe($workspace->id);
});

it('can switch workspaces and open that workspace settings', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['type' => 'team']);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceMembership::ROLE_OWNER,
    ]);

    $this->actingAs($user)
        ->post(route('workspaces.switch', $workspace->id), [
            'destination' => 'settings',
        ])
        ->assertRedirect(route('mailboxes.index'));

    expect(session(User::ACTIVE_WORKSPACE_SESSION_KEY))->toBe($workspace->id);
});

it('falls back safely when switching from a workspace-owned detail page', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['type' => 'team']);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceMembership::ROLE_OWNER,
    ]);

    $this->actingAs($user)
        ->from(route('campaigns.show', 123))
        ->post(route('workspaces.switch', $workspace->id))
        ->assertRedirect(route('unibox.index'));

    expect(session(User::ACTIVE_WORKSPACE_SESSION_KEY))->toBe($workspace->id);
});

it('shares all memberships and the active workspace with the shell', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'name' => 'Second workspace',
        'type' => 'team',
        'avatar_path' => 'workspace-avatars/2/favicon.png',
    ]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceMembership::ROLE_OWNER,
    ]);

    $this->actingAs($user)
        ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $workspace->id])
        ->get(route('unibox.index'))
        ->assertInertia(fn ($page) => $page
            ->where('auth.workspace.id', $workspace->id)
            ->where('auth.workspace.avatar_url', '/storage/workspace-avatars/2/favicon.png')
            ->has('auth.workspaces', 2)
            ->where('auth.workspaces', function ($workspaces) use ($workspace): bool {
                return collect($workspaces)->contains(
                    fn ($available) => $available['id'] === $workspace->id
                        && $available['name'] === 'Second workspace',
                );
            }));
});

it('keeps an api token bound to its creation workspace after the browser switches', function () {
    $user = User::factory()->create();
    $personal = $user->currentWorkspace();
    $workspace = Workspace::factory()->create(['type' => 'team']);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceMembership::ROLE_OWNER,
    ]);

    $token = $user->createToken('Personal automation', [TokenScope::CampaignsRead->value]);

    $this->actingAs($user)
        ->from(route('prospects.index'))
        ->post(route('workspaces.switch', $workspace->id))
        ->assertRedirect(route('prospects.index'));

    expect($token->accessToken->workspace_id)->toBe($personal->id);

    $tokenUser = User::query()->findOrFail($user->id);
    $tokenUser->withAccessToken($token->accessToken->fresh());

    expect($tokenUser->currentWorkspace()->is($personal))->toBeTrue();
});

it('fails closed when an api token has no valid workspace membership', function () {
    $user = User::factory()->create();
    $token = $user->createToken('Scoped automation');
    $token->accessToken->forceFill(['workspace_id' => null])->save();

    $tokenUser = User::query()->findOrFail($user->id);
    $tokenUser->withAccessToken($token->accessToken->fresh());

    expect(fn () => $tokenUser->currentWorkspace())
        ->toThrow(ModelNotFoundException::class);
});

it('falls back safely when the session references a workspace membership that no longer exists', function () {
    $user = User::factory()->create();
    $personal = $user->currentWorkspace();
    $foreignWorkspace = Workspace::factory()->create(['type' => 'team']);

    $this->actingAs($user)
        ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $foreignWorkspace->id])
        ->get(route('unibox.index'))
        ->assertOk();

    expect($user->currentWorkspace()->is($personal))->toBeTrue()
        ->and(session()->has(User::ACTIVE_WORKSPACE_SESSION_KEY))->toBeFalse();
});
