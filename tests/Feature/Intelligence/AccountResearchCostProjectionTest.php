<?php

use App\Domain\Ai\Enums\AiCredentialStatus;
use App\Domain\Ai\Enums\AiProviderName;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Services\AiBudgetGateway;
use App\Domain\Ai\Services\AiWorkspaceSettings;
use App\Domain\Api\TokenScope;
use App\Domain\Intelligence\Services\PreflightAccountResearch;
use App\Domain\Intelligence\Services\StartAccountResearch;
use App\Models\Account;
use App\Models\AccountResearchRun;
use App\Models\AiProviderCredential;
use App\Models\AiProviderCredentialVersion;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{owner:User,workspace:Workspace,account:Account,run:AccountResearchRun,credential:AiProviderCredential,version:AiProviderCredentialVersion}
 */
function makeAccountResearchCostProjectionFixture(): array
{
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);

    $credential = AiProviderCredential::query()->create([
        'workspace_id' => $workspace->id,
        'capability' => 'research',
        'provider' => AiProviderName::Tavily,
        'status' => AiCredentialStatus::Healthy,
    ]);
    $version = $credential->versions()->create([
        'version' => 1,
        'encrypted_secret' => 'cost-projection-test-secret',
        'status' => 'active',
    ]);
    $credential->forceFill(['active_version_id' => $version->id])->save();

    $account = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Cost projection account',
        'website' => 'https://stripe.com',
        'domain_normalized' => 'stripe.com',
        'created_by_user_id' => $owner->id,
    ]);
    $preview = app(PreflightAccountResearch::class)->handle(
        $workspace,
        $account,
        capabilities: ['research.search'],
    );
    $run = app(StartAccountResearch::class)->handle(
        $workspace,
        $account,
        $owner,
        'cost-projection-run',
        capabilities: ['research.search'],
        maximumCostMicros: $preview->maximumCostMicros,
        preflightHash: $preview->inputHash,
    );

    return [
        'owner' => $owner,
        'workspace' => $workspace->fresh(),
        'account' => $account->fresh(),
        'run' => $run->fresh(),
        'credential' => $credential->fresh(),
        'version' => $version->fresh(),
    ];
}

it('projects linked provider reservation and settled cost through REST and browser read models', function (): void {
    [
        'owner' => $owner,
        'workspace' => $workspace,
        'account' => $account,
        'run' => $run,
        'credential' => $credential,
        'version' => $version,
    ] = makeAccountResearchCostProjectionFixture();

    $gateway = app(AiBudgetGateway::class);
    $operation = $gateway->reserve(
        $workspace,
        $credential,
        $version,
        $owner,
        AiTask::AccountResearch,
        'search',
        'tavily/search',
        1,
        12_000,
        'cost-projection-provider-operation',
        $gateway->fingerprint(['test' => 'cost-projection'], 'cost-projection-content'),
        $run->research_budget_run_id,
    );
    $gateway->settle($operation, 4_700, 1, 'provider_exact');

    $token = apiToken($owner, [TokenScope::IntelligenceRead->value]);
    $this->withToken($token)
        ->getJson('/api/v1/account-research-runs/'.$run->id)
        ->assertOk()
        ->assertJsonPath('data.id', $run->id)
        ->assertJsonPath('data.reserved_cost_micros', 12_000)
        ->assertJsonPath('data.actual_cost_micros', 4_700);

    $this->actingAs($owner)
        ->get(route('accounts.intelligence.show', [
            'account' => $account->id,
            'run' => $run->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('researchRun.id', $run->id)
            ->where('researchRun.reserved_cost_micros', 12_000)
            ->where('researchRun.actual_cost_micros', 4_700)
            ->where('researchRuns.0.id', $run->id)
            ->where('researchRuns.0.reserved_cost_micros', 12_000)
            ->where('researchRuns.0.actual_cost_micros', 4_700));
});
