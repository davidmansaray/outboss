<?php

use App\Domain\Api\TokenScope;
use App\Domain\Devices\Services\PruneDeviceAuthRecords;
use App\Domain\Devices\Support\DeviceScopes;
use App\Domain\Devices\Support\Pkce;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\DeviceRefreshToken;
use App\Models\DeviceSession;
use App\Models\MobileAuthorizationCode;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * @return array{0: string, 1: string} [verifier, challenge]
 */
function mobilePkce(): array
{
    $verifier = Str::random(64);

    return [$verifier, Pkce::challengeFor($verifier)];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function mobileAuthorizeParams(string $challenge, array $overrides = []): array
{
    return array_merge([
        'client' => 'ios',
        'redirect_uri' => 'outboss://auth/callback',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'state' => 'state-abc123',
        'device_name' => 'Work iPhone',
    ], $overrides);
}

/**
 * Allow access in the web sheet and return the code from the callback URL.
 *
 * @return array{code: string, verifier: string}
 */
function mobileAuthorizationCode(User $user, ?int $workspaceId = null): array
{
    [$verifier, $challenge] = mobilePkce();

    $response = test()->actingAs($user)->post('/mobile/authorize', mobileAuthorizeParams($challenge, [
        'decision' => 'allow',
        'workspace_id' => $workspaceId ?? $user->currentWorkspace()->id,
    ]));
    $response->assertRedirect();
    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
    app('auth')->forgetGuards();

    return ['code' => (string) $query['code'], 'verifier' => $verifier];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function mobileExchange(string $code, string $verifier, array $overrides = []): TestResponse
{
    app('auth')->forgetGuards();

    return test()->postJson('/api/v1/auth/token', array_merge([
        'grant_type' => 'authorization_code',
        'code' => $code,
        'code_verifier' => $verifier,
        'redirect_uri' => 'outboss://auth/callback',
        'device' => [
            'install_id' => 'install-6F1C2E8A',
            'name' => 'Work iPhone',
            'platform' => 'ios',
            'app_version' => '1.0 (12)',
        ],
    ], $overrides));
}

/**
 * @return array<string, mixed> The token payload from a full sign-in.
 */
function mobileSignIn(User $user, ?int $workspaceId = null): array
{
    $authorization = mobileAuthorizationCode($user, $workspaceId);

    return mobileExchange($authorization['code'], $authorization['verifier'])->assertOk()->json('data');
}

function mobileRefresh(string $refreshToken, ?int $workspaceId = null): TestResponse
{
    app('auth')->forgetGuards();

    return test()->postJson('/api/v1/auth/token', array_filter([
        'grant_type' => 'refresh_token',
        'refresh_token' => $refreshToken,
        'workspace_id' => $workspaceId,
    ], fn ($value) => $value !== null));
}

function mobileApi(string $accessToken): TestCase
{
    app('auth')->forgetGuards();

    return test()->withToken($accessToken);
}

function secondWorkspaceFor(User $user, string $name = 'Acme Labs', string $role = 'member'): Workspace
{
    $workspace = Workspace::factory()->create(['name' => $name, 'type' => 'team']);
    WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => $role]);

    return $workspace;
}

it('sends people who are not signed in to the login page without a sign-up link', function () {
    [, $challenge] = mobilePkce();

    $this->get('/mobile/authorize?'.http_build_query(mobileAuthorizeParams($challenge)))
        ->assertRedirect('/login');

    $this->get('/login')
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/Login')
            ->where('canRegister', false));
});

it('keeps the sign-up link on a normal login', function () {
    $this->get('/login')
        ->assertInertia(fn (Assert $page) => $page->where('canRegister', true));
});

it('shows the workspace choice with each workspace and role', function () {
    $user = User::factory()->create();
    $labs = secondWorkspaceFor($user);
    [, $challenge] = mobilePkce();

    $this->actingAs($user)
        ->get('/mobile/authorize?'.http_build_query(mobileAuthorizeParams($challenge)))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/MobileAuthorize')
            ->where('invalid', false)
            ->where('email', $user->email)
            ->where('request.state', 'state-abc123')
            ->where('request.device_name', 'Work iPhone')
            ->has('workspaces', 2)
            ->where('workspaces', fn ($workspaces) => collect($workspaces)->contains(fn ($w) => $w['id'] === $labs->id && $w['name'] === 'Acme Labs' && $w['role'] === 'member'))
            ->missing('permissions'));

    expect(MobileAuthorizationCode::query()->count())->toBe(0);
});

/**
 * Open the sign-in sheet signed out and sign in with a password, as the
 * browser sheet does. Returns the URL Fortify sends the person back to.
 *
 * @param  array<string, mixed>  $params
 */
function mobileSignInFromSheet(User $user, array $params): string
{
    $authorizeUrl = '/mobile/authorize?'.http_build_query($params);

    test()->get($authorizeUrl)->assertRedirect('/login');

    $location = (string) test()->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect()
        ->headers->get('Location');

    // Fortify returns the person to the request they started (query re-ordered).
    expect($location)->toContain('/mobile/authorize?')->toContain('state=state-');

    return $location;
}

/**
 * @return array<string, string>
 */
function callbackQuery(TestResponse $response): array
{
    $location = (string) $response->headers->get('Location');
    expect($location)->toStartWith('outboss://auth/callback?');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return $query;
}

it('skips the page after a fresh sign-in from the sheet when the person has one workspace', function () {
    $user = User::factory()->create();
    [$verifier, $challenge] = mobilePkce();

    $authorizeUrl = mobileSignInFromSheet($user, mobileAuthorizeParams($challenge));
    $query = callbackQuery($this->get($authorizeUrl)->assertRedirect());

    expect($query['state'])->toBe('state-abc123')
        ->and($query['code'])->toStartWith('moc_')
        ->and(MobileAuthorizationCode::query()->sole()->workspace_id)->toBe($user->currentWorkspace()->id);

    $tokens = mobileExchange($query['code'], $verifier)->assertOk()->json('data');
    expect($tokens['workspace_id'])->toBe($user->currentWorkspace()->id)
        ->and(PersonalAccessToken::query()->whereNotNull('device_session_id')->sole()->abilities)->toBe(DeviceScopes::all());
});

it('skips the page only once per sign-in', function () {
    $user = User::factory()->create();
    [, $challenge] = mobilePkce();

    $authorizeUrl = mobileSignInFromSheet($user, mobileAuthorizeParams($challenge));
    callbackQuery($this->get($authorizeUrl)->assertRedirect());

    $this->get($authorizeUrl)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/MobileAuthorize')->where('invalid', false));

    expect(MobileAuthorizationCode::query()->count())->toBe(1);
});

it('skips the page after a two-factor sign-in from the sheet', function () {
    $user = User::factory()->withTwoFactor()->create();
    [, $challenge] = mobilePkce();
    $authorizeUrl = '/mobile/authorize?'.http_build_query(mobileAuthorizeParams($challenge));

    $this->get($authorizeUrl)->assertRedirect('/login');
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/two-factor-challenge');
    $authorizeUrl = (string) $this->post('/two-factor-challenge', ['recovery_code' => 'recovery-code-1'])
        ->assertRedirect()
        ->headers->get('Location');
    expect($authorizeUrl)->toContain('/mobile/authorize?');

    $query = callbackQuery($this->get($authorizeUrl)->assertRedirect());
    expect($query['code'])->toStartWith('moc_');
});

it('answers an Inertia visit after a fresh sign-in with a location response', function () {
    $user = User::factory()->create();
    [, $challenge] = mobilePkce();

    $authorizeUrl = mobileSignInFromSheet($user, mobileAuthorizeParams($challenge));
    $version = (string) app(HandleInertiaRequests::class)->version(request());

    $response = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
        ->get($authorizeUrl)
        ->assertStatus(409);

    expect((string) $response->headers->get('X-Inertia-Location'))->toStartWith('outboss://auth/callback?code=moc_');
});

it('shows the page to someone who was already signed in, even with one workspace', function () {
    $user = User::factory()->create();
    [, $challenge] = mobilePkce();

    $this->actingAs($user)
        ->get('/mobile/authorize?'.http_build_query(mobileAuthorizeParams($challenge)))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/MobileAuthorize')
            ->has('workspaces', 1));

    expect(MobileAuthorizationCode::query()->count())->toBe(0);
});

it('shows the page after a fresh sign-in when the person has several workspaces', function () {
    $user = User::factory()->create();
    secondWorkspaceFor($user);
    [, $challenge] = mobilePkce();

    $authorizeUrl = mobileSignInFromSheet($user, mobileAuthorizeParams($challenge));

    $this->get($authorizeUrl)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('workspaces', 2));

    expect(MobileAuthorizationCode::query()->count())->toBe(0);
});

it('shows the page when the sign-in is more than two minutes old', function () {
    $user = User::factory()->create();
    [, $challenge] = mobilePkce();

    $authorizeUrl = mobileSignInFromSheet($user, mobileAuthorizeParams($challenge));
    $this->travel(121)->seconds();

    $this->get($authorizeUrl)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/MobileAuthorize'));

    expect(MobileAuthorizationCode::query()->count())->toBe(0);
});

it('shows the page when the fresh sign-in was for a different request', function () {
    $user = User::factory()->create();
    [, $challenge] = mobilePkce();
    [, $otherChallenge] = mobilePkce();

    mobileSignInFromSheet($user, mobileAuthorizeParams($challenge));

    $this->get('/mobile/authorize?'.http_build_query(mobileAuthorizeParams($otherChallenge, ['state' => 'state-other'])))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/MobileAuthorize'));

    expect(MobileAuthorizationCode::query()->count())->toBe(0);
});

it('does not count a sign-in that finishes long after the sheet sent the person to login', function () {
    $user = User::factory()->create();
    [, $challenge] = mobilePkce();
    $authorizeUrl = '/mobile/authorize?'.http_build_query(mobileAuthorizeParams($challenge));

    $this->get($authorizeUrl)->assertRedirect('/login');
    $this->travel(11)->minutes();
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();

    $this->get($authorizeUrl)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/MobileAuthorize'));

    expect(MobileAuthorizationCode::query()->count())->toBe(0);
});

it('does not count a sign-in that is not returning to the sheet', function () {
    $user = User::factory()->create();
    [, $challenge] = mobilePkce();
    $authorizeUrl = '/mobile/authorize?'.http_build_query(mobileAuthorizeParams($challenge));

    $this->get($authorizeUrl)->assertRedirect('/login');
    session()->forget('url.intended');
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $this->get($authorizeUrl)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/MobileAuthorize'));

    expect(MobileAuthorizationCode::query()->count())->toBe(0);
});

it('refuses unlisted redirect URIs and non-S256 challenges without redirecting', function (array $overrides) {
    $user = User::factory()->create();
    [, $challenge] = mobilePkce();

    $this->actingAs($user)
        ->get('/mobile/authorize?'.http_build_query(mobileAuthorizeParams($challenge, $overrides)))
        ->assertStatus(400)
        ->assertInertia(fn (Assert $page) => $page->component('auth/MobileAuthorize')->where('invalid', true));

    $this->actingAs($user)
        ->post('/mobile/authorize', mobileAuthorizeParams($challenge, [...$overrides, 'decision' => 'allow', 'workspace_id' => $user->currentWorkspace()->id]))
        ->assertStatus(400);

    expect(MobileAuthorizationCode::query()->count())->toBe(0);
})->with([
    'unlisted redirect' => [['redirect_uri' => 'https://evil.test/callback']],
    'plain method' => [['code_challenge_method' => 'plain']],
    'short challenge' => [['code_challenge' => 'abc']],
    'missing state' => [['state' => '']],
    'unknown client' => [['client' => 'android']],
]);

it('redirects to the app with a code and the original state when allowed', function () {
    $user = User::factory()->create();
    [, $challenge] = mobilePkce();

    $response = $this->actingAs($user)->post('/mobile/authorize', mobileAuthorizeParams($challenge, [
        'decision' => 'allow',
        'workspace_id' => $user->currentWorkspace()->id,
    ]));

    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('outboss://auth/callback?')
        ->and($query['state'])->toBe('state-abc123')
        ->and($query['code'])->toStartWith('moc_');

    $stored = MobileAuthorizationCode::query()->sole();
    expect($stored->code_hash)->toBe(hash('sha256', $query['code']))
        ->and($stored->code_challenge)->toBe($challenge);
});

it('uses an Inertia location response so the browser sheet follows the custom scheme', function () {
    $user = User::factory()->create();
    [, $challenge] = mobilePkce();

    $this->actingAs($user)
        ->withHeaders(['X-Inertia' => 'true'])
        ->post('/mobile/authorize', mobileAuthorizeParams($challenge, [
            'decision' => 'allow',
            'workspace_id' => $user->currentWorkspace()->id,
        ]))
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location');
});

it('redirects with access_denied when the person does not allow access', function () {
    $user = User::factory()->create();
    [, $challenge] = mobilePkce();

    $this->actingAs($user)
        ->post('/mobile/authorize', mobileAuthorizeParams($challenge, ['decision' => 'deny']))
        ->assertRedirect('outboss://auth/callback?error=access_denied&state=state-abc123');

    expect(MobileAuthorizationCode::query()->count())->toBe(0);
});

it('will not issue a code for a workspace the person does not belong to', function () {
    $user = User::factory()->create();
    $other = Workspace::factory()->create();
    [, $challenge] = mobilePkce();

    $this->actingAs($user)
        ->post('/mobile/authorize', mobileAuthorizeParams($challenge, ['decision' => 'allow', 'workspace_id' => $other->id]))
        ->assertForbidden();

    expect(MobileAuthorizationCode::query()->count())->toBe(0);
});

it('exchanges a code for a workspace-bound device token and refresh token', function () {
    $user = User::factory()->create();
    $tokens = mobileSignIn($user);

    expect($tokens['token_type'])->toBe('Bearer')
        ->and($tokens['expires_in'])->toBe(3600)
        ->and($tokens['refresh_token'])->toStartWith('ort_')
        ->and($tokens['workspace_id'])->toBe($user->currentWorkspace()->id);

    $session = DeviceSession::query()->sole();
    expect($session->name)->toBe('Work iPhone')
        ->and($session->app_version)->toBe('1.0 (12)')
        ->and($session->workspace_id)->toBe($tokens['workspace_id']);

    $token = PersonalAccessToken::query()->where('device_session_id', $session->id)->sole();
    expect($token->abilities)->toBe(DeviceScopes::all())
        ->and($token->workspace_id)->toBe($tokens['workspace_id']);

    mobileApi($tokens['access_token'])->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email)
        ->assertJsonPath('data.workspace.id', $tokens['workspace_id'])
        ->assertJsonPath('data.session.kind', 'device')
        ->assertJsonPath('data.session.id', $session->id)
        ->assertJsonPath('data.session.scopes', DeviceScopes::all())
        ->assertJsonPath('data.capabilities.push', false);
});

it('rejects a code with the wrong verifier and spends the code', function () {
    $user = User::factory()->create();
    $authorization = mobileAuthorizationCode($user);
    [$wrongVerifier] = mobilePkce();

    mobileExchange($authorization['code'], $wrongVerifier)
        ->assertStatus(400)
        ->assertJsonPath('error.type', 'invalid_grant');

    mobileExchange($authorization['code'], $authorization['verifier'])
        ->assertStatus(400)
        ->assertJsonPath('error.type', 'invalid_grant');

    expect(DeviceSession::query()->count())->toBe(0);
});

it('rejects reused, expired and redirect-mismatched codes', function () {
    $user = User::factory()->create();

    $used = mobileAuthorizationCode($user);
    mobileExchange($used['code'], $used['verifier'])->assertOk();
    mobileExchange($used['code'], $used['verifier'])->assertStatus(400)->assertJsonPath('error.type', 'invalid_grant');

    $expired = mobileAuthorizationCode($user);
    $this->travel(61)->seconds();
    mobileExchange($expired['code'], $expired['verifier'])->assertStatus(400)->assertJsonPath('error.type', 'invalid_grant');
    $this->travelBack();

    $mismatched = mobileAuthorizationCode($user);
    mobileExchange($mismatched['code'], $mismatched['verifier'], ['redirect_uri' => 'outboss://other'])
        ->assertStatus(400)
        ->assertJsonPath('error.type', 'invalid_grant');

    mobileExchange('moc_unknown', Str::random(64))->assertStatus(400)->assertJsonPath('error.type', 'invalid_grant');
});

it('limits device tokens to inbox and read-only scopes', function () {
    $user = User::factory()->create();
    $tokens = mobileSignIn($user);

    mobileApi($tokens['access_token'])->getJson('/api/v1/threads')->assertOk();
    mobileApi($tokens['access_token'])->getJson('/api/v1/campaigns')->assertOk();
    mobileApi($tokens['access_token'])->getJson('/api/v1/people')->assertOk();
    mobileApi($tokens['access_token'])->postJson('/api/v1/campaigns', ['name' => 'Nope'])
        ->assertForbidden()
        ->assertJsonPath('error.type', 'insufficient_scope');
    mobileApi($tokens['access_token'])->getJson('/api/v1/suppressions')
        ->assertForbidden()
        ->assertJsonPath('error.type', 'insufficient_scope');
});

it('reports an expired access token as token_expired and refreshes it', function () {
    $user = User::factory()->create();
    $tokens = mobileSignIn($user);

    $this->travel(61)->minutes();

    mobileApi($tokens['access_token'])->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.type', 'token_expired');

    $refreshed = mobileRefresh($tokens['refresh_token'])->assertOk()->json('data');

    expect($refreshed['access_token'])->not->toBe($tokens['access_token'])
        ->and($refreshed['refresh_token'])->not->toBe($tokens['refresh_token']);

    mobileApi($refreshed['access_token'])->getJson('/api/v1/me')->assertOk();
    mobileApi($tokens['access_token'])->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.type', 'unauthenticated');
    expect(PersonalAccessToken::query()->whereNotNull('device_session_id')->count())->toBe(1);
});

it('treats a quick retry of a spent refresh token as a lost response', function () {
    $user = User::factory()->create();
    $tokens = mobileSignIn($user);

    $first = mobileRefresh($tokens['refresh_token'])->assertOk()->json('data');
    $retry = mobileRefresh($tokens['refresh_token'])->assertOk()->json('data');

    // The pair from the lost response is retired; only the newest works.
    mobileRefresh($first['refresh_token'])->assertUnauthorized()->assertJsonPath('error.type', 'session_revoked');
    expect(DeviceSession::query()->sole()->isRevoked())->toBeTrue();
    mobileApi($retry['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
});

it('revokes the device session when a spent refresh token is reused later', function () {
    $user = User::factory()->create();
    $tokens = mobileSignIn($user);
    $next = mobileRefresh($tokens['refresh_token'])->assertOk()->json('data');

    $this->travel(31)->seconds();

    mobileRefresh($tokens['refresh_token'])
        ->assertUnauthorized()
        ->assertJsonPath('error.type', 'session_revoked');

    expect(DeviceSession::query()->sole()->isRevoked())->toBeTrue();
    mobileApi($next['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
    mobileRefresh($next['refresh_token'])->assertUnauthorized()->assertJsonPath('error.type', 'session_revoked');
});

it('rejects unknown and expired refresh tokens', function () {
    $user = User::factory()->create();
    $tokens = mobileSignIn($user);

    mobileRefresh('ort_unknown')->assertStatus(400)->assertJsonPath('error.type', 'invalid_grant');

    $this->travel(61)->days();
    mobileRefresh($tokens['refresh_token'])->assertStatus(400)->assertJsonPath('error.type', 'invalid_grant');
});

it('switches workspace on refresh only for workspaces the person belongs to', function () {
    $user = User::factory()->create();
    $labs = secondWorkspaceFor($user);
    $stranger = Workspace::factory()->create();
    $tokens = mobileSignIn($user);

    mobileRefresh($tokens['refresh_token'], $stranger->id)
        ->assertForbidden()
        ->assertJsonPath('error.type', 'workspace_forbidden');

    // A refused switch leaves the refresh token usable.
    $switched = mobileRefresh($tokens['refresh_token'], $labs->id)->assertOk()->json('data');

    expect($switched['workspace_id'])->toBe($labs->id);
    mobileApi($switched['access_token'])->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.workspace.id', $labs->id)
        ->assertJsonPath('data.workspace.role', 'member')
        ->assertJsonCount(2, 'data.workspaces');

    // Omitting workspace_id keeps the current one.
    $kept = mobileRefresh($switched['refresh_token'])->assertOk()->json('data');
    expect($kept['workspace_id'])->toBe($labs->id);
});

it('moves someone removed from the active workspace to their default workspace on refresh', function () {
    $user = User::factory()->create();
    $personal = $user->currentWorkspace();
    $labs = secondWorkspaceFor($user);
    $tokens = mobileSignIn($user, $labs->id);

    WorkspaceMembership::query()->where('workspace_id', $labs->id)->where('user_id', $user->id)->delete();

    mobileApi($tokens['access_token'])->getJson('/api/v1/threads')->assertNotFound();

    $refreshed = mobileRefresh($tokens['refresh_token'])->assertOk()->json('data');
    expect($refreshed['workspace_id'])->toBe($personal->id);
});

it('signs out a device and its tokens stop working', function () {
    $user = User::factory()->create();
    $tokens = mobileSignIn($user);

    mobileApi($tokens['access_token'])->postJson('/api/v1/auth/logout')->assertNoContent();

    mobileApi($tokens['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
    mobileRefresh($tokens['refresh_token'])->assertUnauthorized()->assertJsonPath('error.type', 'session_revoked');
});

it('refuses device-only endpoints for personal API keys', function () {
    $user = User::factory()->create();
    $token = apiToken($user, [TokenScope::InboxRead->value]);

    $this->withToken($token)->postJson('/api/v1/auth/logout')
        ->assertForbidden()
        ->assertJsonPath('error.type', 'device_session_required');

    $this->withToken($token)->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.session.kind', 'api_key')
        ->assertJsonPath('data.session.scopes', [TokenScope::InboxRead->value]);
});

it('replaces the earlier session when the same phone signs in again', function () {
    $user = User::factory()->create();
    $first = mobileSignIn($user);
    $second = mobileSignIn($user);

    expect(DeviceSession::query()->count())->toBe(1)
        ->and($second['device_session_id'])->not->toBe($first['device_session_id']);
    mobileApi($first['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
    mobileRefresh($first['refresh_token'])->assertStatus(400)->assertJsonPath('error.type', 'invalid_grant');
});

it('lists signed-in devices in settings and signs one out', function () {
    $user = User::factory()->create();
    $tokens = mobileSignIn($user);
    $session = DeviceSession::query()->sole();

    $this->actingAs($user)->get('/settings/devices')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('devices/Index')
            ->has('devices', 1)
            ->where('devices.0.name', 'Work iPhone')
            ->where('devices.0.workspace', $user->currentWorkspace()->name));

    $this->actingAs(User::factory()->create())
        ->delete('/settings/devices/'.$session->id)
        ->assertNotFound();

    $this->actingAs($user)
        ->delete('/settings/devices/'.$session->id)
        ->assertRedirect('/settings/devices');

    mobileApi($tokens['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
    mobileRefresh($tokens['refresh_token'])->assertUnauthorized()->assertJsonPath('error.type', 'session_revoked');

    $this->actingAs($user)->get('/settings/devices')
        ->assertInertia(fn (Assert $page) => $page->has('devices', 0));
});

it('keeps device tokens off the API keys page', function () {
    $user = User::factory()->create();
    $user->createToken('Reporting agent', [TokenScope::InboxRead->value]);
    mobileSignIn($user);
    $deviceToken = PersonalAccessToken::query()->whereNotNull('device_session_id')->sole();

    $this->actingAs($user)->get('/settings/api-keys')
        ->assertInertia(fn (Assert $page) => $page
            ->has('keys', 1)
            ->where('keys.0.name', 'Reporting agent'));

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete('/settings/api-keys/'.$deviceToken->id)
        ->assertForbidden();
});

it('prunes spent sign-in records', function () {
    $user = User::factory()->create();
    mobileSignIn($user);
    $session = DeviceSession::query()->sole();
    $session->forceFill(['revoked_at' => now()])->save();

    $this->travel(62)->days();
    $result = app(PruneDeviceAuthRecords::class)->handle();

    expect($result['codes'])->toBe(1)
        ->and(DeviceRefreshToken::query()->count())->toBe(0)
        ->and(DeviceSession::query()->count())->toBe(0);
});
