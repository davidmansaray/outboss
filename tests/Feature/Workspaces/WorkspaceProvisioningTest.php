<?php

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

it('provisions one personal workspace for every factory user', function () {
    $user = User::factory()->create();

    expect($user->workspaces()->count())->toBe(1)
        ->and($user->workspaceMemberships()->count())->toBe(1)
        ->and($user->workspaceMemberships()->first()->role)->toBe('owner')
        ->and($user->currentWorkspace()->type)->toBe('personal')
        ->and($user->currentWorkspace()->id)->toBe($user->workspaces()->first()->id);
});

it('provisions a personal workspace through the real registration route', function () {
    $this->post(route('register.store'), [
        'name' => 'Workspace Owner',
        'email' => 'workspace-owner@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('unibox.index', absolute: false));

    $user = User::query()->where('email', 'workspace-owner@example.test')->firstOrFail();
    $workspace = $user->currentWorkspace();

    expect(Workspace::query()->where('type', 'personal')->count())->toBe(1)
        ->and($workspace->name)->toBe("Workspace Owner's Workspace")
        ->and(WorkspaceMembership::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($workspace->memberships()->first()->role)->toBe('owner');
});
