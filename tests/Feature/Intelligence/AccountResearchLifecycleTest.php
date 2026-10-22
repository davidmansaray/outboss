<?php

use App\Domain\Ai\Enums\AiCredentialStatus;
use App\Domain\Ai\Enums\AiOperationStatus;
use App\Domain\Ai\Enums\AiProviderName;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Exceptions\AiBudgetExceeded;
use App\Domain\Ai\Services\AiBudgetGateway;
use App\Domain\Ai\Services\AiWorkspaceSettings;
use App\Domain\Intelligence\DTOs\StartAccountResearchRequest;
use App\Domain\Intelligence\Enums\AccountResearchRunState;
use App\Domain\Intelligence\Exceptions\AccountResearchBusy;
use App\Domain\Intelligence\Exceptions\AccountResearchPaused;
use App\Domain\Intelligence\Services\AccountResearchStageService;
use App\Domain\Intelligence\Services\CancelAccountResearch;
use App\Domain\Intelligence\Services\CompleteAccountResearchRun;
use App\Domain\Intelligence\Services\EvaluateAccountQualification;
use App\Domain\Intelligence\Services\ExtractAndValidateClaims;
use App\Domain\Intelligence\Services\FinalizeAccountResearchBudgets;
use App\Domain\Intelligence\Services\PersistResearchSnapshots;
use App\Domain\Intelligence\Services\PreflightAccountResearch;
use App\Domain\Intelligence\Services\RecoverAccountResearchRuns;
use App\Domain\Intelligence\Services\RunAccountResearchStage;
use App\Domain\Intelligence\Services\StartAccountResearch;
use App\Models\Account;
use App\Models\AccountResearchAttempt;
use App\Models\AccountResearchDispatch;
use App\Models\AccountResearchRun;
use App\Models\AiBudgetRun;
use App\Models\AiProviderCredential;
use App\Models\AiProviderCredentialVersion;
use App\Models\AiProviderOperation;
use App\Models\QualificationDecision;
use App\Models\ResearchClaim;
use App\Models\ResearchClaimEvidence;
use App\Models\ResearchSourceSnapshot;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;

/** @return array{0:User,1:Workspace,2:Account} */
function makeS2Fixture(): array
{
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $account = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Acme Software',
        'website' => 'https://stripe.com',
        'domain_normalized' => 'stripe.com',
        'created_by_user_id' => $user->id,
    ]);

    return [$user, $workspace->fresh(), $account->fresh()];
}

/** @return array{0:AiProviderCredential,1:AiProviderCredentialVersion} */
function makeS2TavilyCredential(Workspace $workspace): array
{
    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);
    $credential = AiProviderCredential::query()->create([
        'workspace_id' => $workspace->id,
        'capability' => 'research',
        'provider' => AiProviderName::Tavily,
        'status' => AiCredentialStatus::Healthy,
    ]);
    $version = $credential->versions()->create([
        'version' => 1,
        'encrypted_secret' => 'tvly-lifecycle-test',
        'status' => 'active',
    ]);
    $credential->forceFill(['active_version_id' => $version->id])->save();

    return [$credential->fresh(), $version->fresh()];
}

it('makes Account intelligence available without rollout configuration', function () {
    [, $workspace, $account] = makeS2Fixture();

    $preview = app(PreflightAccountResearch::class)->handle($workspace, $account);

    expect($preview->account->is($account))->toBeTrue()
        ->and($preview->accountSnapshot['name'])->toBe('Acme Software');
});

it('pauses only new research while keeping preflight available', function (): void {
    [$user, $workspace, $account] = makeS2Fixture();
    config()->set('intelligence.research.pause_new', true);

    $preflight = app(PreflightAccountResearch::class)->handle(
        $workspace,
        $account,
    );

    expect($preflight->account->is($account))->toBeTrue();

    app(StartAccountResearch::class)->handle(
        $workspace,
        $account,
        $user,
        'paused-research-run',
    );
})->throws(AccountResearchPaused::class);

it('freezes one Account into an idempotent awaiting-provider run without a paid call', function () {
    [$user, $workspace, $account] = makeS2Fixture();
    $preview = app(PreflightAccountResearch::class)->handle($workspace, $account);
    $request = new StartAccountResearchRequest(
        workspace: $workspace,
        account: $account,
        actor: $user,
        idempotencyKey: 'account-run-1',
        maximumCostMicros: $preview->maximumCostMicros,
        preflightHash: $preview->inputHash,
    );

    $run = app(StartAccountResearch::class)->handle($request);
    $replay = app(StartAccountResearch::class)->handle($request);

    expect($run->state)->toBe(AccountResearchRunState::AwaitingProvider)
        ->and($run->account_id)->toBe($account->id)
        ->and($run->stages)->toHaveCount(6)
        ->and($run->dispatches)->toHaveCount(1)
        ->and($run->budget_envelope_id)->not->toBeNull()
        ->and($run->research_budget_run_id)->not->toBeNull()
        ->and($run->qualification_budget_run_id)->not->toBeNull()
        ->and($replay->id)->toBe($run->id)
        ->and(AccountResearchRun::query()->count())->toBe(1)
        ->and(AiBudgetRun::query()->where('budget_envelope_id', $run->budget_envelope_id)->count())->toBe(2)
        ->and(AiProviderOperation::query()->count())->toBe(0);
});

it('keeps a budget-delayed provider retry inside the original S2 envelope', function (): void {
    [$user, $workspace, $account] = makeS2Fixture();
    [$credential, $version] = makeS2TavilyCredential($workspace);
    DB::table('ai_budget_settings')->where('workspace_id', $workspace->id)->update([
        'monthly_limit_micros' => 1,
        'updated_at' => now(),
    ]);
    $capabilities = ['research.search'];
    $preview = app(PreflightAccountResearch::class)->handle($workspace, $account, capabilities: $capabilities);

    $run = app(StartAccountResearch::class)->handle(
        $workspace,
        $account,
        $user,
        'budget-delayed-envelope',
        capabilities: $capabilities,
        maximumCostMicros: $preview->maximumCostMicros,
        preflightHash: $preview->inputHash,
    );
    expect($run->state)->toBe(AccountResearchRunState::AwaitingBudget)
        ->and($run->budget_envelope_id)->not->toBeNull()
        ->and($run->researchBudgetRun()->firstOrFail()->task)->toBe(AiTask::AccountResearch)
        ->and($run->qualificationBudgetRun()->firstOrFail()->task)->toBe(AiTask::Qualification)
        ->and($run->researchBudgetRun()->firstOrFail()->budget_envelope_id)->toBe($run->budget_envelope_id)
        ->and($run->qualificationBudgetRun()->firstOrFail()->budget_envelope_id)->toBe($run->budget_envelope_id);

    expect(fn () => app(AiBudgetGateway::class)->reserve(
        $workspace,
        $credential,
        $version,
        $user,
        AiTask::AccountResearch,
        'search',
        'tavily/search',
        1,
        1_000,
        'budget-delayed-envelope-provider-call',
        hash('sha256', 'budget-delayed-envelope-provider-request'),
        $run->research_budget_run_id,
    ))->toThrow(AiBudgetExceeded::class);

    $stage = $run->stages()->where('stage_key', 'targeted_search')->firstOrFail();
    $stage->forceFill(['missing_facts_snapshot' => ['company.category']])->save();
    foreach (range(1, 5) as $_) {
        app(RunAccountResearchStage::class)->handle($workspace->id, $run->id, $stage->id);
    }

    expect($run->fresh()->state)->toBe(AccountResearchRunState::AwaitingBudget)
        ->and($run->fresh()->budget_envelope_id)->toBe($run->budget_envelope_id)
        ->and(AiBudgetRun::query()->where('budget_envelope_id', $run->budget_envelope_id)->count())->toBe(2)
        ->and(AiBudgetRun::query()->where('workspace_id', $workspace->id)->whereNull('budget_envelope_id')->count())->toBe(0)
        ->and(AiProviderOperation::query()->where('workspace_id', $workspace->id)->count())->toBe(0)
        ->and(AccountResearchAttempt::query()->where('stage_id', $stage->id)->count())->toBe(1)
        ->and(AccountResearchAttempt::query()->where('stage_id', $stage->id)->sole()->state->value)->toBe('waiting_budget')
        ->and($stage->fresh()->state->value)->toBe('pending');
});

it('resumes a re-admitted waiting run when its next stage lease is acquired', function (string $waitingState): void {
    [$user, $workspace, $account] = makeS2Fixture();
    $preview = app(PreflightAccountResearch::class)->handle($workspace, $account);
    $run = app(StartAccountResearch::class)->handle(
        $workspace,
        $account,
        $user,
        're-admitted-'.$waitingState,
        maximumCostMicros: $preview->maximumCostMicros,
        preflightHash: $preview->inputHash,
    );
    $run->forceFill(['state' => $waitingState, 'awaiting_reason' => str_replace('awaiting_', '', $waitingState)])->save();
    $stage = $run->stages()->where('stage_key', 'eligibility')->firstOrFail();

    $begun = app(AccountResearchStageService::class)->begin(
        $run->fresh(),
        $stage,
        hash('sha256', 're-admitted-'.$waitingState),
    );

    expect($begun['attempt'])->toBeInstanceOf(AccountResearchAttempt::class)
        ->and($run->fresh()->state)->toBe(AccountResearchRunState::Running)
        ->and($run->fresh()->awaiting_reason)->toBeNull();
})->with([
    AccountResearchRunState::AwaitingProvider->value,
    AccountResearchRunState::AwaitingBudget->value,
]);

it('does not resolve an Account from another workspace', function () {
    [$user, $workspace] = makeS2Fixture();
    [, , $otherAccount] = makeS2Fixture();

    expect(fn () => app(PreflightAccountResearch::class)->handle($workspace, $otherAccount))
        ->toThrow(ModelNotFoundException::class);
});

it('does not let a stale worker skip a newer Account research attempt', function (): void {
    [$user, $workspace, $account] = makeS2Fixture();
    $capabilities = ['research.search'];
    $preview = app(PreflightAccountResearch::class)->handle($workspace, $account, capabilities: $capabilities);
    $run = app(StartAccountResearch::class)->handle(
        $workspace,
        $account,
        $user,
        'skip-fence-race',
        capabilities: $capabilities,
        maximumCostMicros: $preview->maximumCostMicros,
        preflightHash: $preview->inputHash,
    );
    $stage = $run->stages()->where('stage_key', 'targeted_search')->firstOrFail();
    $stages = app(AccountResearchStageService::class);
    $inputHash = hash('sha256', 'skip-fence-race-input');

    $oldAttempt = $stages->begin($run, $stage, $inputHash)['attempt'];
    expect($oldAttempt)->toBeInstanceOf(AccountResearchAttempt::class);
    $oldAttempt->forceFill(['lease_expires_at' => now()->subSecond()])->save();

    $newAttempt = $stages->begin($run->fresh(), $stage->fresh(), $inputHash)['attempt'];
    expect($newAttempt)->toBeInstanceOf(AccountResearchAttempt::class)
        ->and($newAttempt->attempt_number)->toBe(2)
        ->and($newAttempt->fencing_token)->toBeGreaterThan($oldAttempt->fencing_token);
    expect(DB::table('account_research_attempts')->where('id', $oldAttempt->id)->value('state'))->toBe('definitely_not_sent')
        ->and(DB::table('account_research_attempts')->where('id', $oldAttempt->id)->value('safe_error_code'))->toBe('execution_lease_expired');

    expect(fn () => $stages->skip(
        $run->fresh(),
        $stage->fresh(),
        $oldAttempt->fresh(),
        ['reason' => 'website_evidence_satisfies_named_facts'],
    ))->toThrow(AccountResearchBusy::class);

    expect(DB::table('account_research_stages')->where('id', $stage->id)->value('state'))->toBe('running')
        ->and(DB::table('account_research_attempts')->where('id', $oldAttempt->id)->value('state'))->toBe('definitely_not_sent')
        ->and(DB::table('account_research_attempts')->where('id', $newAttempt->id)->value('state'))->toBe('running');

    $stages->skip(
        $run->fresh(),
        $stage->fresh(),
        $newAttempt->fresh(),
        ['reason' => 'website_evidence_satisfies_named_facts'],
    );

    expect(DB::table('account_research_stages')->where('id', $stage->id)->value('state'))->toBe('skipped')
        ->and(DB::table('account_research_attempts')->where('id', $oldAttempt->id)->value('state'))->toBe('definitely_not_sent')
        ->and(DB::table('account_research_attempts')->where('id', $newAttempt->id)->value('state'))->toBe('succeeded');
});

it('recovers an orphan provider operation by deterministic key without allocating a replacement attempt', function (): void {
    [$user, $workspace, $account] = makeS2Fixture();
    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);
    $credential = AiProviderCredential::query()->create([
        'workspace_id' => $workspace->id,
        'capability' => 'research',
        'provider' => AiProviderName::Tavily,
        'status' => AiCredentialStatus::Healthy,
    ]);
    $version = $credential->versions()->create([
        'version' => 1,
        'encrypted_secret' => 'tvly-orphan-operation-test',
        'status' => 'active',
    ]);
    $credential->forceFill(['active_version_id' => $version->id])->save();

    $capabilities = ['research.search'];
    $preview = app(PreflightAccountResearch::class)->handle($workspace, $account, capabilities: $capabilities);
    $run = app(StartAccountResearch::class)->handle(
        $workspace,
        $account,
        $user,
        'orphan-provider-operation-run',
        capabilities: $capabilities,
        maximumCostMicros: $preview->maximumCostMicros,
        preflightHash: $preview->inputHash,
    );
    $stage = $run->stages()->where('stage_key', 'targeted_search')->firstOrFail();
    $stages = app(AccountResearchStageService::class);
    $attempt = $stages->begin($run, $stage, hash('sha256', 'orphan-provider-operation-input'))['attempt'];
    expect($attempt)->toBeInstanceOf(AccountResearchAttempt::class);
    $attempt = $stages->claimProviderBoundary($attempt);

    $operation = app(AiBudgetGateway::class)->reserve(
        $workspace,
        $credential,
        $version,
        $user,
        AiTask::AccountResearch,
        'search',
        'tavily/search',
        1,
        1_000,
        $attempt->deterministic_idempotency_key,
        hash('sha256', 'orphan-provider-operation-request'),
        $run->research_budget_run_id,
    );
    $operation->forceFill([
        'status' => AiOperationStatus::Executing,
        'lease_owner' => 'orphan-provider-worker',
        'fencing_token' => 1,
        'lease_expires_at' => now()->addMinute(),
        'started_at' => now(),
    ])->save();
    $attempt->forceFill(['lease_expires_at' => now()->subSecond()])->save();
    $stage->forceFill(['lease_expires_at' => now()->subSecond()])->save();

    expect(fn () => $stages->begin($run->fresh(), $stage->fresh(), hash('sha256', 'orphan-provider-operation-input')))
        ->toThrow(AccountResearchBusy::class);

    expect(AccountResearchAttempt::query()->where('stage_id', $stage->id)->count())->toBe(1)
        ->and(AiProviderOperation::query()->where('workspace_id', $workspace->id)->count())->toBe(1)
        ->and($attempt->fresh()->provider_operation_id)->toBe($operation->id)
        ->and($attempt->fresh()->state->value)->toBe('ambiguous')
        ->and($stage->fresh()->safe_error_code)->toBe('provider_uncertain');
});

it('does not let stale failure cleanup clobber a newer leased attempt', function (): void {
    [$user, $workspace, $account] = makeS2Fixture();
    $capabilities = ['research.search'];
    $preview = app(PreflightAccountResearch::class)->handle($workspace, $account, capabilities: $capabilities);
    $run = app(StartAccountResearch::class)->handle(
        $workspace,
        $account,
        $user,
        'failure-fence-race',
        capabilities: $capabilities,
        maximumCostMicros: $preview->maximumCostMicros,
        preflightHash: $preview->inputHash,
    );
    $stage = $run->stages()->where('stage_key', 'targeted_search')->firstOrFail();
    $stages = app(AccountResearchStageService::class);
    $inputHash = hash('sha256', 'failure-fence-race-input');
    $oldAttempt = $stages->begin($run, $stage, $inputHash)['attempt'];
    expect($oldAttempt)->toBeInstanceOf(AccountResearchAttempt::class);
    $oldAttempt->forceFill(['lease_expires_at' => now()->subSecond()])->save();
    $newAttempt = $stages->begin($run->fresh(), $stage->fresh(), $inputHash)['attempt'];
    expect($newAttempt)->toBeInstanceOf(AccountResearchAttempt::class);

    expect($stages->fail($oldAttempt->fresh(), 'research_timed_out'))->toBeFalse();
    app(RunAccountResearchStage::class)->failById($workspace->id, $run->id, $stage->id, 'research_timed_out');

    expect($newAttempt->fresh()->state->value)->toBe('running')
        ->and($stage->fresh()->state->value)->toBe('running')
        ->and($run->fresh()->state)->not->toBe(AccountResearchRunState::Failed);
});

it('does not let late failure cleanup rewrite a completed research run', function (): void {
    [, $workspace, $account] = makeS2Fixture();
    $preview = app(PreflightAccountResearch::class)->handle($workspace, $account, capabilities: ['research.search']);
    $run = app(StartAccountResearch::class)->handle(
        $workspace,
        $account,
        null,
        'completed-run-timeout',
        capabilities: ['research.search'],
        maximumCostMicros: $preview->maximumCostMicros,
        preflightHash: $preview->inputHash,
    );
    $stage = $run->stages()->where('stage_key', 'targeted_search')->firstOrFail();
    $run->forceFill(['state' => AccountResearchRunState::Completed, 'finished_at' => now()])->save();

    app(RunAccountResearchStage::class)->failById($workspace->id, $run->id, $stage->id, 'research_timed_out');

    expect($run->fresh()->state)->toBe(AccountResearchRunState::Completed)
        ->and($run->fresh()->safe_failure_code)->toBeNull()
        ->and($stage->fresh()->state->value)->toBe('pending');
});

it('rolls back provider snapshots when the research fence changes during persistence', function (): void {
    [$user, $workspace, $account] = makeS2Fixture();
    $capabilities = ['research.extract'];
    $preview = app(PreflightAccountResearch::class)->handle($workspace, $account, capabilities: $capabilities);
    $run = app(StartAccountResearch::class)->handle(
        $workspace,
        $account,
        $user,
        'snapshot-persistence-fence',
        capabilities: $capabilities,
        maximumCostMicros: $preview->maximumCostMicros,
        preflightHash: $preview->inputHash,
    );
    $stage = $run->stages()->where('stage_key', 'website_retrieval')->firstOrFail();
    $stages = app(AccountResearchStageService::class);
    $attempt = $stages->begin($run, $stage, hash('sha256', 'snapshot-persistence-fence'))['attempt'];
    expect($attempt)->toBeInstanceOf(AccountResearchAttempt::class);
    $attempt = $stages->claimProviderBoundary($attempt);
    $originalFence = (int) $attempt->fencing_token;

    expect(fn () => $stages->persistAndCheckpoint($attempt, function () use ($workspace, $run, $stage, $originalFence): array {
        $snapshots = app(PersistResearchSnapshots::class)->handle($workspace, $run, $stage, [[
            'source_type' => 'website',
            'url' => 'https://stripe.com/about',
            'content' => 'This stale provider result must roll back.',
            'result_identity' => 'snapshot-persistence-fence-result',
        ]]);
        DB::table('account_research_stages')->where('id', $stage->id)->update([
            'fencing_token' => $originalFence + 1,
            'updated_at' => now(),
        ]);

        return ['snapshot_ids' => array_map(static fn ($snapshot): string => (string) $snapshot->id, $snapshots)];
    }))->toThrow(AccountResearchBusy::class);

    expect(ResearchSourceSnapshot::query()->where('research_run_id', $run->id)->count())->toBe(0)
        ->and((int) $stage->fresh()->fencing_token)->toBe($originalFence)
        ->and($stage->fresh()->state->value)->toBe('running')
        ->and($attempt->fresh()->state->value)->toBe('provider_boundary');
});

it('rolls back provider claims and evidence when the research fence changes during persistence', function (): void {
    [$user, $workspace, $account] = makeS2Fixture();
    $capabilities = ['llm.structured_generate'];
    $preview = app(PreflightAccountResearch::class)->handle($workspace, $account, capabilities: $capabilities);
    $run = app(StartAccountResearch::class)->handle(
        $workspace,
        $account,
        $user,
        'claim-persistence-fence',
        capabilities: $capabilities,
        maximumCostMicros: $preview->maximumCostMicros,
        preflightHash: $preview->inputHash,
    );
    $stage = $run->stages()->where('stage_key', 'claim_synthesis')->firstOrFail();
    $source = app(PersistResearchSnapshots::class)->handle($workspace, $run, $stage, [[
        'source_type' => 'website',
        'url' => 'https://stripe.com/about',
        'content' => 'Acme builds workflow software.',
        'result_identity' => 'claim-persistence-fence-source',
    ]])[0];
    $stages = app(AccountResearchStageService::class);
    $attempt = $stages->begin($run, $stage, hash('sha256', 'claim-persistence-fence'))['attempt'];
    expect($attempt)->toBeInstanceOf(AccountResearchAttempt::class);
    $attempt = $stages->claimProviderBoundary($attempt);
    $originalFence = (int) $attempt->fencing_token;

    expect(fn () => $stages->persistAndCheckpoint($attempt, function () use ($workspace, $run, $stage, $source, $originalFence): array {
        $claims = app(ExtractAndValidateClaims::class)->handle($workspace, $run, $stage, [[
            'predicate' => 'company.category',
            'value' => 'workflow software',
            'value_type' => 'string',
            'confidence' => 0.9,
            'evidence' => [['snapshot_id' => (string) $source->id]],
        ]], [$source]);
        DB::table('account_research_stages')->where('id', $stage->id)->update([
            'fencing_token' => $originalFence + 1,
            'updated_at' => now(),
        ]);

        return ['claim_ids' => array_map(static fn ($claim): string => (string) $claim->id, $claims)];
    }))->toThrow(AccountResearchBusy::class);

    expect(ResearchSourceSnapshot::query()->where('research_run_id', $run->id)->count())->toBe(1)
        ->and(ResearchClaim::query()->where('research_run_id', $run->id)->count())->toBe(0)
        ->and(ResearchClaimEvidence::query()->where('workspace_id', $workspace->id)->count())->toBe(0)
        ->and((int) $stage->fresh()->fencing_token)->toBe($originalFence)
        ->and($stage->fresh()->state->value)->toBe('running')
        ->and($attempt->fresh()->state->value)->toBe('provider_boundary');
});

it('rolls back qualification decisions when the local research fence changes during persistence', function (): void {
    [$user, $workspace, $account] = makeS2Fixture();
    $capabilities = ['research.search'];
    $preview = app(PreflightAccountResearch::class)->handle($workspace, $account, capabilities: $capabilities);
    $run = app(StartAccountResearch::class)->handle(
        $workspace,
        $account,
        $user,
        'qualification-persistence-fence',
        capabilities: $capabilities,
        maximumCostMicros: $preview->maximumCostMicros,
        preflightHash: $preview->inputHash,
    );
    $stage = $run->stages()->where('stage_key', 'qualification')->firstOrFail();
    $stages = app(AccountResearchStageService::class);
    $attempt = $stages->begin($run, $stage, hash('sha256', 'qualification-persistence-fence'))['attempt'];
    expect($attempt)->toBeInstanceOf(AccountResearchAttempt::class);
    $originalFence = (int) $attempt->fencing_token;

    expect(fn () => $stages->persistLocalAndCheckpoint($attempt, function () use ($workspace, $run, $stage, $originalFence): array {
        $decision = app(EvaluateAccountQualification::class)->handle($workspace, $run);
        DB::table('account_research_stages')->where('id', $stage->id)->update([
            'fencing_token' => $originalFence + 1,
            'updated_at' => now(),
        ]);

        return ['decision_id' => (string) $decision->id, 'outcome' => $decision->outcome->value];
    }))->toThrow(AccountResearchBusy::class);

    expect(QualificationDecision::query()->where('research_run_id', $run->id)->count())->toBe(0)
        ->and((int) $stage->fresh()->fencing_token)->toBe($originalFence)
        ->and($stage->fresh()->state->value)->toBe('running')
        ->and($attempt->fresh()->state->value)->toBe('running');
});

it('reclaims an orphan reserved operation with the same attempt and provider key', function (): void {
    [$user, $workspace, $account] = makeS2Fixture();
    [$credential, $version] = makeS2TavilyCredential($workspace);
    $capabilities = ['research.search'];
    $preview = app(PreflightAccountResearch::class)->handle($workspace, $account, capabilities: $capabilities);
    $run = app(StartAccountResearch::class)->handle(
        $workspace,
        $account,
        $user,
        'reserved-operation-recovery',
        capabilities: $capabilities,
        maximumCostMicros: $preview->maximumCostMicros,
        preflightHash: $preview->inputHash,
    );
    $stage = $run->stages()->where('stage_key', 'targeted_search')->firstOrFail();
    $stages = app(AccountResearchStageService::class);
    $attempt = $stages->begin($run, $stage, hash('sha256', 'reserved-operation-recovery'))['attempt'];
    expect($attempt)->toBeInstanceOf(AccountResearchAttempt::class);
    $attempt = $stages->claimProviderBoundary($attempt);
    $originalFence = (int) $attempt->fencing_token;
    $operation = app(AiBudgetGateway::class)->reserve(
        $workspace,
        $credential,
        $version,
        $user,
        AiTask::AccountResearch,
        'search',
        'tavily/search',
        1,
        1_000,
        $attempt->deterministic_idempotency_key,
        hash('sha256', 'reserved-operation-recovery-request'),
        $run->research_budget_run_id,
    );
    $attempt->forceFill(['lease_expires_at' => now()->subSecond()])->save();
    $stage->forceFill(['lease_expires_at' => now()->subSecond()])->save();

    $reclaimed = $stages->begin($run->fresh(), $stage->fresh(), hash('sha256', 'reserved-operation-recovery'))['attempt'];

    expect($reclaimed)->toBeInstanceOf(AccountResearchAttempt::class)
        ->and($reclaimed->id)->toBe($attempt->id)
        ->and($reclaimed->deterministic_idempotency_key)->toBe($operation->idempotency_key)
        ->and($reclaimed->provider_operation_id)->toBe($operation->id)
        ->and($reclaimed->state->value)->toBe('running')
        ->and($reclaimed->fencing_token)->toBeGreaterThan($originalFence)
        ->and(AccountResearchAttempt::query()->where('stage_id', $stage->id)->count())->toBe(1)
        ->and(AiProviderOperation::query()->where('workspace_id', $workspace->id)->count())->toBe(1)
        ->and($operation->fresh()->status)->toBe(AiOperationStatus::Reserved)
        ->and($run->fresh()->state)->toBe(AccountResearchRunState::Running);
});

it('does not reset a published dispatch while its current stage attempt is fresh', function (): void {
    [, $workspace, $account] = makeS2Fixture();
    $run = AccountResearchRun::factory()->create([
        'workspace_id' => $workspace->id,
        'account_id' => $account->id,
        'state' => AccountResearchRunState::Running,
        'current_stage' => 'targeted_search',
    ]);
    $stage = $run->stages()->create([
        'workspace_id' => $workspace->id,
        'stage_order' => 4,
        'stage_key' => 'targeted_search',
        'state' => 'running',
        'entry_predicate_snapshot' => [],
        'missing_facts_snapshot' => [],
        'expected_cost_micros' => 1_000,
        'maximum_cost_micros' => 1_000,
        'implementation_version' => 1,
        'lease_owner' => 'active-stage-worker',
        'fencing_token' => 1,
        'lease_expires_at' => now()->addMinutes(5),
        'started_at' => now(),
    ]);
    $attempt = $stage->attempts()->create([
        'workspace_id' => $workspace->id,
        'research_run_id' => $run->id,
        'attempt_number' => 1,
        'deterministic_idempotency_key' => (string) Str::uuid(),
        'input_hash' => hash('sha256', 'fresh-published-dispatch'),
        'state' => 'running',
        'lease_owner' => 'active-stage-worker',
        'fencing_token' => 1,
        'lease_expires_at' => now()->addMinutes(5),
        'started_at' => now(),
    ]);
    $dispatch = $run->dispatches()->create([
        'workspace_id' => $workspace->id,
        'dispatch_kind' => 'stage',
        'stage_key' => 'targeted_search',
        'state' => 'published',
        'available_at' => now(),
        'published_at' => now(),
        'publish_attempts' => 1,
    ]);

    expect(app(RecoverAccountResearchRuns::class)->handle((int) $workspace->id))->toBe(0)
        ->and($dispatch->fresh()->state->value)->toBe('published')
        ->and($dispatch->fresh()->published_at)->not->toBeNull()
        ->and(AccountResearchAttempt::query()->where('stage_id', $stage->id)->count())->toBe(1)
        ->and($attempt->fresh()->state->value)->toBe('running');
});

it('waits for reconciliation and then classifies the lost paid result without replaying it', function (): void {
    [$user, $workspace, $account] = makeS2Fixture();
    [$credential, $version] = makeS2TavilyCredential($workspace);
    $capabilities = ['research.search'];
    $preview = app(PreflightAccountResearch::class)->handle($workspace, $account, capabilities: $capabilities);
    $run = app(StartAccountResearch::class)->handle(
        $workspace,
        $account,
        $user,
        'ambiguous-operation-recovery',
        capabilities: $capabilities,
        maximumCostMicros: $preview->maximumCostMicros,
        preflightHash: $preview->inputHash,
    );
    $stage = $run->stages()->where('stage_key', 'targeted_search')->firstOrFail();
    $dispatch = AccountResearchDispatch::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'research_run_id' => $run->id,
        'dispatch_kind' => 'stage',
        'stage_key' => 'targeted_search',
        'state' => 'published',
        'available_at' => now(),
        'published_at' => now(),
        'publish_lease_owner' => null,
        'publish_lease_expires_at' => null,
        'publish_attempts' => 1,
        'safe_error_code' => null,
    ]);
    $stages = app(AccountResearchStageService::class);
    $attempt = $stages->begin($run, $stage, hash('sha256', 'ambiguous-operation-recovery'))['attempt'];
    expect($attempt)->toBeInstanceOf(AccountResearchAttempt::class);
    $attempt = $stages->claimProviderBoundary($attempt);
    $operation = app(AiBudgetGateway::class)->reserve(
        $workspace,
        $credential,
        $version,
        $user,
        AiTask::AccountResearch,
        'search',
        'tavily/search',
        1,
        1_000,
        $attempt->deterministic_idempotency_key,
        hash('sha256', 'ambiguous-operation-recovery-request'),
        $run->research_budget_run_id,
    );
    $stages->attachProviderOperation($attempt, $operation->id);
    app(AiBudgetGateway::class)->markUncertain(
        $operation,
        'provider_timeout',
        'The provider outcome requires reconciliation.',
    );

    expect($stages->awaitProviderReconciliation($attempt))->toBeTrue()
        ->and($attempt->fresh()->state->value)->toBe('ambiguous')
        ->and($stage->fresh()->state->value)->toBe('running')
        ->and($run->fresh()->state)->toBe(AccountResearchRunState::AwaitingProvider)
        ->and(app(RecoverAccountResearchRuns::class)->handle((int) $workspace->id))->toBe(0)
        ->and($dispatch->fresh()->state->value)->toBe('published');

    app(AiBudgetGateway::class)->settle(
        $operation->fresh(),
        1_000,
        1,
        'provider_exact',
        ['native_unit' => 'credits', 'cost_evidence_source' => 'reconciliation'],
        AiOperationStatus::Reconciled,
    );

    expect(app(RecoverAccountResearchRuns::class)->handle((int) $workspace->id))->toBe(1)
        ->and($dispatch->fresh()->state->value)->toBe('pending')
        ->and($dispatch->fresh()->published_at)->toBeNull()
        ->and($run->dispatches()->where('stage_key', 'targeted_search')->count())->toBe(1);
    app(RunAccountResearchStage::class)->handle($workspace->id, $run->id, $stage->id);

    expect($operation->fresh()->status)->toBe(AiOperationStatus::Reconciled)
        ->and(AiProviderOperation::query()->where('workspace_id', $workspace->id)->count())->toBe(1)
        ->and($stage->fresh()->state->value)->toBe('paid_result_lost')
        ->and($run->fresh()->state)->toBe(AccountResearchRunState::Failed)
        ->and($run->fresh()->safe_failure_code)->toBe('paid_result_lost')
        ->and($run->researchBudgetRun()->firstOrFail()->status)->toBe('closed')
        ->and($run->budgetEnvelope()->firstOrFail()->state)->toBe('closed');
});

it('closes non-cancelled budget accounting when a stage exhausts its retries', function (): void {
    [, $workspace, $account] = makeS2Fixture();
    config()->set('intelligence.research.max_attempts', 1);
    $preview = app(PreflightAccountResearch::class)->handle($workspace, $account, capabilities: ['research.search']);
    $run = app(StartAccountResearch::class)->handle(
        $workspace,
        $account,
        null,
        'retry-exhaustion-accounting',
        capabilities: ['research.search'],
        maximumCostMicros: $preview->maximumCostMicros,
        preflightHash: $preview->inputHash,
    );
    $stage = $run->stages()->where('stage_key', 'targeted_search')->firstOrFail();
    $stages = app(AccountResearchStageService::class);
    $attempt = $stages->begin($run, $stage, hash('sha256', 'retry-exhaustion-accounting'))['attempt'];
    expect($attempt)->toBeInstanceOf(AccountResearchAttempt::class);
    $attempt->forceFill(['lease_expires_at' => now()->subSecond()])->save();
    $stage->forceFill(['lease_expires_at' => now()->subSecond()])->save();

    expect(fn () => $stages->begin($run->fresh(), $stage->fresh(), hash('sha256', 'retry-exhaustion-accounting')))
        ->toThrow(RuntimeException::class, 'exhausted');

    expect($run->fresh()->state)->toBe(AccountResearchRunState::Failed)
        ->and($run->researchBudgetRun()->firstOrFail()->status)->toBe('closed')
        ->and($run->researchBudgetRun()->firstOrFail()->closed_at)->not->toBeNull()
        ->and($run->budgetEnvelope()->firstOrFail()->state)->toBe('closed')
        ->and($run->budgetEnvelope()->firstOrFail()->closed_at)->not->toBeNull();
});

it('does not fence a reconciler that claims an expired executing operation', function (): void {
    [$user, $workspace, $account] = makeS2Fixture();
    [$credential, $version] = makeS2TavilyCredential($workspace);
    $gateway = app(AiBudgetGateway::class);
    $run = AccountResearchRun::factory()->create([
        'workspace_id' => $workspace->id,
        'account_id' => $account->id,
        'state' => AccountResearchRunState::Failed,
    ]);
    $envelope = $gateway->openEnvelope(
        $workspace,
        $user,
        'account_research_finalizer_race',
        (string) $run->id,
        100_000,
    );
    $budgetRun = $gateway->openRun($workspace, $user, AiTask::AccountResearch, $envelope);
    $run->forceFill([
        'budget_envelope_id' => $envelope->id,
        'research_budget_run_id' => $budgetRun->id,
    ])->save();
    $operation = $gateway->reserve(
        $workspace,
        $credential,
        $version,
        $user,
        AiTask::AccountResearch,
        'search',
        'tavily/search',
        1,
        1_000,
        'finalizer-race-operation',
        hash('sha256', 'finalizer-race-request'),
        $budgetRun->id,
    );
    $operation->forceFill([
        'status' => AiOperationStatus::Executing,
        'lease_owner' => 'expired-execution-worker',
        'fencing_token' => 7,
        'lease_expires_at' => now()->subMinute(),
        'started_at' => now()->subMinutes(2),
    ])->save();

    $realGateway = app(AiBudgetGateway::class);
    $finalizerGateway = Mockery::mock(AiBudgetGateway::class);
    $finalizerGateway->shouldReceive('markUncertain')
        ->once()
        ->andReturnUsing(function (
            AiProviderOperation $observed,
            string $safeCode,
            string $safeMessage,
            ?string $leaseOwner,
            ?int $fencingToken,
        ) use ($realGateway): AiProviderOperation {
            expect($leaseOwner)->toBe('expired-execution-worker')
                ->and($fencingToken)->toBe(7);
            DB::table('ai_provider_operations')
                ->where('id', $observed->id)
                ->update([
                    'status' => AiOperationStatus::Reconciling->value,
                    'lease_owner' => 'concurrent-reconciler',
                    'fencing_token' => 9,
                    'lease_expires_at' => now()->addMinutes(5),
                    'updated_at' => now(),
                ]);

            return $realGateway->markUncertain(
                $observed,
                $safeCode,
                $safeMessage,
                $leaseOwner,
                $fencingToken,
            );
        });

    expect((new FinalizeAccountResearchBudgets($finalizerGateway))->handle($run))->toBeFalse()
        ->and($operation->fresh()->status)->toBe(AiOperationStatus::Reconciling)
        ->and($operation->fresh()->lease_owner)->toBe('concurrent-reconciler')
        ->and($operation->fresh()->fencing_token)->toBe(9)
        ->and($operation->fresh()->safe_error_code)->toBeNull()
        ->and($budgetRun->fresh()->status)->toBe('open')
        ->and($budgetRun->fresh()->held_micros)->toBe(1_000);
});

it('does not complete a final-stage run after cancellation has started', function (): void {
    [, $workspace, $account] = makeS2Fixture();
    $preview = app(PreflightAccountResearch::class)->handle($workspace, $account, capabilities: ['research.search']);
    $run = app(StartAccountResearch::class)->handle(
        $workspace,
        $account,
        null,
        'final-stage-cancellation-race',
        capabilities: ['research.search'],
        maximumCostMicros: $preview->maximumCostMicros,
        preflightHash: $preview->inputHash,
    );
    $run->forceFill(['state' => AccountResearchRunState::Cancelling, 'cancelled_at' => now()])->save();

    app(CompleteAccountResearchRun::class)->handle($run);

    expect($run->fresh()->state)->toBe(AccountResearchRunState::Cancelling)
        ->and($run->fresh()->finished_at)->toBeNull()
        ->and($run->researchBudgetRun()->firstOrFail()->status)->toBe('open');
});

it('invalidates an admitted stage fence when research is cancelled', function (): void {
    [$user, $workspace, $account] = makeS2Fixture();
    [$credential, $version] = makeS2TavilyCredential($workspace);
    $capabilities = ['research.search'];
    $preview = app(PreflightAccountResearch::class)->handle($workspace, $account, capabilities: $capabilities);
    $run = app(StartAccountResearch::class)->handle(
        $workspace,
        $account,
        $user,
        'cancel-provider-boundary',
        capabilities: $capabilities,
        maximumCostMicros: $preview->maximumCostMicros,
        preflightHash: $preview->inputHash,
    );
    $stage = $run->stages()->where('stage_key', 'targeted_search')->firstOrFail();
    $stages = app(AccountResearchStageService::class);
    $attempt = $stages->begin($run, $stage, hash('sha256', 'cancel-provider-boundary'))['attempt'];
    expect($attempt)->toBeInstanceOf(AccountResearchAttempt::class);
    $attempt = $stages->claimProviderBoundary($attempt);
    $operation = app(AiBudgetGateway::class)->reserve(
        $workspace,
        $credential,
        $version,
        $user,
        AiTask::AccountResearch,
        'search',
        'tavily/search',
        1,
        1_000,
        $attempt->deterministic_idempotency_key,
        hash('sha256', 'cancel-provider-boundary-request'),
        $run->research_budget_run_id,
    );

    app(CancelAccountResearch::class)->handle($workspace, $run, $user);

    $budgetRun = $run->researchBudgetRun()->firstOrFail();
    $envelope = $budgetRun->envelope()->firstOrFail();

    expect(fn () => $stages->attachProviderOperation($attempt, $operation->id))->toThrow(AccountResearchBusy::class)
        ->and(fn () => $stages->claimProviderBoundary($attempt))->toThrow(AccountResearchBusy::class)
        ->and($run->fresh()->state)->toBe(AccountResearchRunState::Cancelled)
        ->and($stage->fresh()->state->value)->toBe('cancelled')
        ->and($attempt->fresh()->state->value)->toBe('cancelled')
        ->and($attempt->fresh()->lease_owner)->toBeNull()
        ->and($operation->fresh()->status)->toBe(AiOperationStatus::Cancelled)
        ->and($operation->fresh()->safe_error_code)->toBe('account_research_cancelled')
        ->and($budgetRun->fresh()->status)->toBe('cancelled')
        ->and($budgetRun->fresh()->held_micros)->toBe(0)
        ->and($envelope->fresh()->state)->toBe('closed')
        ->and($envelope->fresh()->held_micros)->toBe(0);
});

it('keeps cancellation pending until in-flight provider accounting is terminal', function (): void {
    [$user, $workspace, $account] = makeS2Fixture();
    [$credential, $version] = makeS2TavilyCredential($workspace);
    $capabilities = ['research.search'];
    $preview = app(PreflightAccountResearch::class)->handle($workspace, $account, capabilities: $capabilities);
    $run = app(StartAccountResearch::class)->handle(
        $workspace,
        $account,
        $user,
        'cancel-in-flight-operation',
        capabilities: $capabilities,
        maximumCostMicros: $preview->maximumCostMicros,
        preflightHash: $preview->inputHash,
    );
    $stage = $run->stages()->where('stage_key', 'targeted_search')->firstOrFail();
    $stages = app(AccountResearchStageService::class);
    $attempt = $stages->begin($run, $stage, hash('sha256', 'cancel-in-flight-operation'))['attempt'];
    expect($attempt)->toBeInstanceOf(AccountResearchAttempt::class);
    $attempt = $stages->claimProviderBoundary($attempt);
    $operation = app(AiBudgetGateway::class)->reserve(
        $workspace,
        $credential,
        $version,
        $user,
        AiTask::AccountResearch,
        'search',
        'tavily/search',
        1,
        1_000,
        $attempt->deterministic_idempotency_key,
        hash('sha256', 'cancel-in-flight-operation-request'),
        $run->research_budget_run_id,
    );
    $operation->forceFill([
        'status' => AiOperationStatus::Executing,
        'lease_owner' => 'in-flight-provider-worker',
        'fencing_token' => 1,
        'lease_expires_at' => now()->addMinute(),
        'started_at' => now(),
    ])->save();

    $cancelling = app(CancelAccountResearch::class)->handle($workspace, $run, $user);

    expect($cancelling->state)->toBe(AccountResearchRunState::Cancelling)
        ->and($cancelling->finished_at)->toBeNull()
        ->and($operation->fresh()->status)->toBe(AiOperationStatus::Executing)
        ->and($run->researchBudgetRun()->firstOrFail()->status)->toBe('open');
    expect($stages->awaitProviderReconciliation($attempt))->toBeFalse()
        ->and($run->fresh()->state)->toBe(AccountResearchRunState::Cancelling);
    $rejected = $stages->begin($run, $stage, hash('sha256', 'cancel-in-flight-operation'));
    expect($rejected['attempt'])->toBeNull()
        ->and(AccountResearchAttempt::query()->where('stage_id', $stage->id)->count())->toBe(1);

    $settled = app(AiBudgetGateway::class)->settleExact(
        $operation,
        1_000,
        1,
        'provider_exact',
        ['native_unit' => 'credits', 'cost_evidence_source' => 'response'],
        leaseOwner: 'in-flight-provider-worker',
        fencingToken: 1,
    );
    expect($settled)->not->toBeNull();

    expect(app(RecoverAccountResearchRuns::class)->handle((int) $workspace->id))->toBe(1)
        ->and($run->fresh()->state)->toBe(AccountResearchRunState::Cancelled)
        ->and($run->fresh()->finished_at)->not->toBeNull()
        ->and($run->researchBudgetRun()->firstOrFail()->status)->toBe('cancelled')
        ->and($run->researchBudgetRun()->firstOrFail()->held_micros)->toBe(0)
        ->and($run->researchBudgetRun()->firstOrFail()->committed_micros)->toBe(1_000)
        ->and($run->researchBudgetRun()->firstOrFail()->envelope()->firstOrFail()->state)->toBe('closed');
});

it('leaves a run recoverable when timeout cleanup sees no attempt', function (): void {
    [, $workspace, $account] = makeS2Fixture();
    $preview = app(PreflightAccountResearch::class)->handle($workspace, $account, capabilities: ['research.search']);
    $run = app(StartAccountResearch::class)->handle(
        $workspace,
        $account,
        null,
        'no-attempt-timeout',
        capabilities: ['research.search'],
        maximumCostMicros: $preview->maximumCostMicros,
        preflightHash: $preview->inputHash,
    );
    $stage = $run->stages()->where('stage_key', 'targeted_search')->firstOrFail();

    app(RunAccountResearchStage::class)->failById($workspace->id, $run->id, $stage->id, 'research_timed_out');

    expect(AccountResearchAttempt::query()->where('stage_id', $stage->id)->count())->toBe(0)
        ->and($run->fresh()->state)->not->toBe(AccountResearchRunState::Failed)
        ->and($stage->fresh()->state->value)->toBe('pending');
});
