<?php

use App\Domain\Api\TokenScope;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

it('displays the api keys settings page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('api-keys.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('api-keys/Index')
            ->has('keys')
            ->has('scopeGroups')
            ->where('readOnlyScopes', TokenScope::readOnly())
            ->where('plainTextToken', null),
        );
});

it('creates a key, returns the plaintext once, and persists scopes', function () {
    $user = User::factory()->create();
    $scopes = [TokenScope::CampaignsRead->value, TokenScope::PeopleRead->value];

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('api-keys.index'))
        ->post(route('api-keys.store'), [
            'name' => 'CI agent',
            'scopes' => $scopes,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('api-keys.index'))
        ->assertSessionHas('plainTextToken')
        ->assertInertiaFlash('plainTextToken');

    $plainTextToken = session('plainTextToken');

    expect($plainTextToken)->toBeString()
        ->and($plainTextToken)->toContain('|');

    $secret = explode('|', $plainTextToken, 2)[1];
    $token = PersonalAccessToken::query()->where('tokenable_id', $user->id)->sole();

    expect($token->name)->toBe('CI agent')
        ->and($token->abilities)->toBe($scopes)
        ->and($token->workspace_id)->toBe($user->currentWorkspace()->id)
        ->and($token->display_prefix)->toBe(substr($secret, 0, 8).'…');

    $this->actingAs($user)
        ->get(route('api-keys.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('api-keys/Index')
            ->where('plainTextToken', $plainTextToken)
            ->has('keys', 1)
            ->where('keys.0.name', 'CI agent')
            ->where('keys.0.scopes', $scopes)
            ->where('keys.0.display_prefix', $token->display_prefix),
        );

    $this->actingAs($user)
        ->get(route('api-keys.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('plainTextToken', null)
            ->has('keys', 1),
        );
});

it('rejects an invalid scope', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('api-keys.index'))
        ->post(route('api-keys.store'), [
            'name' => 'Broken key',
            'scopes' => ['not:a:scope'],
        ])
        ->assertRedirect(route('api-keys.index'))
        ->assertSessionHasErrors('scopes.0');

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('revokes the authenticated user\'s own key', function () {
    $user = User::factory()->create();
    $token = $user->createToken('Mine', [TokenScope::ExportsRead->value])->accessToken;

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('api-keys.destroy', $token->id))
        ->assertRedirect(route('api-keys.index'));

    expect(PersonalAccessToken::query()->whereKey($token->id)->exists())->toBeFalse();
});

it('forbids revoking another user\'s key', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $token = $owner->createToken('Private', [TokenScope::ExportsRead->value])->accessToken;

    $this->actingAs($intruder)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('api-keys.destroy', $token->id))
        ->assertForbidden();

    expect(PersonalAccessToken::query()->whereKey($token->id)->exists())->toBeTrue();
});

it('requires recent password confirmation to create or revoke a key', function () {
    $user = User::factory()->create();
    $token = $user->createToken('Mine', [TokenScope::ExportsRead->value])->accessToken;

    $this->actingAs($user)
        ->post(route('api-keys.store'), [
            'name' => 'Unconfirmed key',
            'scopes' => [TokenScope::CampaignsRead->value],
        ])
        ->assertRedirect(route('password.confirm'));

    $this->actingAs($user)
        ->delete(route('api-keys.destroy', $token->id))
        ->assertRedirect(route('password.confirm'));

    expect($user->tokens()->count())->toBe(1)
        ->and(PersonalAccessToken::query()->whereKey($token->id)->exists())->toBeTrue();
});

it('allows a bearer token with the matching scope', function () {
    Route::middleware(['auth:sanctum', 'scope:campaigns:read'])
        ->get('/__test/scoped', fn () => response()->json(['ok' => true]));

    $user = User::factory()->create();
    $plainTextToken = $user->createToken('Scoped', [TokenScope::CampaignsRead->value])->plainTextToken;

    $this->withToken($plainTextToken)
        ->getJson('/__test/scoped')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('blocks a bearer token that is missing the required scope', function () {
    Route::middleware(['auth:sanctum', 'scope:campaigns:launch'])
        ->get('/__test/scoped-missing', fn () => response()->json(['ok' => true]));

    $user = User::factory()->create();
    $plainTextToken = $user->createToken('Read only', [TokenScope::CampaignsRead->value])->plainTextToken;

    $this->withToken($plainTextToken)
        ->getJson('/__test/scoped-missing')
        ->assertForbidden()
        ->assertExactJson([
            'error' => [
                'type' => 'insufficient_scope',
                'message' => 'This API key does not have the campaigns:launch scope.',
            ],
        ]);
});

it('allows session-authenticated users through the scope middleware', function () {
    Route::middleware(['auth:sanctum', 'scope:inbox:send'])
        ->get('/__test/scoped-session', fn () => response()->json(['ok' => true]));

    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/__test/scoped-session')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('authenticates a bearer token against a protected route', function () {
    Route::middleware('auth:sanctum')
        ->get('/__test/sanctum-smoke', fn () => response()->json(['authenticated' => true]));

    $user = User::factory()->create();
    $plainTextToken = $user->createToken('Smoke', [TokenScope::ExportsRead->value])->plainTextToken;

    $this->withToken($plainTextToken)
        ->getJson('/__test/sanctum-smoke')
        ->assertOk()
        ->assertJson(['authenticated' => true]);
});

it('rate limits API ingress before authentication', function () {
    $route = Route::getRoutes()->match(Request::create('/api/v1/campaigns', 'GET'));
    $middleware = $route->gatherMiddleware();
    $positions = array_flip($middleware);

    expect($middleware)->toContain('throttle:ingress')
        ->and($positions['throttle:ingress'])
        ->toBeLessThan($positions['auth:sanctum']);
});
