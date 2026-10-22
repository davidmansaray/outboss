<?php

use App\Domain\Ai\DTOs\LlmResult;
use App\Domain\Ai\DTOs\ResearchExtractRequest;
use App\Domain\Ai\DTOs\ResearchResult;
use App\Domain\Ai\DTOs\ResearchSearchRequest;
use App\Domain\Ai\Enums\AiCredentialStatus;
use App\Domain\Ai\Enums\AiProviderName;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Providers\AiProviderRegistry;
use App\Domain\Ai\Providers\DeterministicOpenRouterAdapter;
use App\Domain\Ai\Providers\DeterministicTavilyAdapter;
use App\Domain\Ai\Services\AiCostEstimator;
use App\Domain\Ai\Services\AiWorkspaceSettings;
use App\Domain\Intelligence\Contracts\IntelligenceProviderBridge;
use App\Domain\Intelligence\Enums\AccountResearchStageKey;
use App\Domain\Intelligence\Enums\QualificationOutcome;
use App\Domain\Intelligence\Exceptions\QualificationValidationException;
use App\Domain\Intelligence\Services\EvaluateAccountQualification;
use App\Domain\Intelligence\Services\ExtractAndValidateClaims;
use App\Domain\Intelligence\Services\PersistResearchSnapshots;
use App\Domain\Intelligence\Services\PreflightAccountResearch;
use App\Domain\Intelligence\Services\ProjectAccountIntelligence;
use App\Domain\Intelligence\Services\RunAccountResearchStage;
use App\Domain\Intelligence\Services\StartAccountResearch;
use App\Models\Account;
use App\Models\AccountResearchAttempt;
use App\Models\AccountResearchRun;
use App\Models\AccountResearchStage;
use App\Models\AiBudgetSetting;
use App\Models\AiProviderCredential;
use App\Models\AiProviderOperation;
use App\Models\Campaign;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

function installS2DeterministicCredential(User $owner, AiProviderName $provider, string $fixtureId): void
{
    $credential = AiProviderCredential::query()->create([
        'workspace_id' => $owner->currentWorkspace()->id,
        'capability' => $provider->capability(),
        'provider' => $provider,
        'status' => AiCredentialStatus::Healthy,
        'last_tested_at' => now(),
        'last_healthy_at' => now(),
        'provider_balance_summary' => 'Deterministic browser fixture',
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
    ]);
    $version = $credential->versions()->create([
        'version' => 1,
        'encrypted_secret' => $fixtureId,
        'status' => 'active',
        'created_by_user_id' => $owner->id,
        'tested_at' => now(),
        'activated_at' => now(),
    ]);
    $credential->forceFill(['active_version_id' => $version->id])->save();
}

/** @return array{Workspace,AccountResearchRun,AccountResearchStage} */
function makeS2CleanQualificationRun(string $idempotencyKey, float $claimConfidence = 0.95): array
{
    config()->set('intelligence.testing.fake_providers', true);
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);
    installS2DeterministicCredential($owner, AiProviderName::OpenRouter, DeterministicOpenRouterAdapter::FIXTURE_ID);
    $account = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Clean qualification account',
        'website' => 'https://example.com',
        'domain_normalized' => 'example.com',
        'source' => Account::SOURCE_IMPORT,
    ]);
    $capabilities = ['llm.structured_generate'];
    $preflight = app(PreflightAccountResearch::class)->handle($workspace, $account, capabilities: $capabilities);
    expect($preflight->stages[array_key_last($preflight->stages)]['expected_cost_micros'])->toBeGreaterThan(0);
    $run = app(StartAccountResearch::class)->handle(
        $workspace,
        $account,
        $owner,
        $idempotencyKey,
        capabilities: $capabilities,
        maximumCostMicros: $preflight->maximumCostMicros,
        preflightHash: $preflight->inputHash,
    );
    $qualificationStage = $run->stages()->where('stage_key', 'qualification')->firstOrFail();
    $snapshot = app(PersistResearchSnapshots::class)->handle($workspace, $run, $qualificationStage, [[
        'source_type' => 'website',
        'url' => 'https://example.com/about',
        'content' => 'Clean qualification evidence.',
        'result_identity' => $idempotencyKey.'-evidence',
    ]])[0];
    app(ExtractAndValidateClaims::class)->handle($workspace, $run, $qualificationStage, [[
        'predicate' => 'company.category',
        'value' => 'workflow software',
        'value_type' => 'string',
        'status' => 'supported',
        'confidence' => $claimConfidence,
        'evidence' => [['snapshot_id' => (string) $snapshot->id]],
    ]], [$snapshot]);

    return [$workspace, $run, $qualificationStage];
}

it('runs the supplied Account through deterministic providers with durable evidence and no outreach', function () {
    config()->set('intelligence.testing.fake_providers', true);
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);
    installS2DeterministicCredential($owner, AiProviderName::Tavily, DeterministicTavilyAdapter::FIXTURE_ID);
    installS2DeterministicCredential($owner, AiProviderName::OpenRouter, DeterministicOpenRouterAdapter::FIXTURE_ID);
    $account = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'S2 Browser Account',
        'website' => 'https://example.com',
        'domain_normalized' => 'example.com',
        'source' => Account::SOURCE_IMPORT,
    ]);

    $preflight = app(PreflightAccountResearch::class)->handle($workspace, $account);
    expect($preflight->blocked)->toBeFalse();
    $run = app(StartAccountResearch::class)->handle(
        $workspace,
        $account,
        $owner,
        'deterministic-e2e-run',
        maximumCostMicros: $preflight->maximumCostMicros,
        preflightHash: $preflight->inputHash,
    );
    $replay = app(StartAccountResearch::class)->handle(
        $workspace,
        $account,
        $owner,
        'deterministic-e2e-run',
        maximumCostMicros: $preflight->maximumCostMicros,
        preflightHash: $preflight->inputHash,
    );
    expect($replay->id)->toBe($run->id);

    expect(Artisan::call('account-research:sweep', [
        '--workspace' => $workspace->id,
        '--passes' => 10,
        '--json' => true,
    ]))->toBe(0)
        ->and(json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)['published'])->toBe(6);

    $projection = app(ProjectAccountIntelligence::class)->handle($workspace, $account);
    $run->refresh();
    expect($run->state->value)->toBe('completed')
        ->and($run->stages()->where('state', 'succeeded')->count())->toBe(5)
        ->and($run->stages()->where('state', 'skipped')->count())->toBe(1)
        ->and(AccountResearchAttempt::query()->where('research_run_id', $run->id)->where('state', 'running')->count())->toBe(0)
        ->and(AiProviderOperation::query()->where('workspace_id', $workspace->id)->count())->toBe(2)
        ->and(AiProviderOperation::query()->where('workspace_id', $workspace->id)->where('task', AiTask::Qualification->value)->count())->toBe(0)
        ->and($run->qualificationBudgetRun()->firstOrFail()->status)->toBe('closed')
        ->and($projection['by_status']['supported'])->toHaveCount(1)
        ->and($projection['by_status']['inferred'])->toHaveCount(1)
        ->and($projection['by_status']['conflicting'])->toHaveCount(1)
        ->and($projection['by_status']['unsupported'])->toHaveCount(1)
        ->and($run->qualificationDecisions()->latest('created_at')->first()?->outcome?->value)->toBe('needs_review')
        ->and(Campaign::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0);
});

it('runs one clean qualification provider call against its task budget and settled cost', function (): void {
    [$workspace, $run, $qualificationStage] = makeS2CleanQualificationRun('clean-qualification-provider-run');

    app(RunAccountResearchStage::class)->handle($workspace->id, $run->id, $qualificationStage->id);

    $run->refresh();
    $operation = AiProviderOperation::query()
        ->where('workspace_id', $workspace->id)
        ->where('task', AiTask::Qualification->value)
        ->sole();
    $decision = $run->qualificationDecisions()->latest('created_at')->firstOrFail();
    expect($run->state->value)->toBe('completed')
        ->and(AiProviderOperation::query()->where('workspace_id', $workspace->id)->count())->toBe(1)
        ->and($operation->budget_run_id)->toBe($run->qualification_budget_run_id)
        ->and($operation->status->value)->toBe('succeeded')
        ->and($operation->cost_basis)->toBe('provider_exact')
        ->and($operation->actual_cost_micros)->toBe(600)
        ->and($decision->provider_operation_id)->toBe($operation->id)
        ->and($decision->provider_cost_micros)->toBe(600)
        ->and($run->qualificationBudgetRun()->firstOrFail()->status)->toBe('closed');
});

it('reduces unsafe or conflicting provider recommendations to needs review', function (): void {
    [$workspace, $run, $qualificationStage] = makeS2CleanQualificationRun('provider-contact-rubric-guard');
    app(RunAccountResearchStage::class)->handle($workspace->id, $run->id, $qualificationStage->id);

    $service = app(EvaluateAccountQualification::class);
    $assessment = $service->assess($workspace, $run);
    $operation = AiProviderOperation::query()
        ->where('workspace_id', $workspace->id)
        ->where('task', AiTask::Qualification->value)
        ->sole();
    $claimId = (string) $assessment['accepted_claims'][0]['claim_id'];
    $base = [
        'outcome' => 'contact',
        'confidence' => 0.95,
        'reasons' => ['The provider recommends contact.'],
        'missing_facts' => [],
        'disqualifiers' => [],
        'claim_ids' => [$claimId],
        'prohibited_inference' => false,
        'unsupported_causality' => false,
    ];
    $unsafeContacts = [
        'missing fact' => [...$base, 'missing_facts' => ['company.size']],
        'disqualifier' => [...$base, 'disqualifiers' => ['company.category']],
        'prohibited inference' => [...$base, 'prohibited_inference' => true],
        'unsupported causality' => [...$base, 'unsupported_causality' => true],
        'below threshold' => [...$base, 'confidence' => 0.5],
        'non-contact model disagreement' => [...$base, 'outcome' => 'withhold', 'disqualifiers' => ['company.category']],
    ];

    foreach ($unsafeContacts as $label => $payload) {
        $evaluation = $service->providerEvaluation(
            json_encode($payload, JSON_THROW_ON_ERROR),
            $assessment['accepted_claims'],
        );
        $decision = $service->persistAssessment($workspace, $assessment, $evaluation, $operation);

        expect($decision->outcome)
            ->toBe(QualificationOutcome::NeedsReview, "{$label} must never retain contact authority");
    }
});

it('reduces deterministic and provider disagreement to needs review', function (): void {
    [$workspace, $run, $qualificationStage] = makeS2CleanQualificationRun('provider-rule-disagreement', 0.6);
    app(RunAccountResearchStage::class)->handle($workspace->id, $run->id, $qualificationStage->id);

    $service = app(EvaluateAccountQualification::class);
    $assessment = $service->assess($workspace, $run);
    $operation = AiProviderOperation::query()
        ->where('workspace_id', $workspace->id)
        ->where('task', AiTask::Qualification->value)
        ->sole();
    $claimId = (string) $assessment['accepted_claims'][0]['claim_id'];
    $providerContact = $service->providerEvaluation(json_encode([
        'outcome' => 'contact',
        'confidence' => 0.95,
        'reasons' => ['The provider recommends contact despite the deterministic result.'],
        'missing_facts' => [],
        'disqualifiers' => [],
        'claim_ids' => [$claimId],
        'prohibited_inference' => false,
        'unsupported_causality' => false,
    ], JSON_THROW_ON_ERROR), $assessment['accepted_claims']);
    $decision = $service->persistAssessment($workspace, $assessment, $providerContact, $operation);

    expect($assessment['deterministic']->outcome)->toBe(QualificationOutcome::NeedsReview)
        ->and($decision->outcome)->toBe(QualificationOutcome::NeedsReview)
        ->and($decision->provider_operation_id)->toBe($operation->id);
});

it('refuses qualification provider work without its exact S2 task budget', function (): void {
    [$workspace, $run, $qualificationStage] = makeS2CleanQualificationRun('qualification-budget-graph-guard');
    $run->forceFill(['qualification_budget_run_id' => null])->save();

    app(RunAccountResearchStage::class)->handle($workspace->id, $run->id, $qualificationStage->id);

    expect($run->fresh()->state->value)->toBe('awaiting_budget')
        ->and($qualificationStage->fresh()->state->value)->toBe('pending')
        ->and(AccountResearchAttempt::query()->where('stage_id', $qualificationStage->id)->sole()->state->value)->toBe('waiting_budget')
        ->and(AiProviderOperation::query()->where('workspace_id', $workspace->id)->count())->toBe(0);
});

it('rejects a qualification response that cites a Claim outside the accepted set', function (): void {
    expect(fn () => app(EvaluateAccountQualification::class)->providerEvaluation(json_encode([
        'outcome' => 'contact',
        'confidence' => 0.9,
        'reasons' => ['Unsupported citation must be rejected.'],
        'missing_facts' => [],
        'disqualifiers' => [],
        'claim_ids' => ['not-accepted'],
        'prohibited_inference' => false,
        'unsupported_causality' => false,
    ], JSON_THROW_ON_ERROR), [['claim_id' => 'accepted']]))
        ->toThrow(QualificationValidationException::class);
});

it('rejects an incomplete qualification provider response', function (string $missingField): void {
    $response = [
        'outcome' => 'contact',
        'confidence' => 0.9,
        'reasons' => ['The accepted evidence supports contact.'],
        'missing_facts' => [],
        'disqualifiers' => [],
        'claim_ids' => ['accepted'],
        'prohibited_inference' => false,
        'unsupported_causality' => false,
    ];
    unset($response[$missingField]);

    expect(fn () => app(EvaluateAccountQualification::class)->providerEvaluation(
        json_encode($response, JSON_THROW_ON_ERROR),
        [['claim_id' => 'accepted']],
    ))->toThrow(QualificationValidationException::class, "The qualification output is missing {$missingField}.");
})->with([
    'disqualifiers',
    'prohibited_inference',
    'unsupported_causality',
]);

it('never enables deterministic provider adapters outside the testing environment', function () {
    config()->set('intelligence.testing.fake_providers', true);
    $this->app->detectEnvironment(fn (): string => 'production');
    $this->app->forgetInstance(AiProviderRegistry::class);

    $registry = $this->app->make(AiProviderRegistry::class);

    expect($registry->for(AiProviderName::OpenRouter))->not->toBeInstanceOf(DeterministicOpenRouterAdapter::class)
        ->and($registry->for(AiProviderName::Tavily))->not->toBeInstanceOf(DeterministicTavilyAdapter::class);
});

it('does not persist a provider result after another worker replaces the stage fence', function () {
    config()->set('intelligence.testing.fake_providers', true);
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);
    installS2DeterministicCredential($owner, AiProviderName::Tavily, DeterministicTavilyAdapter::FIXTURE_ID);
    $staleStageProvider = new class implements IntelligenceProviderBridge
    {
        public function extract(Workspace $workspace, User $actor, ResearchExtractRequest $request, string $idempotencyKey, ?string $budgetRunId = null): ResearchResult
        {
            $attempt = AccountResearchAttempt::query()
                ->where('workspace_id', $workspace->id)
                ->where('deterministic_idempotency_key', $idempotencyKey)
                ->firstOrFail();
            $stage = AccountResearchStage::query()->whereKey($attempt->stage_id)->firstOrFail();
            $stage->forceFill([
                'lease_owner' => (string) Str::uuid(),
                'fencing_token' => (int) $stage->fencing_token + 1,
            ])->save();

            return new ResearchResult(
                results: [[
                    'url' => $request->urls[0] ?? 'https://example.com',
                    'content' => 'This stale response must never become durable evidence.',
                ]],
                credits: 1,
                providerRequestId: 'stale-stage-response',
            );
        }

        public function search(Workspace $workspace, User $actor, ResearchSearchRequest $request, string $idempotencyKey, ?string $budgetRunId = null): ResearchResult
        {
            throw new RuntimeException('Search is not part of this regression.');
        }

        public function generate(Workspace $workspace, User $actor, string $content, string $idempotencyKey, ?string $budgetRunId = null, AiTask $task = AiTask::AccountResearch): LlmResult
        {
            throw new RuntimeException('Generation is not part of this regression.');
        }
    };
    $this->app->instance(IntelligenceProviderBridge::class, $staleStageProvider);
    $account = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Stale stage account',
        'website' => 'https://example.com',
        'domain_normalized' => 'example.com',
        'source' => Account::SOURCE_IMPORT,
    ]);

    $preflight = app(PreflightAccountResearch::class)->handle($workspace, $account, capabilities: ['research.extract']);
    $run = app(StartAccountResearch::class)->handle(
        $workspace,
        $account,
        $owner,
        'stale-stage-run',
        capabilities: ['research.extract'],
        maximumCostMicros: $preflight->maximumCostMicros,
        preflightHash: $preflight->inputHash,
    );

    expect(Artisan::call('account-research:sweep', [
        '--workspace' => $workspace->id,
        '--passes' => 10,
        '--json' => true,
    ]))->toBe(0);
    $run->refresh();
    $stage = $run->stages()->where('stage_key', 'website_retrieval')->firstOrFail();

    expect($run->snapshots()->count())->toBe(0)
        ->and($run->claims()->count())->toBe(0)
        ->and($stage->state->value)->toBe('running')
        ->and(AccountResearchAttempt::query()->where('stage_id', $stage->id)->sole()->state->value)->toBe('provider_boundary')
        ->and(AiProviderOperation::query()->where('workspace_id', $workspace->id)->count())->toBe(0);
});

it('runs an llm-only plan without a Tavily credential or provider call', function (): void {
    config()->set('intelligence.testing.fake_providers', true);
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);
    installS2DeterministicCredential($owner, AiProviderName::OpenRouter, DeterministicOpenRouterAdapter::FIXTURE_ID);
    $account = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'LLM-only account',
        'website' => 'https://example.com',
        'domain_normalized' => 'example.com',
        'source' => Account::SOURCE_IMPORT,
    ]);

    $capabilities = ['llm.structured_generate'];
    $preview = app(PreflightAccountResearch::class)->handle($workspace, $account, capabilities: $capabilities);
    $stageKeys = array_map('strval', array_column($preview->stages, 'stage_key'));

    expect($preview->blocked)->toBeFalse()
        ->and($preview->capabilitySnapshot['required']['llm.structured_generate'])->toBeTrue()
        ->and($preview->capabilitySnapshot['required']['research.extract'])->toBeTrue()
        ->and($preview->capabilitySnapshot['required']['research.search'])->toBeTrue()
        ->and($stageKeys)->toBe(['eligibility', 'domain_availability', 'claim_synthesis', 'qualification'])
        ->and(AiProviderCredential::query()->where('workspace_id', $workspace->id)->where('provider', AiProviderName::Tavily->value)->exists())->toBeFalse();

    $run = app(StartAccountResearch::class)->handle(
        $workspace,
        $account,
        $owner,
        'llm-only-capability-run',
        capabilities: $capabilities,
        maximumCostMicros: $preview->maximumCostMicros,
        preflightHash: $preview->inputHash,
    );

    expect(Artisan::call('account-research:sweep', [
        '--workspace' => $workspace->id,
        '--passes' => 10,
        '--json' => true,
    ]))->toBe(0);

    $run->refresh();
    $operation = AiProviderOperation::query()->where('workspace_id', $workspace->id)->sole();
    expect($run->stages()->orderBy('stage_order')->pluck('stage_key')->map(static fn (AccountResearchStageKey|string $stage): string => $stage instanceof AccountResearchStageKey ? $stage->value : $stage)->all())->toBe($stageKeys)
        ->and($operation->provider)->toBe(AiProviderName::OpenRouter)
        ->and($operation->operation)->toBe('completion')
        ->and(AiProviderOperation::query()->where('workspace_id', $workspace->id)->where('provider', AiProviderName::Tavily->value)->count())->toBe(0);
});

it('creates and costs only the selected provider stages', function (array $capabilities, array $expectedStages, array $expectedOperations): void {
    config()->set('intelligence.testing.fake_providers', true);
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);
    installS2DeterministicCredential($owner, AiProviderName::Tavily, DeterministicTavilyAdapter::FIXTURE_ID);
    installS2DeterministicCredential($owner, AiProviderName::OpenRouter, DeterministicOpenRouterAdapter::FIXTURE_ID);
    $account = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Selected capability account',
        'website' => 'https://example.com',
        'domain_normalized' => 'example.com',
        'source' => Account::SOURCE_IMPORT,
    ]);

    $preview = app(PreflightAccountResearch::class)->handle($workspace, $account, capabilities: $capabilities);
    $stageKeys = array_column($preview->stages, 'stage_key');
    $stageCosts = array_column($preview->stages, 'expected_cost_micros', 'stage_key');
    $price = (int) AiBudgetSetting::query()->whereKey($workspace->id)->value('tavily_credit_price_micros');
    $expectedEstimate = 0;
    if (in_array('research.extract', $capabilities, true)) {
        $expectedEstimate += app(AiCostEstimator::class)->tavilyExtract(1, 'basic', $price)->costMicros;
    }
    if (in_array('research.search', $capabilities, true)) {
        $expectedEstimate += app(AiCostEstimator::class)->tavilySearch('basic', $price)->costMicros;
    }
    if (in_array('llm.structured_generate', $capabilities, true)) {
        expect($stageCosts['claim_synthesis'])->toBeGreaterThan(0);
        expect($stageCosts['qualification'])
            ->toBe(app(AiCostEstimator::class)->openRouter(
                (string) config('ai.tasks.qualification.model_id'),
                (int) config('ai.tasks.qualification.maximum_input_bytes'),
                (int) config('ai.tasks.qualification.maximum_output_tokens'),
            )->costMicros);
        $expectedEstimate += $stageCosts['claim_synthesis'];
        $expectedEstimate += $stageCosts['qualification'];
    }

    expect($stageKeys)->toBe($expectedStages)
        ->and($preview->estimatedCostMicros)->toBe($expectedEstimate)
        ->and(array_sum($stageCosts))->toBe($preview->estimatedCostMicros)
        ->and($preview->maximumCostMicros)->toBe($preview->estimatedCostMicros + 50_000);

    $run = app(StartAccountResearch::class)->handle(
        $workspace,
        $account,
        $owner,
        'selected-capability-run-'.implode('-', $capabilities),
        capabilities: $capabilities,
        maximumCostMicros: $preview->maximumCostMicros,
        preflightHash: $preview->inputHash,
    );
    expect(Artisan::call('account-research:sweep', [
        '--workspace' => $workspace->id,
        '--passes' => 10,
        '--json' => true,
    ]))->toBe(0);
    $run->refresh();

    expect($run->state->value)->toBe('completed')
        ->and($run->stages()->orderBy('stage_order')->get()->map(static fn (AccountResearchStage $stage): string => $stage->stage_key->value)->all())->toBe($expectedStages)
        ->and(AiProviderOperation::query()->where('workspace_id', $workspace->id)->pluck('operation')->sort()->values()->all())->toBe(collect($expectedOperations)->sort()->values()->all())
        ->and($run->qualificationBudgetRun()->firstOrFail()->status)->toBe('closed');
})->with([
    'extract only' => [
        ['research.extract'],
        ['eligibility', 'domain_availability', 'website_retrieval', 'qualification'],
        ['extract'],
    ],
    'extract and llm, without search' => [
        ['research.extract', 'llm.structured_generate'],
        ['eligibility', 'domain_availability', 'website_retrieval', 'claim_synthesis', 'qualification'],
        ['extract', 'completion'],
    ],
]);
