<?php

use App\Domain\Intelligence\DTOs\StartAccountResearchRequest;
use App\Domain\Intelligence\Services\PreflightAccountResearch;
use App\Domain\Intelligence\Services\StartAccountResearch;
use App\Models\Account;
use App\Models\AccountResearchRun;
use App\Models\ResearchClaim;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{owner: User, workspace: Workspace, account: Account}
 */
function makeWebIntelligenceFixture(): array
{
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $account = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Browser intelligence account',
        'website' => 'https://stripe.com',
        'domain_normalized' => 'stripe.com',
        'created_by_user_id' => $owner->id,
        'source' => Account::SOURCE_MANUAL,
    ]);

    return [
        'owner' => $owner,
        'workspace' => $workspace->fresh(),
        'account' => $account->fresh(),
    ];
}

it('renders one existing Account with server-backed preflight and S1 provenance', function (): void {
    [
        'owner' => $owner,
        'account' => $account,
    ] = makeWebIntelligenceFixture();

    $this->actingAs($owner)
        ->get(route('accounts.intelligence.show', $account))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounts/Intelligence')
            ->where('account.id', $account->id)
            ->where('account.name', 'Browser intelligence account')
            ->where('account.source', Account::SOURCE_MANUAL)
            ->where('account.supplied_by.source_count', 0)
            ->where('provenance.source_count', 0)
            ->where('preflight.account_id', $account->id)
            ->where('preflight.account_snapshot.name', $account->name)
            ->has('preflight.blockers')
            ->has('researchRuns', 0)
            ->where('researchRun', null)
            ->where('isOwner', true)
            ->where('canWrite', true));
});

it('lets a workspace member read but not mutate Account intelligence', function (): void {
    [
        'owner' => $owner,
        'workspace' => $workspace,
        'account' => $account,
    ] = makeWebIntelligenceFixture();
    $member = User::factory()->withoutWorkspace()->create();
    $workspace->memberships()->create([
        'user_id' => $member->id,
        'role' => 'member',
    ]);

    $session = [User::ACTIVE_WORKSPACE_SESSION_KEY => $workspace->id];

    $this->actingAs($member)
        ->withSession($session)
        ->get(route('accounts.intelligence.show', $account))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounts/Intelligence')
            ->where('account.id', $account->id)
            ->where('isOwner', false)
            ->where('canWrite', false));

    $this->actingAs($member)
        ->withSession($session)
        ->postJson(route('accounts.intelligence.research-runs.store', $account), [
            'idempotency_key' => 'member-mutation',
        ])
        ->assertForbidden();

    expect($owner->id)->not->toBe($member->id);
});

it('does not resolve an Account or run from another workspace', function (): void {
    [
        'owner' => $owner,
        'account' => $account,
    ] = makeWebIntelligenceFixture();
    [
        'owner' => $foreignOwner,
        'workspace' => $foreignWorkspace,
        'account' => $foreignAccount,
    ] = makeWebIntelligenceFixture();

    $this->actingAs($owner)
        ->get(route('accounts.intelligence.show', $foreignAccount))
        ->assertNotFound();

    $preview = app(PreflightAccountResearch::class)->handle($foreignWorkspace, $foreignAccount);
    $foreignRun = app(StartAccountResearch::class)->start(new StartAccountResearchRequest(
        workspace: $foreignWorkspace,
        account: $foreignAccount,
        actor: $foreignOwner,
        idempotencyKey: 'foreign-browser-run',
        maximumCostMicros: $preview->maximumCostMicros,
        preflightHash: $preview->inputHash,
    ));

    $this->actingAs($owner)
        ->postJson(route('account-research-runs.cancel', $foreignRun), [])
        ->assertNotFound();

    expect($account->workspace_id)->not->toBe($foreignAccount->workspace_id);
});

it('starts an awaiting-provider run idempotently through the browser session route', function (): void {
    [
        'owner' => $owner,
        'workspace' => $workspace,
        'account' => $account,
    ] = makeWebIntelligenceFixture();
    $preview = app(PreflightAccountResearch::class)->handle($workspace, $account);
    $payload = [
        'idempotency_key' => 'browser-idempotent-run',
        'preflight_hash' => $preview->inputHash,
        'maximum_cost_micros' => $preview->maximumCostMicros,
    ];

    $first = $this->actingAs($owner)
        ->postJson(route('accounts.intelligence.research-runs.store', $account), $payload)
        ->assertOk()
        ->json('run');
    $second = $this->actingAs($owner)
        ->postJson(route('accounts.intelligence.research-runs.store', $account), $payload)
        ->assertOk()
        ->json('run');

    expect($first['id'])->toBe($second['id'])
        ->and($first['account_id'])->toBe($account->id)
        ->and($first['state'])->toBe('awaiting_provider')
        ->and(AccountResearchRun::query()
            ->where('workspace_id', $workspace->id)
            ->where('account_id', $account->id)
            ->count())->toBe(1);
});

it('keeps the superseded Claim visible after an owner correction', function (): void {
    [
        'owner' => $owner,
        'workspace' => $workspace,
        'account' => $account,
    ] = makeWebIntelligenceFixture();
    $run = AccountResearchRun::factory()->create([
        'workspace_id' => $workspace->id,
        'account_id' => $account->id,
        'state' => 'completed',
        'current_stage' => null,
        'finished_at' => now(),
    ]);
    $claim = ResearchClaim::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'account_id' => $account->id,
        'research_run_id' => $run->id,
        'predicate' => 'company.positioning',
        'value_type' => 'string',
        'value_json' => 'Conflicting positioning',
        'value_hash' => hash('sha256', 'Conflicting positioning'),
        'status' => 'conflicting',
        'confidence' => 0.5,
        'extractor_kind' => 'fixture',
        'extractor_version' => '1',
        'safe_for_message' => false,
        'content_hash' => hash('sha256', 'claim-history-original'),
    ]);

    $this->actingAs($owner)
        ->postJson(route('research-claims.corrections', $claim), [
            'value' => 'Owner-confirmed positioning',
            'reason' => 'Owner resolved the conflicting public wording.',
        ])
        ->assertOk();

    $this->actingAs($owner)
        ->get(route('accounts.intelligence.show', ['account' => $account, 'run' => $run->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('intelligence.by_status.owner_confirmed.0.value', 'Owner-confirmed positioning')
            ->where('intelligence.by_status.owner_confirmed.0.history.0.claim_id', $claim->id)
            ->where('intelligence.by_status.owner_confirmed.0.history.0.value', 'Conflicting positioning')
            ->where('intelligence.by_status.owner_confirmed.0.history.0.reason', 'Owner resolved the conflicting public wording.'));
});
