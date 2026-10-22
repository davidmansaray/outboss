<?php

use App\Domain\Ai\Contracts\AiProviderAdapter;
use App\Domain\Ai\Contracts\LlmProvider;
use App\Domain\Ai\DTOs\LlmRequest;
use App\Domain\Ai\DTOs\LlmResult;
use App\Domain\Ai\DTOs\ProviderTestResult;
use App\Domain\Ai\Enums\AiProviderName;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Exceptions\SafeProviderException;
use App\Domain\Ai\Providers\AiProviderRegistry;
use App\Domain\Ai\Providers\DeterministicOpenRouterAdapter;
use App\Domain\Ai\Services\AiBudgetGateway;
use App\Domain\Ai\Services\AiWorkspaceSettings;
use App\Domain\Api\TokenScope;
use App\Domain\Campaigns\DTOs\EnrollmentCandidate;
use App\Domain\Campaigns\Enums\ImportSource;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Exceptions\GovernedCampaignInputMutation;
use App\Domain\Campaigns\Exceptions\GovernedCampaignLaunchRequired;
use App\Domain\Campaigns\Exceptions\GovernedExecutionBlocked;
use App\Domain\Campaigns\Services\AcquireRecipientSendLease;
use App\Domain\Campaigns\Services\CommitRecipientImport;
use App\Domain\Campaigns\Services\EnrollPeopleInCampaign;
use App\Domain\Campaigns\Services\GovernedCampaignCompilationCapability;
use App\Domain\Campaigns\Services\GovernedCampaignInputGuard;
use App\Domain\Campaigns\Services\LaunchCampaignService;
use App\Domain\Campaigns\Services\PauseResumeRecipient;
use App\Domain\Campaigns\Services\PreviewRecipientImport;
use App\Domain\Campaigns\Services\PublishSequenceVersion;
use App\Domain\Campaigns\Services\RenderSequenceMessage;
use App\Domain\Campaigns\Services\ScheduleDueCampaignSends;
use App\Domain\Campaigns\Services\SendCampaignMessage;
use App\Domain\Campaigns\Services\SequenceStepEditor;
use App\Domain\Campaigns\Services\SpintaxTemplate;
use App\Domain\Campaigns\Services\StopCampaignRecipient;
use App\Domain\Campaigns\Services\TestSequenceStep;
use App\Domain\Intelligence\Support\CanonicalIntelligence;
use App\Domain\Mail\Enums\SendResolution;
use App\Domain\Mail\Providers\FakeMailProvider;
use App\Domain\Mail\Services\CloneCampaignForExperiment;
use App\Domain\Mail\Services\EnsureConnectionUsable;
use App\Domain\Mail\Services\ReconcileUnknownSend;
use App\Domain\Mail\Services\ResolveManualReviewSend;
use App\Domain\Objectives\Enums\PolicyEvaluationStage;
use App\Domain\Objectives\Exceptions\ApprovalConflict;
use App\Domain\Objectives\Exceptions\ObjectiveRunConflict;
use App\Domain\Objectives\Services\AdvanceObjectiveRun;
use App\Domain\Objectives\Services\ApproveExecutionPlan;
use App\Domain\Objectives\Services\BuildObjectiveDraftPrompt;
use App\Domain\Objectives\Services\CancelObjectiveRun;
use App\Domain\Objectives\Services\CompileExecutionPlan;
use App\Domain\Objectives\Services\CreateGtmExperiment;
use App\Domain\Objectives\Services\CreateObjective;
use App\Domain\Objectives\Services\CreateStrategy;
use App\Domain\Objectives\Services\DraftObjectiveMessages;
use App\Domain\Objectives\Services\ExecuteObjectiveRunPhase;
use App\Domain\Objectives\Services\FreezeExperimentAssignments;
use App\Domain\Objectives\Services\GovernedExecutionCheckpoint;
use App\Domain\Objectives\Services\LaunchObjectiveExecution;
use App\Domain\Objectives\Services\ObjectiveReadiness;
use App\Domain\Objectives\Services\ObjectiveRunPreflight;
use App\Domain\Objectives\Services\RecordObjectiveExposure;
use App\Domain\Objectives\Services\RecoverObjectiveRuns;
use App\Domain\Objectives\Services\RejectExecutionPlan;
use App\Domain\Objectives\Services\RevokeExecutionApproval;
use App\Domain\Objectives\Services\StartObjectiveRun;
use App\Domain\Objectives\Services\SupersedeObjectiveRun;
use App\Domain\Objectives\Services\TransitionObjective;
use App\Domain\Objectives\Services\ValidateExecutionPlan;
use App\Domain\Objectives\Services\VersionObjective;
use App\Domain\Objectives\Support\ApprovalManifest;
use App\Domain\Objectives\Support\DeterministicAccountAssignment;
use App\Domain\Objectives\Support\DeterministicMessageTemplate;
use App\Domain\Objectives\Support\MessageEvidenceContract;
use App\Domain\People\Services\DeleteAccount;
use App\Domain\People\Services\PersonEditor;
use App\Domain\Workspaces\Services\DeleteWorkspace;
use App\Mcp\OutbossServer;
use App\Mcp\Tools\EnrollPeople;
use App\Mcp\Tools\LaunchCampaign;
use App\Mcp\Tools\SendTestStep;
use App\Mcp\Tools\UpdateCampaign;
use App\Mcp\Tools\UpdateRecipient;
use App\Models\Account;
use App\Models\AiProviderCallAttempt;
use App\Models\AiProviderCredential;
use App\Models\AiProviderOperation;
use App\Models\AiTaskSetting;
use App\Models\AiUsageLedger;
use App\Models\ApprovalBatch;
use App\Models\ApprovalEvent;
use App\Models\ApprovalGrant;
use App\Models\AudienceSnapshotMember;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\ExecutionActionEvent;
use App\Models\ExecutionPlanActionCompilation;
use App\Models\ExecutionPlanCampaign;
use App\Models\ExecutionPlanCompilation;
use App\Models\GtmExperiment;
use App\Models\GtmExperimentAssignment;
use App\Models\GtmExperimentAssignmentMember;
use App\Models\Mailbox;
use App\Models\MailExperiment;
use App\Models\MailExperimentCohort;
use App\Models\Message;
use App\Models\ObjectiveRun;
use App\Models\ObjectiveRunDispatch;
use App\Models\ObjectiveRunPhase;
use App\Models\OfferVersion;
use App\Models\Person;
use App\Models\PolicyEvaluation;
use App\Models\ProviderConnection;
use App\Models\ReasonToContactClaim;
use App\Models\ReasonToContactVersion;
use App\Models\RecipientImport;
use App\Models\ResearchClaim;
use App\Models\ResearchClaimEvidence;
use App\Models\SendAttempt;
use App\Models\SequenceStep;
use App\Models\SequenceVersion;
use App\Models\Strategy;
use App\Models\SuppressionEntry;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

require_once __DIR__.'/../../Support/ObjectiveScenarioFixtures.php';

beforeEach(function (): void {
    // Keep business-day send expectations independent of the host calendar.
    $this->travelTo(Carbon::parse('2026-09-07 10:00:00 UTC'));
});

it('uses a stable cross-platform HMAC basis-point bucket', function (): void {
    expect(DeterministicAccountAssignment::bucket(str_repeat('a', 32), 42))->toBe(5907)
        ->and(fn () => DeterministicAccountAssignment::bucket('short', 42))->toThrow(InvalidArgumentException::class);
});

it('freezes every included Person exactly once at the Account assignment unit', function (): void {
    $fixture = s3IncrementTwoObjectiveFixture();
    $control = s3IncrementTwoStrategy($fixture, 'Control');
    $treatment = s3IncrementTwoStrategy($fixture, 'Treatment', [
        'proof_summary' => 'The verified frozen Claim is the primary message proof.',
    ]);
    $experiment = app(CreateGtmExperiment::class)->handle($fixture['owner']->currentWorkspace(), $fixture['owner'], [
        'objective_version_id' => $fixture['version']->id,
        'name' => 'Positioning pilot',
        'hypothesis' => 'Specific proof improves acceptance.',
        'arms' => [
            [
                'strategy_version_id' => $control->current_version_id,
                'key' => 'control',
                'label' => 'Control',
                'allocation_basis_points' => 5000,
                'declared_differences' => [],
            ],
            [
                'strategy_version_id' => $treatment->current_version_id,
                'key' => 'treatment',
                'label' => 'Treatment',
                'allocation_basis_points' => 5000,
                'declared_differences' => ['proof' => 'Lead with verified proof'],
            ],
        ],
    ]);
    $assigned = app(FreezeExperimentAssignments::class)->handle($experiment, $fixture['owner'], 1);
    $replay = app(FreezeExperimentAssignments::class)->handle($assigned, $fixture['owner'], 999);

    expect($assigned->status->value)->toBe('assigned')
        ->and($assigned->assignment_seed_hash)->toHaveLength(64)
        ->and($assigned->assignments)->toHaveCount(2)
        ->and($replay->id)->toBe($assigned->id)
        ->and(GtmExperimentAssignmentMember::query()->count())->toBe(4)
        ->and(GtmExperimentAssignmentMember::query()->distinct('audience_snapshot_member_id')->count('audience_snapshot_member_id'))->toBe(4);
    foreach (GtmExperimentAssignment::query()->with('members')->get() as $assignment) {
        expect($assignment->members)->toHaveCount(2);
    }
    expect(fn () => GtmExperimentAssignment::query()->firstOrFail()->forceFill(['bucket' => 1])->save())
        ->toThrow(LogicException::class);

    app(DeleteAccount::class)->handle($fixture['accounts'][0], $fixture['owner']);
    expect($assigned->fresh()->status->value)->toBe('paused')
        ->and(GtmExperimentAssignment::query()->whereNull('account_id')->count())->toBe(1)
        ->and(GtmExperimentAssignmentMember::query()->whereNull('person_id')->count())->toBe(2);
});

it('maps every allowed treatment declaration to its real outbound Strategy fields', function (string $dimension, array $changes): void {
    $fixture = s3IncrementTwoObjectiveFixture(1, 1);
    $control = s3IncrementTwoStrategy($fixture, 'Control');
    $treatment = s3IncrementTwoStrategy($fixture, 'Treatment', $changes);
    $experiment = app(CreateGtmExperiment::class)->handle(
        $fixture['owner']->currentWorkspace(),
        $fixture['owner'],
        [
            'objective_version_id' => $fixture['version']->id,
            'name' => ucfirst($dimension).' treatment integrity',
            'arms' => [
                [
                    'strategy_version_id' => $control->current_version_id,
                    'key' => 'control',
                    'label' => 'Control',
                    'allocation_basis_points' => 5000,
                    'declared_differences' => [],
                ],
                [
                    'strategy_version_id' => $treatment->current_version_id,
                    'key' => 'treatment',
                    'label' => 'Treatment',
                    'allocation_basis_points' => 5000,
                    'declared_differences' => [$dimension => 'Exact governed treatment.'],
                ],
            ],
        ],
    );
    $templates = app(DeterministicMessageTemplate::class);
    $controlHash = $templates->strategyContract($control->currentVersion()->firstOrFail())['projection_hash'];
    $treatmentHash = $templates->strategyContract($treatment->currentVersion()->firstOrFail())['projection_hash'];

    expect($controlHash)->not->toBe($treatmentHash)
        ->and($experiment->arms()->where('key', 'treatment')->sole()->declared_differences)
        ->toBe([$dimension => 'Exact governed treatment.']);
})->with([
    'positioning' => ['positioning', ['positioning' => 'Operational clarity for evidence-led revenue teams.']],
    'proof' => ['proof', ['proof_summary' => 'Verified frozen evidence is the primary support.']],
    'cta' => ['cta', ['cta_text' => 'Reserve the approved workshop']],
    'copy maps conservatively to problem frame' => ['copy', ['problem_frame' => 'Reviewed research is difficult to activate safely.']],
]);

it('rejects false undeclared and invariant-breaking treatment declarations', function (string $condition): void {
    $fixture = s3IncrementTwoObjectiveFixture(1, 1);
    $control = s3IncrementTwoStrategy($fixture, 'Control');
    $changes = match ($condition) {
        'identical' => [],
        'false_declared' => [],
        'undeclared' => ['positioning' => 'A materially different governed position.'],
        'invariant' => ['message_generation_contract' => ['message_count' => 1, 'maximum_words' => 121]],
        default => throw new InvalidArgumentException('Unknown treatment integrity condition.'),
    };
    $declared = match ($condition) {
        'identical' => [],
        'false_declared' => ['proof' => 'A difference that does not exist.'],
        'undeclared' => ['proof' => 'The wrong declared dimension.'],
        'invariant' => ['copy' => 'A limit is not a copy treatment.'],
        default => [],
    };
    $treatment = s3IncrementTwoStrategy($fixture, 'Treatment', $changes);

    expect(fn () => app(CreateGtmExperiment::class)->handle(
        $fixture['owner']->currentWorkspace(),
        $fixture['owner'],
        [
            'objective_version_id' => $fixture['version']->id,
            'name' => 'Rejected '.$condition.' treatment',
            'arms' => [
                [
                    'strategy_version_id' => $control->current_version_id,
                    'key' => 'control',
                    'allocation_basis_points' => 5000,
                    'declared_differences' => [],
                ],
                [
                    'strategy_version_id' => $treatment->current_version_id,
                    'key' => 'treatment',
                    'allocation_basis_points' => 5000,
                    'declared_differences' => $declared,
                ],
            ],
        ],
    ))->toThrow(ValidationException::class)
        ->and(GtmExperiment::query()->count())->toBe(0);
})->with(['identical', 'false_declared', 'undeclared', 'invariant']);

it('rejects unsafe or oversized outbound Strategy text without cleaning it', function (array $changes): void {
    $fixture = s3IncrementTwoObjectiveFixture(1, 1);

    expect(fn () => s3IncrementTwoStrategy($fixture, 'Unsafe Strategy', $changes))
        ->toThrow(ValidationException::class)
        ->and(Strategy::query()->count())->toBe(0);
})->with([
    'surrounding whitespace' => [['positioning' => ' Leading whitespace is not accepted.']],
    'Unicode surrounding whitespace' => [['positioning' => "\u{00A0}Unicode whitespace is not accepted."]],
    'unsafe control' => [['positioning' => "Unsafe\0control"]],
    'header-like line' => [['positioning' => 'Subject: forged treatment']],
    'HTML' => [['proof_summary' => '<strong>Fabricated proof</strong>']],
    'HTML-like delimiter' => [['proof_summary' => '<script without a closing delimiter']],
    'URL-like text' => [['cta_text' => 'Book at https://attacker.example/path']],
    'bare domain destination' => [['cta_text' => 'Book at attacker.example/path']],
    'email destination' => [['cta_text' => 'Email attacker@example.test']],
    'placeholder' => [['positioning' => 'Special help for {{company}}']],
    'malformed braces' => [['positioning' => 'Special help for {company']],
    'spintax' => [['positioning' => '{Fast|Guaranteed} results']],
    'fabricated guarantee' => [['proof_summary' => 'We guarantee results for every account.']],
    'sensitive inference' => [['problem_frame' => 'A medical condition makes this urgent.']],
    'false familiarity' => [['positioning' => 'Our previous conversation established the need.']],
    'causal claim' => [['proof_summary' => 'Hiring caused the revenue change.']],
    'per-field byte limit' => [['positioning' => str_repeat('é', 121)]],
    'per-field word limit' => [['positioning' => implode(' ', array_fill(0, 31, 'word'))]],
    'combined word limit' => [[
        'positioning' => implode(' ', array_fill(0, 21, 'position')),
        'problem_frame' => implode(' ', array_fill(0, 21, 'problem')),
        'proof_summary' => implode(' ', array_fill(0, 19, 'proof')),
    ]],
]);

it('rejects lossy Strategy proof placeholder and generation contracts', function (string $condition): void {
    $fixture = s3IncrementTwoObjectiveFixture(1, 1);
    $changes = match ($condition) {
        'duplicate_claim' => ['proof_claim_ids' => [$fixture['claim_id'], $fixture['claim_id']]],
        'malformed_claim' => ['proof_claim_ids' => [$fixture['claim_id'], 'not-a-uuid']],
        'missing_placeholder' => ['personalisation_contract' => ['allowed_placeholders' => ['company']]],
        'duplicate_placeholder' => ['personalisation_contract' => ['allowed_placeholders' => ['company', 'first_name', 'company']]],
        'coerced_limit' => ['message_generation_contract' => ['message_count' => 1, 'maximum_words' => '120']],
        default => throw new InvalidArgumentException('Unknown Strategy contract condition.'),
    };

    expect(fn () => s3IncrementTwoStrategy($fixture, 'Lossy Strategy', $changes))
        ->toThrow(ValidationException::class)
        ->and(Strategy::query()->count())->toBe(0);
})->with(['duplicate_claim', 'malformed_claim', 'missing_placeholder', 'duplicate_placeholder', 'coerced_limit']);

it('rejects stale locks unsafe caps and cross-workspace Objective inputs', function (): void {
    $fixture = s3IncrementTwoObjectiveFixture(1, 1);

    expect(fn () => app(VersionObjective::class)->handle($fixture['objective'], $fixture['owner'], [], 1))
        ->toThrow(RuntimeException::class, 'stale_version');

    $foreign = User::factory()->create();
    $foreignObjective = app(CreateObjective::class)->handle($foreign->currentWorkspace(), $foreign);
    $input = $fixture['version']->toArray();
    $input['maximum_people'] = (int) config('objectives.safety.maximum_people') + 1;
    expect(fn () => app(VersionObjective::class)->handle($foreignObjective, $foreign, $input, 1))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(CreateStrategy::class)->handle($foreign->currentWorkspace(), $foreign, [
            'objective_version_id' => $fixture['version']->id,
        ]))->toThrow(ModelNotFoundException::class);
});

it('fails readiness closed when reviewed evidence Offer or mailbox state is unavailable', function (): void {
    $fixture = s3IncrementTwoObjectiveFixture(1, 1);
    $readiness = app(ObjectiveReadiness::class)->handle($fixture['objective']);

    expect($readiness->ready())->toBeFalse()
        ->and(collect($readiness->blockers)->pluck('code')->all())->toContain(
            'qualification_changed',
            'conversion_definition_unavailable',
            'offer_not_active',
            'mailbox_unavailable',
        )
        ->and($readiness->inputHash)->toHaveLength(64)
        ->and(fn () => app(TransitionObjective::class)->handle(
            $fixture['objective']->fresh(),
            $fixture['owner'],
            'ready',
            2,
        ))->toThrow(ValidationException::class);
});

it('accepts an Objective horizon exactly at the configured pilot maximum and preserves its future start', function (): void {
    $fixture = s3IncrementTwoObjectiveFixture(1, 1);
    $startsAt = now()->addDay();
    $maximumDays = (int) config('objectives.safety.maximum_horizon_days');
    $endsAt = $startsAt->copy()->addDays($maximumDays);
    $input = $fixture['version']->toArray();
    $input['name'] = 'Exactly bounded Objective';
    $input['starts_at'] = $startsAt->toAtomString();
    $input['ends_at'] = $endsAt->toAtomString();

    $version = app(VersionObjective::class)->handle(
        $fixture['objective']->fresh(),
        $fixture['owner'],
        $input,
        2,
    );

    expect($maximumDays)->toBe(30)
        ->and($version->starts_at->toAtomString())->toBe($startsAt->toAtomString())
        ->and($version->ends_at->toAtomString())->toBe($endsAt->toAtomString());
});

it('rejects expired and overlong Objective horizons while versioning', function (string $condition): void {
    $fixture = s3IncrementTwoObjectiveFixture(1, 1);
    $startsAt = $condition === 'expired' ? now()->subDays(2) : now()->addDay();
    $endsAt = $condition === 'expired'
        ? now()->subDay()
        : $startsAt->copy()->addDays((int) config('objectives.safety.maximum_horizon_days'))->addSecond();
    $input = $fixture['version']->toArray();
    $input['name'] = 'Invalid horizon '.$condition;
    $input['starts_at'] = $startsAt->toAtomString();
    $input['ends_at'] = $endsAt->toAtomString();

    expect(fn () => app(VersionObjective::class)->handle(
        $fixture['objective']->fresh(),
        $fixture['owner'],
        $input,
        2,
    ))->toThrow(ValidationException::class)
        ->and($fixture['objective']->versions()->count())->toBe(1);
})->with(['expired', 'overlong']);

it('fails readiness and run preflight for invalid persisted Objective horizons', function (string $condition, string $code): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $startsAt = now()->addDay();
    $endsAt = match ($condition) {
        'expired' => now()->subSecond(),
        'inverted' => $startsAt->copy()->subSecond(),
        'overlong' => $startsAt->copy()->addDays((int) config('objectives.safety.maximum_horizon_days'))->addSecond(),
    };
    if ($condition === 'expired') {
        $startsAt = now()->subDay();
    } elseif ($condition === 'inverted') {
        $startsAt = now()->addDays(2);
    }
    DB::table('objective_versions')->where('id', $fixture['version']->id)->update([
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
    ]);

    $readiness = app(ObjectiveReadiness::class)->handle($fixture['objective']);
    $preflight = app(ObjectiveRunPreflight::class)->handle($fixture['objective'], $fixture['experiment']);

    expect($readiness->ready())->toBeFalse()
        ->and(collect($readiness->blockers)->pluck('code')->all())->toContain($code)
        ->and($preflight->ready())->toBeFalse()
        ->and(collect($preflight->blockers)->pluck('code')->all())->toContain($code)
        ->and(fn () => app(StartObjectiveRun::class)->handle(
            $fixture['objective'],
            $fixture['experiment'],
            $fixture['owner'],
            'invalid-horizon-'.$condition,
        ))->toThrow(ValidationException::class)
        ->and(ObjectiveRun::query()->count())->toBe(0)
        ->and(AiProviderOperation::query()->count())->toBe(0);
})->with([
    'expired' => ['expired', 'objective_horizon_expired'],
    'inverted' => ['inverted', 'objective_horizon_invalid'],
    'overlong' => ['overlong', 'objective_horizon_too_long'],
]);

it('fails readiness and preflight when the selected Reason to Contact loses its frozen binding', function (string $condition): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture(2);
    s3InvalidateSelectedReason($fixture, $condition);

    $readiness = app(ObjectiveReadiness::class)->handle($fixture['objective']);
    $preflight = app(ObjectiveRunPreflight::class)->handle($fixture['objective'], $fixture['experiment']);

    expect($readiness->ready())->toBeFalse()
        ->and(collect($readiness->blockers)->pluck('code')->all())->toContain('reason_to_contact_unavailable')
        ->and($preflight->ready())->toBeFalse()
        ->and(collect($preflight->blockers)->pluck('code')->all())->toContain('reason_to_contact_unavailable')
        ->and(fn () => app(StartObjectiveRun::class)->handle(
            $fixture['objective'],
            $fixture['experiment'],
            $fixture['owner'],
            'invalid-reason-'.$condition,
        ))->toThrow(ValidationException::class)
        ->and(AiProviderOperation::query()->count())->toBe(0)
        ->and(DB::table('execution_plans')->count())->toBe(0)
        ->and(DB::table('execution_plan_actions')->count())->toBe(0);
})->with(['stale', 'expired', 'cross_account', 'cross_decision', 'hash_mismatch']);

it('returns the active outreach policy maximum as the effective run cap when it is lower', function (): void {
    $fixture = s3IncrementThreeReadyFixture();
    $preflight = app(ObjectiveRunPreflight::class);
    $before = $preflight->handle($fixture['objective'], $fixture['experiment']);
    $policy = s3SetOutreachPolicyMaximum($fixture['owner'], 90_000);

    $limited = $preflight->handle($fixture['objective'], $fixture['experiment']);
    $replay = $preflight->handle($fixture['objective'], $fixture['experiment']);
    $this->withToken(apiToken($fixture['owner'], [TokenScope::ObjectivesWrite->value]))
        ->postJson('/api/v1/objectives/'.$fixture['objective']->id.'/runs/preflight', [
            'gtm_experiment_id' => $fixture['experiment']->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.maximum_cost_micros', 90_000);
    $policy->forceFill(['status' => 'paused'])->save();
    $inactive = $preflight->handle($fixture['objective'], $fixture['experiment']);

    expect($before->effectiveMaximumCostMicros)->toBe(100_000)
        ->and($limited->effectiveMaximumCostMicros)->toBe(90_000)
        ->and($limited->inputHash)->not->toBe($before->inputHash)
        ->and($replay->inputHash)->toBe($limited->inputHash)
        ->and(collect($limited->blockers)->pluck('code')->all())->toContain('outreach_policy_cap_exceeded')
        ->and($inactive->effectiveMaximumCostMicros)->toBe(0)
        ->and($inactive->inputHash)->not->toBe($limited->inputHash)
        ->and(collect($inactive->blockers)->pluck('code')->all())->toContain(
            'outreach_policy_unavailable',
            'objective_ai_budget_exceeded',
        );
});

it('persists the Objective maximum when it is lower than the outreach policy maximum', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture(1, 50_000);
    $preflight = app(ObjectiveRunPreflight::class)->handle($fixture['objective'], $fixture['experiment']);
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-effective-objective-budget',
    );
    $replay = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-effective-objective-budget',
    );

    expect($preflight->ready())->toBeTrue()
        ->and($preflight->effectiveMaximumCostMicros)->toBe(50_000)
        ->and((int) $run->maximum_cost_micros)->toBe(50_000)
        ->and((int) $run->budgetEnvelope()->firstOrFail()->hard_limit_micros)->toBe(50_000)
        ->and($replay->id)->toBe($run->id)
        ->and($replay->input_hash)->toBe($preflight->inputHash);

    s3SetOutreachPolicyMaximum($fixture['owner'], 40_000);
    expect(fn () => app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-effective-objective-budget',
    ))->toThrow(ObjectiveRunConflict::class);
});

it('persists one equal Objective and outreach policy maximum through the plan', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    s3SetOutreachPolicyMaximum($fixture['owner'], 100_000);
    $preflight = app(ObjectiveRunPreflight::class)->handle($fixture['objective'], $fixture['experiment']);
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-equal-effective-budget',
    );
    $plan = runS3IncrementThreeToApproval($run)->executionPlans()->sole();

    expect($preflight->ready())->toBeTrue()
        ->and($preflight->effectiveMaximumCostMicros)->toBe(100_000)
        ->and((int) $run->maximum_cost_micros)->toBe(100_000)
        ->and((int) $run->budgetEnvelope()->firstOrFail()->hard_limit_micros)->toBe(100_000)
        ->and((int) $plan->maximum_cost_micros)->toBe(100_000);
});

it('marks settlement above the lower effective run cap as an envelope breach', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture(1, 50_000);
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-effective-budget-breach',
    );
    [$run, $phase, $dispatch, $fence] = claimS3DraftPhase(runS3IncrementThreeToDraftPhase($run));
    expect(app(DraftObjectiveMessages::class)->reserveAllArmOperations(
        $run,
        $phase,
        $dispatch,
        $fence,
    ))->toBeTrue();
    $operation = AiProviderOperation::query()->where('task', 'message_drafting')->sole();

    app(AiBudgetGateway::class)->settle($operation, 50_001, 1, 'provider_reported');
    $envelope = $run->budgetEnvelope()->firstOrFail()->fresh();

    expect((int) $envelope->hard_limit_micros)->toBe(50_000)
        ->and((int) $envelope->committed_micros)->toBe(50_001)
        ->and($envelope->state)->toBe('breached');
});

it('builds one bounded idempotent draft plan without creating or sending a Campaign', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-deterministic-plan',
    );
    $replay = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-deterministic-plan',
    );
    $run = runS3IncrementThreeToApproval($run);
    $plan = $run->executionPlans->sole();
    $action = $plan->actions->sole();

    expect($replay->id)->toBe($run->id)
        ->and($run->state->value)->toBe('waiting')
        ->and($run->current_phase->value)->toBe('await_approval')
        ->and($run->armDrafts->sole()->state->value)->toBe('succeeded')
        ->and($plan->status->value)->toBe('awaiting_approval')
        ->and($fixture['objective']->fresh()->current_plan_id)->toBe($plan->id)
        ->and($plan->action_count)->toBe(1)
        ->and(ReasonToContactClaim::query()
            ->where('workspace_id', $action->workspace_id)
            ->where('reason_to_contact_version_id', AudienceSnapshotMember::query()
                ->whereKey($action->audience_snapshot_member_id)
                ->value('reason_to_contact_version_id'))
            ->where('claim_id', $action->selected_claim_ids[0])
            ->exists())->toBeTrue()
        ->and($plan->plan_hash)->toHaveLength(64)
        ->and($action->destination_email_hash)->toHaveLength(64)
        ->and($action->destination_email_address)->toContain('@example.test')
        ->and($action->getRawOriginal('destination_email_address'))->not->toContain('@example.test')
        ->and($action->getRawOriginal('sender_address_snapshot'))->not->toContain('@example.test')
        ->and($action->subject)->toContain('Acme')
        ->and($action->body_text)->toContain('Hi')
        ->and(AiProviderOperation::query()->where('task', 'message_drafting')->count())->toBe(1)
        ->and(strlen((string) AiProviderOperation::query()->where('task', 'message_drafting')->sole()->idempotency_key))->toBeLessThanOrEqual(160)
        ->and((int) $run->actual_cost_micros)->toBe(700)
        ->and($run->budgetEnvelope()->firstOrFail()->state)->toBe('closed')
        ->and(Campaign::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0);

    $draftPhase = $run->phases()->where('phase', 'draft_messages')->sole();
    $draftDispatch = $run->dispatches()->where('phase', 'draft_messages')->sole();
    app(DraftObjectiveMessages::class)->handle(
        $run,
        $draftPhase,
        $draftDispatch,
        (int) $draftPhase->fencing_token,
    );
    expect(AiProviderOperation::query()->where('task', 'message_drafting')->count())->toBe(1);
});

it('uses one non-identifying arm prompt and renders every Account Claim separately', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture(2);
    $arm = $fixture['experiment']->arms()->sole();
    $prompt = app(BuildObjectiveDraftPrompt::class)->handle($arm);
    $providerPayload = json_decode($prompt['content'], true, 32, JSON_THROW_ON_ERROR);

    expect($providerPayload)->toBeArray()
        ->and($providerPayload)->not->toHaveKeys(['workspace_id', 'account_id', 'claim_id'])
        ->and($providerPayload['evidence_choices'])->toHaveCount(1)
        ->and(array_keys($providerPayload['evidence_choices'][0]))->toBe(['choice_id', 'value_type'])
        ->and($providerPayload['offer']['destination_url'])->toBe('https://example.test/workshop');
    foreach ($fixture['claim_ids'] as $claimId) {
        expect($prompt['content'])->not->toContain($claimId, $fixture['claim_values'][$claimId]);
    }
    foreach ($fixture['accounts'] as $account) {
        expect($prompt['content'])->not->toContain((string) $account->name);
    }

    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-two-account-single-arm-call',
    );
    $run = runS3IncrementThreeToApproval($run);
    $plan = $run->executionPlans->sole();
    $draft = $run->armDrafts->sole();

    expect($plan->actions)->toHaveCount(2)
        ->and($plan->status->value)->toBe('awaiting_approval')
        ->and(AiProviderOperation::query()->where('task', 'message_drafting')->count())->toBe(1)
        ->and($draft->subject_template)->toBeNull()
        ->and($draft->body_template)->toBeNull()
        ->and($draft->claim_manifest)->toHaveKeys(['message_pattern', 'evidence_choice', 'choice_coverage_hash'])
        ->and($draft->claim_manifest)->not->toHaveKeys(['claim_ids', 'claim_safety_hashes']);

    foreach ($plan->actions as $action) {
        $claim = ResearchClaim::query()->where('account_id', $action->account_id)->sole();
        $claimId = (string) $claim->id;
        expect($action->selected_claim_ids)->toBe([$claimId])
            ->and($action->selected_evidence_ids)->toBe([$fixture['evidence_ids'][$claimId]])
            ->and($action->selected_claim_safety_hashes)->toHaveKey($claimId)
            ->and($action->selected_claim_safety_hashes[$claimId])->toHaveLength(64)
            ->and($action->body_text)->toContain(
                $fixture['claim_values'][$claimId],
                'https://example.test/workshop',
            )
            ->and($action->cta_destination_url)->toBe('https://example.test/workshop')
            ->and($action->cta_response_route)->toBe('calendar');
        foreach (array_diff($fixture['claim_ids'], [$claimId]) as $otherClaimId) {
            expect($action->body_text)->not->toContain($fixture['claim_values'][$otherClaimId]);
        }
    }
});

it('propagates distinct arm Strategies through every governed hash and final body', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture(20);
    $workspace = $fixture['owner']->currentWorkspace();
    $controlVersionId = $fixture['experiment']->arms()->sole()->strategy_version_id;
    $treatment = s3IncrementTwoStrategy($fixture, 'Positioning treatment', [
        'positioning' => 'Operational clarity for evidence-led revenue teams.',
    ]);
    $experiment = app(CreateGtmExperiment::class)->handle($workspace, $fixture['owner'], [
        'objective_version_id' => $fixture['version']->id,
        'name' => 'Distinct outbound arm contracts',
        'arms' => [
            [
                'strategy_version_id' => $controlVersionId,
                'key' => 'control',
                'allocation_basis_points' => 5000,
                'declared_differences' => [],
            ],
            [
                'strategy_version_id' => $treatment->current_version_id,
                'key' => 'treatment',
                'allocation_basis_points' => 5000,
                'declared_differences' => ['positioning' => 'Test the exact operational-clarity position.'],
            ],
        ],
    ]);
    $experiment = app(FreezeExperimentAssignments::class)->handle($experiment, $fixture['owner'], 1);
    $controlArm = $experiment->arms()->where('key', 'control')->sole();
    $treatmentArm = $experiment->arms()->where('key', 'treatment')->sole();
    expect($experiment->assignments->pluck('gtm_experiment_arm_id')->unique())->toHaveCount(2);
    $controlPrompt = app(BuildObjectiveDraftPrompt::class)->handle($controlArm);
    $treatmentPrompt = app(BuildObjectiveDraftPrompt::class)->handle($treatmentArm);

    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $experiment,
        $fixture['owner'],
        's3-distinct-outbound-arm-hashes',
    );
    $run = runS3IncrementThreeToApproval($run);
    $drafts = $run->armDrafts->keyBy('gtm_experiment_arm_id');
    $controlDraft = $drafts->get($controlArm->id);
    $treatmentDraft = $drafts->get($treatmentArm->id);
    $actions = $run->executionPlans->sole()->actions;
    $controlAction = $actions->firstWhere('gtm_experiment_arm_id', $controlArm->id);
    $treatmentAction = $actions->firstWhere('gtm_experiment_arm_id', $treatmentArm->id);

    expect($controlPrompt['input_hash'])->not->toBe($treatmentPrompt['input_hash'])
        ->and($controlPrompt['contract']['strategy']['projection_hash'])
        ->not->toBe($treatmentPrompt['contract']['strategy']['projection_hash'])
        ->and($controlDraft->input_hash)->not->toBe($treatmentDraft->input_hash)
        ->and($controlDraft->output_hash)->not->toBe($treatmentDraft->output_hash)
        ->and($controlDraft->template_hash)->not->toBe($treatmentDraft->template_hash)
        ->and($controlAction)->not->toBeNull()
        ->and($treatmentAction)->not->toBeNull()
        ->and($controlAction->template_hash)->not->toBe($treatmentAction->template_hash)
        ->and($controlAction->content_hash)->not->toBe($treatmentAction->content_hash)
        ->and($controlAction->action_fingerprint)->not->toBe($treatmentAction->action_fingerprint)
        ->and($controlAction->body_text)->toContain('Evidence-backed planning for revenue leaders.')
        ->and($treatmentAction->body_text)->toContain('Operational clarity for evidence-led revenue teams.')
        ->and($controlAction->body_text)->not->toBe($treatmentAction->body_text);
});

it('creates no plan or action after frozen Strategy projection drift or substitution', function (string $condition): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-frozen-strategy-'.$condition,
    );
    [$run, $phase, $dispatch, $fence] = claimS3ValidatePlanPhase(runS3IncrementThreeToValidatePlan($run));
    $draft = $run->armDrafts()->sole();
    if ($condition === 'hash') {
        $manifest = $draft->claim_manifest;
        $manifest['strategy_projection_hash'] = str_repeat('f', 64);
        DB::table('objective_run_arm_drafts')->where('id', $draft->id)->update([
            'claim_manifest' => json_encode($manifest, JSON_THROW_ON_ERROR),
        ]);
    } elseif ($condition === 'projection') {
        $manifest = $draft->claim_manifest;
        $manifest['strategy_projection']['positioning'] = 'Substituted frozen positioning.';
        $manifest['strategy_projection_hash'] = app(DeterministicMessageTemplate::class)
            ->strategyProjectionHash($manifest['strategy_projection']);
        DB::table('objective_run_arm_drafts')->where('id', $draft->id)->update([
            'claim_manifest' => json_encode($manifest, JSON_THROW_ON_ERROR),
        ]);
    } else {
        $substitute = s3IncrementTwoStrategy($fixture, 'Substituted Strategy', [
            'positioning' => 'A substituted outbound position.',
        ]);
        DB::table('gtm_experiment_arms')->where('id', $fixture['experiment']->arms()->sole()->id)->update([
            'strategy_version_id' => $substitute->current_version_id,
        ]);
    }

    expect(fn () => app(ValidateExecutionPlan::class)->handle($run, $phase, $dispatch, $fence))
        ->toThrow(ValidationException::class)
        ->and($run->executionPlans()->count())->toBe(0)
        ->and(DB::table('execution_plan_actions')->count())->toBe(0)
        ->and($fixture['objective']->fresh()->current_plan_id)->toBeNull();
})->with(['hash', 'projection', 'substitution']);

it('turns unsafe deterministic provider output into review without an action', function (string $outcome): void {
    Queue::fake();
    config()->set('intelligence.testing.message_drafting_outcome', $outcome);
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-invalid-draft-'.$outcome,
    );
    foreach (range(1, 4) as $_) {
        $run = $run->fresh();
        $dispatch = $run->dispatches()->where('state', 'pending')->oldest()->firstOrFail();
        $phase = $run->phases()->where('phase', $dispatch->phase->value)
            ->where('state', 'queued')->latest('attempt_number')->firstOrFail();
        app(ExecuteObjectiveRunPhase::class)->handle(
            (int) $run->workspace_id,
            (string) $run->id,
            (string) $phase->id,
            (string) $dispatch->id,
        );
    }
    $run = $run->fresh(['armDrafts', 'executionPlans']);

    expect($run->state->value)->toBe('needs_review')
        ->and($run->armDrafts->sole()->state->value)->toBe('needs_review')
        ->and($run->armDrafts->sole()->safe_error_code)->toBe('output_invalid')
        ->and($run->executionPlans)->toBeEmpty()
        ->and(AiProviderOperation::query()->where('task', 'message_drafting')->count())->toBe(1)
        ->and((int) $run->actual_cost_micros)->toBe(700)
        ->and($run->budgetEnvelope()->firstOrFail()->state)->toBe('closed')
        ->and(Campaign::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0);
})->with(['unsupported_claim', 'unsafe_content', 'malformed_schema']);

it('blocks unsafe expired and superseded frozen Claims before a provider call', function (string $condition): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $workspace = $fixture['owner']->currentWorkspace();
    $claim = ResearchClaim::query()->where('workspace_id', $workspace->id)
        ->whereKey($fixture['claim_id'])->firstOrFail();
    if ($condition === 'unsafe') {
        DB::table('research_claims')->where('id', $claim->id)->update(['safe_for_message' => false]);
    } elseif ($condition === 'expired') {
        DB::table('research_claims')->where('id', $claim->id)->update(['fresh_until' => now()->subMinute()]);
    } else {
        ResearchClaim::query()->create([
            'workspace_id' => $workspace->id,
            'account_id' => $claim->account_id,
            'research_run_id' => $claim->research_run_id,
            'supersedes_claim_id' => $claim->id,
            'subject_type' => 'account',
            'predicate' => $claim->predicate,
            'value_type' => 'string',
            'value_json' => 'Replacement category',
            'value_hash' => CanonicalIntelligence::valueHash('Replacement category'),
            'status' => 'owner_confirmed',
            'confidence' => 1,
            'calibration_band' => 'owner_confirmed',
            'observed_at' => now(),
            'fresh_until' => now()->addDays(30),
            'expires_at' => now()->addDays(30),
            'extractor_kind' => 'owner_confirmation',
            'extractor_version' => '1',
            'safe_for_message' => true,
            'content_hash' => hash('sha256', 'replacement-'.$claim->id),
        ]);
    }

    expect(fn () => app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-unsafe-frozen-'.$condition,
    ))->toThrow(ValidationException::class)
        ->and(AiProviderOperation::query()->where('task', 'message_drafting')->count())->toBe(0)
        ->and(Campaign::query()->count())->toBe(0);
})->with(['unsafe', 'expired', 'superseded']);

it('blocks a tampered approved Offer destination before a provider call', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $offerVersion = OfferVersion::query()->whereKey($fixture['version']->offer_version_id)->firstOrFail();
    $payload = $offerVersion->payload;
    $payload['cta']['destination_url'] = 'https://attacker.example/redirect';
    DB::table('offer_versions')->where('id', $offerVersion->id)->update([
        'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
    ]);

    expect(fn () => app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-tampered-offer-destination',
    ))->toThrow(ValidationException::class)
        ->and(AiProviderOperation::query()->where('task', 'message_drafting')->count())->toBe(0)
        ->and(Campaign::query()->count())->toBe(0);
});

it('creates no plan when the Objective expires while messages are being drafted', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $deadline = now()->addMinutes(5);
    DB::table('objective_versions')->where('id', $fixture['version']->id)->update([
        'starts_at' => now()->subMinute(),
        'ends_at' => $deadline,
    ]);
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-objective-expires-during-drafting',
    );
    $run = runS3IncrementThreeToValidatePlan($run);
    $this->travelTo($deadline->copy()->addSecond());
    [$run, $phase, $dispatch, $fence] = claimS3ValidatePlanPhase($run->fresh());

    expect(fn () => app(ValidateExecutionPlan::class)->handle($run, $phase, $dispatch, $fence))
        ->toThrow(ValidationException::class)
        ->and($run->executionPlans()->count())->toBe(0)
        ->and(DB::table('execution_plan_actions')->count())->toBe(0)
        ->and($fixture['objective']->fresh()->current_plan_id)->toBeNull();
});

it('rolls back the entire plan when an exact action schedule is inverted', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-inverted-action-window',
    );
    [$run, $phase, $dispatch, $fence] = claimS3ValidatePlanPhase(runS3IncrementThreeToValidatePlan($run));
    DB::table('objective_runs')->where('id', $run->id)->update([
        'created_at' => $fixture['version']->ends_at->copy()->addSecond(),
    ]);

    expect(fn () => app(ValidateExecutionPlan::class)->handle($run, $phase, $dispatch, $fence))
        ->toThrow(ValidationException::class)
        ->and($run->executionPlans()->count())->toBe(0)
        ->and(DB::table('execution_plan_actions')->count())->toBe(0)
        ->and($fixture['objective']->fresh()->current_plan_id)->toBeNull();
});

it('rechecks the selected Reason to Contact inside plan validation immediately before persistence', function (string $condition): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture(2);
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-plan-reason-integrity-'.$condition,
    );
    [$run, $phase, $dispatch, $fence] = claimS3ValidatePlanPhase(runS3IncrementThreeToValidatePlan($run));
    $mutated = false;
    $afterFirstRender = function () use (&$mutated, $fixture, $condition): void {
        if ($mutated) {
            return;
        }
        $mutated = true;
        s3InvalidateSelectedReason($fixture, $condition);
    };
    $renderer = new class(app(SpintaxTemplate::class), $afterFirstRender) extends RenderSequenceMessage
    {
        public function __construct(SpintaxTemplate $spintax, private readonly Closure $afterRender)
        {
            parent::__construct($spintax);
        }

        public function previewTemplates(string $subjectTemplate, string $bodyTemplate, array $recipientSnapshot): array
        {
            $rendered = parent::previewTemplates($subjectTemplate, $bodyTemplate, $recipientSnapshot);
            ($this->afterRender)();

            return $rendered;
        }
    };
    $this->app->instance(RenderSequenceMessage::class, $renderer);

    expect(fn () => app(ValidateExecutionPlan::class)->handle($run, $phase, $dispatch, $fence))
        ->toThrow(ValidationException::class)
        ->and($mutated)->toBeTrue()
        ->and($run->executionPlans()->count())->toBe(0)
        ->and(DB::table('execution_plan_actions')->count())->toBe(0)
        ->and($fixture['objective']->fresh()->current_plan_id)->toBeNull();
})->with(['stale', 'expired', 'cross_account', 'cross_decision', 'hash_mismatch']);

it('creates no plan when a safe selected Claim is not linked to the exact Reason to Contact', function (string $lineage): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture($lineage === 'another_account_reason' ? 2 : 1);
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-plan-claim-lineage-'.$lineage,
    );
    [$run, $phase, $dispatch, $fence] = claimS3ValidatePlanPhase(runS3IncrementThreeToValidatePlan($run));
    $members = AudienceSnapshotMember::query()
        ->where('workspace_id', $run->workspace_id)
        ->where('audience_snapshot_id', $run->audience_snapshot_id)
        ->orderBy('account_id')
        ->get();
    $member = $members->firstOrFail();
    $claimId = (string) ($member->evidence_manifest[0]['claim_id'] ?? '');
    DB::table('reason_to_contact_claims')
        ->where('workspace_id', $run->workspace_id)
        ->where('reason_to_contact_version_id', $member->reason_to_contact_version_id)
        ->where('claim_id', $claimId)
        ->delete();

    if ($lineage === 'another_account_reason') {
        $otherMember = $members->last();
        ReasonToContactClaim::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $run->workspace_id,
            'reason_to_contact_version_id' => $otherMember->reason_to_contact_version_id,
            'claim_id' => $claimId,
            'role' => 'basis',
        ]);
    }

    expect(ResearchClaim::query()->whereKey($claimId)->sole()->safe_for_message)->toBeTrue()
        ->and(fn () => app(ValidateExecutionPlan::class)->handle($run, $phase, $dispatch, $fence))
        ->toThrow(ValidationException::class)
        ->and($run->executionPlans()->count())->toBe(0)
        ->and(DB::table('execution_plan_actions')->count())->toBe(0)
        ->and($fixture['objective']->fresh()->current_plan_id)->toBeNull();
})->with(['unlinked', 'another_account_reason']);

it('rechecks selected Claim lineage immediately before plan persistence', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-plan-claim-lineage-final-check',
    );
    [$run, $phase, $dispatch, $fence] = claimS3ValidatePlanPhase(runS3IncrementThreeToValidatePlan($run));
    $mutated = false;
    $afterFirstRender = function () use (&$mutated, $run): void {
        if ($mutated) {
            return;
        }
        $mutated = true;
        $member = AudienceSnapshotMember::query()
            ->where('workspace_id', $run->workspace_id)
            ->where('audience_snapshot_id', $run->audience_snapshot_id)
            ->sole();
        DB::table('reason_to_contact_claims')
            ->where('workspace_id', $run->workspace_id)
            ->where('reason_to_contact_version_id', $member->reason_to_contact_version_id)
            ->where('claim_id', $member->evidence_manifest[0]['claim_id'])
            ->delete();
    };
    $renderer = new class(app(SpintaxTemplate::class), $afterFirstRender) extends RenderSequenceMessage
    {
        public function __construct(SpintaxTemplate $spintax, private readonly Closure $afterRender)
        {
            parent::__construct($spintax);
        }

        public function previewTemplates(string $subjectTemplate, string $bodyTemplate, array $recipientSnapshot): array
        {
            $rendered = parent::previewTemplates($subjectTemplate, $bodyTemplate, $recipientSnapshot);
            ($this->afterRender)();

            return $rendered;
        }
    };
    $this->app->instance(RenderSequenceMessage::class, $renderer);

    expect(fn () => app(ValidateExecutionPlan::class)->handle($run, $phase, $dispatch, $fence))
        ->toThrow(ValidationException::class)
        ->and($mutated)->toBeTrue()
        ->and($run->executionPlans()->count())->toBe(0)
        ->and(DB::table('execution_plan_actions')->count())->toBe(0)
        ->and($fixture['objective']->fresh()->current_plan_id)->toBeNull();
});

it('does not substitute a linked alternative when the deterministic selected Claim lacks Reason lineage', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $workspace = $fixture['owner']->currentWorkspace();
    $member = AudienceSnapshotMember::query()
        ->where('workspace_id', $workspace->id)
        ->where('audience_snapshot_id', $fixture['version']->audience_snapshot_id)
        ->sole();
    $selectedClaim = ResearchClaim::query()
        ->whereKey($fixture['claim_id'])
        ->with('evidence')
        ->firstOrFail();
    $alternativeClaimId = 'ffffffff-ffff-4fff-bfff-ffffffffffff';
    $alternativeValue = 'Alternative reviewed B2B category';
    $alternativeClaim = ResearchClaim::query()->create([
        'id' => $alternativeClaimId,
        'workspace_id' => $workspace->id,
        'account_id' => $selectedClaim->account_id,
        'research_run_id' => $selectedClaim->research_run_id,
        'supersedes_claim_id' => null,
        'subject_type' => 'account',
        'predicate' => $selectedClaim->predicate,
        'value_type' => 'string',
        'value_json' => $alternativeValue,
        'value_hash' => CanonicalIntelligence::valueHash($alternativeValue),
        'status' => 'supported',
        'confidence' => 1,
        'calibration_band' => 'high',
        'observed_at' => now(),
        'fresh_until' => now()->addDays(30),
        'expires_at' => now()->addDays(30),
        'extractor_kind' => 'deterministic_fixture',
        'extractor_version' => '1',
        'safe_for_message' => true,
        'content_hash' => hash('sha256', 'alternative-claim-content-'.$member->id),
    ]);
    ResearchClaimEvidence::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'claim_id' => $alternativeClaim->id,
        'source_snapshot_id' => $selectedClaim->evidence->sole()->source_snapshot_id,
        'edge_type' => 'supports',
        'locator' => 'Alternative reviewed category',
        'locator_hash' => hash('sha256', 'Alternative reviewed category'),
        'quote_hash' => hash('sha256', $alternativeValue),
        'evidence_weight' => 1,
        'extraction_version' => '1',
    ]);
    $alternativeManifest = app(MessageEvidenceContract::class)->currentManifest(
        $alternativeClaim->load('evidence'),
        (int) $workspace->id,
        (int) $member->account_id,
    );
    expect($alternativeManifest)->not->toBeNull();
    DB::table('audience_snapshot_members')->where('id', $member->id)->update([
        'evidence_manifest' => json_encode([
            ...$member->evidence_manifest,
            $alternativeManifest,
        ], JSON_THROW_ON_ERROR),
    ]);
    ReasonToContactClaim::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'reason_to_contact_version_id' => $member->reason_to_contact_version_id,
        'claim_id' => $alternativeClaim->id,
        'role' => 'basis',
    ]);
    $strategy = s3IncrementTwoStrategy([
        ...$fixture,
        'claim_ids' => [$selectedClaim->id, $alternativeClaim->id],
    ], 'Multi-claim lineage control');
    $experiment = app(CreateGtmExperiment::class)->handle($workspace, $fixture['owner'], [
        'objective_version_id' => $fixture['version']->id,
        'name' => 'Multi-claim lineage plan',
        'arms' => [[
            'strategy_version_id' => $strategy->current_version_id,
            'key' => 'control',
            'label' => 'Control',
            'allocation_basis_points' => 10000,
            'declared_differences' => [],
        ]],
    ]);
    $experiment = app(FreezeExperimentAssignments::class)->handle($experiment, $fixture['owner'], 1);
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $experiment,
        $fixture['owner'],
        's3-partial-multi-claim-lineage',
    );
    [$run, $phase, $dispatch, $fence] = claimS3ValidatePlanPhase(runS3IncrementThreeToValidatePlan($run));
    DB::table('reason_to_contact_claims')
        ->where('workspace_id', $workspace->id)
        ->where('reason_to_contact_version_id', $member->reason_to_contact_version_id)
        ->where('claim_id', $selectedClaim->id)
        ->delete();

    expect(strcmp((string) $selectedClaim->id, $alternativeClaimId))->toBeLessThan(0)
        ->and(ReasonToContactClaim::query()
            ->where('reason_to_contact_version_id', $member->reason_to_contact_version_id)
            ->where('claim_id', $alternativeClaimId)
            ->exists())->toBeTrue()
        ->and(fn () => app(ValidateExecutionPlan::class)->handle($run, $phase, $dispatch, $fence))
        ->toThrow(ValidationException::class)
        ->and($run->executionPlans()->count())->toBe(0)
        ->and(DB::table('execution_plan_actions')->count())->toBe(0)
        ->and($fixture['objective']->fresh()->current_plan_id)->toBeNull();
});

it('cannot validate or advance a plan after cancellation invalidates the claimed phase fence', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-cancel-before-plan-validation',
    );
    [$run, $phase, $dispatch, $fence] = claimS3ValidatePlanPhase(runS3IncrementThreeToValidatePlan($run));
    $staleRun = clone $run;
    $stalePhase = clone $phase;
    $staleDispatch = clone $dispatch;
    $cancelled = app(CancelObjectiveRun::class)->handle($run, $fixture['owner'], 'Cancel before plan validation.');

    $plan = app(ValidateExecutionPlan::class)->handle(
        $staleRun,
        $stalePhase,
        $staleDispatch,
        $fence,
    );
    app(AdvanceObjectiveRun::class)->succeeded(
        $staleRun,
        $stalePhase,
        $fence,
        hash('sha256', 'cancelled-plan-output'),
    );

    expect($plan)->toBeNull()
        ->and($cancelled->fresh()->state->value)->toBe('cancelled')
        ->and($phase->fresh()->state->value)->toBe('cancelled')
        ->and($cancelled->executionPlans()->count())->toBe(0)
        ->and($fixture['objective']->fresh()->current_plan_id)->toBeNull()
        ->and(Campaign::query()->count())->toBe(0);
});

it('creates no plan after either claimed validate-plan lease expires', function (string $expiredLease): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-expired-plan-'.$expiredLease.'-lease',
    );
    [$run, $phase, $dispatch, $fence] = claimS3ValidatePlanPhase(runS3IncrementThreeToValidatePlan($run));
    $staleRun = clone $run;
    $stalePhase = clone $phase;
    $staleDispatch = clone $dispatch;
    DB::table($expiredLease === 'run' ? 'objective_runs' : 'objective_run_phases')
        ->where('id', $expiredLease === 'run' ? $run->id : $phase->id)
        ->update(['lease_expires_at' => now()->subSecond()]);

    $plan = app(ValidateExecutionPlan::class)->handle(
        $staleRun,
        $stalePhase,
        $staleDispatch,
        $fence,
    );
    app(AdvanceObjectiveRun::class)->succeeded(
        $staleRun,
        $stalePhase,
        $fence,
        hash('sha256', 'expired-plan-output'),
    );

    expect($plan)->toBeNull()
        ->and($run->executionPlans()->count())->toBe(0)
        ->and(DB::table('execution_plan_actions')->count())->toBe(0)
        ->and($fixture['objective']->fresh()->current_plan_id)->toBeNull()
        ->and($run->fresh()->state->value)->toBe('running')
        ->and($run->fresh()->current_phase->value)->toBe('validate_plan')
        ->and($phase->fresh()->state->value)->toBe('running')
        ->and($dispatch->fresh()->state->value)->toBe('consumed');
})->with(['run', 'phase']);

it('does not reuse a plan for a recovered stale worker and lets the replacement finish idempotently', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-recovered-plan-fence',
    );
    [$run, $phase, $dispatch, $fence] = claimS3ValidatePlanPhase(runS3IncrementThreeToValidatePlan($run));
    $plan = app(ValidateExecutionPlan::class)->handle($run, $phase, $dispatch, $fence);
    expect($plan)->not->toBeNull();
    if ($plan === null) {
        throw new RuntimeException('The valid claimed plan context did not produce a plan.');
    }
    $planId = (string) $plan->id;
    $staleRun = clone $run;
    $stalePhase = clone $phase;
    $staleDispatch = clone $dispatch;
    DB::table('objective_runs')->where('id', $run->id)->update([
        'lease_expires_at' => now()->subSecond(),
    ]);
    DB::table('objective_run_phases')->where('id', $phase->id)->update([
        'lease_expires_at' => now()->subSecond(),
    ]);

    expect(app(RecoverObjectiveRuns::class)->handle((int) $run->workspace_id))->toBe(1);
    $replacement = $run->phases()->where('phase', 'validate_plan')->where('state', 'queued')->sole();
    $recoveredDispatch = $dispatch->fresh();
    $staleResult = app(ValidateExecutionPlan::class)->handle(
        $staleRun,
        $stalePhase,
        $staleDispatch,
        $fence,
    );

    expect($staleResult)->toBeNull()
        ->and($run->executionPlans()->count())->toBe(1)
        ->and($run->executionPlans()->sole()->actions()->count())->toBe(1)
        ->and($fixture['objective']->fresh()->current_plan_id)->toBe($planId)
        ->and($phase->fresh()->state->value)->toBe('failed')
        ->and($replacement->state->value)->toBe('queued')
        ->and($recoveredDispatch->state->value)->toBe('pending');

    app(ExecuteObjectiveRunPhase::class)->handle(
        (int) $run->workspace_id,
        (string) $run->id,
        (string) $replacement->id,
        (string) $recoveredDispatch->id,
    );

    expect($run->fresh()->state->value)->toBe('waiting')
        ->and($run->fresh()->current_phase->value)->toBe('await_approval')
        ->and($replacement->fresh()->state->value)->toBe('succeeded')
        ->and($run->executionPlans()->count())->toBe(1)
        ->and($run->executionPlans()->sole()->actions()->count())->toBe(1)
        ->and($fixture['objective']->fresh()->current_plan_id)->toBe($planId);
});

it('records deterministic provider abstention as a visible partial plan', function (): void {
    Queue::fake();
    config()->set('intelligence.testing.message_drafting_outcome', 'abstain');
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-abstaining-draft',
    );
    $run = runS3IncrementThreeToApproval($run);
    $plan = $run->executionPlans->sole();

    expect($run->state->value)->toBe('needs_review')
        ->and($run->armDrafts->sole()->state->value)->toBe('abstained')
        ->and($plan->status->value)->toBe('partial')
        ->and($plan->action_count)->toBe(0)
        ->and($plan->actions)->toBeEmpty()
        ->and(data_get($plan->input_manifest, 'omitted.0.reason'))->toBe('arm_draft_unavailable')
        ->and(Campaign::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0);
});

it('rejects a second active run and keeps provider work behind durable phases', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-first-run',
    );

    expect(fn () => app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-second-run',
    ))->toThrow(ObjectiveRunConflict::class)
        ->and($run->state->value)->toBe('planned')
        ->and(AiProviderOperation::query()->count())->toBe(0)
        ->and(Campaign::query()->count())->toBe(0);
});

it('recovers an expired phase by reusing its durable dispatch boundary', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-recovery-run',
    );
    $phase = $run->phases()->sole();
    $dispatch = $run->dispatches()->sole();
    $run->forceFill([
        'state' => 'running',
        'lease_owner' => 'expired-worker',
        'lease_expires_at' => now()->subMinute(),
        'fencing_token' => 1,
    ])->save();
    $phase->forceFill([
        'state' => 'running',
        'lease_owner' => 'expired-worker',
        'lease_expires_at' => now()->subMinute(),
        'fencing_token' => 1,
        'started_at' => now()->subMinutes(2),
    ])->save();
    $dispatch->forceFill(['state' => 'consumed', 'consumed_at' => now()->subMinute()])->save();
    $staleRun = clone $run;
    $stalePhase = clone $phase;

    expect(app(RecoverObjectiveRuns::class)->handle((int) $run->workspace_id))->toBe(1)
        ->and($run->phases()->count())->toBe(2)
        ->and($run->phases()->latest('attempt_number')->firstOrFail()->state->value)->toBe('queued')
        ->and($run->dispatches()->count())->toBe(1)
        ->and($run->fresh()->fencing_token)->toBe(2)
        ->and($dispatch->fresh()->state->value)->toBe('pending')
        ->and($dispatch->fresh()->consumed_at)->toBeNull();

    app(AdvanceObjectiveRun::class)->succeeded(
        $staleRun,
        $stalePhase,
        1,
        hash('sha256', 'stale-worker-output'),
    );

    expect($run->fresh()->current_phase->value)->toBe('validate_inputs')
        ->and($run->fresh()->fencing_token)->toBe(2)
        ->and($stalePhase->fresh()->state->value)->toBe('failed')
        ->and($run->phases()->count())->toBe(2)
        ->and($run->dispatches()->count())->toBe(1);
});

it('does not advance a run when the guarded phase lease is no longer owned', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-lost-phase-lease',
    );
    $phase = $run->phases()->sole();
    $leaseOwner = (string) Str::uuid();
    $leaseExpiresAt = now()->addMinute();
    $run->forceFill([
        'state' => 'running',
        'lease_owner' => $leaseOwner,
        'lease_expires_at' => $leaseExpiresAt,
        'fencing_token' => 1,
    ])->save();
    $phase->forceFill([
        'state' => 'running',
        'lease_owner' => $leaseOwner,
        'lease_expires_at' => $leaseExpiresAt,
        'fencing_token' => 1,
        'started_at' => now(),
    ])->save();
    $claimedPhase = clone $phase;
    DB::table('objective_run_phases')->where('id', $phase->id)->update([
        'lease_owner' => (string) Str::uuid(),
    ]);

    app(AdvanceObjectiveRun::class)->succeeded(
        $run->fresh(),
        $claimedPhase,
        1,
        hash('sha256', 'unowned-phase-output'),
    );

    expect($run->fresh()->current_phase->value)->toBe('validate_inputs')
        ->and($run->fresh()->state->value)->toBe('running')
        ->and($run->fresh()->fencing_token)->toBe(1)
        ->and($phase->fresh()->state->value)->toBe('running')
        ->and($run->phases()->count())->toBe(1)
        ->and($run->dispatches()->count())->toBe(1);
});

it('consumes a duplicate phase dispatch only once', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-duplicate-dispatch-run',
    );
    $phase = $run->phases()->sole();
    $dispatch = $run->dispatches()->sole();
    $arguments = [(int) $run->workspace_id, (string) $run->id, (string) $phase->id, (string) $dispatch->id];

    app(ExecuteObjectiveRunPhase::class)->handle(...$arguments);
    app(ExecuteObjectiveRunPhase::class)->handle(...$arguments);

    expect($phase->fresh()->state->value)->toBe('succeeded')
        ->and($dispatch->fresh()->state->value)->toBe('consumed')
        ->and($run->fresh()->current_phase->value)->toBe('recheck_exclusions')
        ->and($run->phases()->count())->toBe(2)
        ->and($run->dispatches()->count())->toBe(2)
        ->and(AiProviderOperation::query()->count())->toBe(0);
});

it('leaves the owner approval boundary untouched during recovery sweeps', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-await-approval-run',
    );
    $run = runS3IncrementThreeToApproval($run);
    $phaseCount = $run->phases()->count();
    $dispatchCount = $run->dispatches()->count();

    expect(app(RecoverObjectiveRuns::class)->handle((int) $run->workspace_id))->toBe(0)
        ->and($run->fresh()->state->value)->toBe('waiting')
        ->and($run->fresh()->current_phase->value)->toBe('await_approval')
        ->and($run->phases()->count())->toBe($phaseCount)
        ->and($run->dispatches()->count())->toBe($dispatchCount);
});

it('returns exact plan content only through the scoped workspace read endpoint', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-plan-api-run',
    );
    $plan = runS3IncrementThreeToApproval($run)->executionPlans->sole();

    $this->withToken(apiToken($fixture['owner'], [TokenScope::ObjectivesRead->value]))
        ->getJson('/api/v1/execution-plans/'.$plan->id)
        ->assertOk()
        ->assertJsonPath('data.status', 'awaiting_approval')
        ->assertJsonPath('data.actions.0.destination_email_address', fn (string $email): bool => str_ends_with($email, '@example.test'))
        ->assertJsonPath('data.actions.0.subject', fn (string $subject): bool => str_contains($subject, 'Acme'))
        ->assertJsonPath('data.actions.0.sender_address', fn (string $email): bool => str_ends_with($email, '@example.test'))
        ->assertJsonPath('data.actions.0.body_text', fn (string $body): bool => str_contains($body, 'Hi'))
        ->assertJsonMissingPath('data.workspace_id');

    $intruder = User::factory()->create();
    $this->app['auth']->forgetGuards();
    $this->withToken(apiToken($intruder, [TokenScope::ObjectivesRead->value]))
        ->getJson('/api/v1/execution-plans/'.$plan->id)
        ->assertNotFound();
});

it('cancels every pre-provider artifact and closes its unused AI budget', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-cancel-run',
    );
    $cancelled = app(CancelObjectiveRun::class)->handle($run, $fixture['owner'], 'Owner stopped the pilot.');

    expect($cancelled->state->value)->toBe('cancelled')
        ->and($cancelled->phases->sole()->state->value)->toBe('cancelled')
        ->and($cancelled->armDrafts->sole()->state->value)->toBe('cancelled')
        ->and($cancelled->dispatches()->sole()->state->value)->toBe('cancelled')
        ->and($cancelled->armDrafts->sole()->budgetRun()->firstOrFail()->status)->toBe('cancelled')
        ->and($cancelled->budgetEnvelope()->firstOrFail()->state)->toBe('closed')
        ->and(AiProviderCredential::query()->count())->toBe(1)
        ->and(AiProviderOperation::query()->count())->toBe(0);
});

it('supersedes an approval-bound plan with a fresh planned run', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $original = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-original-run',
    );
    $original = runS3IncrementThreeToApproval($original);
    $successor = app(SupersedeObjectiveRun::class)->handle(
        $original,
        $fixture['experiment'],
        $fixture['owner'],
        's3-successor-run',
        'Owner requested a fresh exact plan.',
    );
    $firstSupersession = $original->fresh();
    $envelopeCount = DB::table('ai_budget_envelopes')->count();
    $budgetRunCount = DB::table('ai_budget_runs')->count();
    $replayed = app(SupersedeObjectiveRun::class)->handle(
        $original,
        $fixture['experiment'],
        $fixture['owner'],
        's3-successor-run',
        'A replay may repeat the original reason.',
    );

    expect($original->fresh()->state->value)->toBe('superseded')
        ->and($original->fresh()->superseded_by_run_id)->toBe($successor->id)
        ->and($original->fresh()->cancellation_detail)->toBe('Owner requested a fresh exact plan.')
        ->and($original->fresh()->fencing_token)->toBe($firstSupersession->fencing_token)
        ->and($original->executionPlans()->sole()->status->value)->toBe('superseded')
        ->and($fixture['objective']->fresh()->current_plan_id)->toBeNull()
        ->and($successor->state->value)->toBe('planned')
        ->and($successor->run_number)->toBe(2)
        ->and($successor->phases->sole()->state->value)->toBe('queued')
        ->and($successor->armDrafts->sole()->state->value)->toBe('planned')
        ->and($replayed->id)->toBe($successor->id)
        ->and(ObjectiveRun::query()->where('objective_id', $fixture['objective']->id)->count())->toBe(2)
        ->and(DB::table('ai_budget_envelopes')->count())->toBe($envelopeCount)
        ->and(DB::table('ai_budget_runs')->count())->toBe($budgetRunCount)
        ->and(app('router')->getRoutes()->getByName('objective-inputs.objective-runs.supersede')?->gatherMiddleware())->toContain('idempotency')
        ->and(Campaign::query()->count())->toBe(0);

    expect(fn () => app(SupersedeObjectiveRun::class)->handle(
        $original,
        $fixture['experiment'],
        $fixture['owner'],
        's3-conflicting-successor-run',
        'A conflicting retry must not return the prior successor.',
    ))->toThrow(ValidationException::class);
    expect(ObjectiveRun::query()->where('objective_id', $fixture['objective']->id)->count())->toBe(2);
});

it('rejects the predecessor idempotency key before changing any run artifacts or budgets', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $predecessor = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-predecessor-key-reuse',
    );
    $budgetEnvelopeId = $predecessor->ai_budget_envelope_id;
    $budgetRunId = $predecessor->armDrafts()->sole()->ai_budget_run_id;
    $before = [
        'runs' => DB::table('objective_runs')->count(),
        'envelopes' => DB::table('ai_budget_envelopes')->count(),
        'budget_runs' => DB::table('ai_budget_runs')->count(),
    ];

    expect(fn () => app(SupersedeObjectiveRun::class)->handle(
        $predecessor,
        $fixture['experiment'],
        $fixture['owner'],
        's3-predecessor-key-reuse',
        'A predecessor must never become its own successor.',
    ))->toThrow(ObjectiveRunConflict::class);

    $predecessor->refresh();
    expect($predecessor->state->value)->toBe('planned')
        ->and($predecessor->superseded_by_run_id)->toBeNull()
        ->and($predecessor->cancellation_reason_code)->toBeNull()
        ->and($predecessor->superseded_at)->toBeNull()
        ->and($predecessor->phases()->sole()->state->value)->toBe('queued')
        ->and($predecessor->dispatches()->sole()->state->value)->toBe('pending')
        ->and($predecessor->armDrafts()->sole()->state->value)->toBe('planned')
        ->and(DB::table('ai_budget_envelopes')->where('id', $budgetEnvelopeId)->value('state'))->toBe('open')
        ->and(DB::table('ai_budget_runs')->where('id', $budgetRunId)->value('status'))->toBe('open')
        ->and(DB::table('objective_runs')->count())->toBe($before['runs'])
        ->and(DB::table('ai_budget_envelopes')->count())->toBe($before['envelopes'])
        ->and(DB::table('ai_budget_runs')->count())->toBe($before['budget_runs']);
});

it('rejects an older terminal run key even when its frozen input fingerprint matches', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $older = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-older-terminal-key',
    );
    app(CancelObjectiveRun::class)->handle($older, $fixture['owner'], 'Make room for the current run.');
    $predecessor = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-current-run-key',
    );
    $predecessor = runS3IncrementThreeToApproval($predecessor);
    $plan = $predecessor->executionPlans()->sole();
    $currentPlanId = $fixture['objective']->fresh()->current_plan_id;
    $runCount = ObjectiveRun::query()->where('objective_id', $fixture['objective']->id)->count();
    $envelopeCount = DB::table('ai_budget_envelopes')->count();
    $budgetRunCount = DB::table('ai_budget_runs')->count();
    $phaseCount = DB::table('objective_run_phases')->count();
    $draftCount = DB::table('objective_run_arm_drafts')->count();
    $dispatchCount = DB::table('objective_run_dispatches')->count();

    expect($older->input_hash)->toBe($predecessor->input_hash)
        ->and(fn () => app(SupersedeObjectiveRun::class)->handle(
            $predecessor,
            $fixture['experiment'],
            $fixture['owner'],
            's3-older-terminal-key',
            'An older terminal run is not a successor.',
        ))->toThrow(ObjectiveRunConflict::class);

    $predecessor->refresh();
    expect($older->fresh()->state->value)->toBe('cancelled')
        ->and($predecessor->state->value)->toBe('waiting')
        ->and($predecessor->superseded_by_run_id)->toBeNull()
        ->and($predecessor->cancellation_reason_code)->toBeNull()
        ->and($plan->fresh()->status->value)->toBe('awaiting_approval')
        ->and($fixture['objective']->fresh()->current_plan_id)->toBe($currentPlanId)
        ->and($currentPlanId)->toBe($plan->id)
        ->and(ObjectiveRun::query()->where('objective_id', $fixture['objective']->id)->count())->toBe($runCount)
        ->and(DB::table('ai_budget_envelopes')->count())->toBe($envelopeCount)
        ->and(DB::table('ai_budget_runs')->count())->toBe($budgetRunCount)
        ->and(DB::table('objective_run_phases')->count())->toBe($phaseCount)
        ->and(DB::table('objective_run_arm_drafts')->count())->toBe($draftCount)
        ->and(DB::table('objective_run_dispatches')->count())->toBe($dispatchCount);
});

it('rolls back successor creation when supersession cannot safely finalize the predecessor', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $predecessor = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-unsafe-supersession-predecessor',
    );
    [$predecessor, $phase, $dispatch, $fence] = claimS3DraftPhase(runS3IncrementThreeToDraftPhase($predecessor));
    $drafting = app(DraftObjectiveMessages::class);
    expect($drafting->reserveAllArmOperations($predecessor, $phase, $dispatch, $fence))->toBeTrue();
    $operation = AiProviderOperation::query()->where('task', 'message_drafting')->sole();
    app(AiBudgetGateway::class)->markUncertain(
        $operation,
        'provider_uncertain',
        'The provider may have accepted this request.',
    );
    $runCount = ObjectiveRun::query()->where('objective_id', $fixture['objective']->id)->count();
    $envelopeCount = DB::table('ai_budget_envelopes')->count();
    $budgetRunCount = DB::table('ai_budget_runs')->count();
    $phaseCount = DB::table('objective_run_phases')->count();
    $draftCount = DB::table('objective_run_arm_drafts')->count();
    $dispatchCount = DB::table('objective_run_dispatches')->count();

    expect(fn () => app(SupersedeObjectiveRun::class)->handle(
        $predecessor,
        $fixture['experiment'],
        $fixture['owner'],
        's3-unsafe-supersession-successor',
        'Do not supersede while provider work is ambiguous.',
    ))->toThrow(ValidationException::class, 'awaiting reconciliation');

    $predecessor->refresh();
    expect($predecessor->state->value)->toBe('running')
        ->and($predecessor->superseded_by_run_id)->toBeNull()
        ->and($predecessor->cancellation_reason_code)->toBeNull()
        ->and($phase->fresh()->state->value)->toBe('running')
        ->and($dispatch->fresh()->state->value)->toBe('consumed')
        ->and($predecessor->armDrafts()->sole()->state->value)->toBe('awaiting_provider')
        ->and($operation->fresh()->status->value)->toBe('uncertain')
        ->and(ObjectiveRun::query()->where('objective_id', $fixture['objective']->id)->count())->toBe($runCount)
        ->and(DB::table('ai_budget_envelopes')->count())->toBe($envelopeCount)
        ->and(DB::table('ai_budget_runs')->count())->toBe($budgetRunCount)
        ->and(DB::table('objective_run_phases')->count())->toBe($phaseCount)
        ->and(DB::table('objective_run_arm_drafts')->count())->toBe($draftCount)
        ->and(DB::table('objective_run_dispatches')->count())->toBe($dispatchCount);
});

it('rejects cross-workspace ownership at the exact action database boundary', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-action-constraint-run',
    );
    $action = runS3IncrementThreeToApproval($run)->executionPlans->sole()->actions->sole();
    $foreignOwner = User::factory()->create();
    $foreignAccount = Account::factory()->create([
        'workspace_id' => $foreignOwner->currentWorkspace()->id,
    ]);

    expect(fn () => DB::table('execution_plan_actions')->where('id', $action->id)->update([
        'account_id' => $foreignAccount->id,
    ]))->toThrow(QueryException::class);
});

it('rejects a cross-workspace Objective current-plan pointer', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-current-plan-tenant-constraint',
    );
    $plan = runS3IncrementThreeToApproval($run)->executionPlans()->sole();
    $foreignOwner = User::factory()->create();
    $foreignObjective = app(CreateObjective::class)->handle(
        $foreignOwner->currentWorkspace(),
        $foreignOwner,
    );

    expect(fn () => DB::table('objectives')->where('id', $foreignObjective->id)->update([
        'current_plan_id' => $plan->id,
    ]))->toThrow(QueryException::class)
        ->and($foreignObjective->fresh()->current_plan_id)->toBeNull();
});

function runS3IncrementThreeToDraftPhase(ObjectiveRun $run): ObjectiveRun
{
    foreach (range(1, 3) as $_) {
        $run = $run->fresh();
        $dispatch = $run->dispatches()->where('state', 'pending')->oldest()->firstOrFail();
        $phase = $run->phases()->where('phase', $dispatch->phase->value)
            ->where('state', 'queued')->latest('attempt_number')->firstOrFail();
        app(ExecuteObjectiveRunPhase::class)->handle(
            (int) $run->workspace_id,
            (string) $run->id,
            (string) $phase->id,
            (string) $dispatch->id,
        );
    }

    return $run->fresh(['phases', 'dispatches', 'armDrafts']);
}

function runS3IncrementThreeToValidatePlan(ObjectiveRun $run): ObjectiveRun
{
    foreach (range(1, 4) as $_) {
        $run = $run->fresh();
        $dispatch = $run->dispatches()->where('state', 'pending')->oldest()->firstOrFail();
        $phase = $run->phases()->where('phase', $dispatch->phase->value)
            ->where('state', 'queued')->latest('attempt_number')->firstOrFail();
        app(ExecuteObjectiveRunPhase::class)->handle(
            (int) $run->workspace_id,
            (string) $run->id,
            (string) $phase->id,
            (string) $dispatch->id,
        );
    }

    return $run->fresh(['phases', 'dispatches', 'armDrafts']);
}

/** @return array{ObjectiveRun,ObjectiveRunPhase,ObjectiveRunDispatch,int} */
function claimS3ValidatePlanPhase(ObjectiveRun $run): array
{
    $phase = $run->phases()->where('phase', 'validate_plan')->where('state', 'queued')->firstOrFail();
    $dispatch = $run->dispatches()->where('phase', 'validate_plan')->where('state', 'pending')->firstOrFail();
    $fence = (int) $run->fencing_token + 1;
    $leaseOwner = 'plan-validation-worker';
    $leaseExpiresAt = now()->addMinute();
    $run->forceFill([
        'state' => 'running',
        'lease_owner' => $leaseOwner,
        'lease_expires_at' => $leaseExpiresAt,
        'fencing_token' => $fence,
        'heartbeat_at' => now(),
    ])->save();
    $phase->forceFill([
        'state' => 'running',
        'lease_owner' => $leaseOwner,
        'lease_expires_at' => $leaseExpiresAt,
        'fencing_token' => $fence,
        'started_at' => now(),
    ])->save();
    $dispatch->forceFill([
        'state' => 'consumed',
        'attempts' => (int) $dispatch->attempts + 1,
        'published_at' => now(),
        'consumed_at' => now(),
    ])->save();

    return [$run->fresh(), $phase->fresh(), $dispatch->fresh(), $fence];
}

/** @return array{ObjectiveRun,ObjectiveRunPhase,ObjectiveRunDispatch,int} */
function claimS3DraftPhase(ObjectiveRun $run): array
{
    $phase = $run->phases()->where('phase', 'draft_messages')->where('state', 'queued')->firstOrFail();
    $dispatch = $run->dispatches()->where('phase', 'draft_messages')->where('state', 'pending')->firstOrFail();
    $fence = (int) $run->fencing_token + 1;
    $run->forceFill([
        'state' => 'running',
        'lease_owner' => 'crash-window-worker',
        'lease_expires_at' => now()->addMinute(),
        'fencing_token' => $fence,
    ])->save();
    $phase->forceFill([
        'state' => 'running',
        'lease_owner' => 'crash-window-worker',
        'lease_expires_at' => now()->addMinute(),
        'fencing_token' => $fence,
        'started_at' => now(),
    ])->save();
    $dispatch->forceFill([
        'state' => 'consumed',
        'attempts' => 1,
        'published_at' => now(),
        'consumed_at' => now(),
    ])->save();

    return [$run->fresh(), $phase->fresh(), $dispatch->fresh(), $fence];
}

it('safely resumes an awaiting draft whose worker died before reservation', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-after-draft-transition-crash',
    );
    [$run, $phase, $dispatch, $fence] = claimS3DraftPhase(runS3IncrementThreeToDraftPhase($run));
    $draft = $run->armDrafts()->sole();
    $draft->forceFill([
        'state' => 'awaiting_provider',
        'ai_provider_operation_id' => null,
        'started_at' => now()->subMinute(),
    ])->save();

    $result = app(DraftObjectiveMessages::class)->handle($run, $phase, $dispatch, $fence);

    expect($result->runState->value)->toBe('running')
        ->and($draft->fresh()->state->value)->toBe('succeeded')
        ->and($draft->fresh()->ai_provider_operation_id)->not->toBeNull()
        ->and(AiProviderOperation::query()->where('task', 'message_drafting')->count())->toBe(1)
        ->and(AiProviderCallAttempt::query()->count())->toBe(1)
        ->and(AiUsageLedger::query()->count())->toBe(1)
        ->and((int) AiUsageLedger::query()->sum('cost_micros'))->toBe(700)
        ->and((int) $run->fresh()->actual_cost_micros)->toBe(700)
        ->and($run->budgetEnvelope()->firstOrFail()->state)->toBe('closed');
});

it('recovers and claims the same reserved operation after a pre-claim crash', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-reserve-before-claim-crash',
    );
    [$run, $phase, $dispatch, $fence] = claimS3DraftPhase(runS3IncrementThreeToDraftPhase($run));
    $drafting = app(DraftObjectiveMessages::class);
    expect($drafting->reserveAllArmOperations($run, $phase, $dispatch, $fence))->toBeTrue();
    $reserved = AiProviderOperation::query()->where('task', 'message_drafting')->sole();
    $frozenDraft = $run->armDrafts()->sole();
    expect($reserved->status->value)->toBe('reserved')
        ->and($frozenDraft->ai_provider_operation_id)->toBe($reserved->id)
        ->and($reserved->model_or_endpoint)->toBe($frozenDraft->model_id)
        ->and($reserved->policy_version)->toBe($frozenDraft->task_policy_version)
        ->and(AiProviderCallAttempt::query()->count())->toBe(0);

    $run->forceFill([
        'state' => 'waiting',
        'lease_owner' => null,
        'lease_expires_at' => null,
    ])->save();
    $phase->forceFill([
        'state' => 'waiting',
        'lease_owner' => null,
        'lease_expires_at' => null,
        'safe_error_code' => 'provider_reconciliation_pending',
    ])->save();

    expect(app(RecoverObjectiveRuns::class)->handle((int) $run->workspace_id))->toBe(1);
    $replacement = $run->phases()->where('phase', 'draft_messages')->where('state', 'queued')->sole();
    $dispatch = $dispatch->fresh();
    app(ExecuteObjectiveRunPhase::class)->handle(
        (int) $run->workspace_id,
        (string) $run->id,
        (string) $replacement->id,
        (string) $dispatch->id,
    );

    expect($reserved->fresh()->status->value)->toBe('succeeded')
        ->and($run->fresh()->current_phase->value)->toBe('validate_plan')
        ->and($run->armDrafts()->sole()->state->value)->toBe('succeeded')
        ->and(AiProviderOperation::query()->where('task', 'message_drafting')->count())->toBe(1)
        ->and(AiProviderCallAttempt::query()->count())->toBe(1)
        ->and(AiUsageLedger::query()->count())->toBe(1)
        ->and((int) AiUsageLedger::query()->sum('cost_micros'))->toBe(700)
        ->and($run->budgetEnvelope()->firstOrFail()->state)->toBe('closed');
});

it('keeps successful drafting recoverable until authoritative budget closure succeeds', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-budget-closure-recovery',
    );
    [$run, $phase, $dispatch, $fence] = claimS3DraftPhase(runS3IncrementThreeToDraftPhase($run));
    $gateway = app(AiBudgetGateway::class);
    $extraRun = $gateway->openRun(
        $fixture['owner']->currentWorkspace(),
        $fixture['owner'],
        AiTask::MessageDrafting,
        $run->budgetEnvelope()->firstOrFail(),
    );

    $waiting = app(DraftObjectiveMessages::class)->handle($run, $phase, $dispatch, $fence);
    expect($waiting->runState->value)->toBe('waiting')
        ->and($waiting->waitingReason)->toBe('budget_closure_pending')
        ->and($run->armDrafts()->sole()->state->value)->toBe('succeeded')
        ->and($run->budgetEnvelope()->firstOrFail()->state)->toBe('open');

    $gateway->closeRun($extraRun);
    $completed = app(DraftObjectiveMessages::class)->handle($run, $phase, $dispatch, $fence);
    expect($completed->runState->value)->toBe('running')
        ->and($run->budgetEnvelope()->firstOrFail()->state)->toBe('closed')
        ->and(AiProviderCallAttempt::query()->count())->toBe(1)
        ->and(AiUsageLedger::query()->count())->toBe(1);
});

it('does not let approval backlogs consume the bounded recovery selection', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $recoverable = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-recovery-after-approval-backlog',
    );
    $phase = $recoverable->phases()->sole();
    $dispatch = $recoverable->dispatches()->sole();
    $recoverable->forceFill([
        'state' => 'running',
        'lease_owner' => 'expired-after-backlog',
        'lease_expires_at' => now()->subMinute(),
        'fencing_token' => 1,
    ])->save();
    $phase->forceFill([
        'state' => 'running',
        'lease_owner' => 'expired-after-backlog',
        'lease_expires_at' => now()->subMinute(),
        'fencing_token' => 1,
        'started_at' => now()->subMinutes(2),
    ])->save();
    $dispatch->forceFill(['state' => 'consumed', 'consumed_at' => now()->subMinute()])->save();

    foreach (range(1, 3) as $index) {
        $approval = $recoverable->replicate();
        $approval->forceFill([
            'id' => sprintf('00000000-0000-4000-8000-%012d', $index),
            'run_number' => 100 + $index,
            'state' => 'waiting',
            'current_phase' => 'await_approval',
            'idempotency_key_hash' => hash('sha256', 'approval-backlog-'.$index),
            'ai_budget_envelope_id' => null,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'fencing_token' => 0,
        ])->save();
    }

    expect(app(RecoverObjectiveRuns::class)->handle((int) $recoverable->workspace_id, 1))->toBe(1)
        ->and($recoverable->phases()->count())->toBe(2)
        ->and($recoverable->phases()->latest('attempt_number')->firstOrFail()->state->value)->toBe('queued');
});

it('cancels and redacts an exact plan before deleting its Account', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $workspace = $fixture['owner']->currentWorkspace();
    $account = Account::query()->where('workspace_id', $workspace->id)->sole();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-account-deletion-plan',
    );
    $run = runS3IncrementThreeToApproval($run);
    $plan = $run->executionPlans()->with('actions')->sole();
    $action = $plan->actions->sole();
    $draft = $run->armDrafts()->sole();
    $member = AudienceSnapshotMember::query()
        ->where('workspace_id', $workspace->id)
        ->where('account_id', $account->id)
        ->sole();
    $auditHashes = [
        'destination' => $action->destination_email_hash,
        'content' => $action->content_hash,
        'fingerprint' => $action->action_fingerprint,
    ];

    expect($fixture['objective']->fresh()->current_plan_id)->toBe($plan->id)
        ->and($member->evidence_manifest)->not->toBeEmpty()
        ->and($draft->claim_manifest)->not->toBeNull();

    expect(app(DeleteAccount::class)->handle($account, $fixture['owner']))->toBe(0);

    $action = $action->fresh();
    expect($run->fresh()->state->value)->toBe('cancelled')
        ->and($plan->fresh()->status->value)->toBe('cancelled')
        ->and($action->state->value)->toBe('cancelled')
        ->and($action->account_id)->toBeNull()
        ->and($action->person_id)->toBeNull()
        ->and($action->destination_email_address)->toBeNull()
        ->and($action->subject)->toBeNull()
        ->and($action->body_text)->toBeNull()
        ->and($action->destination_email_hash)->toBe($auditHashes['destination'])
        ->and($action->content_hash)->toBe($auditHashes['content'])
        ->and($action->action_fingerprint)->toBe($auditHashes['fingerprint'])
        ->and($draft->fresh()->state->value)->toBe('cancelled')
        ->and($draft->fresh()->claim_manifest)->toBeNull()
        ->and($draft->fresh()->cta_manifest)->toBeNull()
        ->and($member->fresh()->account_id)->toBeNull()
        ->and($member->fresh()->person_id)->toBeNull()
        ->and($member->fresh()->evidence_manifest)->toBe([])
        ->and($fixture['objective']->fresh()->current_plan_id)->toBeNull()
        ->and(Account::query()->whereKey($account->id)->exists())->toBeFalse()
        ->and(Campaign::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0);
});

it('blocks Account deletion until linked provider ambiguity is reconciled', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $workspace = $fixture['owner']->currentWorkspace();
    $account = Account::query()->where('workspace_id', $workspace->id)->sole();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-account-deletion-ambiguous-provider',
    );
    [$run, $phase, $dispatch, $fence] = claimS3DraftPhase(runS3IncrementThreeToDraftPhase($run));
    expect(app(DraftObjectiveMessages::class)->reserveAllArmOperations($run, $phase, $dispatch, $fence))->toBeTrue();
    $operation = AiProviderOperation::query()->where('task', 'message_drafting')->sole();
    app(AiBudgetGateway::class)->markUncertain(
        $operation,
        'provider_uncertain',
        'The deterministic deletion test requires reconciliation.',
    );

    expect(fn () => app(DeleteAccount::class)->handle($account, $fixture['owner']))
        ->toThrow(ValidationException::class, 'waiting for in-flight Objective provider work')
        ->and(Account::query()->whereKey($account->id)->exists())->toBeTrue()
        ->and($run->fresh()->state->value)->toBe('running')
        ->and($operation->fresh()->status->value)->toBe('uncertain')
        ->and($run->executionPlans()->count())->toBe(0)
        ->and(Campaign::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0);
});

it('fences and purges an in-flight Objective graph with its workspace', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $workspace = $fixture['owner']->currentWorkspace();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-workspace-deletion-run',
    );
    $phase = $run->phases()->sole();
    $dispatch = $run->dispatches()->sole();

    expect(app(DeleteWorkspace::class)->handle($workspace, $fixture['owner']))->toBeTrue();

    app(ExecuteObjectiveRunPhase::class)->handle(
        (int) $workspace->id,
        (string) $run->id,
        (string) $phase->id,
        (string) $dispatch->id,
    );

    foreach ([
        'execution_plan_actions',
        'execution_plans',
        'objective_run_arm_drafts',
        'objective_run_dispatches',
        'objective_run_phases',
        'objective_runs',
        'ai_provider_operations',
        'ai_budget_runs',
        'ai_budget_envelopes',
    ] as $table) {
        expect(DB::table($table)->where('workspace_id', $workspace->id)->exists())->toBeFalse();
    }
    expect(DB::table('workspaces')->where('id', $workspace->id)->exists())->toBeFalse()
        ->and(Campaign::query()->where('workspace_id', $workspace->id)->exists())->toBeFalse()
        ->and(Message::query()->where('workspace_id', $workspace->id)->exists())->toBeFalse();
});

it('does not publish an in-flight provider result after cancellation fences the worker', function (string $providerOutcome): void {
    Queue::fake();
    config()->set('intelligence.testing.message_drafting_outcome', $providerOutcome);
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-cancel-during-provider-call-'.$providerOutcome,
    );
    $run = runS3IncrementThreeToDraftPhase($run);
    $registry = app(AiProviderRegistry::class);
    $tavily = $registry->for(AiProviderName::Tavily);
    $cancellingProvider = new class($run, $fixture['owner']) implements AiProviderAdapter, LlmProvider
    {
        private DeterministicOpenRouterAdapter $delegate;

        public function __construct(
            private readonly ObjectiveRun $run,
            private readonly User $owner,
        ) {
            $this->delegate = new DeterministicOpenRouterAdapter;
        }

        public function test(string $secret): ProviderTestResult
        {
            return $this->delegate->test($secret);
        }

        public function reconcile(string $secret, AiProviderOperation $operation): ?ProviderTestResult
        {
            return $this->delegate->reconcile($secret, $operation);
        }

        public function generate(string $secret, LlmRequest $request): LlmResult
        {
            app(CancelObjectiveRun::class)->handle(
                $this->run->fresh(),
                $this->owner,
                'Cancel while the provider operation is in flight.',
            );

            return $this->delegate->generate($secret, $request);
        }
    };
    app()->instance(AiProviderRegistry::class, new AiProviderRegistry([
        AiProviderName::OpenRouter->value => $cancellingProvider,
        AiProviderName::Tavily->value => $tavily,
    ]));

    $phase = $run->phases()->where('phase', 'draft_messages')->where('state', 'queued')->sole();
    $dispatch = $run->dispatches()->where('phase', 'draft_messages')->where('state', 'pending')->sole();
    app(ExecuteObjectiveRunPhase::class)->handle(
        (int) $run->workspace_id,
        (string) $run->id,
        (string) $phase->id,
        (string) $dispatch->id,
    );

    $draft = $run->armDrafts()->sole();
    expect($run->fresh()->state->value)->toBe('cancelled')
        ->and($phase->fresh()->state->value)->toBe('cancelled')
        ->and($draft->state->value)->toBe('awaiting_provider')
        ->and($draft->subject_template)->toBeNull()
        ->and($draft->body_template)->toBeNull()
        ->and($draft->output_hash)->toBeNull()
        ->and($draft->safe_error_code)->toBeNull()
        ->and(AiProviderOperation::query()->where('task', 'message_drafting')->sole()->status->value)->toBe('succeeded')
        ->and(AiProviderCallAttempt::query()->count())->toBe(1)
        ->and(AiUsageLedger::query()->count())->toBe(1)
        ->and((int) AiUsageLedger::query()->sum('cost_micros'))->toBe(700)
        ->and($run->budgetEnvelope()->firstOrFail()->state)->toBe('closed')
        ->and($run->executionPlans()->count())->toBe(0)
        ->and(Campaign::query()->count())->toBe(0);
})->with(['valid', 'malformed_schema']);

it('does not let a stale pre-call worker overwrite an authoritative cancellation', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-cancel-before-provider-reservation',
    );
    [$run, $phase, $dispatch, $fence] = claimS3DraftPhase(runS3IncrementThreeToDraftPhase($run));
    $staleRun = clone $run;
    $stalePhase = clone $phase;
    $staleDispatch = clone $dispatch;

    app(CancelObjectiveRun::class)->handle(
        $run,
        $fixture['owner'],
        'Cancel before the stale worker reserves provider work.',
    );

    expect(app(DraftObjectiveMessages::class)->reserveAllArmOperations(
        $staleRun,
        $stalePhase,
        $staleDispatch,
        $fence,
    ))->toBeFalse()
        ->and($run->fresh()->state->value)->toBe('cancelled')
        ->and($phase->fresh()->state->value)->toBe('cancelled')
        ->and($run->armDrafts()->sole()->state->value)->toBe('cancelled')
        ->and(AiProviderOperation::query()->count())->toBe(0)
        ->and(AiProviderCallAttempt::query()->count())->toBe(0)
        ->and(AiUsageLedger::query()->count())->toBe(0);
});

it('does not let a provider exception overwrite a run cancelled during the call', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-cancel-during-provider-exception',
    );
    $run = runS3IncrementThreeToDraftPhase($run);
    $registry = app(AiProviderRegistry::class);
    $tavily = $registry->for(AiProviderName::Tavily);
    $throwingProvider = new class($run, $fixture['owner']) implements AiProviderAdapter, LlmProvider
    {
        private DeterministicOpenRouterAdapter $delegate;

        public function __construct(
            private readonly ObjectiveRun $run,
            private readonly User $owner,
        ) {
            $this->delegate = new DeterministicOpenRouterAdapter;
        }

        public function test(string $secret): ProviderTestResult
        {
            return $this->delegate->test($secret);
        }

        public function reconcile(string $secret, AiProviderOperation $operation): ?ProviderTestResult
        {
            return $this->delegate->reconcile($secret, $operation);
        }

        public function generate(string $secret, LlmRequest $request): LlmResult
        {
            app(CancelObjectiveRun::class)->handle(
                $this->run->fresh(),
                $this->owner,
                'Cancel while the provider exception is in flight.',
            );

            throw new SafeProviderException('provider_unavailable', 'The deterministic provider failed before accepting work.');
        }
    };
    app()->instance(AiProviderRegistry::class, new AiProviderRegistry([
        AiProviderName::OpenRouter->value => $throwingProvider,
        AiProviderName::Tavily->value => $tavily,
    ]));

    $phase = $run->phases()->where('phase', 'draft_messages')->where('state', 'queued')->sole();
    $dispatch = $run->dispatches()->where('phase', 'draft_messages')->where('state', 'pending')->sole();
    app(ExecuteObjectiveRunPhase::class)->handle(
        (int) $run->workspace_id,
        (string) $run->id,
        (string) $phase->id,
        (string) $dispatch->id,
    );

    $draft = $run->armDrafts()->sole();
    $operation = AiProviderOperation::query()->where('task', 'message_drafting')->sole();
    expect($run->fresh()->state->value)->toBe('cancelled')
        ->and($phase->fresh()->state->value)->toBe('cancelled')
        ->and($draft->state->value)->toBe('awaiting_provider')
        ->and($draft->safe_error_code)->toBeNull()
        ->and($draft->output_hash)->toBeNull()
        ->and($operation->status->value)->toBe('failed')
        ->and($operation->safe_error_code)->toBe('provider_unavailable')
        ->and($operation->cost_basis)->toBe('not_charged')
        ->and(AiProviderCallAttempt::query()->count())->toBe(1)
        ->and(AiUsageLedger::query()->count())->toBe(0)
        ->and($run->budgetEnvelope()->firstOrFail()->state)->toBe('closed')
        ->and($run->executionPlans()->count())->toBe(0);
});

it('keeps a recovered replacement authoritative when the stale result is malformed', function (): void {
    Queue::fake();
    config()->set('intelligence.testing.message_drafting_outcome', 'malformed_schema');
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-recover-during-malformed-provider-result',
    );
    $run = runS3IncrementThreeToDraftPhase($run);
    $registry = app(AiProviderRegistry::class);
    $tavily = $registry->for(AiProviderName::Tavily);
    $recoveringProvider = new class($run) implements AiProviderAdapter, LlmProvider
    {
        public int $recovered = 0;

        private DeterministicOpenRouterAdapter $delegate;

        public function __construct(private readonly ObjectiveRun $run)
        {
            $this->delegate = new DeterministicOpenRouterAdapter;
        }

        public function test(string $secret): ProviderTestResult
        {
            return $this->delegate->test($secret);
        }

        public function reconcile(string $secret, AiProviderOperation $operation): ?ProviderTestResult
        {
            return $this->delegate->reconcile($secret, $operation);
        }

        public function generate(string $secret, LlmRequest $request): LlmResult
        {
            DB::table('objective_runs')->where('id', $this->run->id)->update([
                'lease_expires_at' => now()->subMinute(),
            ]);
            DB::table('objective_run_phases')
                ->where('objective_run_id', $this->run->id)
                ->where('phase', 'draft_messages')
                ->where('state', 'running')
                ->update(['lease_expires_at' => now()->subMinute()]);
            $this->recovered = app(RecoverObjectiveRuns::class)->handle((int) $this->run->workspace_id);

            return $this->delegate->generate($secret, $request);
        }
    };
    app()->instance(AiProviderRegistry::class, new AiProviderRegistry([
        AiProviderName::OpenRouter->value => $recoveringProvider,
        AiProviderName::Tavily->value => $tavily,
    ]));

    $oldPhase = $run->phases()->where('phase', 'draft_messages')->where('state', 'queued')->sole();
    $dispatch = $run->dispatches()->where('phase', 'draft_messages')->where('state', 'pending')->sole();
    app(ExecuteObjectiveRunPhase::class)->handle(
        (int) $run->workspace_id,
        (string) $run->id,
        (string) $oldPhase->id,
        (string) $dispatch->id,
    );

    $draft = $run->armDrafts()->sole();
    expect($recoveringProvider->recovered)->toBe(1)
        ->and($oldPhase->fresh()->state->value)->toBe('failed')
        ->and($oldPhase->fresh()->safe_error_code)->toBe('lease_recovered')
        ->and($run->fresh()->current_phase->value)->toBe('draft_messages')
        ->and($draft->state->value)->toBe('awaiting_provider')
        ->and($draft->safe_error_code)->toBeNull()
        ->and($draft->output_hash)->toBeNull()
        ->and(AiProviderOperation::query()->count())->toBe(1)
        ->and(AiProviderCallAttempt::query()->count())->toBe(1);

    $replacement = $run->phases()->where('phase', 'draft_messages')->where('state', 'queued')->sole();
    $recoveredDispatch = $dispatch->fresh();
    app(ExecuteObjectiveRunPhase::class)->handle(
        (int) $run->workspace_id,
        (string) $run->id,
        (string) $replacement->id,
        (string) $recoveredDispatch->id,
    );

    expect($run->fresh()->state->value)->toBe('needs_review')
        ->and($replacement->fresh()->state->value)->toBe('failed')
        ->and($draft->fresh()->state->value)->toBe('needs_review')
        ->and($draft->fresh()->safe_error_code)->toBe('paid_result_lost')
        ->and(AiProviderOperation::query()->count())->toBe(1)
        ->and(AiProviderCallAttempt::query()->count())->toBe(1)
        ->and(AiUsageLedger::query()->count())->toBe(1);
});

it('reviews a resumed draft without a provider call when its frozen validator changed', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-validator-changed-before-provider-claim',
    );
    $frozenValidator = (string) config('ai.tasks.message_drafting.validator_version');
    expect($run->message_validator_version)->toBe($frozenValidator)
        ->and($run->armDrafts()->sole()->validator_version)->toBe($frozenValidator);

    [$run, $phase, $dispatch, $fence] = claimS3DraftPhase(runS3IncrementThreeToDraftPhase($run));
    $drafting = app(DraftObjectiveMessages::class);
    expect($drafting->reserveAllArmOperations($run, $phase, $dispatch, $fence))->toBeTrue();
    $reserved = AiProviderOperation::query()->where('task', 'message_drafting')->sole();
    expect($reserved->status->value)->toBe('reserved')
        ->and(AiProviderCallAttempt::query()->count())->toBe(0)
        ->and(AiUsageLedger::query()->count())->toBe(0);

    config()->set('ai.tasks.message_drafting.validator_version', 'objective_message_validator_v4');
    $result = $drafting->handle($run, $phase, $dispatch, $fence);
    $draft = $run->armDrafts()->sole();

    expect($result->runState->value)->toBe('needs_review')
        ->and($draft->state->value)->toBe('needs_review')
        ->and($draft->safe_error_code)->toBe('draft_validator_changed')
        ->and($reserved->fresh()->status->value)->toBe('failed')
        ->and($reserved->fresh()->cost_basis)->toBe('not_charged')
        ->and((int) $reserved->fresh()->actual_cost_micros)->toBe(0)
        ->and(AiProviderCallAttempt::query()->count())->toBe(0)
        ->and(AiUsageLedger::query()->count())->toBe(0)
        ->and($draft->budgetRun()->firstOrFail()->status)->toBe('closed')
        ->and($run->budgetEnvelope()->firstOrFail()->state)->toBe('closed')
        ->and($run->fresh()->reserved_cost_micros)->toBe(0)
        ->and($run->fresh()->actual_cost_micros)->toBe(0);
});

it('reviews a frozen draft without spending when its model policy changes between phases', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $workspace = $fixture['owner']->currentWorkspace();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-model-policy-changed-before-drafting',
    );
    $frozenDraft = $run->armDrafts()->sole();
    $originalPolicy = AiTaskSetting::query()
        ->where('workspace_id', $workspace->id)
        ->where('task', AiTask::MessageDrafting->value)
        ->firstOrFail();
    expect($frozenDraft->model_id)->toBe($originalPolicy->model_id)
        ->and($frozenDraft->task_policy_version)->toBe($originalPolicy->policy_version);

    $dispatch = $run->dispatches()->where('state', 'pending')->sole();
    $phase = $run->phases()->where('state', 'queued')->sole();
    app(ExecuteObjectiveRunPhase::class)->handle(
        (int) $run->workspace_id,
        (string) $run->id,
        (string) $phase->id,
        (string) $dispatch->id,
    );
    $run->refresh();
    expect($run->current_phase->value)->toBe('recheck_exclusions');

    $models = config('ai.models');
    $models['openai/gpt-5-mini']['tasks'][] = AiTask::MessageDrafting->value;
    config()->set('ai.models', $models);
    app(AiWorkspaceSettings::class)->updateTask(
        $workspace,
        $fixture['owner'],
        AiTask::MessageDrafting,
        'openai/gpt-5-mini',
        (int) $originalPolicy->per_run_limit_micros,
    );

    $run = runS3IncrementThreeToDraftPhase($run);
    $draft = $run->armDrafts()->sole();
    expect($run->state->value)->toBe('needs_review')
        ->and($run->current_phase->value)->toBe('draft_messages')
        ->and($draft->state->value)->toBe('needs_review')
        ->and($draft->safe_error_code)->toBe('draft_policy_changed')
        ->and($draft->model_id)->toBe($frozenDraft->model_id)
        ->and($draft->task_policy_version)->toBe($frozenDraft->task_policy_version)
        ->and(AiProviderOperation::query()->count())->toBe(0)
        ->and(AiProviderCallAttempt::query()->count())->toBe(0)
        ->and(AiUsageLedger::query()->count())->toBe(0)
        ->and($draft->budgetRun()->firstOrFail()->status)->toBe('closed')
        ->and($run->budgetEnvelope()->firstOrFail()->state)->toBe('closed')
        ->and($run->reserved_cost_micros)->toBe(0)
        ->and($run->actual_cost_micros)->toBe(0);
});

it('blocks drafting before any reservation when frozen proof is not linked to each exact frozen Reason', function (string $lineage): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture($lineage === 'partial_multi_account' ? 2 : 1);
    $workspace = $fixture['owner']->currentWorkspace();
    $arm = $fixture['experiment']->arms()->sole();
    $members = AudienceSnapshotMember::query()
        ->where('audience_snapshot_id', $fixture['version']->audience_snapshot_id)
        ->orderBy('account_id')
        ->get();
    $member = $lineage === 'partial_multi_account' ? $members->last() : $members->first();
    expect($member)->toBeInstanceOf(AudienceSnapshotMember::class);

    $frozenReason = ReasonToContactVersion::query()
        ->where('workspace_id', $workspace->id)
        ->whereKey($member->reason_to_contact_version_id)
        ->firstOrFail();
    $link = ReasonToContactClaim::query()
        ->where('workspace_id', $workspace->id)
        ->where('reason_to_contact_version_id', $frozenReason->id)
        ->sole();

    if ($lineage === 'cross_reason') {
        $otherReason = ReasonToContactVersion::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'account_id' => $frozenReason->account_id,
            'qualification_decision_id' => $frozenReason->qualification_decision_id,
            'why_account' => 'A different current reason for the same Account.',
            'why_offer' => 'A different offer rationale.',
            'why_now' => 'A different timing rationale.',
            'factual_basis' => 'A distinct reason lineage.',
            'internal_rationale' => null,
            'confidence' => 0.95,
            'fresh_until' => now()->addDays(30),
            'expires_at' => now()->addDays(30),
            'safe_for_message' => true,
            'sensitive_inference' => false,
            'prohibited_inference' => false,
            'content_hash' => hash('sha256', 'other-reason-'.$member->id),
        ]);
        DB::table('reason_to_contact_claims')
            ->where('id', $link->id)
            ->update(['reason_to_contact_version_id' => $otherReason->id]);
    } else {
        DB::table('reason_to_contact_claims')->where('id', $link->id)->delete();
    }

    $before = [
        'operations' => AiProviderOperation::query()->count(),
        'calls' => AiProviderCallAttempt::query()->count(),
        'ledger' => AiUsageLedger::query()->count(),
        'envelopes' => DB::table('ai_budget_envelopes')->count(),
        'runs' => DB::table('ai_budget_runs')->count(),
        'objective_runs' => ObjectiveRun::query()->count(),
    ];

    expect(fn () => app(BuildObjectiveDraftPrompt::class)->handle($arm))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(StartObjectiveRun::class)->handle(
            $fixture['objective'],
            $fixture['experiment'],
            $fixture['owner'],
            'invalid-reason-claim-lineage-'.$lineage,
        ))->toThrow(ValidationException::class)
        ->and(AiProviderOperation::query()->count())->toBe($before['operations'])
        ->and(AiProviderCallAttempt::query()->count())->toBe($before['calls'])
        ->and(AiUsageLedger::query()->count())->toBe($before['ledger'])
        ->and(DB::table('ai_budget_envelopes')->count())->toBe($before['envelopes'])
        ->and(DB::table('ai_budget_runs')->count())->toBe($before['runs'])
        ->and(ObjectiveRun::query()->count())->toBe($before['objective_runs']);
})->with(['unlinked', 'cross_reason', 'partial_multi_account']);

it('admits exact frozen Reason proof linkage through drafting and deterministic rendering', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $arm = $fixture['experiment']->arms()->sole();
    $member = AudienceSnapshotMember::query()
        ->where('audience_snapshot_id', $fixture['version']->audience_snapshot_id)
        ->sole();
    $claimId = (string) $member->evidence_manifest[0]['claim_id'];
    $prompt = app(BuildObjectiveDraftPrompt::class)->handle($arm);

    expect($prompt['choices'])->toHaveCount(1)
        ->and($prompt['content'])->not->toContain(
            (string) $member->reason_to_contact_version_id,
            $claimId,
            $fixture['claim_values'][$claimId],
        );

    $beforeOperations = AiProviderOperation::query()->count();
    $beforeCalls = AiProviderCallAttempt::query()->count();
    $beforeLedger = AiUsageLedger::query()->count();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        'valid-exact-reason-proof-lineage',
    );
    $plan = runS3IncrementThreeToApproval($run)->executionPlans()->sole();

    expect($plan->action_count)->toBe(1)
        ->and(AiProviderOperation::query()->count())->toBe($beforeOperations + 1)
        ->and(AiProviderCallAttempt::query()->count())->toBe($beforeCalls + 1)
        ->and(AiUsageLedger::query()->count())->toBe($beforeLedger + 1);
});

it('compiles one arm with exact per-Person bytes and replays without sending', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture(2);
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-compiler-one-arm',
    );
    $plan = approveS3PlanForCompilerFixture(runS3IncrementThreeToApproval($run));
    $compilation = app(CompileExecutionPlan::class)->handle($plan, $fixture['owner']);
    $replay = app(CompileExecutionPlan::class)->handle($plan->fresh(), $fixture['owner']);
    $campaignLink = ExecutionPlanCampaign::query()->sole();
    $step = SequenceStep::query()->whereKey($campaignLink->sequence_step_id)->sole();

    expect($compilation->state->value)->toBe('succeeded')
        ->and($replay->id)->toBe($compilation->id)
        ->and(ExecutionPlanCompilation::query()->count())->toBe(1)
        ->and(Campaign::query()->count())->toBe(1)
        ->and(ExecutionPlanCampaign::query()->count())->toBe(1)
        ->and(ExecutionPlanActionCompilation::query()->count())->toBe(2)
        ->and(CampaignRecipient::query()->count())->toBe(2)
        ->and($step->position)->toBe(1)
        ->and($step->send_as->value)->toBe('new_thread')
        ->and($step->delay_minutes)->toBe(0)
        ->and($step->subject_spintax_enabled)->toBeFalse()
        ->and($step->body_spintax_enabled)->toBeFalse()
        ->and($plan->fresh()->status->value)->toBe('compiled')
        ->and($run->fresh()->current_phase->value)->toBe('launch')
        ->and(Message::query()->count())->toBe(0)
        ->and(SendAttempt::query()->count())->toBe(0)
        ->and(FakeMailProvider::$store['sends'] ?? [])->toBe([]);

    foreach ($plan->actions as $action) {
        $actionLink = ExecutionPlanActionCompilation::query()
            ->where('execution_plan_action_id', $action->id)
            ->sole();
        $recipient = CampaignRecipient::query()->whereKey($actionLink->campaign_recipient_id)->sole();
        $rendered = app(RenderSequenceMessage::class)->handle($step, $recipient);
        expect($recipient->person_id)->toBe($action->person_id)
            ->and($recipient->account_id)->toBe($action->account_id)
            ->and($recipient->mailbox_id)->toBe($action->mailbox_id)
            ->and($rendered['subject'])->toBe($action->subject)
            ->and($rendered['body'])->toBe($action->body_text)
            ->and($actionLink->compiled_input_hash)->toBe($action->action_fingerprint)
            ->and($actionLink->rendered_content_hash)->toBe($action->content_hash)
            ->and($recipient->toArray())->not->toHaveKeys([
                'governed_subject_snapshot',
                'governed_body_text_snapshot',
            ]);
    }
});

it('compiles two experiment arms in one transaction', function (): void {
    Queue::fake();
    $fixture = s3IncrementFourTwoArmFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-compiler-two-arm',
    );
    $plan = approveS3PlanForCompilerFixture(runS3IncrementThreeToApproval($run));

    app(CompileExecutionPlan::class)->handle($plan, $fixture['owner']);

    expect($plan->arm_count)->toBe(2)
        ->and(ExecutionPlanCampaign::query()->count())->toBe(2)
        ->and(Campaign::query()->count())->toBe(2)
        ->and(SequenceStep::query()->count())->toBe(2)
        ->and(CampaignRecipient::query()->count())->toBe($plan->action_count)
        ->and(ExecutionPlanActionCompilation::query()->count())->toBe($plan->action_count)
        ->and(Message::query()->count())->toBe(0)
        ->and(SendAttempt::query()->count())->toBe(0)
        ->and(FakeMailProvider::$store['sends'] ?? [])->toBe([]);
    foreach (ExecutionPlanCampaign::query()->get() as $mapping) {
        expect(SequenceStep::query()->where('sequence_version_id', $mapping->sequence_version_id)->count())->toBe(1)
            ->and(CampaignRecipient::query()->where('campaign_id', $mapping->campaign_id)->count())->toBeGreaterThan(0);
    }
});

it('keeps compilation unavailable until the exact approval status contract is present', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-compiler-unapproved',
    );
    $plan = runS3IncrementThreeToApproval($run)->executionPlans()->sole();

    expect(fn () => app(CompileExecutionPlan::class)->handle($plan, $fixture['owner']))
        ->toThrow(ValidationException::class, 'Only an exactly approved plan')
        ->and(ExecutionPlanCompilation::query()->count())->toBe(0)
        ->and(Campaign::query()->count())->toBe(0);
});

it('rejects a cross-workspace compiler actor without leaving lineage', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-compiler-cross-workspace',
    );
    $plan = approveS3PlanForCompilerFixture(runS3IncrementThreeToApproval($run));
    $foreignOwner = User::factory()->create();

    expect(fn () => app(CompileExecutionPlan::class)->handle($plan, $foreignOwner))
        ->toThrow(ValidationException::class, 'Only an owner of the plan workspace')
        ->and(ExecutionPlanCompilation::query()->count())->toBe(0)
        ->and(Campaign::query()->count())->toBe(0);
});

it('fails closed when an approved compiler dependency is missing or stale', function (string $condition): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-compiler-stale-'.$condition,
    );
    $plan = approveS3PlanForCompilerFixture(runS3IncrementThreeToApproval($run));
    $action = $plan->actions->sole();
    if ($condition === 'connection_version') {
        DB::table('provider_connections')->where('id', $action->provider_connection_id)->increment('authorization_version');
    } else {
        DB::table('objectives')->where('id', $run->objective_id)->update(['current_plan_id' => null]);
    }

    expect(fn () => app(CompileExecutionPlan::class)->handle($plan, $fixture['owner']))
        ->toThrow(ValidationException::class)
        ->and(ExecutionPlanCompilation::query()->count())->toBe(0)
        ->and(ApprovalBatch::query()->sole()->state->value)->toBe('invalidated')
        ->and(ApprovalEvent::query()->where('event', 'invalidated')->count())->toBe($plan->action_count)
        ->and(ExecutionPlanCampaign::query()->count())->toBe(0)
        ->and(ExecutionPlanActionCompilation::query()->count())->toBe(0)
        ->and(Campaign::query()->count())->toBe(0);
})->with(['connection_version', 'current_plan_missing']);

it('rolls back the entire Campaign graph when exact enrolment fails', function (): void {
    Queue::fake();
    $fixture = s3IncrementFourTwoArmFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-compiler-rollback',
    );
    $plan = approveS3PlanForCompilerFixture(runS3IncrementThreeToApproval($run));
    $lastArmId = $plan->actions->pluck('gtm_experiment_arm_id')->unique()->sort()->last();
    $blocked = $plan->actions->where('gtm_experiment_arm_id', $lastArmId)->sortBy('id')->last();
    // Introduce suppression during construction of the second arm, after the
    // approval checkpoint and first arm have succeeded. This exercises actual
    // all-arm rollback rather than the earlier approval-revalidation blocker.
    $builtArms = 0;
    Campaign::creating(function () use (&$builtArms, $fixture, $plan, $blocked): void {
        if (++$builtArms === 2) {
            SuppressionEntry::factory()->create([
                'user_id' => $fixture['owner']->id,
                'workspace_id' => $plan->workspace_id,
                'email_address' => $blocked->destination_email_address,
                'email_normalized' => $blocked->destination_email_address,
            ]);
        }
    });

    expect(fn () => app(CompileExecutionPlan::class)->handle($plan, $fixture['owner']))
        ->toThrow(ValidationException::class, 'approved recipient is no longer eligible')
        ->and(ExecutionPlanCompilation::query()->where('state', 'failed')->count())->toBe(1)
        ->and(ExecutionPlanCampaign::query()->count())->toBe(0)
        ->and(ExecutionPlanActionCompilation::query()->count())->toBe(0)
        ->and(CampaignRecipient::query()->count())->toBe(0)
        ->and(Campaign::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0)
        ->and(SendAttempt::query()->count())->toBe(0)
        ->and(FakeMailProvider::$store['sends'] ?? [])->toBe([]);
    expect($builtArms)->toBe(2);
});

it('freezes compiled inputs across service, bulk, clone, and direct model bypasses', function (): void {
    $fixture = compiledS3GuardFixture();
    $campaign = $fixture['campaign'];
    $step = $fixture['step'];
    $version = $fixture['version'];
    $recipient = $fixture['recipient'];
    $replacementMailbox = Mailbox::factory()->create([
        'user_id' => $fixture['owner']->id,
        'workspace_id' => $campaign->workspace_id,
    ]);
    $latePerson = Person::factory()->create(['workspace_id' => $campaign->workspace_id]);
    $candidate = new EnrollmentCandidate(
        personId: $latePerson->id,
        accountId: $latePerson->account_id,
        emailAddress: $latePerson->email_address,
        emailNormalized: $latePerson->email_normalized,
        fullName: $latePerson->full_name,
        firstName: $latePerson->first_name,
        company: null,
    );
    $experiment = MailExperiment::factory()->create([
        'created_by_user_id' => $fixture['owner']->id,
        'workspace_id' => $campaign->workspace_id,
    ]);
    $cohort = MailExperimentCohort::factory()->create([
        'mail_experiment_id' => $experiment->id,
        'key' => 'guard-clone',
    ]);
    $preparedImport = RecipientImport::factory()->make([
        'campaign_id' => $campaign->id,
        'user_id' => $fixture['owner']->id,
        'workspace_id' => $campaign->workspace_id,
    ]);
    DB::table('recipient_imports')->insert([
        ...$preparedImport->getAttributes(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $preparedImport = RecipientImport::query()->latest('id')->firstOrFail();

    $mutations = [
        fn () => app(SequenceStepEditor::class)->ensureDraft($campaign->fresh()),
        fn () => app(SequenceStepEditor::class)->add($campaign->fresh()),
        fn () => app(SequenceStepEditor::class)->update($step->fresh(), ['body_template' => 'changed']),
        fn () => app(SequenceStepEditor::class)->delete($step->fresh()),
        fn () => app(SequenceStepEditor::class)->reorder($campaign->fresh(), [$step->id]),
        fn () => app(PublishSequenceVersion::class)->cloneDraft($campaign->fresh()),
        fn () => app(PublishSequenceVersion::class)->migrateUnstarted($campaign->fresh(), $version->fresh()),
        fn () => app(EnrollPeopleInCampaign::class)->handle($campaign->fresh(), [$candidate]),
        fn () => app(PreviewRecipientImport::class)->handle(
            $fixture['owner'],
            $campaign->fresh(),
            ImportSource::Paste,
            [['email' => $latePerson->email_address]],
            ['email' => 'email'],
        ),
        fn () => app(CommitRecipientImport::class)->handle($preparedImport, 1),
        fn () => app(PauseResumeRecipient::class)->pinMailbox($recipient->fresh(), $replacementMailbox),
        fn () => app(PauseResumeRecipient::class)->remove($recipient->fresh()),
        fn () => app(CloneCampaignForExperiment::class)->handle($campaign->fresh(), $cohort),
        function () use ($campaign): void {
            $model = $campaign->fresh();
            $model->forceFill(['name' => 'Changed governed name'])->save();
        },
        function () use ($step): void {
            $model = $step->fresh();
            $model->forceFill(['body_template' => 'Changed governed body'])->save();
        },
        function () use ($version): void {
            $model = $version->fresh();
            $model->forceFill(['version' => 99])->save();
        },
        function () use ($recipient): void {
            $model = $recipient->fresh();
            $model->forceFill(['email_address' => 'changed@example.test'])->save();
        },
        fn () => $campaign->fresh()->mailboxes()->attach($replacementMailbox->id, ['position' => 2]),
        fn () => $campaign->fresh()->mailboxes()->detach($fixture['mailbox']->id),
        fn () => SequenceStep::query()->create([
            'sequence_version_id' => $version->id,
            'position' => 2,
            'title' => 'Late step',
            'delay_minutes' => 1,
            'body_template' => 'Late body',
        ]),
        fn () => SequenceVersion::query()->create([
            'campaign_id' => $campaign->id,
            'version' => 2,
            'status' => 'draft',
        ]),
        fn () => CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'workspace_id' => $campaign->workspace_id,
            'mailbox_id' => $fixture['mailbox']->id,
            'sequence_version_id' => $version->id,
            'email_address' => 'late-recipient@example.test',
            'email_normalized' => 'late-recipient@example.test',
        ]),
        fn () => RecipientImport::factory()->create([
            'campaign_id' => $campaign->id,
            'user_id' => $fixture['owner']->id,
            'workspace_id' => $campaign->workspace_id,
        ]),
        fn () => $recipient->fresh()->delete(),
        fn () => $step->fresh()->delete(),
        fn () => $version->fresh()->delete(),
        fn () => $campaign->fresh()->delete(),
    ];

    foreach ($mutations as $mutation) {
        expect($mutation)->toThrow(GovernedCampaignInputMutation::class);
    }

    expect($campaign->fresh()->name)->not->toBe('Changed governed name')
        ->and($campaign->fresh()->mailboxes)->toHaveCount(1)
        ->and(CampaignRecipient::query()->where('campaign_id', $campaign->id)->count())->toBe(1)
        ->and(SequenceStep::query()->where('sequence_version_id', $version->id)->count())->toBe(1)
        ->and(Message::query()->count())->toBe(0)
        ->and(SendAttempt::query()->count())->toBe(0)
        ->and(FakeMailProvider::$store['sends'] ?? [])->toBe([]);
});

it('does not accept a caller-constructed compiler capability', function (): void {
    $owner = User::factory()->create();
    $workspaceId = (int) $owner->currentWorkspace()->id;

    expect(fn () => DB::transaction(fn () => app(GovernedCampaignInputGuard::class)->assertCompilerCapability(
        new GovernedCampaignCompilationCapability($workspaceId, (string) Str::uuid(), DB::transactionLevel()),
        $workspaceId,
    )))->toThrow(LogicException::class, 'compiler capability is not active');
});

it('allows only narrow governed pause, complete, recipient resume, and stop transitions', function (): void {
    $fixture = compiledS3GuardFixture();
    $guard = app(GovernedCampaignInputGuard::class);
    $campaign = $fixture['campaign'];
    $recipient = $fixture['recipient'];
    $replacementMailbox = Mailbox::factory()->create([
        'user_id' => $fixture['owner']->id,
        'workspace_id' => $campaign->workspace_id,
    ]);

    expect(fn () => $guard->runRecipientPause($recipient->fresh(), function () use ($recipient, $replacementMailbox): void {
        $recipient = $recipient->fresh();
        $recipient->forceFill([
            'status' => RecipientStatus::Paused,
            'mailbox_id' => $replacementMailbox->id,
        ])->save();
    }))->toThrow(GovernedCampaignInputMutation::class);

    $campaign = app(LaunchCampaignService::class)->pause($campaign->fresh());
    $recipient = app(PauseResumeRecipient::class)->pause($recipient->fresh());
    expect($campaign->status->value)->toBe('paused')
        ->and($recipient->status->value)->toBe('paused')
        ->and($recipient->mailbox_id)->toBe($fixture['mailbox']->id);

    $recipient = app(PauseResumeRecipient::class)->resume($recipient);
    expect($recipient->status->value)->toBe('queued')
        ->and($recipient->next_send_at)->not->toBeNull();

    expect(app(StopCampaignRecipient::class)->handle(
        $recipient,
        RecipientStatus::Replied,
        'human_reply',
    ))->toBeTrue();
    $recipient->refresh();
    expect($recipient->status->value)->toBe('replied')
        ->and($recipient->stopped_at)->not->toBeNull()
        ->and($recipient->stop_reason)->toBe('human_reply');

    // Stopping the final recipient now completes the campaign automatically.
    $campaign = $campaign->fresh();
    expect($campaign->status->value)->toBe('completed')
        ->and(Message::query()->count())->toBe(0)
        ->and(SendAttempt::query()->count())->toBe(0)
        ->and(FakeMailProvider::$store['sends'] ?? [])->toBe([]);
});

it('rejects direct governed launch, resume, and test send before any provider boundary', function (): void {
    $fixture = compiledS3GuardFixture();

    expect(fn () => app(LaunchCampaignService::class)->handle($fixture['campaign']->fresh(), $fixture['owner']))
        ->toThrow(GovernedCampaignLaunchRequired::class)
        ->and(fn () => app(LaunchCampaignService::class)->resume($fixture['campaign']->fresh(), $fixture['owner']))
        ->toThrow(GovernedCampaignLaunchRequired::class)
        ->and(fn () => app(TestSequenceStep::class)->handle(
            $fixture['owner'],
            $fixture['step'],
            $fixture['mailbox'],
            'test-recipient@example.test',
            (string) Str::uuid(),
        ))->toThrow(GovernedCampaignInputMutation::class)
        ->and($fixture['campaign']->fresh()->status->value)->toBe('draft')
        ->and(Message::query()->count())->toBe(0)
        ->and(SendAttempt::query()->count())->toBe(0)
        ->and(FakeMailProvider::$store['sends'] ?? [])->toBe([]);
});

it('returns governed errors to the campaign page for Inertia requests', function (): void {
    $fixture = compiledS3GuardFixture();
    $campaign = $fixture['campaign'];
    $other = Person::factory()->create(['workspace_id' => $campaign->workspace_id]);
    $page = route('campaigns.show', $campaign);
    $this->actingAs($fixture['owner'])->from($page);

    foreach ([
        ['patch', route('campaigns.update', $campaign), ['name' => 'Not mutable'], 'campaign', 303],
        ['post', route('campaigns.launch', $campaign), [], 'campaign', 302],
        ['delete', route('sequence-steps.destroy', $fixture['step']), [], 'sequence', 303],
        ['delete', route('campaign-recipients.destroy', $fixture['recipient']), [], 'recipient', 303],
        ['post', route('campaigns.enrollments.store', $campaign), ['person_ids' => [$other->id]], 'campaign', 302],
    ] as [$method, $url, $data, $field, $status]) {
        $code = $url === route('campaigns.launch', $campaign)
            ? GovernedCampaignLaunchRequired::CODE
            : GovernedCampaignInputMutation::CODE;
        $this->withCookie(config('session.cookie'), session()->getId())->{$method}($url, $data, ['X-Inertia' => 'true'])
            ->assertStatus($status)
            ->assertRedirect($page)
            ->assertSessionHasErrors($field);
        $this->withCookie(config('session.cookie'), session()->getId())->get($page, ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion()])
            ->assertOk()
            ->assertJsonPath('props.errors.'.$field, fn ($error) => is_string($error) && str_starts_with($error, $code));
    }
});

it('returns stable governed conflicts through session, REST, and MCP surfaces', function (): void {
    $fixture = compiledS3GuardFixture();
    $owner = $fixture['owner'];
    $campaign = $fixture['campaign'];
    $step = $fixture['step'];
    $recipient = $fixture['recipient'];
    $other = Person::factory()->create(['workspace_id' => $campaign->workspace_id]);

    $webUpdate = $this->actingAs($owner)
        ->patch(route('campaigns.update', $campaign), ['name' => 'Not mutable']);
    $webUpdate
        ->assertRedirect()
        ->assertSessionHasErrors('campaign');
    $webLaunch = $this->actingAs($owner)
        ->post(route('campaigns.launch', $campaign));
    $webLaunch
        ->assertRedirect()
        ->assertSessionHasErrors('campaign');

    $token = apiToken($owner, [
        TokenScope::CampaignsWrite->value,
        TokenScope::CampaignsLaunch->value,
        TokenScope::InboxSend->value,
    ]);
    $this->app['auth']->forgetGuards();
    $apiCampaign = $this->withToken($token)
        ->patchJson('/api/v1/campaigns/'.$campaign->id, ['name' => 'Not mutable']);
    $apiCampaign
        ->assertStatus(409)
        ->assertJsonPath('error.type', GovernedCampaignInputMutation::CODE);
    $apiStep = $this->withToken($token)
        ->patchJson('/api/v1/sequence-steps/'.$step->id, ['body_template' => 'Not mutable']);
    $apiStep
        ->assertStatus(409)
        ->assertJsonPath('error.type', GovernedCampaignInputMutation::CODE);
    $apiEnroll = $this->withToken($token)
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/campaigns/'.$campaign->id.'/enrollments', ['person_ids' => [$other->id]]);
    $apiEnroll
        ->assertStatus(409)
        ->assertJsonPath('error.type', GovernedCampaignInputMutation::CODE);
    $apiDelete = $this->withToken($token)
        ->deleteJson('/api/v1/campaign-recipients/'.$recipient->id);
    $apiDelete
        ->assertStatus(409)
        ->assertJsonPath('error.type', GovernedCampaignInputMutation::CODE);
    $testOperation = (string) Str::uuid();
    $apiTest = $this->withToken($token)
        ->withHeader('Idempotency-Key', $testOperation)
        ->postJson('/api/v1/sequence-steps/'.$step->id.'/test', [
            'mailbox_id' => $fixture['mailbox']->id,
            'to' => 'test-recipient@example.test',
            'client_operation_id' => $testOperation,
        ]);
    $apiTest
        ->assertStatus(409)
        ->assertJsonPath('error.type', GovernedCampaignInputMutation::CODE);
    $apiLaunch = $this->withToken($token)
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/campaigns/'.$campaign->id.'/launch');
    $apiLaunch
        ->assertStatus(409)
        ->assertJsonPath('error.type', GovernedCampaignLaunchRequired::CODE);

    OutbossServer::actingAs(mcpUser($owner, [TokenScope::CampaignsWrite->value]))
        ->tool(UpdateCampaign::class, ['campaign_id' => $campaign->id, 'name' => 'Not mutable'])
        ->assertHasErrors([GovernedCampaignInputMutation::CODE]);
    OutbossServer::actingAs(mcpUser($owner, [TokenScope::CampaignsWrite->value]))
        ->tool(EnrollPeople::class, ['campaign_id' => $campaign->id, 'person_ids' => [$other->id]])
        ->assertHasErrors([GovernedCampaignInputMutation::CODE]);
    OutbossServer::actingAs(mcpUser($owner, [TokenScope::CampaignsWrite->value]))
        ->tool(UpdateRecipient::class, [
            'recipient_id' => $recipient->id,
            'mailbox_id' => $fixture['mailbox']->id,
        ])
        ->assertHasErrors([GovernedCampaignInputMutation::CODE]);
    OutbossServer::actingAs(mcpUser($owner, [TokenScope::InboxSend->value]))
        ->tool(SendTestStep::class, [
            'step_id' => $step->id,
            'mailbox_id' => $fixture['mailbox']->id,
            'to' => 'test-recipient@example.test',
            'client_operation_id' => (string) Str::uuid(),
        ])
        ->assertHasErrors([GovernedCampaignInputMutation::CODE]);
    OutbossServer::actingAs(mcpUser($owner, [TokenScope::CampaignsLaunch->value]))
        ->tool(LaunchCampaign::class, ['campaign_id' => $campaign->id])
        ->assertHasErrors([GovernedCampaignLaunchRequired::CODE]);

    expect(Message::query()->count())->toBe(0)
        ->and(SendAttempt::query()->count())->toBe(0)
        ->and(FakeMailProvider::$store['sends'] ?? [])->toBe([]);
});

it('fails closed when governed compilation lineage is partial or corrupt', function (string $condition): void {
    $fixture = compiledS3GuardFixture();
    $link = ExecutionPlanActionCompilation::query()->sole();

    if ($condition === 'partial') {
        DB::table('execution_plan_action_compilations')->where('id', $link->id)->delete();
    } else {
        DB::table('execution_plan_action_compilations')->where('id', $link->id)->update([
            'compiled_input_hash' => hash('sha256', 'corrupt-cross-lineage-input'),
        ]);
    }

    $campaign = $fixture['campaign']->fresh();
    $campaign->forceFill(['name' => 'Must remain frozen']);
    expect(fn () => $campaign->save())
        ->toThrow(GovernedCampaignInputMutation::class)
        ->and(fn () => app(PersonEditor::class)->delete($fixture['person'], $fixture['owner']))
        ->toThrow(GovernedCampaignInputMutation::class)
        ->and($fixture['campaign']->fresh()->name)->not->toBe('Must remain frozen')
        ->and($fixture['person']->fresh())->not->toBeNull()
        ->and($fixture['recipient']->fresh()->person_id)->toBe($fixture['person']->id);
})->with(['partial', 'hash_mismatch']);

it('stops and redacts compiled recipients before canonical Person deletion detaches lineage', function (): void {
    $fixture = compiledS3GuardFixture();

    app(PersonEditor::class)->delete($fixture['person'], $fixture['owner']);

    $recipient = $fixture['recipient']->fresh();
    expect($fixture['person']->fresh())->toBeNull()
        ->and($recipient->person_id)->toBeNull()
        ->and($recipient->account_id)->toBeNull()
        ->and($recipient->email_address)->toEndWith('@deleted.invalid')
        ->and($recipient->full_name)->toBeNull()
        ->and($recipient->governed_subject_snapshot)->toBeNull()
        ->and($recipient->governed_body_text_snapshot)->toBeNull()
        ->and($recipient->status->value)->toBe('removed')
        ->and($recipient->stop_reason)->toBe('person_deleted')
        ->and($fixture['plan']->fresh()->status->value)->toBe('cancelled')
        ->and(fn () => app(LaunchCampaignService::class)->handle($fixture['campaign']->fresh(), $fixture['owner']))
        ->toThrow(GovernedCampaignLaunchRequired::class)
        ->and(Message::query()->count())->toBe(0)
        ->and(SendAttempt::query()->count())->toBe(0)
        ->and(FakeMailProvider::$store['sends'] ?? [])->toBe([]);
});

it('stops and redacts compiled recipients before canonical Account deletion detaches lineage', function (): void {
    $fixture = compiledS3GuardFixture();

    expect(app(DeleteAccount::class)->handle($fixture['account'], $fixture['owner']))->toBe(1);

    $recipient = $fixture['recipient']->fresh();
    expect($fixture['account']->fresh())->toBeNull()
        ->and($fixture['person']->fresh())->not->toBeNull()
        ->and($fixture['person']->fresh()->account_id)->toBeNull()
        ->and($recipient->person_id)->toBeNull()
        ->and($recipient->account_id)->toBeNull()
        ->and($recipient->email_address)->toEndWith('@deleted.invalid')
        ->and($recipient->governed_subject_snapshot)->toBeNull()
        ->and($recipient->governed_body_text_snapshot)->toBeNull()
        ->and($recipient->status->value)->toBe('removed')
        ->and($recipient->stop_reason)->toBe('account_deleted')
        ->and($fixture['plan']->fresh()->status->value)->toBe('cancelled')
        ->and(fn () => app(LaunchCampaignService::class)->handle($fixture['campaign']->fresh(), $fixture['owner']))
        ->toThrow(GovernedCampaignLaunchRequired::class)
        ->and(Message::query()->count())->toBe(0)
        ->and(SendAttempt::query()->count())->toBe(0)
        ->and(FakeMailProvider::$store['sends'] ?? [])->toBe([]);
});

it('purges compiled lineage and Campaign copies with an authorised workspace deletion', function (): void {
    $fixture = compiledS3GuardFixture();
    $workspace = $fixture['owner']->currentWorkspace();

    expect(app(DeleteWorkspace::class)->handle($workspace, $fixture['owner']))->toBeTrue()
        ->and(ApprovalEvent::query()->count())->toBe(0)
        ->and(ApprovalGrant::query()->count())->toBe(0)
        ->and(ApprovalBatch::query()->count())->toBe(0)
        ->and(PolicyEvaluation::query()->count())->toBe(0)
        ->and(ExecutionPlanActionCompilation::query()->count())->toBe(0)
        ->and(ExecutionPlanCampaign::query()->count())->toBe(0)
        ->and(ExecutionPlanCompilation::query()->count())->toBe(0)
        ->and(CampaignRecipient::query()->count())->toBe(0)
        ->and(Campaign::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0)
        ->and(SendAttempt::query()->count())->toBe(0)
        ->and(FakeMailProvider::$store['sends'] ?? [])->toBe([]);
});

it('creates one immutable owner grant per exact action and replays only the same decision input', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-exact-approval-grants',
    );
    $plan = runS3IncrementThreeToApproval($run)->executionPlans()->sole();
    $actions = $plan->actions()->orderBy('id')->get();
    $manifest = app(ApprovalManifest::class);
    $service = app(ApproveExecutionPlan::class);
    $arguments = [
        $plan,
        $fixture['owner'],
        (string) $plan->plan_hash,
        $manifest->hash($plan, $actions),
        $manifest->actionFingerprints($plan, $actions),
        'Owner reviewed every exact action.',
    ];

    $batch = $service->handle(...$arguments);
    $replay = $service->handle(...$arguments);

    expect($replay->id)->toBe($batch->id)
        ->and($batch->state->value)->toBe('approved')
        ->and($batch->expires_at->lessThan($actions->min('scheduled_not_after')))->toBeTrue()
        ->and(ApprovalBatch::query()->count())->toBe(1)
        ->and(ApprovalGrant::query()->count())->toBe($actions->count())
        ->and(ApprovalGrant::query()->distinct('action_fingerprint')->count('action_fingerprint'))->toBe($actions->count())
        ->and(ApprovalEvent::query()->where('event', 'approved')->count())->toBe($actions->count())
        ->and(PolicyEvaluation::query()->where('stage', 'approval')->where('result', 'allowed')->count())->toBe($actions->count())
        ->and(Campaign::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0)
        ->and(SendAttempt::query()->count())->toBe(0)
        ->and(fn () => $service->handle(
            $plan,
            $fixture['owner'],
            (string) $plan->plan_hash,
            $manifest->hash($plan, $actions),
            $manifest->actionFingerprints($plan, $actions),
            'A different replay payload.',
        ))->toThrow(ApprovalConflict::class, 'different decision');
});

it('rejects stale echoes and non-owner or cross-workspace approval attempts', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-approval-auth-and-echo',
    );
    $plan = runS3IncrementThreeToApproval($run)->executionPlans()->sole();
    $actions = $plan->actions()->orderBy('id')->get();
    $manifest = app(ApprovalManifest::class);
    $payload = [
        'plan_hash' => (string) $plan->plan_hash,
        'display_manifest_hash' => $manifest->hash($plan, $actions),
        'action_fingerprints' => $manifest->actionFingerprints($plan, $actions),
    ];

    $this->actingAs($fixture['owner'])
        ->post(route('execution-plans.approve', $plan), [
            ...$payload,
            'display_manifest_hash' => str_repeat('0', 64),
        ], ['Idempotency-Key' => 'stale-session-approval'])
        ->assertRedirect()
        ->assertSessionHasErrors('approval');

    $member = User::factory()->withoutWorkspace()->create();
    WorkspaceMembership::query()->create([
        'workspace_id' => $plan->workspace_id,
        'user_id' => $member->id,
        'role' => 'member',
    ]);
    $this->actingAs($member)
        ->post(route('execution-plans.approve', $plan), $payload, ['Idempotency-Key' => 'member-approval'])
        ->assertForbidden();

    $foreign = User::factory()->create();
    $this->actingAs($foreign)
        ->post(route('execution-plans.approve', $plan), $payload, ['Idempotency-Key' => 'foreign-approval'])
        ->assertNotFound();

    expect(ApprovalBatch::query()->count())->toBe(0)
        ->and(ApprovalGrant::query()->count())->toBe(0);
});

it('exposes owner REST approval with exact echoes and refuses a stale action set', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-rest-exact-approval',
    );
    $plan = runS3IncrementThreeToApproval($run)->executionPlans()->sole();
    $actions = $plan->actions()->orderBy('id')->get();
    $manifest = app(ApprovalManifest::class);
    $token = apiToken($fixture['owner'], [TokenScope::ObjectivesApprove->value]);
    $payload = [
        'plan_hash' => (string) $plan->plan_hash,
        'display_manifest_hash' => $manifest->hash($plan, $actions),
        'action_fingerprints' => [str_repeat('0', 64)],
    ];

    $this->withToken($token)
        ->postJson('/api/v1/execution-plans/'.$plan->id.'/approve', $payload, ['Idempotency-Key' => 'stale-rest-action-set'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'approval_input_changed');

    $payload['action_fingerprints'] = $manifest->actionFingerprints($plan, $actions);
    $this->withToken($token)
        ->postJson('/api/v1/execution-plans/'.$plan->id.'/approve', $payload, ['Idempotency-Key' => 'exact-rest-action-set'])
        ->assertCreated()
        ->assertJsonPath('data.state', 'approved')
        ->assertJsonPath('data.action_count', $actions->count())
        ->assertJsonCount($actions->count(), 'data.grants');
});

it('records blocked approval evaluations and never creates grants for suppressed recipients', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-suppressed-approval',
    );
    $plan = runS3IncrementThreeToApproval($run)->executionPlans()->sole();
    $actions = $plan->actions()->orderBy('id')->get();
    SuppressionEntry::factory()->create([
        'user_id' => $fixture['owner']->id,
        'workspace_id' => $plan->workspace_id,
        'email_address' => $actions->first()->destination_email_address,
        'email_normalized' => $actions->first()->destination_email_address,
    ]);
    $manifest = app(ApprovalManifest::class);

    expect(fn () => app(ApproveExecutionPlan::class)->handle(
        $plan,
        $fixture['owner'],
        (string) $plan->plan_hash,
        $manifest->hash($plan, $actions),
        $manifest->actionFingerprints($plan, $actions),
    ))->toThrow(ApprovalConflict::class, 'failed exact policy')
        ->and(ApprovalBatch::query()->count())->toBe(0)
        ->and(ApprovalGrant::query()->count())->toBe(0)
        ->and(PolicyEvaluation::query()->where('result', 'blocked')->count())->toBe($actions->count())
        ->and(PolicyEvaluation::query()->first()->reason_codes)->toContain('recipient_suppressed');
});

it('derives expiry and dependency invalidation from append-only grant events', function (string $condition): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-approval-terminal-'.$condition,
    );
    $plan = approveS3PlanForCompilerFixture(runS3IncrementThreeToApproval($run));
    $batch = ApprovalBatch::query()->sole();
    $action = $plan->actions->first();
    if ($condition === 'expiry') {
        DB::table('approval_batches')->where('id', $batch->id)->update(['expires_at' => now()->subSecond()]);
        DB::table('approval_grants')->where('approval_batch_id', $batch->id)->update(['expires_at' => now()->subSecond()]);
    } else {
        DB::table('provider_connections')->where('id', $action->provider_connection_id)->increment('authorization_version');
    }

    $decision = app(GovernedExecutionCheckpoint::class)->forPlan(
        $plan,
        PolicyEvaluationStage::Approval,
        true,
    );

    expect($decision->allowed())->toBeFalse()
        ->and(collect($decision->blockers)->pluck('code')->all())->toContain(
            $condition === 'expiry' ? 'approval_expired' : 'approval_invalidated',
        )
        ->and($batch->fresh()->state->value)->toBe($condition === 'expiry' ? 'expired' : 'invalidated')
        ->and(ApprovalEvent::query()->where('event', $condition === 'expiry' ? 'expired' : 'invalidated')->count())
        ->toBe($plan->action_count);
})->with(['expiry', 'dependency_mutation']);

it('rejects the fixed batch with immutable per-action decisions and exact replay semantics', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-approval-reject',
    );
    $plan = runS3IncrementThreeToApproval($run)->executionPlans()->sole();
    $actions = $plan->actions()->orderBy('id')->get();
    $manifest = app(ApprovalManifest::class);
    $service = app(RejectExecutionPlan::class);
    $arguments = [
        $plan,
        $fixture['owner'],
        (string) $plan->plan_hash,
        $manifest->hash($plan, $actions),
        $manifest->actionFingerprints($plan, $actions),
        'Do not execute this batch.',
    ];

    $batch = $service->handle(...$arguments);
    $replay = $service->handle(...$arguments);

    expect($batch->state->value)->toBe('rejected')
        ->and($replay->id)->toBe($batch->id)
        ->and(ApprovalGrant::query()->where('decision', 'rejected')->count())->toBe($actions->count())
        ->and(ApprovalEvent::query()->where('event', 'rejected')->count())->toBe($actions->count())
        ->and($plan->fresh()->status->value)->toBe('rejected')
        ->and($plan->actions()->where('state', 'rejected')->count())->toBe($actions->count())
        ->and(Campaign::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0)
        ->and(SendAttempt::query()->count())->toBe(0)
        ->and(fn () => $service->handle(...array_replace($arguments, [5 => 'A changed rejection.'])))
        ->toThrow(ApprovalConflict::class, 'different decision');
});

it('revokes every action grant as one exact owner decision and replays the same revocation', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-approval-revoke',
    );
    $plan = approveS3PlanForCompilerFixture(runS3IncrementThreeToApproval($run));
    $batch = ApprovalBatch::query()->sole();
    $actions = $plan->actions()->orderBy('id')->get();
    $manifest = app(ApprovalManifest::class);
    $arguments = [
        $batch,
        $fixture['owner'],
        (string) $plan->plan_hash,
        $manifest->hash($plan, $actions),
        $manifest->actionFingerprints($plan, $actions),
        'Stop all remaining actions.',
    ];

    $revoked = app(RevokeExecutionApproval::class)->handle(...$arguments);
    $replay = app(RevokeExecutionApproval::class)->handle(...$arguments);

    expect($revoked->state->value)->toBe('revoked')
        ->and($replay->id)->toBe($revoked->id)
        ->and(ApprovalEvent::query()->where('event', 'revoked')->count())->toBe($plan->action_count)
        ->and($plan->fresh()->status->value)->toBe('revoked')
        ->and($plan->actions()->where('state', 'revoked')->count())->toBe($plan->action_count)
        ->and(app(GovernedExecutionCheckpoint::class)->forPlan(
            $plan,
            PolicyEvaluationStage::Approval,
            true,
        )->primaryCode())->toBe('plan_state_invalid');
});

it('refuses compiler bypass when only mutable plan and action projections are flipped', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-compiler-grant-bypass',
    );
    $plan = runS3IncrementThreeToApproval($run)->executionPlans()->sole();
    DB::table('execution_plan_actions')->where('execution_plan_id', $plan->id)
        ->update(['state' => 'approved', 'updated_at' => now()]);
    DB::table('execution_plans')->where('id', $plan->id)
        ->update(['status' => 'approved', 'updated_at' => now()]);

    expect(fn () => app(CompileExecutionPlan::class)->handle($plan->fresh(), $fixture['owner']))
        ->toThrow(ValidationException::class, 'approval_missing')
        ->and(ApprovalGrant::query()->count())->toBe(0)
        ->and(Campaign::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0)
        ->and(SendAttempt::query()->count())->toBe(0);
});

it('waits for the approved window even when launched early or made prematurely due', function (): void {
    FakeMailProvider::reset();
    $this->travelTo(now()->setTime(10, 0));
    Config::set('outboss.campaign_sending_enabled', true);
    $fixture = compiledS3GuardFixture(startDelayMinutes: 10);
    app(LaunchObjectiveExecution::class)->handle($fixture['plan'], $fixture['owner']);
    $action = $fixture['plan']->actions()->sole();

    expect($fixture['recipient']->fresh()->next_send_at->equalTo($action->scheduled_not_before))->toBeTrue()
        ->and(app(ScheduleDueCampaignSends::class)->handle())->toBe(0);

    // Even a stale scheduler due-time cannot override the approved boundary.
    DB::table('campaign_recipients')->where('id', $fixture['recipient']->id)->update(['next_send_at' => now()]);
    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(0);
    foreach ([PolicyEvaluationStage::Schedule, PolicyEvaluationStage::Lease, PolicyEvaluationStage::PreSend] as $stage) {
        $decision = app(GovernedExecutionCheckpoint::class)->forAction($action, $stage);
        expect(array_column($decision->blockers, 'code'))->toContain('schedule_not_open');
    }
    expect(ApprovalBatch::query()->sole()->state->value)->toBe('approved')
        ->and(Message::query()->count())->toBe(0)
        ->and(SendAttempt::query()->count())->toBe(0)
        ->and(FakeMailProvider::$store['sends'] ?? [])->toBe([]);

    $this->travelTo($action->scheduled_not_before->copy()->subSecond());
    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(0);
    $this->travelTo($action->scheduled_not_before);
    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(1);
    $attempt = SendAttempt::query()->sole();
    app(SendCampaignMessage::class)->handle($attempt->id);
    app(SendCampaignMessage::class)->handle($attempt->id);
    expect([$attempt->fresh()->status->value, $attempt->fresh()->deferred_reason])->toBe(['sent', null])
        ->and(FakeMailProvider::$store['sends'] ?? [])->toHaveCount(1);
});

it('launches and sends one exact governed action once through every provider checkpoint', function (): void {
    FakeMailProvider::reset();
    $this->travelTo(now()->setTime(10, 0));
    Config::set('outboss.campaign_sending_enabled', true);
    $fixture = compiledS3GuardFixture();
    $plan = app(LaunchObjectiveExecution::class)->handle($fixture['plan'], $fixture['owner']);

    expect($plan->status->value)->toBe('executing')
        ->and($fixture['campaign']->fresh()->status->value)->toBe('active')
        ->and($plan->actions()->sole()->state->value)->toBe('queued')
        ->and(PolicyEvaluation::query()->where('stage', 'launch')->where('result', 'allowed')->exists())->toBeTrue();

    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(1)
        ->and(app(ScheduleDueCampaignSends::class)->handle())->toBe(1)
        ->and(Message::query()->count())->toBe(1)
        ->and(SendAttempt::query()->count())->toBe(1);

    $attempt = SendAttempt::query()->sole();
    app(SendCampaignMessage::class)->handle($attempt->id);
    app(SendCampaignMessage::class)->handle($attempt->id);

    app(RecordObjectiveExposure::class)->handle((int) $plan->workspace_id, (int) $attempt->id);
    $events = ExecutionActionEvent::query()->orderBy('occurred_at')->pluck('event')->map->value->all();
    expect(FakeMailProvider::$store['sends'] ?? [])->toHaveCount(1)
        ->and($attempt->fresh()->status->value)->toBe('sent')
        ->and($plan->actions()->sole()->state->value)->toBe('sent')
        ->and($events)->toContain('planned', 'compiled', 'queued', 'attempted', 'confirmed_sent')
        ->and(ExecutionActionEvent::query()->where('event', 'attempted')->count())->toBe(1)
        ->and(PolicyEvaluation::query()->where('stage', 'schedule')->where('result', 'allowed')->count())->toBeGreaterThanOrEqual(1)
        ->and(PolicyEvaluation::query()->where('stage', 'lease')->where('result', 'allowed')->count())->toBe(1)
        ->and(PolicyEvaluation::query()->where('stage', 'pre_send')->where('result', 'allowed')->count())->toBe(1);
});

it('requires the plan workspace owner and keeps every arm unchanged when governed launch blocks', function (): void {
    Queue::fake();
    FakeMailProvider::reset();
    $fixture = s3IncrementFourTwoArmFixture();
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-governed-launch-atomic',
    );
    $plan = approveS3PlanForCompilerFixture(runS3IncrementThreeToApproval($run));
    app(CompileExecutionPlan::class)->handle($plan, $fixture['owner']);
    $campaignIds = ExecutionPlanCampaign::query()->orderBy('campaign_id')->pluck('campaign_id');
    $foreignOwner = User::factory()->create();

    expect(fn () => app(LaunchObjectiveExecution::class)->handle($plan, $foreignOwner))
        ->toThrow(AuthorizationException::class)
        ->and(Campaign::query()->whereIn('id', $campaignIds)->where('status', 'draft')->count())->toBe(2);

    $lastCampaignId = (int) $campaignIds->last();
    DB::table('campaign_recipients')->where('campaign_id', $lastCampaignId)->update([
        'status' => 'removed',
        'updated_at' => now(),
    ]);

    expect(fn () => app(LaunchObjectiveExecution::class)->handle($plan->fresh(), $fixture['owner']))
        ->toThrow(GovernedExecutionBlocked::class)
        ->and(Campaign::query()->whereIn('id', $campaignIds)->where('status', 'draft')->count())->toBe(2)
        ->and($plan->fresh()->status->value)->toBe('compiled')
        ->and(Message::query()->count())->toBe(0)
        ->and(SendAttempt::query()->count())->toBe(0)
        ->and(FakeMailProvider::$store['sends'] ?? [])->toBe([]);
});

it('fails closed at scheduling when a governed action mapping is corrupt', function (): void {
    FakeMailProvider::reset();
    $this->travelTo(now()->setTime(10, 0));
    Config::set('outboss.campaign_sending_enabled', true);
    $fixture = compiledS3GuardFixture();
    app(LaunchObjectiveExecution::class)->handle($fixture['plan'], $fixture['owner']);
    $link = ExecutionPlanActionCompilation::query()->sole();
    DB::table('execution_plan_action_compilations')->where('id', $link->id)->update([
        'compiled_input_hash' => hash('sha256', 'corrupt-after-launch'),
    ]);

    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(0)
        ->and(Message::query()->count())->toBe(0)
        ->and(SendAttempt::query()->count())->toBe(0)
        ->and(FakeMailProvider::$store['sends'] ?? [])->toBe([]);
});

it('rechecks approval at the lease seam and never calls the provider after revocation', function (): void {
    FakeMailProvider::reset();
    $this->travelTo(now()->setTime(10, 0));
    Config::set('outboss.campaign_sending_enabled', true);
    $fixture = compiledS3GuardFixture();
    app(LaunchObjectiveExecution::class)->handle($fixture['plan'], $fixture['owner']);
    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(1);
    $attempt = SendAttempt::query()->sole();
    $batch = ApprovalBatch::query()->sole();
    $actions = $fixture['plan']->actions()->orderBy('id')->get();
    $manifest = app(ApprovalManifest::class);
    app(RevokeExecutionApproval::class)->handle(
        $batch,
        $fixture['owner'],
        (string) $fixture['plan']->plan_hash,
        $manifest->hash($fixture['plan'], $actions),
        $manifest->actionFingerprints($fixture['plan'], $actions),
        'Stop before the provider boundary.',
    );

    expect(app(AcquireRecipientSendLease::class)->handle($attempt->fresh()))->toBeNull();
    app(SendCampaignMessage::class)->handle($attempt->id);

    expect($attempt->fresh()->status->value)->toBe('deferred')
        ->and(FakeMailProvider::$store['sends'] ?? [])->toBe([])
        ->and(PolicyEvaluation::query()->where('stage', 'lease')->where('result', 'blocked')->exists())->toBeTrue()
        ->and(ExecutionActionEvent::query()->where('event', 'attempted')->count())->toBe(0);
});

it('rechecks suppression immediately before provider send and releases the governed lease', function (): void {
    FakeMailProvider::reset();
    $this->travelTo(now()->setTime(10, 0));
    Config::set('outboss.campaign_sending_enabled', true);
    $fixture = compiledS3GuardFixture();
    app(LaunchObjectiveExecution::class)->handle($fixture['plan'], $fixture['owner']);
    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(1);
    $attempt = SendAttempt::query()->sole();
    $realLeases = app(AcquireRecipientSendLease::class);
    $this->mock(AcquireRecipientSendLease::class)
        ->shouldReceive('handle')
        ->once()
        ->andReturnUsing(function (SendAttempt $candidate) use ($realLeases, $fixture): ?SendAttempt {
            $leased = $realLeases->handle($candidate);
            SuppressionEntry::factory()->create([
                'user_id' => $fixture['owner']->id,
                'workspace_id' => $fixture['plan']->workspace_id,
                'email_address' => $fixture['recipient']->email_address,
                'email_normalized' => $fixture['recipient']->email_normalized,
            ]);

            return $leased;
        });

    app(SendCampaignMessage::class)->handle($attempt->id);

    expect($attempt->fresh()->status->value)->toBe('deferred')
        ->and($fixture['recipient']->fresh()->send_lease_token)->toBeNull()
        ->and(FakeMailProvider::$store['sends'] ?? [])->toBe([])
        ->and(ExecutionActionEvent::query()->where('event', 'attempted')->count())->toBe(0);
});

it('blocks live policy and sender mutations at the exact pre-provider checkpoint', function (string $mutation, string $reason): void {
    FakeMailProvider::reset();
    $this->travelTo(now()->setTime(10, 0));
    Config::set('outboss.campaign_sending_enabled', true);
    $fixture = compiledS3GuardFixture();
    app(LaunchObjectiveExecution::class)->handle($fixture['plan'], $fixture['owner']);
    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(1);
    $attempt = SendAttempt::query()->sole();
    $realLeases = app(AcquireRecipientSendLease::class);
    $this->mock(AcquireRecipientSendLease::class)
        ->shouldReceive('handle')
        ->once()
        ->andReturnUsing(function (SendAttempt $candidate) use ($realLeases, $fixture, $mutation): ?SendAttempt {
            $leased = $realLeases->handle($candidate);
            if ($mutation === 'policy_pause') {
                DB::table('outreach_policies')->where('workspace_id', $fixture['plan']->workspace_id)->update([
                    'execution_paused_at' => now(),
                    'execution_paused_reason' => 'operator_pause',
                    'execution_paused_by_user_id' => $fixture['owner']->id,
                    'updated_at' => now(),
                ]);
            } else {
                $identities = $fixture['mailbox']->fresh()->sender_identities;
                $identities[0]['name'] = 'Changed after lease';
                DB::table('mailboxes')->where('id', $fixture['mailbox']->id)->update([
                    'sender_identities' => json_encode($identities, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
            }

            return $leased;
        });

    app(SendCampaignMessage::class)->handle($attempt->id);

    $evaluation = PolicyEvaluation::query()->where('stage', 'pre_send')->where('result', 'blocked')->sole();
    expect($attempt->fresh()->status->value)->toBe('deferred')
        ->and($fixture['recipient']->fresh()->send_lease_token)->toBeNull()
        ->and(FakeMailProvider::$store['sends'] ?? [])->toBe([])
        ->and($evaluation->reason_codes)->toContain($reason)
        ->and(ExecutionActionEvent::query()->where('event', 'attempted')->count())->toBe(0);
})->with([
    'policy pause' => ['policy_pause', 'workspace_outreach_paused'],
    'sender mutation' => ['sender_mutation', 'sender_identity_changed'],
]);

it('rejects a stolen governed send lease without clearing the newer fence or calling the provider', function (): void {
    FakeMailProvider::reset();
    $this->travelTo(now()->setTime(10, 0));
    Config::set('outboss.campaign_sending_enabled', true);
    $fixture = compiledS3GuardFixture();
    app(LaunchObjectiveExecution::class)->handle($fixture['plan'], $fixture['owner']);
    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(1);
    $attempt = SendAttempt::query()->sole();
    $stolenToken = (string) Str::uuid();
    $realConnections = app(EnsureConnectionUsable::class);
    $this->mock(EnsureConnectionUsable::class)
        ->shouldReceive('handle')
        ->once()
        ->andReturnUsing(function (ProviderConnection $connection) use ($realConnections, $fixture, $stolenToken): ProviderConnection {
            $usable = $realConnections->handle($connection);
            DB::table('campaign_recipients')->where('id', $fixture['recipient']->id)->update([
                'send_lease_token' => $stolenToken,
                'send_lease_expires_at' => now()->addMinutes(5),
                'updated_at' => now(),
            ]);

            return $usable;
        });

    app(SendCampaignMessage::class)->handle($attempt->id);

    expect($attempt->fresh()->status->value)->toBe('deferred')
        ->and($attempt->fresh()->deferred_reason)->toBe('stale_send_lease')
        ->and($fixture['recipient']->fresh()->send_lease_token)->toBe($stolenToken)
        ->and(FakeMailProvider::$store['sends'] ?? [])->toBe([])
        ->and(ExecutionActionEvent::query()->where('event', 'attempted')->count())->toBe(0);
});

it('never automatically retries an uncertain or manually reviewed governed provider attempt', function (): void {
    FakeMailProvider::reset();
    $this->travelTo(now()->setTime(10, 0));
    Config::set('outboss.campaign_sending_enabled', true);
    $fixture = compiledS3GuardFixture();
    app(LaunchObjectiveExecution::class)->handle($fixture['plan'], $fixture['owner']);
    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(1);
    $attempt = SendAttempt::query()->sole();
    FakeMailProvider::$timeoutAfterSend = true;

    app(SendCampaignMessage::class)->handle($attempt->id);
    app(SendCampaignMessage::class)->handle($attempt->id);

    expect($attempt->fresh()->status->value)->toBe('unknown')
        ->and(FakeMailProvider::$store['sends'] ?? [])->toHaveCount(1)
        ->and(ExecutionActionEvent::query()->where('event', 'attempted')->count())->toBe(1)
        ->and(ExecutionActionEvent::query()->where('event', 'uncertain')->count())->toBe(1);

    expect(app(ReconcileUnknownSend::class)->handle($attempt->id))->toBe(1)
        ->and($attempt->fresh()->status->value)->toBe('manual_review');
    app(SendCampaignMessage::class)->handle($attempt->id);
    expect(FakeMailProvider::$store['sends'] ?? [])->toHaveCount(1);

    FakeMailProvider::$timeoutAfterSend = false;
    app(ResolveManualReviewSend::class)->handle(
        $attempt->fresh(),
        SendResolution::ProvenNotSent,
        $fixture['owner']->email,
        'Authenticated owner verified the exact provider attempt was not sent.',
    );
    app(SendCampaignMessage::class)->handle($attempt->id);

    expect($attempt->fresh()->status->value)->toBe('deferred')
        ->and(FakeMailProvider::$store['sends'] ?? [])->toHaveCount(1)
        ->and(ExecutionActionEvent::query()->where('event', 'failed')->count())->toBe(1)
        ->and(PolicyEvaluation::query()->where('stage', 'lease')->where('result', 'blocked')->exists())->toBeTrue();
});
