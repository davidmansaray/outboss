<?php

use App\Models\User;

it('uses separate canonical routes for workspace and account settings', function () {
    $user = User::factory()->create();

    expect(route('mailboxes.index', absolute: false))->toBe('/settings/mailboxes')
        ->and(route('providers.index', absolute: false))->toBe('/settings/ai-research')
        ->and(route('data-export.index', absolute: false))->toBe('/settings/exports')
        ->and(route('api-keys.index', absolute: false))->toBe('/settings/api-keys')
        ->and(route('profile.edit', absolute: false))->toBe('/account/profile')
        ->and(route('security.edit', absolute: false))->toBe('/account/security')
        ->and(route('appearance.edit', absolute: false))->toBe('/account/appearance');

    $this->actingAs($user)->get('/settings')->assertRedirect('/settings/mailboxes');
    $this->actingAs($user)->get('/settings/providers')->assertRedirect('/settings/ai-research');
    $this->actingAs($user)->get('/settings/data')->assertRedirect('/settings/exports');
    $this->actingAs($user)->get('/settings/profile')->assertRedirect('/account/profile');
});

it('shares the current workspace identity with the application shell', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();

    $this->actingAs($user)
        ->get(route('providers.index'))
        ->assertInertia(fn ($page) => $page
            ->where('auth.workspace.id', $workspace->id)
            ->where('auth.workspace.name', $workspace->name)
            ->where('auth.workspace.role', 'owner')
            ->has('auth.workspaces', 1)
            ->where('auth.workspaces.0.id', $workspace->id));
});
