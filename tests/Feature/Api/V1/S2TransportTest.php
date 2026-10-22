<?php

use App\Domain\Api\TokenScope;
use App\Models\Account;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Str;

/** @return array{owner: User, account: Account} */
function makeS2ApiFixture(): array
{
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $account = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'REST S2 Account',
        'website' => 'https://rest-s2.example',
        'domain_normalized' => 'rest-s2.example',
        'notes' => 'This private note must not cross the transport boundary.',
    ]);

    return ['owner' => $owner, 'account' => $account->fresh()];
}

it('rejects intelligence reads for write-only tokens', function (): void {
    ['owner' => $owner, 'account' => $account] = makeS2ApiFixture();
    $writeOnlyToken = apiToken($owner, [TokenScope::IntelligenceWrite->value]);

    $this->withToken($writeOnlyToken)
        ->getJson("/api/v1/accounts/{$account->id}/intelligence")
        ->assertStatus(403)
        ->assertJsonPath('error.type', 'insufficient_scope');
});

it('enforces intelligence reads within the current workspace', function (): void {
    ['owner' => $owner, 'account' => $account] = makeS2ApiFixture();
    $readToken = apiToken($owner, [TokenScope::IntelligenceRead->value]);

    $this->withToken($readToken)
        ->getJson("/api/v1/accounts/{$account->id}/intelligence")
        ->assertOk()
        ->assertJsonPath('data.account.id', $account->id)
        ->assertJsonMissing(['This private note must not cross the transport boundary.']);

    ['account' => $foreignAccount] = makeS2ApiFixture();
    $this->withToken($readToken)
        ->getJson("/api/v1/accounts/{$foreignAccount->id}/intelligence")
        ->assertNotFound();
});

it('returns a bounded, side-effect-free preflight through the REST contract', function (): void {
    ['owner' => $owner, 'account' => $account] = makeS2ApiFixture();
    $token = apiToken($owner, [TokenScope::IntelligenceRead->value]);

    $response = $this->withToken($token)
        ->postJson("/api/v1/accounts/{$account->id}/research/preflight", [
            'freshness_policy' => ['snapshot_max_age_hours' => 24],
            'capabilities' => ['research.extract'],
        ])
        ->assertOk()
        ->assertJsonPath('data.account_id', $account->id)
        ->assertJsonStructure(['data' => ['input_hash', 'blocked', 'blockers', 'stages', 'capability_snapshot']]);

    expect($response->json('data.blocked'))->toBeTrue();
});

it('reports the emergency research pause as an operational control', function (): void {
    ['owner' => $owner, 'account' => $account] = makeS2ApiFixture();
    config()->set('intelligence.research.pause_new', true);
    $token = apiToken($owner, [TokenScope::IntelligenceWrite->value]);

    $this->withToken($token)
        ->postJson("/api/v1/accounts/{$account->id}/research-runs", [
            'idempotency_key' => 'paused-research',
        ], ['Idempotency-Key' => 'paused-research'])
        ->assertServiceUnavailable()
        ->assertJsonPath('error.type', 'account_research_paused');
});

it('lets members read intelligence but refuses owner-only research mutations', function (): void {
    ['owner' => $owner, 'account' => $account] = makeS2ApiFixture();
    $workspace = $owner->currentWorkspace();
    $member = User::factory()->withoutWorkspace()->create();
    WorkspaceMembership::query()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $member->id,
        'role' => 'member',
    ]);
    $token = $member->createToken('member-s2', [
        TokenScope::IntelligenceRead->value,
        TokenScope::IntelligenceWrite->value,
    ])->plainTextToken;

    $this->withToken($token)
        ->getJson("/api/v1/accounts/{$account->id}/intelligence")
        ->assertOk();

    $this->withToken($token)
        ->postJson("/api/v1/accounts/{$account->id}/research-runs", [
            'idempotency_key' => 'member-cannot-spend',
        ], ['Idempotency-Key' => 'member-cannot-spend'])
        ->assertForbidden();
});

it('rejects unsupported qualification Claim roles at the REST boundary', function (): void {
    ['owner' => $owner] = makeS2ApiFixture();
    $token = apiToken($owner, [TokenScope::IntelligenceWrite->value]);

    $response = $this->withToken($token)
        ->postJson('/api/v1/qualification-decisions/'.Str::uuid().'/reviews', [
            'action' => 'correct',
            'correction' => [
                'claims' => [[
                    'claim_id' => (string) Str::uuid(),
                    'role' => 'invented_role',
                ]],
            ],
        ], ['Idempotency-Key' => 'reject-invalid-qualification-role'])
        ->assertUnprocessable()
        ->assertJsonPath('error.type', 'validation_error');

    expect($response->json('error.errors'))->toHaveKey('correction.claims.0.role');
});
