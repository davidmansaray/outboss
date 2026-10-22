<?php

use App\Domain\Ai\Enums\AiCredentialStatus;
use App\Domain\Ai\Enums\AiOperationStatus;
use App\Domain\Ai\Enums\AiProviderName;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Services\AiBudgetGateway;
use App\Domain\Ai\Services\AiWorkspaceSettings;
use App\Domain\Gtm\Enums\GtmProfileResearchState;
use App\Domain\Gtm\Enums\GtmResearchPhaseStatus;
use App\Domain\Gtm\Enums\GtmResearchRunState;
use App\Domain\Gtm\Exceptions\GtmPayloadValidationException;
use App\Domain\Gtm\Exceptions\GtmResearchPaidResultLost;
use App\Domain\Gtm\Exceptions\GtmResearchPhaseBusy;
use App\Domain\Gtm\Exceptions\GtmResearchReconciliationPending;
use App\Domain\Gtm\Services\GtmResearchPhaseService;
use App\Domain\Gtm\Services\PersistGtmProfileResearchSources;
use App\Domain\Gtm\Services\PublishGtmProfileResearchDispatches;
use App\Domain\Gtm\Services\RefreshGtmProfileResearch;
use App\Domain\Gtm\Services\RunGtmProfileResearch;
use App\Domain\Gtm\Services\SweepGtmProfileResearchDispatches;
use App\Domain\Gtm\Support\GtmResearchOutputValidator;
use App\Domain\Gtm\Support\PublicWebsiteUrl;
use App\Domain\Workspaces\Services\ProvisionWorkspace;
use App\Jobs\Gtm\ResearchWorkspaceGtmProfile;
use App\Models\AiBudgetPeriod;
use App\Models\AiBudgetRun;
use App\Models\AiBudgetSetting;
use App\Models\AiProviderCredential;
use App\Models\AiProviderCredentialVersion;
use App\Models\AiProviderOperation;
use App\Models\GtmProfile;
use App\Models\GtmProfileResearchAttempt;
use App\Models\GtmProfileResearchDispatch;
use App\Models\GtmProfileResearchRun;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/**
 * @return array{AiProviderCredential, AiProviderCredentialVersion}
 */
function gtmResearchCredential(Workspace $workspace, User $user, AiProviderName $provider): array
{
    $credential = AiProviderCredential::query()->create([
        'workspace_id' => $workspace->id,
        'capability' => $provider->capability(),
        'provider' => $provider,
        'status' => AiCredentialStatus::Healthy,
        'created_by_user_id' => $user->id,
        'updated_by_user_id' => $user->id,
    ]);
    $version = $credential->versions()->create([
        'version' => 1,
        'encrypted_secret' => 'gtm-research-test-secret',
        'status' => 'active',
        'created_by_user_id' => $user->id,
        'tested_at' => now(),
        'activated_at' => now(),
    ]);
    $credential->forceFill(['active_version_id' => $version->id])->save();

    return [$credential->fresh(), $version];
}

/** @return array{0: User, 1: Workspace, 2: GtmProfile, 3: GtmProfileResearchRun} */
function gtmResearchFixture(string $website = 'https://example.com'): array
{
    Queue::fake();
    $user = User::factory()->withoutWorkspace()->create(['name' => 'Profile Owner']);
    $workspace = app(ProvisionWorkspace::class)->create($user, 'Acme Labs', 'team', $website, 'gtm-workspace-op');
    $profile = $workspace->gtmProfile()->firstOrFail();
    $run = $profile->currentResearchRun()->firstOrFail();

    return [$user, $workspace, $profile, $run];
}

function gtmResearchResponseBody(): string
{
    $citations = array_fill_keys([
        'synthesis',
        'company.name',
        'company.category',
        'company.product_description',
        'company.target_problem',
        'company.primary_outcome',
        'market.target_segment',
        'market.geographies',
        'market.company_traits',
        'market.excluded_segments',
        'market.timing_thesis',
        'buyers.target_roles',
        'buyers.buying_triggers',
        'buyers.blocking_roles',
        'messaging.proof_points',
        'messaging.prohibited_claims',
        'messaging.brand_voice',
        'messaging.message_constraints',
        'qualification.evidence_requirements',
        'qualification.business_disqualifiers',
    ], ['SRC_1']);

    return json_encode([
        'synthesis' => 'Acme Labs helps revenue teams turn public evidence into focused campaigns.',
        'company' => [
            'name' => 'Different Acme Name',
            'category' => 'Revenue software',
            'product_description' => 'A workspace for evidence-backed outbound planning.',
            'target_problem' => 'Teams struggle to turn scattered market signals into useful action.',
            'primary_outcome' => 'More relevant conversations with qualified buyers.',
        ],
        'market' => [
            'target_segment' => 'Founder-led B2B software teams',
            'geographies' => ['North America'],
            'company_traits' => ['Lean revenue team'],
            'excluded_segments' => ['Consumer-only businesses'],
            'timing_thesis' => 'Teams act when a new growth motion needs focus.',
        ],
        'buyers' => [
            'target_roles' => ['Founder', 'Revenue leader'],
            'buying_triggers' => ['Launching a new outbound motion'],
            'blocking_roles' => ['Finance'],
        ],
        'messaging' => [
            'proof_points' => ['Public evidence is attached to every recommendation.'],
            'prohibited_claims' => ['Guaranteed pipeline'],
            'brand_voice' => 'Clear and practical',
            'message_constraints' => ['Lead with evidence.'],
        ],
        'qualification' => [
            'evidence_requirements' => ['A defined target segment'],
            'business_disqualifiers' => ['No B2B motion'],
        ],
        'citations' => $citations,
    ], JSON_THROW_ON_ERROR);
}

function gtmResearchOperationForPhase(
    Workspace $workspace,
    User $user,
    AiBudgetRun $budgetRun,
    string $phase,
    string $idempotencyKey,
): AiProviderOperation {
    $provider = $phase === 'generate' ? AiProviderName::OpenRouter : AiProviderName::Tavily;
    $credential = AiProviderCredential::query()
        ->where('workspace_id', $workspace->id)
        ->where('provider', $provider)
        ->with('activeVersion')
        ->firstOrFail();
    $operation = $phase === 'generate' ? 'completion' : $phase;
    $endpoint = $phase === 'generate' ? 'openai/gpt-5' : 'tavily/'.$phase;

    return app(AiBudgetGateway::class)->reserve(
        $workspace,
        $credential,
        $credential->activeVersion,
        $user,
        AiTask::AccountResearch,
        $operation,
        $endpoint,
        1,
        1_000,
        $idempotencyKey,
        app(AiBudgetGateway::class)->fingerprint(['phase' => $phase], $idempotencyKey),
        $budgetRun->id,
    );
}

it('runs extract, conditional search, generate, merge, and closes one explicit budget run', function (): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();
    gtmResearchCredential($workspace, $user, AiProviderName::Tavily);
    gtmResearchCredential($workspace, $user, AiProviderName::OpenRouter);

    Http::fake([
        'https://api.tavily.com/extract' => Http::response([
            'request_id' => 'extract-request',
            'results' => [[
                'url' => 'https://example.com/about',
                'title' => 'About Acme',
                'raw_content' => 'Acme Labs builds practical revenue software for focused B2B teams.',
            ]],
        ]),
        'https://api.tavily.com/search' => Http::response([
            'request_id' => 'search-request',
            'results' => [[
                'url' => 'https://example.com/customers',
                'title' => 'Acme customers',
                'content' => 'Customer evidence shows lean revenue teams use Acme Labs when a new motion needs focus.',
            ]],
        ]),
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'generation-id',
            'choices' => [['message' => ['content' => gtmResearchResponseBody()]]],
            'usage' => [
                'prompt_tokens' => 400,
                'completion_tokens' => 220,
                'cost' => 0.001,
            ],
        ], 200, ['x-request-id' => 'generate-request']),
    ]);

    app(RunGtmProfileResearch::class)->handle($profile->id, $run->id, $user->id);

    $profile->refresh();
    $run->refresh();
    expect($profile->research_state)->toBe(GtmProfileResearchState::Ready)
        ->and($run->state)->toBe(GtmResearchRunState::Ready)
        ->and($profile->versions()->count())->toBe(2)
        ->and($profile->sources()->count())->toBe(2)
        ->and($profile->reviewItems()->where('field_path', 'company.name')->exists())->toBeTrue()
        ->and(data_get($profile->currentVersion()->firstOrFail()->payload, 'company.name.value'))->toBe('Acme Labs')
        ->and($run->budgetRun()->firstOrFail()->status)->toBe('closed')
        ->and($run->phases()->where('phase', 'extract')->firstOrFail()->status)->toBe(GtmResearchPhaseStatus::Succeeded)
        ->and($run->phases()->where('phase', 'search')->firstOrFail()->status)->toBe(GtmResearchPhaseStatus::Succeeded)
        ->and($run->phases()->where('phase', 'generate')->firstOrFail()->status)->toBe(GtmResearchPhaseStatus::Succeeded);

    Http::assertSentCount(3);
    Queue::assertPushed(ResearchWorkspaceGtmProfile::class);
});

it('maps only field-cited source handles to stored source UUIDs', function (): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();
    gtmResearchCredential($workspace, $user, AiProviderName::Tavily);
    gtmResearchCredential($workspace, $user, AiProviderName::OpenRouter);
    $output = json_decode(gtmResearchResponseBody(), true, 32, JSON_THROW_ON_ERROR);
    $output['citations']['company.category'] = ['SRC_1'];
    $output['citations']['market.target_segment'] = ['SRC_2'];
    Http::fake([
        'https://api.tavily.com/extract' => Http::response([
            'request_id' => 'citation-extract',
            'results' => [[
                'url' => 'https://example.com/extract',
                'title' => 'Extract source',
                'raw_content' => 'Short first-party evidence.',
            ]],
        ]),
        'https://api.tavily.com/search' => Http::response([
            'request_id' => 'citation-search',
            'results' => [[
                'url' => 'https://example.com/search',
                'title' => 'Search source',
                'content' => 'Short corroborating evidence.',
            ]],
        ]),
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'citation-generation',
            'choices' => [['message' => ['content' => json_encode($output, JSON_THROW_ON_ERROR)]]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 100, 'cost' => 0.001],
        ]),
    ]);

    app(RunGtmProfileResearch::class)->handle($profile->id, $run->id, $user->id);

    $extractSource = $profile->sources()->where('canonical_url', 'https://example.com/extract')->sole();
    $searchSource = $profile->sources()->where('canonical_url', 'https://example.com/search')->sole();
    $payload = $profile->fresh()->currentVersion()->firstOrFail()->payload;
    expect(data_get($payload, 'company.category.source_ids'))->toBe([$extractSource->id])
        ->and(data_get($payload, 'market.target_segment.source_ids'))->toBe([$searchSource->id])
        ->and(data_get($payload, 'synthesis.source_ids'))->toBe([$extractSource->id]);

    $generationRequest = Http::recorded(fn ($request) => str_contains($request->url(), '/chat/completions'))->first();
    expect($generationRequest)->not->toBeNull();
    $prompt = data_get($generationRequest, '0.messages.0.content');
    expect($prompt)->toBeString()
        ->toContain('SRC_1')
        ->toContain('SRC_2')
        ->not->toContain($extractSource->id)
        ->not->toContain($searchSource->id);

    expect(fn () => app(GtmResearchOutputValidator::class)->validateCitations(
        ['company.category' => [$extractSource->id]],
        ['SRC_1' => 0, 'SRC_2' => 1],
        ['company' => ['category' => 'Revenue software']],
    ))->toThrow(GtmPayloadValidationException::class);
});

it('waits for provider configuration before creating a budget run', function (): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();

    app(RunGtmProfileResearch::class)->handle($profile->id, $run->id, $user->id);

    expect($profile->fresh()->research_state)->toBe(GtmProfileResearchState::AwaitingProvider)
        ->and($run->fresh()->state)->toBe(GtmResearchRunState::AwaitingProvider)
        ->and($run->fresh()->budget_run_id)->toBeNull();
});

it('waits for budget admission without calling a provider', function (): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();
    gtmResearchCredential($workspace, $user, AiProviderName::Tavily);
    gtmResearchCredential($workspace, $user, AiProviderName::OpenRouter);
    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);
    AiBudgetSetting::query()->whereKey($workspace->id)->update(['monthly_limit_micros' => 0]);

    app(RunGtmProfileResearch::class)->handle($profile->id, $run->id, $user->id);

    expect($profile->fresh()->research_state)->toBe(GtmProfileResearchState::AwaitingBudget)
        ->and($run->fresh()->state)->toBe(GtmResearchRunState::AwaitingBudget)
        ->and($run->fresh()->budget_run_id)->toBeNull()
        ->and($run->attempts()->count())->toBe(0)
        ->and(AiBudgetRun::query()->where('workspace_id', $workspace->id)->count())->toBe(0);
    Http::assertNothingSent();
});

it('automatically requeues an awaiting-provider run after both providers recover', function (): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();

    app(RunGtmProfileResearch::class)->handle($profile->id, $run->id, $user->id);
    expect($run->fresh()->state)->toBe(GtmResearchRunState::AwaitingProvider);

    gtmResearchCredential($workspace, $user, AiProviderName::Tavily);
    gtmResearchCredential($workspace, $user, AiProviderName::OpenRouter);

    app(SweepGtmProfileResearchDispatches::class)->handle();

    $dispatch = $run->dispatch()->firstOrFail();
    expect($run->fresh()->state)->toBe(GtmResearchRunState::Queued)
        ->and($profile->fresh()->research_state)->toBe(GtmProfileResearchState::Queued)
        ->and($dispatch->status)->toBe('leased')
        ->and($dispatch->published_at)->toBeNull()
        ->and($dispatch->lease_expires_at)->not->toBeNull();
    Queue::assertPushed(ResearchWorkspaceGtmProfile::class);
});

it('retires an awaiting run and its open ledger before an ordinary refresh replaces it', function (): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();
    [$tavily] = gtmResearchCredential($workspace, $user, AiProviderName::Tavily);
    gtmResearchCredential($workspace, $user, AiProviderName::OpenRouter);
    Http::fake([
        'https://api.tavily.com/extract' => function () use ($tavily) {
            $tavily->forceFill(['status' => AiCredentialStatus::Disabled])->save();

            return Http::response([
                'request_id' => 'awaiting-refresh-extract',
                'results' => [['url' => 'https://example.com', 'raw_content' => 'Short extract evidence.']],
            ]);
        },
    ]);

    app(RunGtmProfileResearch::class)->handle($profile->id, $run->id, $user->id);
    $budgetRun = $run->fresh()->budgetRun()->firstOrFail();
    expect($run->fresh()->state)->toBe(GtmResearchRunState::AwaitingProvider)
        ->and($run->phases()->where('phase', 'extract')->firstOrFail()->status)->toBe(GtmResearchPhaseStatus::Succeeded)
        ->and($budgetRun->status)->toBe('open');

    config()->set('gtm.research.refresh_cooldown_seconds', 0);
    $replacement = app(RefreshGtmProfileResearch::class)
        ->refresh($profile->fresh(), $user, 'replace-awaiting-open-ledger');

    expect($replacement->id)->not->toBe($run->id)
        ->and($replacement->parent_run_id)->toBe($run->id)
        ->and($run->fresh()->state)->toBe(GtmResearchRunState::Cancelled)
        ->and($run->dispatch()->firstOrFail()->status)->toBe('retired')
        ->and($budgetRun->fresh()->status)->toBe('cancelled')
        ->and($budgetRun->fresh()->closed_at)->not->toBeNull();
});

it('automatically requeues an awaiting-budget run after budget recovery', function (): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();
    gtmResearchCredential($workspace, $user, AiProviderName::Tavily);
    gtmResearchCredential($workspace, $user, AiProviderName::OpenRouter);
    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);
    AiBudgetSetting::query()->whereKey($workspace->id)->update(['monthly_limit_micros' => 0]);

    app(RunGtmProfileResearch::class)->handle($profile->id, $run->id, $user->id);
    expect($run->fresh()->state)->toBe(GtmResearchRunState::AwaitingBudget);

    AiBudgetSetting::query()->whereKey($workspace->id)->update(['monthly_limit_micros' => 50_000_000]);
    app(SweepGtmProfileResearchDispatches::class)->handle();

    $dispatch = $run->dispatch()->firstOrFail();
    expect($run->fresh()->state)->toBe(GtmResearchRunState::Queued)
        ->and($profile->fresh()->research_state)->toBe(GtmProfileResearchState::Queued)
        ->and($dispatch->status)->toBe('leased')
        ->and($dispatch->published_at)->toBeNull();
});

it('cancels a run when the initiating member is gone', function (): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();
    $workspace->memberships()->where('user_id', $user->id)->delete();

    app(RunGtmProfileResearch::class)->handle($profile->id, $run->id, $user->id);

    expect($profile->fresh()->research_state)->toBe(GtmProfileResearchState::Cancelled)
        ->and($run->fresh()->state)->toBe(GtmResearchRunState::Cancelled)
        ->and($run->fresh()->safe_failure_code)->toBe('initiator_membership_lost');
});

it('cancels instead of borrowing a different actor when the queued initiator is missing', function (): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();
    gtmResearchCredential($workspace, $user, AiProviderName::Tavily);
    gtmResearchCredential($workspace, $user, AiProviderName::OpenRouter);

    app(RunGtmProfileResearch::class)->handle($profile->id, $run->id, 999999);

    expect($profile->fresh()->research_state)->toBe(GtmProfileResearchState::Cancelled)
        ->and($run->fresh()->state)->toBe(GtmResearchRunState::Cancelled)
        ->and($run->fresh()->safe_failure_code)->toBe('initiator_membership_lost');
});

it('wakes reconciliation waits and resolves a settled response without a checkpoint as paid-result-lost', function (): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();
    gtmResearchCredential($workspace, $user, AiProviderName::Tavily);
    gtmResearchCredential($workspace, $user, AiProviderName::OpenRouter);
    $budgetRun = app(AiBudgetGateway::class)->openRun($workspace, $user, AiTask::AccountResearch);
    $credential = AiProviderCredential::query()
        ->where('workspace_id', $workspace->id)
        ->where('provider', AiProviderName::Tavily)
        ->with('activeVersion')
        ->firstOrFail();
    $version = $credential->activeVersion;
    $operation = app(AiBudgetGateway::class)->reserve(
        $workspace,
        $credential,
        $version,
        $user,
        AiTask::AccountResearch,
        'extract',
        'tavily/extract',
        1,
        8_000,
        'reconciliation-wake',
        app(AiBudgetGateway::class)->fingerprint(['wake' => true], 'reconciliation-wake'),
        $budgetRun->id,
    );
    $phase = $run->phases()->create(['phase' => 'extract', 'status' => GtmResearchPhaseStatus::Running]);
    $attempt = $phase->attempts()->create([
        'research_run_id' => $run->id,
        'attempt_number' => 1,
        'deterministic_idempotency_key' => 'reconciliation-wake-attempt',
        'provider_operation_id' => $operation->id,
        'input_hash' => hash('sha256', 'reconciliation-wake'),
        'status' => 'ambiguous',
        'started_at' => now(),
    ]);
    $run->forceFill(['budget_run_id' => $budgetRun->id])->save();
    $profile->forceFill(['research_state' => GtmProfileResearchState::Running])->save();
    $operation->forceFill(['status' => AiOperationStatus::Uncertain])->save();

    app(RunGtmProfileResearch::class)->handle($profile->id, $run->id, $user->id);
    expect($run->fresh()->state)->toBe(GtmResearchRunState::AwaitingProvider);

    expect(app(SweepGtmProfileResearchDispatches::class)->handle())->toBe(0)
        ->and($run->fresh()->state)->toBe(GtmResearchRunState::AwaitingProvider);

    app(AiBudgetGateway::class)->settle(
        $operation->fresh(),
        8_000,
        1,
        'formula_estimate',
        finalStatus: AiOperationStatus::Reconciled,
    );
    app(SweepGtmProfileResearchDispatches::class)->handle();
    app(RunGtmProfileResearch::class)->handle($profile->id, $run->id, $user->id);

    expect($run->fresh()->state)->toBe(GtmResearchRunState::Failed)
        ->and($run->fresh()->safe_failure_code)->toBe('paid_result_lost')
        ->and($attempt->fresh()->safe_error_code)->toBe('paid_result_lost')
        ->and($phase->fresh()->status)->toBe(GtmResearchPhaseStatus::Failed)
        ->and($budgetRun->fresh()->status)->toBe('closed')
        ->and($budgetRun->fresh()->closed_at)->not->toBeNull();
    Http::assertNothingSent();
});

it('rejects a non-HTTPS website snapshot before provider work', function (): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();
    gtmResearchCredential($workspace, $user, AiProviderName::Tavily);
    gtmResearchCredential($workspace, $user, AiProviderName::OpenRouter);
    $run->forceFill(['website_url_snapshot' => 'http://example.com'])->save();

    app(RunGtmProfileResearch::class)->handle($profile->id, $run->id, $user->id);

    expect($profile->fresh()->research_state)->toBe(GtmProfileResearchState::Failed)
        ->and($run->fresh()->safe_failure_code)->toBe('research_failed')
        ->and(AiProviderOperation::query()->where('workspace_id', $workspace->id)->count())->toBe(0);
    Http::assertNothingSent();
});

it('persists a checkpoint and reuses it, while settled work without one is paid-result-lost', function (): void {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $profile = $workspace->gtmProfile()->firstOrFail();
    $run = GtmProfileResearchRun::factory()->create([
        'gtm_profile_id' => $profile->id,
        'website_url_snapshot' => 'https://example.com',
        'initiated_by_user_id' => $user->id,
    ]);
    $profile->forceFill(['current_research_run_id' => $run->id])->save();
    $phases = app(GtmResearchPhaseService::class);

    $started = $phases->begin($run, 'extract', hash('sha256', 'checkpoint'));
    $attempt = $started['attempt'];
    expect($attempt)->toBeInstanceOf(GtmProfileResearchAttempt::class);
    $attempt = $phases->claimProviderBoundary($attempt);
    $phases->checkpoint($attempt, ['sources' => [], 'provider_request_id' => 'checkpoint-request']);

    $reused = $phases->begin($run->fresh(), 'extract', hash('sha256', 'checkpoint'));
    expect($reused['attempt'])->toBeNull()
        ->and($reused['checkpoint']['provider_request_id'])->toBe('checkpoint-request');

    $budgetRun = app(AiBudgetGateway::class)->openRun($workspace, $user, AiTask::AccountResearch);
    [$credential, $version] = gtmResearchCredential($workspace, $user, AiProviderName::OpenRouter);
    $operation = app(AiBudgetGateway::class)->reserve(
        $workspace,
        $credential,
        $version,
        $user,
        AiTask::AccountResearch,
        'completion',
        'openai/gpt-5',
        100,
        1000,
        'paid-result-crash',
        app(AiBudgetGateway::class)->fingerprint(['crash' => true], 'checkpoint'),
        $budgetRun->id,
    );
    $crashRun = GtmProfileResearchRun::factory()->create([
        'gtm_profile_id' => $profile->id,
        'website_url_snapshot' => 'https://example.com',
        'initiated_by_user_id' => $user->id,
    ]);
    $profile->forceFill(['current_research_run_id' => $crashRun->id])->save();
    $crashAttempt = $phases->begin($crashRun, 'generate', hash('sha256', 'paid-result'));
    $crashAttempt = $crashAttempt['attempt'];
    $crashAttempt->forceFill(['provider_operation_id' => $operation->id])->save();
    $operation->forceFill(['status' => AiOperationStatus::Succeeded])->save();

    expect(fn () => $phases->begin($crashRun->fresh(), 'generate', hash('sha256', 'paid-result')))
        ->toThrow(GtmResearchPaidResultLost::class);
});

it('publishes a pending durable dispatch under a lease', function (): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();
    $dispatch = GtmProfileResearchDispatch::query()
        ->where('research_run_id', $run->id)
        ->firstOrFail();

    $published = app(PublishGtmProfileResearchDispatches::class)->handle();

    expect($published)->toBe(1)
        ->and($dispatch->fresh()->status)->toBe('leased')
        ->and($dispatch->fresh()->published_at)->toBeNull()
        ->and($dispatch->fresh()->lease_expires_at)->not->toBeNull();
    Queue::assertPushed(ResearchWorkspaceGtmProfile::class, function (ResearchWorkspaceGtmProfile $job) use ($profile, $run, $user): bool {
        return $job->profileId === $profile->id
            && $job->researchRunUuid === $run->id
            && $job->initiatingUserId === $user->id;
    });
});

it('uses configured queue backoff and distinguishes timeouts from general job failures', function (): void {
    config()->set('gtm.research.backoff_seconds', 47);
    [$timeoutUser, $timeoutWorkspace, $timeoutProfile, $timeoutRun] = gtmResearchFixture();
    $timeoutJob = new ResearchWorkspaceGtmProfile($timeoutProfile->id, $timeoutRun->id, $timeoutUser->id);
    expect($timeoutJob->backoff())->toBe(47)
        ->and($timeoutJob->failOnTimeout)->toBeTrue();
    $timeoutJob->failed(new TimeoutExceededException('The research job timed out.'));
    expect($timeoutRun->fresh()->safe_failure_code)->toBe('research_timed_out');

    [$failedUser, $failedWorkspace, $failedProfile, $failedRun] = gtmResearchFixture();
    $failedJob = new ResearchWorkspaceGtmProfile($failedProfile->id, $failedRun->id, $failedUser->id);
    $failedJob->failed(new RuntimeException('A general worker failure.'));
    expect($failedRun->fresh()->safe_failure_code)->toBe('research_failed');
});

it('keeps a dispatch retryable when a unique job lock suppresses queue publication', function (): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();
    config()->set('gtm.dispatch.lease_seconds', 30);
    Queue::fake();
    $job = new ResearchWorkspaceGtmProfile($profile->id, $run->id, $user->id);
    $cache = app(CacheRepository::class);
    $key = UniqueLock::getKey($job);
    $cache->lock($key)->forceRelease();
    $uniqueLock = $cache->lock($key, $job->uniqueFor);
    expect($uniqueLock->get())->toBeTrue();

    app(PublishGtmProfileResearchDispatches::class)->handle();
    $dispatch = $run->dispatch()->firstOrFail();
    expect($dispatch->status)->toBe('leased')
        ->and($dispatch->published_at)->toBeNull()
        ->and($dispatch->publish_attempts)->toBe(1);
    Queue::assertNotPushed(ResearchWorkspaceGtmProfile::class);

    $this->travelTo(now()->addSeconds(31));
    app(PublishGtmProfileResearchDispatches::class)->handle();
    expect($dispatch->fresh()->published_at)->toBeNull()
        ->and($dispatch->fresh()->publish_attempts)->toBe(2);
    Queue::assertNotPushed(ResearchWorkspaceGtmProfile::class);

    $uniqueLock->release();
    $this->travelTo(now()->addSeconds(31));
    app(PublishGtmProfileResearchDispatches::class)->handle();
    expect($dispatch->fresh()->status)->toBe('leased')
        ->and($dispatch->fresh()->published_at)->toBeNull()
        ->and($dispatch->fresh()->publish_attempts)->toBe(3);
    Queue::assertPushed(ResearchWorkspaceGtmProfile::class, 1);

    $job->handle(app(RunGtmProfileResearch::class));
    expect($dispatch->fresh()->status)->toBe('published')
        ->and($dispatch->fresh()->published_at)->not->toBeNull();
});

it('rejects unbounded source metadata before persistence', function (): void {
    expect(fn () => app(GtmResearchOutputValidator::class)->validateSources([[
        'url' => 'https://example.com',
        'excerpt' => str_repeat('x', 4097),
    ]]))->toThrow(GtmPayloadValidationException::class);

    [$user, $workspace, $profile, $run] = gtmResearchFixture();
    expect(fn () => app(PersistGtmProfileResearchSources::class)->handle(
        $profile,
        $run,
        [[
            'url' => 'https://example.com',
            'excerpt' => str_repeat('x', 4097),
            'source_type' => 'extract',
        ]],
    ))->toThrow(GtmPayloadValidationException::class);
});

it('recovers paid AF-01 operations by deterministic key without an attempt foreign key', function (string $phase): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();
    gtmResearchCredential($workspace, $user, AiProviderName::Tavily);
    gtmResearchCredential($workspace, $user, AiProviderName::OpenRouter);
    $budgetRun = app(AiBudgetGateway::class)->openRun($workspace, $user, AiTask::AccountResearch);
    $run->forceFill(['budget_run_id' => $budgetRun->id])->save();
    $phases = app(GtmResearchPhaseService::class);
    $started = $phases->begin($run->fresh(), $phase, hash('sha256', 'recover-'.$phase));
    $attempt = $started['attempt'];
    $operation = gtmResearchOperationForPhase(
        $workspace,
        $user,
        $budgetRun,
        $phase,
        $attempt->deterministic_idempotency_key,
    );
    $operation->forceFill(['status' => AiOperationStatus::Succeeded])->save();
    $attempt->forceFill([
        'status' => 'failed',
        'safe_error_code' => 'research_failed',
        'finished_at' => now(),
    ])->save();

    expect(fn () => $phases->begin($run->fresh(), $phase, hash('sha256', 'recover-'.$phase)))
        ->toThrow(GtmResearchPaidResultLost::class);

    expect(fn () => $phases->begin($run->fresh(), $phase, hash('sha256', 'recover-'.$phase)))
        ->toThrow(GtmResearchPaidResultLost::class);

    expect($attempt->fresh()->provider_operation_id)->toBe($operation->id)
        ->and($attempt->fresh()->safe_error_code)->toBe('paid_result_lost')
        ->and($run->attempts()->count())->toBe(1);
    Http::assertNothingSent();
})->with(['extract', 'search', 'generate']);

it('records inspected invalid provider output without treating the response as lost', function (string $phase): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();
    gtmResearchCredential($workspace, $user, AiProviderName::Tavily);
    gtmResearchCredential($workspace, $user, AiProviderName::OpenRouter);

    $extractUrl = $phase === 'extract' ? 'http://example.com/unsafe' : 'https://example.com/extract';
    $searchUrl = $phase === 'search' ? 'http://example.com/unsafe' : 'https://example.com/search';
    Http::fake([
        'https://api.tavily.com/extract' => Http::response([
            'request_id' => 'invalid-'.$phase.'-extract',
            'results' => [['url' => $extractUrl, 'raw_content' => 'Short extract evidence.']],
        ]),
        'https://api.tavily.com/search' => Http::response([
            'request_id' => 'invalid-'.$phase.'-search',
            'results' => [['url' => $searchUrl, 'content' => 'Short search evidence.']],
        ]),
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'invalid-'.$phase.'-generation',
            'choices' => [['message' => ['content' => $phase === 'generate'
                ? '{not valid json'
                : gtmResearchResponseBody()]]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 100, 'cost' => 0.001],
        ]),
    ]);

    app(RunGtmProfileResearch::class)->handle($profile->id, $run->id, $user->id);

    $targetAttempt = $run->attempts()
        ->whereHas('phase', fn ($query) => $query->where('phase', $phase))
        ->sole();
    $targetOperation = AiProviderOperation::query()
        ->where('workspace_id', $workspace->id)
        ->where('idempotency_key', $targetAttempt->deterministic_idempotency_key)
        ->sole();
    $sent = Http::recorded()->count();
    $operations = AiProviderOperation::query()->where('workspace_id', $workspace->id)->count();
    $budgetRun = $run->fresh()->budgetRun()->firstOrFail();

    expect($run->fresh()->state)->toBe(GtmResearchRunState::Failed)
        ->and($run->fresh()->safe_failure_code)->toBe('output_invalid')
        ->and($profile->fresh()->safe_error_code)->toBe('output_invalid')
        ->and($targetAttempt->fresh()->safe_error_code)->toBe('output_invalid')
        ->and(data_get($targetAttempt->fresh()->outcome, 'type'))->toBe('inspected_failure')
        ->and(data_get($targetAttempt->fresh()->outcome, 'response_inspected'))->toBeTrue()
        ->and($targetOperation->status)->toBe(AiOperationStatus::Succeeded)
        ->and($budgetRun->status)->toBe('open')
        ->and($budgetRun->closed_at)->toBeNull();

    app(RunGtmProfileResearch::class)->handle($profile->id, $run->id, $user->id);
    expect(Http::recorded()->count())->toBe($sent)
        ->and(AiProviderOperation::query()->where('workspace_id', $workspace->id)->count())->toBe($operations)
        ->and($run->attempts()->whereHas('phase', fn ($query) => $query->where('phase', $phase))->count())->toBe(1);

    $retry = app(RefreshGtmProfileResearch::class)->retry($profile->fresh(), $user, 'invalid-'.$phase.'-retry');
    expect($retry->id)->toBe($run->id)
        ->and($retry->budget_run_id)->toBe($budgetRun->id)
        ->and($retry->state)->toBe(GtmResearchRunState::Queued);
})->with(['extract', 'search', 'generate']);

it('classifies an inspected generation schema violation as output invalid', function (): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();
    gtmResearchCredential($workspace, $user, AiProviderName::Tavily);
    gtmResearchCredential($workspace, $user, AiProviderName::OpenRouter);
    Http::fake([
        'https://api.tavily.com/extract' => Http::response([
            'request_id' => 'invalid-schema-extract',
            'results' => [['url' => 'https://example.com', 'raw_content' => 'Short extract evidence.']],
        ]),
        'https://api.tavily.com/search' => Http::response([
            'request_id' => 'invalid-schema-search',
            'results' => [['url' => 'https://example.com/about', 'content' => 'Search evidence.']],
        ]),
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'invalid-schema-generation',
            'choices' => [['message' => ['content' => json_encode([
                'company' => ['unsupported' => 'value'],
                'citations' => [],
            ], JSON_THROW_ON_ERROR)]]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 100, 'cost' => 0.001],
        ]),
    ]);

    app(RunGtmProfileResearch::class)->handle($profile->id, $run->id, $user->id);

    $attempt = $run->attempts()->whereHas('phase', fn ($query) => $query->where('phase', 'generate'))->sole();
    expect($run->fresh()->safe_failure_code)->toBe('output_invalid')
        ->and($profile->fresh()->safe_error_code)->toBe('output_invalid')
        ->and($attempt->safe_error_code)->toBe('output_invalid')
        ->and(data_get($attempt->outcome, 'type'))->toBe('inspected_failure');
});

it('starts a new logical run when an owner retries after a paid result was lost', function (): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();
    $run->forceFill([
        'state' => GtmResearchRunState::Failed,
        'safe_failure_code' => 'paid_result_lost',
        'failed_at' => now(),
        'finished_at' => now(),
    ])->save();
    $profile->forceFill([
        'research_state' => GtmProfileResearchState::Failed,
        'safe_error_code' => 'paid_result_lost',
    ])->save();

    $retry = app(RefreshGtmProfileResearch::class)->retry($profile->fresh(), $user, 'paid-result-lost-retry');

    expect($retry->id)->not->toBe($run->id)
        ->and($retry->parent_run_id)->toBe($run->id)
        ->and($retry->lineage_type)->toBe('retry')
        ->and($retry->budget_run_id)->toBeNull()
        ->and($retry->state)->toBe(GtmResearchRunState::Queued)
        ->and($run->fresh()->safe_failure_code)->toBe('paid_result_lost')
        ->and($profile->fresh()->current_research_run_id)->toBe($retry->id);
});

it('starts a fresh logical run and closes the old ledger when a phase is exhausted', function (): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();
    config()->set('gtm.research.max_attempts', 1);
    $budgetRun = app(AiBudgetGateway::class)->openRun($workspace, $user, AiTask::AccountResearch);
    $run->forceFill(['budget_run_id' => $budgetRun->id])->save();
    $attempt = app(GtmResearchPhaseService::class)
        ->begin($run->fresh(), 'extract', hash('sha256', 'exhausted-phase'))['attempt'];
    app(GtmResearchPhaseService::class)->fail($attempt, 'research_failed');
    $run->forceFill([
        'state' => GtmResearchRunState::Failed,
        'safe_failure_code' => 'research_failed',
        'failed_at' => now(),
        'finished_at' => now(),
    ])->save();
    $profile->forceFill([
        'research_state' => GtmProfileResearchState::Failed,
        'safe_error_code' => 'research_failed',
    ])->save();

    $replacement = app(RefreshGtmProfileResearch::class)
        ->retry($profile->fresh(), $user, 'retry-exhausted-phase');

    expect($replacement->id)->not->toBe($run->id)
        ->and($replacement->parent_run_id)->toBe($run->id)
        ->and($replacement->budget_run_id)->toBeNull()
        ->and($run->fresh()->safe_failure_code)->toBe('retry_exhausted')
        ->and($budgetRun->fresh()->closed_at)->not->toBeNull()
        ->and($profile->fresh()->current_research_run_id)->toBe($replacement->id);
});

it('recovers ambiguous AF-01 operations by key and waits without repeating them', function (string $phase): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();
    gtmResearchCredential($workspace, $user, AiProviderName::Tavily);
    gtmResearchCredential($workspace, $user, AiProviderName::OpenRouter);
    $budgetRun = app(AiBudgetGateway::class)->openRun($workspace, $user, AiTask::AccountResearch);
    $run->forceFill(['budget_run_id' => $budgetRun->id])->save();
    $phases = app(GtmResearchPhaseService::class);
    $started = $phases->begin($run->fresh(), $phase, hash('sha256', 'ambiguous-'.$phase));
    $attempt = $started['attempt'];
    $operation = gtmResearchOperationForPhase(
        $workspace,
        $user,
        $budgetRun,
        $phase,
        $attempt->deterministic_idempotency_key,
    );
    $operation->forceFill(['status' => AiOperationStatus::Uncertain])->save();

    expect(fn () => $phases->begin($run->fresh(), $phase, hash('sha256', 'ambiguous-'.$phase)))
        ->toThrow(GtmResearchReconciliationPending::class);

    expect($attempt->fresh()->provider_operation_id)->toBe($operation->id)
        ->and($run->attempts()->count())->toBe(1);
    Http::assertNothingSent();
})->with(['extract', 'search', 'generate']);

it('moves unexpected executing provider failures into reconciliation for every phase', function (string $phase): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();
    gtmResearchCredential($workspace, $user, AiProviderName::Tavily);
    gtmResearchCredential($workspace, $user, AiProviderName::OpenRouter);
    $budgetRun = app(AiBudgetGateway::class)->openRun($workspace, $user, AiTask::AccountResearch);
    $run->forceFill(['budget_run_id' => $budgetRun->id])->save();
    $phases = app(GtmResearchPhaseService::class);
    $attempt = $phases->begin($run->fresh(), $phase, hash('sha256', 'unexpected-'.$phase))['attempt'];
    $operation = gtmResearchOperationForPhase(
        $workspace,
        $user,
        $budgetRun,
        $phase,
        $attempt->deterministic_idempotency_key,
    );
    $operation->forceFill([
        'status' => AiOperationStatus::Executing,
        'started_at' => now(),
    ])->save();

    expect(fn () => $phases->classifyFailure($attempt, 'output_invalid'))
        ->toThrow(GtmResearchReconciliationPending::class);
    expect($operation->fresh()->status)->toBe(AiOperationStatus::Uncertain)
        ->and($attempt->fresh()->status)->toBe('ambiguous')
        ->and($attempt->fresh()->safe_error_code)->toBe('provider_uncertain')
        ->and($run->attempts()->count())->toBe(1);
})->with(['extract', 'search', 'generate']);

it('treats fresh local attempts as leased work and only supersedes them after expiry', function (): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();
    config()->set('gtm.research.attempt_lease_seconds', 60);
    $phases = app(GtmResearchPhaseService::class);
    $hash = hash('sha256', 'fresh-attempt');
    $first = $phases->begin($run, 'extract', $hash)['attempt'];

    expect(fn () => $phases->begin($run->fresh(), 'extract', $hash))
        ->toThrow(GtmResearchPhaseBusy::class);
    expect($run->attempts()->count())->toBe(1);

    $first->forceFill([
        'started_at' => now()->subMinutes(2),
        'lease_expires_at' => now()->subMinute(),
    ])->save();
    $second = $phases->begin($run->fresh(), 'extract', $hash)['attempt'];

    expect($first->fresh()->status)->toBe('definitely_not_sent')
        ->and($second->attempt_number)->toBe(2)
        ->and($run->attempts()->count())->toBe(2);
});

it('fences a late worker before it can reserve or checkpoint after replacement', function (): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();
    gtmResearchCredential($workspace, $user, AiProviderName::Tavily);
    config()->set('gtm.research.attempt_lease_seconds', 60);
    $budgetRun = app(AiBudgetGateway::class)->openRun($workspace, $user, AiTask::AccountResearch);
    $run->forceFill(['budget_run_id' => $budgetRun->id])->save();
    $phases = app(GtmResearchPhaseService::class);
    $hash = hash('sha256', 'late-worker-fence');
    $old = $phases->begin($run->fresh(), 'extract', $hash)['attempt'];
    $old->forceFill(['lease_expires_at' => now()->subMinute()])->save();
    $current = $phases->begin($run->fresh(), 'extract', $hash)['attempt'];

    expect(function () use ($phases, $old, $workspace, $user, $budgetRun): void {
        $claimed = $phases->claimProviderBoundary($old);
        gtmResearchOperationForPhase(
            $workspace,
            $user,
            $budgetRun,
            'extract',
            $claimed->deterministic_idempotency_key,
        );
    })->toThrow(GtmResearchPhaseBusy::class);
    expect(fn () => $phases->checkpoint($old, ['sources' => []]))
        ->toThrow(GtmResearchPhaseBusy::class);

    expect(AiProviderOperation::query()->where('budget_run_id', $budgetRun->id)->count())->toBe(0)
        ->and($old->fresh()->status)->toBe('definitely_not_sent')
        ->and($current->attempt_number)->toBe(2)
        ->and($current->fencing_token)->toBeGreaterThan($old->fencing_token)
        ->and($current->lease_owner)->not->toBe($old->lease_owner);
});

it('honors fresh AF-01 operation leases and reconciles stale executing work', function (): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();
    gtmResearchCredential($workspace, $user, AiProviderName::Tavily);
    gtmResearchCredential($workspace, $user, AiProviderName::OpenRouter);
    config()->set('gtm.research.attempt_lease_seconds', 60);
    $budgetRun = app(AiBudgetGateway::class)->openRun($workspace, $user, AiTask::AccountResearch);
    $run->forceFill(['budget_run_id' => $budgetRun->id])->save();
    $phases = app(GtmResearchPhaseService::class);

    $extractAttempt = $phases->begin($run->fresh(), 'extract', hash('sha256', 'leased-reservation'))['attempt'];
    $reserved = gtmResearchOperationForPhase(
        $workspace,
        $user,
        $budgetRun,
        'extract',
        $extractAttempt->deterministic_idempotency_key,
    );
    expect(fn () => $phases->begin($run->fresh(), 'extract', hash('sha256', 'leased-reservation')))
        ->toThrow(GtmResearchPhaseBusy::class);

    $extractAttempt->forceFill(['lease_expires_at' => now()->subMinute()])->save();
    $reserved->forceFill(['reserved_at' => now()->subMinutes(2)])->save();
    $replacement = $phases->begin($run->fresh(), 'extract', hash('sha256', 'leased-reservation'))['attempt'];
    expect($reserved->fresh()->status)->toBe(AiOperationStatus::Failed)
        ->and($extractAttempt->fresh()->status)->toBe('definitely_not_sent')
        ->and($replacement->attempt_number)->toBe(2);

    $searchAttempt = $phases->begin($run->fresh(), 'search', hash('sha256', 'stale-execution'))['attempt'];
    $executing = gtmResearchOperationForPhase(
        $workspace,
        $user,
        $budgetRun,
        'search',
        $searchAttempt->deterministic_idempotency_key,
    );
    $executing->forceFill([
        'status' => AiOperationStatus::Executing,
        'started_at' => now(),
    ])->save();
    expect(fn () => $phases->begin($run->fresh(), 'search', hash('sha256', 'stale-execution')))
        ->toThrow(GtmResearchPhaseBusy::class);

    $searchAttempt->forceFill(['lease_expires_at' => now()->subMinute()])->save();
    $executing->forceFill(['started_at' => now()->subMinutes(2)])->save();
    expect(fn () => $phases->begin($run->fresh(), 'search', hash('sha256', 'stale-execution')))
        ->toThrow(GtmResearchReconciliationPending::class);
    expect($executing->fresh()->status)->toBe(AiOperationStatus::Uncertain)
        ->and($searchAttempt->fresh()->status)->toBe('ambiguous')
        ->and($run->attempts()->where('phase_id', $searchAttempt->phase_id)->count())->toBe(1);
});

it('repairs and completes a run after a worker crashes immediately after phase begin', function (): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();
    gtmResearchCredential($workspace, $user, AiProviderName::Tavily);
    gtmResearchCredential($workspace, $user, AiProviderName::OpenRouter);
    config()->set('gtm.research.attempt_lease_seconds', 60);
    $budgetRun = app(AiBudgetGateway::class)->openRun($workspace, $user, AiTask::AccountResearch);
    $run->forceFill([
        'state' => GtmResearchRunState::Running,
        'budget_run_id' => $budgetRun->id,
        'started_at' => now()->subMinutes(2),
    ])->save();
    $profile->forceFill(['research_state' => GtmProfileResearchState::Running])->save();
    $attempt = app(GtmResearchPhaseService::class)
        ->begin($run->fresh(), 'extract', hash('sha256', 'crash-after-begin'))['attempt'];
    $attempt->forceFill([
        'started_at' => now()->subMinutes(2),
        'lease_expires_at' => now()->subMinute(),
    ])->save();
    $run->dispatch()->firstOrFail()->forceFill([
        'status' => 'published',
        'published_at' => now()->subMinutes(2),
        'lease_owner' => null,
        'lease_expires_at' => null,
    ])->save();

    app(SweepGtmProfileResearchDispatches::class)->handle();

    $dispatch = $run->dispatch()->firstOrFail();
    expect($run->fresh()->state)->toBe(GtmResearchRunState::Queued)
        ->and($profile->fresh()->research_state)->toBe(GtmProfileResearchState::Queued)
        ->and($dispatch->status)->toBe('leased')
        ->and($dispatch->published_at)->toBeNull();

    Http::fake([
        'https://api.tavily.com/extract' => Http::response([
            'request_id' => 'crash-recovery-extract',
            'results' => [['url' => 'https://example.com', 'raw_content' => 'Short extract.']],
        ]),
        'https://api.tavily.com/search' => Http::response([
            'request_id' => 'crash-recovery-search',
            'results' => [['url' => 'https://example.com/about', 'content' => 'Search evidence.']],
        ]),
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'crash-recovery-generation',
            'choices' => [['message' => ['content' => gtmResearchResponseBody()]]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 100, 'cost' => 0.001],
        ]),
    ]);
    app(RunGtmProfileResearch::class)->handle($profile->id, $run->id, $user->id);

    expect($run->fresh()->state)->toBe(GtmResearchRunState::Ready)
        ->and($attempt->fresh()->status)->toBe('definitely_not_sent')
        ->and($run->attempts()->where('phase_id', $attempt->phase_id)->count())->toBe(2)
        ->and(AiBudgetRun::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
});

it('opens exactly one budget run after a zero-budget wait recovers', function (): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();
    gtmResearchCredential($workspace, $user, AiProviderName::Tavily);
    gtmResearchCredential($workspace, $user, AiProviderName::OpenRouter);
    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);
    AiBudgetSetting::query()->whereKey($workspace->id)->update(['monthly_limit_micros' => 0]);

    app(RunGtmProfileResearch::class)->handle($profile->id, $run->id, $user->id);
    expect($run->fresh()->state)->toBe(GtmResearchRunState::AwaitingBudget)
        ->and($run->fresh()->budget_run_id)->toBeNull()
        ->and($run->attempts()->count())->toBe(0);

    AiBudgetSetting::query()->whereKey($workspace->id)->update(['monthly_limit_micros' => 50_000_000]);
    Http::fake([
        'https://api.tavily.com/extract' => Http::response([
            'request_id' => 'budget-recovery-extract',
            'results' => [['url' => 'https://example.com', 'raw_content' => 'A short public company description.']],
        ]),
        'https://api.tavily.com/search' => Http::response([
            'request_id' => 'budget-recovery-search',
            'results' => [['url' => 'https://example.com/about', 'content' => 'Public corroborating evidence.']],
        ]),
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'budget-recovery-generation',
            'choices' => [['message' => ['content' => gtmResearchResponseBody()]]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 100, 'cost' => 0.001],
        ]),
    ]);

    expect(app(RunGtmProfileResearch::class)->recoverAwaitingRun($profile->fresh(), $run->fresh()))->toBeTrue();
    app(RunGtmProfileResearch::class)->handle($profile->id, $run->id, $user->id);

    expect($run->fresh()->state)->toBe(GtmResearchRunState::Ready)
        ->and(AiBudgetRun::query()->where('workspace_id', $workspace->id)->count())->toBe(1)
        ->and($run->fresh()->budget_run_id)->not->toBeNull();
});

it('reuses the same provider attempt after a next-phase budget wait', function (): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();
    gtmResearchCredential($workspace, $user, AiProviderName::Tavily);
    gtmResearchCredential($workspace, $user, AiProviderName::OpenRouter);
    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);
    AiBudgetSetting::query()->whereKey($workspace->id)->update(['monthly_limit_micros' => 8_000]);
    Http::fake([
        'https://api.tavily.com/extract' => Http::response([
            'request_id' => 'phase-budget-extract',
            'results' => [['url' => 'https://example.com', 'raw_content' => 'Short extract.']],
        ]),
        'https://api.tavily.com/search' => Http::response([
            'request_id' => 'phase-budget-search',
            'results' => [['url' => 'https://example.com/about', 'content' => 'Search evidence.']],
        ]),
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'phase-budget-generation',
            'choices' => [['message' => ['content' => gtmResearchResponseBody()]]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 100, 'cost' => 0.001],
        ]),
    ]);

    app(RunGtmProfileResearch::class)->handle($profile->id, $run->id, $user->id);

    $searchPhase = $run->phases()->where('phase', 'search')->firstOrFail();
    $waitingAttempt = $searchPhase->attempts()->sole();
    expect($run->fresh()->state)->toBe(GtmResearchRunState::AwaitingBudget)
        ->and($waitingAttempt->status)->toBe('waiting_budget')
        ->and($searchPhase->attempts()->count())->toBe(1);
    Http::assertSentCount(1);

    AiBudgetPeriod::query()->where('workspace_id', $workspace->id)->update(['limit_snapshot_micros' => 50_000_000]);
    app(RunGtmProfileResearch::class)->handle($profile->id, $run->id, $user->id);

    expect($run->fresh()->state)->toBe(GtmResearchRunState::Ready)
        ->and($searchPhase->attempts()->count())->toBe(1)
        ->and($waitingAttempt->fresh()->status)->toBe('succeeded')
        ->and(AiBudgetRun::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
    Http::assertSentCount(3);
});

it('keeps one open budget ledger across a safe same-run retry', function (): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();
    gtmResearchCredential($workspace, $user, AiProviderName::Tavily);
    gtmResearchCredential($workspace, $user, AiProviderName::OpenRouter);
    Http::fake([
        'https://api.tavily.com/extract' => Http::sequence()
            ->push([], 429, ['Retry-After' => '60'])
            ->push([
                'request_id' => 'safe-retry-extract',
                'results' => [['url' => 'https://example.com', 'raw_content' => 'Short extract.']],
            ]),
        'https://api.tavily.com/search' => Http::response([
            'request_id' => 'safe-retry-search',
            'results' => [['url' => 'https://example.com/about', 'content' => 'Public corroborating evidence.']],
        ]),
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'safe-retry-generation',
            'choices' => [['message' => ['content' => gtmResearchResponseBody()]]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 100, 'cost' => 0.001],
        ]),
    ]);

    app(RunGtmProfileResearch::class)->handle($profile->id, $run->id, $user->id);

    $budgetRun = $run->fresh()->budgetRun()->firstOrFail();
    expect($run->fresh()->state)->toBe(GtmResearchRunState::Failed)
        ->and($run->fresh()->safe_failure_code)->toBe('rate_limited')
        ->and($budgetRun->status)->toBe('open')
        ->and($budgetRun->closed_at)->toBeNull()
        ->and($run->attempts()->count())->toBe(1);

    $retry = app(RefreshGtmProfileResearch::class)->retry($profile->fresh(), $user, 'safe-failure-retry');
    expect($retry->id)->toBe($run->id)
        ->and($retry->budget_run_id)->toBe($budgetRun->id);

    app(RunGtmProfileResearch::class)->handle($profile->id, $run->id, $user->id);

    expect($run->fresh()->state)->toBe(GtmResearchRunState::Ready)
        ->and($run->attempts()->whereHas('phase', fn ($query) => $query->where('phase', 'extract'))->count())->toBe(2)
        ->and($budgetRun->fresh()->status)->toBe('closed')
        ->and(AiBudgetRun::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
});

it('does not replace a closed logical budget run or admit an exhausted one', function (): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();
    gtmResearchCredential($workspace, $user, AiProviderName::Tavily);
    gtmResearchCredential($workspace, $user, AiProviderName::OpenRouter);
    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);
    $budgetRun = app(AiBudgetGateway::class)->openRun($workspace, $user, AiTask::AccountResearch);
    $run->forceFill(['budget_run_id' => $budgetRun->id])->save();
    app(AiBudgetGateway::class)->closeRun($budgetRun);

    expect(app(AiBudgetGateway::class)->mayAdmit($workspace, AiTask::AccountResearch, 1, $budgetRun->fresh()))->toBeFalse();
    app(RunGtmProfileResearch::class)->handle($profile->id, $run->id, $user->id);

    expect($run->fresh()->state)->toBe(GtmResearchRunState::AwaitingBudget)
        ->and($run->fresh()->budget_run_id)->toBe($budgetRun->id)
        ->and(AiBudgetRun::query()->where('workspace_id', $workspace->id)->count())->toBe(1)
        ->and($run->attempts()->count())->toBe(0);

    $retry = app(RefreshGtmProfileResearch::class)->retry($profile->fresh(), $user, 'closed-budget-retry');
    expect($retry->id)->not->toBe($run->id)
        ->and($retry->parent_run_id)->toBe($run->id)
        ->and($retry->budget_run_id)->toBeNull()
        ->and($budgetRun->fresh()->status)->toBe('closed');

    $exhausted = app(AiBudgetGateway::class)->openRun($workspace, $user, AiTask::AccountResearch);
    $exhausted->forceFill(['committed_micros' => $exhausted->limit_snapshot_micros])->save();
    expect(app(AiBudgetGateway::class)->mayAdmit($workspace, AiTask::AccountResearch, 1, $exhausted->fresh()))->toBeFalse();
});

it('reconciles a superseded executing hold and then cancels its terminal ledger', function (): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();
    gtmResearchCredential($workspace, $user, AiProviderName::Tavily);
    gtmResearchCredential($workspace, $user, AiProviderName::OpenRouter);
    config()->set('gtm.research.attempt_lease_seconds', 60);
    $budgetRun = app(AiBudgetGateway::class)->openRun($workspace, $user, AiTask::AccountResearch);
    $run->forceFill(['budget_run_id' => $budgetRun->id])->save();
    $attempt = app(GtmResearchPhaseService::class)
        ->begin($run->fresh(), 'extract', hash('sha256', 'superseded-executing'))['attempt'];
    $operation = gtmResearchOperationForPhase(
        $workspace,
        $user,
        $budgetRun,
        'extract',
        $attempt->deterministic_idempotency_key,
    );
    app(GtmResearchPhaseService::class)->attachProviderOperation($attempt, $operation->id);
    $operation->forceFill([
        'status' => AiOperationStatus::Executing,
        'started_at' => now()->subMinutes(2),
    ])->save();

    $replacement = app(RefreshGtmProfileResearch::class)
        ->refreshForWebsiteChange($profile->fresh(), $user, 'supersede-executing-run');
    expect($replacement->id)->not->toBe($run->id)
        ->and($run->fresh()->state)->toBe(GtmResearchRunState::Cancelled)
        ->and($budgetRun->fresh()->status)->toBe('open');

    app(SweepGtmProfileResearchDispatches::class)->handle();
    expect($operation->fresh()->status)->toBe(AiOperationStatus::Uncertain)
        ->and($budgetRun->fresh()->status)->toBe('open');

    app(AiBudgetGateway::class)->settle(
        $operation->fresh(),
        1_000,
        1,
        'upper_bound_estimate',
        finalStatus: AiOperationStatus::Reconciled,
    );
    app(SweepGtmProfileResearchDispatches::class)->handle();

    expect($budgetRun->fresh()->status)->toBe('cancelled')
        ->and($budgetRun->fresh()->closed_at)->not->toBeNull()
        ->and($budgetRun->fresh()->held_micros)->toBe(0);
});

it('retries durable budget closure for an already-ready research run', function (): void {
    [$user, $workspace, $profile, $run] = gtmResearchFixture();
    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);
    $budgetRun = app(AiBudgetGateway::class)->openRun($workspace, $user, AiTask::AccountResearch);
    $run->forceFill([
        'state' => GtmResearchRunState::Ready,
        'budget_run_id' => $budgetRun->id,
        'finished_at' => now(),
    ])->save();
    $profile->forceFill(['research_state' => GtmProfileResearchState::Ready])->save();

    app(SweepGtmProfileResearchDispatches::class)->handle();

    expect($budgetRun->fresh()->status)->toBe('closed')
        ->and($budgetRun->fresh()->closed_at)->not->toBeNull();
});

it('preserves a distinct source observation per refresh run while deduping within one run', function (): void {
    [$user, $workspace, $profile, $firstRun] = gtmResearchFixture();
    $sources = [[
        'url' => 'https://example.com/about',
        'title' => 'About',
        'excerpt' => 'The same public evidence was observed again.',
        'source_type' => 'extract',
        'published_at' => null,
    ]];
    $recorder = app(PersistGtmProfileResearchSources::class);
    $first = $recorder->handle($profile, $firstRun, $sources);
    $replayed = $recorder->handle($profile, $firstRun, $sources);

    $secondRun = GtmProfileResearchRun::factory()->create([
        'gtm_profile_id' => $profile->id,
        'website_url_snapshot' => 'https://example.com',
        'initiated_by_user_id' => $user->id,
    ]);
    $profile->forceFill(['current_research_run_id' => $secondRun->id])->save();
    $second = $recorder->handle($profile->fresh(), $secondRun, $sources);

    expect($replayed)->toBe($first)
        ->and($second[0])->not->toBe($first[0])
        ->and($profile->sources()->count())->toBe(2)
        ->and($profile->sources()->pluck('research_run_id')->sort()->values()->all())
        ->toBe(collect([$firstRun->id, $secondRun->id])->sort()->values()->all());
});

it('keeps URL normalization cheap and caps unique DNS work', function (): void {
    expect(PublicWebsiteUrl::normalize('unresolved-outboss-host-9f3b7d.com'))
        ->toBe('https://unresolved-outboss-host-9f3b7d.com');

    config()->set('gtm.network.max_dns_hosts_per_batch', 2);
    expect(fn () => PublicWebsiteUrl::assertPublicNetworkBatch([
        'https://one.github.com',
        'https://two.github.com',
        'https://three.github.com',
    ]))->toThrow(InvalidArgumentException::class, 'too many DNS lookups');
    expect(fn () => PublicWebsiteUrl::normalize('http://127.0.0.1'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => PublicWebsiteUrl::normalize('http://example.com'))
        ->toThrow(InvalidArgumentException::class, 'must use HTTPS');
});

it('builds exact-bound and multibyte research context as complete JSON', function (): void {
    $service = app(RunGtmProfileResearch::class);
    $method = new ReflectionMethod($service, 'boundedResearchContext');
    $method->setAccessible(true);
    $sources = [[
        'url' => 'https://example.com/about',
        'title' => 'Olá – müşteri kanıtı',
        'excerpt' => str_repeat(' доказательство🙂', 200),
        'source_type' => 'extract',
        'published_at' => null,
    ]];

    config()->set('gtm.research.max_context_bytes', 32 * 1024);
    $full = $method->invoke($service, 'https://example.com', $sources);
    $exactLength = strlen($full);
    config()->set('gtm.research.max_context_bytes', $exactLength);
    $exact = $method->invoke($service, 'https://example.com', $sources);

    expect($exact)->toBe($full)
        ->and(strlen($exact))->toBe($exactLength)
        ->and(mb_check_encoding($exact, 'UTF-8'))->toBeTrue()
        ->and(json_decode($exact, true, 32, JSON_THROW_ON_ERROR))->toBeArray();

    config()->set('gtm.research.max_context_bytes', $exactLength - 1);
    $bounded = $method->invoke($service, 'https://example.com', $sources);
    expect(strlen($bounded))->toBeLessThanOrEqual($exactLength - 1)
        ->and(mb_check_encoding($bounded, 'UTF-8'))->toBeTrue()
        ->and(json_decode($bounded, true, 32, JSON_THROW_ON_ERROR))->toBeArray();
});
