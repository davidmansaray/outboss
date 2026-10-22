<?php

use App\Domain\Ai\Contracts\AiProviderAdapter;
use App\Domain\Ai\DTOs\ProviderTestResult;
use App\Domain\Ai\Enums\AiCredentialStatus;
use App\Domain\Ai\Enums\AiOperationStatus;
use App\Domain\Ai\Enums\AiProviderName;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Providers\AiProviderRegistry;
use App\Domain\Ai\Services\AiBudgetGateway;
use App\Domain\Ai\Services\AiWorkspaceSettings;
use App\Domain\Ai\Services\ReconcileAiProviderOperations;
use App\Domain\Objectives\Services\CancelObjectiveRun;
use App\Domain\Objectives\Services\CreateObjective;
use App\Domain\Objectives\Services\CreateSegment;
use App\Domain\Objectives\Services\RecoverObjectiveRuns;
use App\Domain\Objectives\Services\ValidateDraftedMessage;
use App\Domain\Objectives\Services\VersionObjective;
use App\Models\AiBudgetPeriod;
use App\Models\AiProviderCallAttempt;
use App\Models\AiProviderCredential;
use App\Models\AiProviderOperation;
use App\Models\AiTaskSetting;
use App\Models\AiUsageLedger;
use App\Models\AudienceSnapshot;
use App\Models\ConversionDefinition;
use App\Models\ConversionDefinitionVersion;
use App\Models\GtmExperiment;
use App\Models\GtmExperimentArm;
use App\Models\ObjectiveRun;
use App\Models\ObjectiveRunArmDraft;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\Strategy;
use App\Models\StrategyVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('projects a late reconciled provider charge onto a cancelled run exactly once', function (): void {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $objective = app(CreateObjective::class)->handle($workspace, $owner);
    $segment = app(CreateSegment::class)->handle(
        $workspace,
        $owner,
        'Terminal recovery fixture',
        ['field' => 'account.domain_present', 'operator' => 'eq', 'value' => true],
    );
    $snapshot = AudienceSnapshot::query()->create([
        'workspace_id' => $workspace->id,
        'segment_version_id' => $segment->current_version_id,
        'objective_id' => $objective->id,
        'purpose' => 'objective_execution',
        'status' => 'frozen',
        'evaluator_version' => 'segment_evaluator_v1',
        'criteria_hash' => hash('sha256', 'terminal-recovery-criteria'),
        'evaluation_hash' => hash('sha256', 'terminal-recovery-audience'),
        'included_people_count' => 0,
        'excluded_people_count' => 0,
        'uncertain_people_count' => 0,
        'suppressed_people_count' => 0,
        'included_accounts_count' => 0,
        'excluded_accounts_count' => 0,
        'uncertain_accounts_count' => 0,
        'suppressed_accounts_count' => 0,
        'source_rights_snapshot' => [],
        'frozen_by_user_id' => $owner->id,
        'frozen_at' => now(),
    ]);
    $definition = ConversionDefinition::factory()->create(['workspace_id' => $workspace->id]);
    $definitionVersion = ConversionDefinitionVersion::factory()->create([
        'workspace_id' => $workspace->id,
        'conversion_definition_id' => $definition->id,
    ]);
    $definition->forceFill(['current_version_id' => $definitionVersion->id])->save();
    $offer = Offer::factory()->withoutSeededVersion()->create(['workspace_id' => $workspace->id]);
    $offerVersion = OfferVersion::factory()->create([
        'workspace_id' => $workspace->id,
        'offer_id' => $offer->id,
        'schema_version' => 2,
        'payload' => ['schema_version' => 2, 'cta' => ['action' => 'review']],
    ]);
    $offer->forceFill(['current_version_id' => $offerVersion->id])->save();
    $objectiveVersion = app(VersionObjective::class)->handle($objective, $owner, [
        'name' => 'Verify terminal AI cost recovery',
        'conversion_definition_id' => $definition->id,
        'conversion_definition_version_id' => $definitionVersion->id,
        'segment_version_id' => $segment->current_version_id,
        'audience_snapshot_id' => $snapshot->id,
        'offer_id' => $offer->id,
        'offer_version_id' => $offerVersion->id,
        'target_kind' => 'count',
        'target_count' => 1,
        'starts_at' => now()->addDay()->toAtomString(),
        'ends_at' => now()->addDays(2)->toAtomString(),
        'attribution_window_days' => 30,
        'maximum_people' => 1,
        'maximum_accounts' => 1,
        'maximum_messages' => 1,
        'maximum_ai_cost_micros' => 5000,
        'required_qualification_outcomes' => ['contact'],
        'minimum_qualification_confidence' => 0.8,
        'maximum_evidence_age_days' => 30,
        'exclusions' => [],
        'authority_envelope' => ['approval_required' => true],
        'forecast_assumptions' => [],
    ], 1);

    $strategy = Strategy::query()->create([
        'workspace_id' => $workspace->id,
        'status' => 'active',
        'lock_version' => 1,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
    ]);
    $strategyVersion = StrategyVersion::query()->create([
        'workspace_id' => $workspace->id,
        'strategy_id' => $strategy->id,
        'version' => 1,
        'objective_version_id' => $objectiveVersion->id,
        'audience_snapshot_id' => $snapshot->id,
        'offer_version_id' => $offerVersion->id,
        'name' => 'Recovery control',
        'positioning' => 'Recovery fixture',
        'problem_frame' => 'A provider result outlived its worker.',
        'proof_summary' => 'The charge is reconciled from provider evidence.',
        'cta_kind' => 'review',
        'cta_text' => 'Review',
        'proof_claim_ids' => [],
        'personalisation_contract' => ['allowed_placeholders' => []],
        'message_generation_contract' => ['message_count' => 1, 'maximum_words' => 40],
        'stop_conditions' => ['on_reply' => true, 'on_conversion' => true],
        'channel' => 'email',
        'sequence_contract' => 'one_new_thread_v1',
        'schema_version' => 'strategy_v1',
        'content_hash' => hash('sha256', 'terminal-recovery-strategy'),
        'created_by_user_id' => $owner->id,
        'created_at' => now(),
    ]);
    $strategy->forceFill(['current_version_id' => $strategyVersion->id])->save();
    $experiment = GtmExperiment::query()->create([
        'workspace_id' => $workspace->id,
        'objective_id' => $objective->id,
        'objective_version_id' => $objectiveVersion->id,
        'audience_snapshot_id' => $snapshot->id,
        'conversion_definition_id' => $definition->id,
        'conversion_definition_version_id' => $definitionVersion->id,
        'name' => 'Terminal recovery experiment',
        'status' => 'assigned',
        'assignment_unit' => 'account',
        'assignment_seed' => Str::random(32),
        'assignment_seed_hash' => hash('sha256', 'terminal-recovery-seed'),
        'assignment_algorithm_version' => 'hmac_sha256_basis_points_v1',
        'allocation_version' => 1,
        'guardrails' => [],
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDays(2),
        'lock_version' => 1,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'assigned_at' => now(),
    ]);
    $arm = GtmExperimentArm::query()->create([
        'workspace_id' => $workspace->id,
        'gtm_experiment_id' => $experiment->id,
        'strategy_version_id' => $strategyVersion->id,
        'offer_version_id' => $offerVersion->id,
        'key' => 'control',
        'label' => 'Control',
        'allocation_basis_points' => 10000,
        'declared_differences' => [],
        'created_at' => now(),
    ]);

    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);
    $taskSetting = AiTaskSetting::query()
        ->where('workspace_id', $workspace->id)
        ->where('task', AiTask::MessageDrafting->value)
        ->firstOrFail();
    $credential = AiProviderCredential::query()->create([
        'workspace_id' => $workspace->id,
        'capability' => AiProviderName::OpenRouter->capability(),
        'provider' => AiProviderName::OpenRouter,
        'status' => AiCredentialStatus::Healthy,
        'last_tested_at' => now(),
        'last_healthy_at' => now(),
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
    ]);
    $credentialVersion = $credential->versions()->create([
        'version' => 1,
        'encrypted_secret' => 'terminal-recovery-secret',
        'status' => 'active',
        'created_by_user_id' => $owner->id,
        'tested_at' => now(),
        'activated_at' => now(),
    ]);
    $credential->forceFill(['active_version_id' => $credentialVersion->id])->save();

    $runId = (string) Str::uuid();
    $budget = app(AiBudgetGateway::class);
    $envelope = $budget->openEnvelope($workspace, $owner, 'objective_run', $runId, 5000);
    $budgetRun = $budget->openRun($workspace, $owner, AiTask::MessageDrafting, $envelope);
    $run = ObjectiveRun::query()->create([
        'id' => $runId,
        'workspace_id' => $workspace->id,
        'objective_id' => $objective->id,
        'objective_version_id' => $objectiveVersion->id,
        'audience_snapshot_id' => $snapshot->id,
        'gtm_experiment_id' => $experiment->id,
        'run_number' => 1,
        'state' => 'running',
        'current_phase' => 'draft_messages',
        'input_hash' => hash('sha256', 'terminal-recovery-run-input'),
        'idempotency_key_hash' => hash('sha256', 'terminal-recovery-run-key'),
        'schema_version' => 'objective_run_v1',
        'message_validator_version' => ValidateDraftedMessage::VERSION,
        'maximum_cost_micros' => 5000,
        'reserved_cost_micros' => 0,
        'actual_cost_micros' => 0,
        'ai_budget_envelope_id' => $envelope->id,
        'fencing_token' => 1,
        'created_by_user_id' => $owner->id,
        'started_at' => now()->subMinutes(2),
    ]);
    $operation = $budget->reserve(
        $workspace,
        $credential,
        $credentialVersion,
        $owner,
        AiTask::MessageDrafting,
        'generate',
        (string) $taskSetting->model_id,
        500,
        1000,
        'terminal-recovery-operation',
        hash('sha256', 'terminal-recovery-request'),
        (string) $budgetRun->id,
        (string) $envelope->id,
        (int) $taskSetting->policy_version,
    );
    ObjectiveRunArmDraft::query()->create([
        'workspace_id' => $workspace->id,
        'objective_run_id' => $run->id,
        'gtm_experiment_arm_id' => $arm->id,
        'ai_budget_run_id' => $budgetRun->id,
        'ai_provider_operation_id' => $operation->id,
        'state' => 'awaiting_provider',
        'prompt_version' => 'objective_message_prompt_v1',
        'schema_version' => 'message_drafting_response_v1',
        'validator_version' => ValidateDraftedMessage::VERSION,
        'model_id' => $taskSetting->model_id,
        'task_policy_version' => $taskSetting->policy_version,
        'input_hash' => hash('sha256', 'terminal-recovery-draft-input'),
        'started_at' => now()->subMinutes(2),
    ]);
    $operation->forceFill([
        'status' => AiOperationStatus::Executing,
        'fencing_token' => 1,
        'lease_owner' => 'dead-provider-worker',
        'lease_expires_at' => now()->subMinute(),
        'provider_request_id' => 'late-provider-request',
        'provider_generation_id' => 'late-provider-generation',
        'started_at' => now()->subMinutes(2),
    ])->save();
    AiProviderCallAttempt::query()->create([
        'operation_id' => $operation->id,
        'attempt_number' => 1,
        'kind' => 'execute',
        'status' => 'started',
        'provider_request_id' => 'late-provider-request',
        'provider_generation_id' => 'late-provider-generation',
        'fencing_token' => 1,
    ]);

    app(CancelObjectiveRun::class)->handle($run, $owner, 'The drafting worker died after reaching the provider.');

    expect(app(RecoverObjectiveRuns::class)->handle((int) $workspace->id))->toBe(0)
        ->and($run->fresh()->state->value)->toBe('cancelled')
        ->and($run->fresh()->reserved_cost_micros)->toBe(0)
        ->and($run->fresh()->actual_cost_micros)->toBe(0)
        ->and($envelope->fresh()->state)->toBe('open')
        ->and($envelope->fresh()->held_micros)->toBe(1000)
        ->and(AiUsageLedger::query()->count())->toBe(0);

    $reconciled = new class implements AiProviderAdapter
    {
        public function test(string $secret): ProviderTestResult
        {
            return $this->result();
        }

        public function reconcile(string $secret, AiProviderOperation $operation): ProviderTestResult
        {
            return $this->result();
        }

        private function result(): ProviderTestResult
        {
            return new ProviderTestResult(
                nativeUnits: 370,
                nativeUnit: 'tokens',
                costMicros: 700,
                costBasis: 'provider_exact',
                providerRequestId: 'late-provider-request',
                providerGenerationId: 'late-provider-generation',
                rawProviderCost: '0.000700',
                evidenceSource: 'reconciliation',
                providerResponseId: 'late-provider-response',
            );
        }
    };
    app()->instance(AiProviderRegistry::class, new AiProviderRegistry([
        AiProviderName::OpenRouter->value => $reconciled,
    ]));

    expect(app(ReconcileAiProviderOperations::class)->handle((string) $operation->id))->toBe(1)
        ->and($operation->fresh()->status)->toBe(AiOperationStatus::Reconciled)
        ->and($envelope->fresh()->state)->toBe('open')
        ->and($envelope->fresh()->held_micros)->toBe(0)
        ->and($envelope->fresh()->committed_micros)->toBe(700)
        ->and($run->fresh()->actual_cost_micros)->toBe(0)
        ->and(AiUsageLedger::query()->count())->toBe(1)
        ->and((int) AiUsageLedger::query()->sum('cost_micros'))->toBe(700);

    $foreignWorkspaceId = (int) User::factory()->create()->currentWorkspace()->id;
    expect(app(RecoverObjectiveRuns::class)->handle($foreignWorkspaceId))->toBe(0)
        ->and($envelope->fresh()->state)->toBe('open')
        ->and($run->fresh()->actual_cost_micros)->toBe(0);

    expect(app(RecoverObjectiveRuns::class)->handle((int) $workspace->id))->toBe(0)
        ->and($envelope->fresh()->state)->toBe('closed')
        ->and($envelope->fresh()->closed_at)->not->toBeNull()
        ->and($run->fresh()->reserved_cost_micros)->toBe(0)
        ->and($run->fresh()->actual_cost_micros)->toBe(700)
        ->and(AiUsageLedger::query()->count())->toBe(1)
        ->and((int) AiBudgetPeriod::query()->sum('committed_micros'))->toBe(700);

    // Recreate the narrow crash window where finalization committed but the
    // Objective projection did not. Closed envelopes must remain repairable.
    DB::table('objective_runs')->where('workspace_id', $workspace->id)->where('id', $run->id)->update([
        'reserved_cost_micros' => 91,
        'actual_cost_micros' => 0,
        'updated_at' => now(),
    ]);
    expect(app(RecoverObjectiveRuns::class)->handle((int) $workspace->id))->toBe(0)
        ->and($run->fresh()->reserved_cost_micros)->toBe(0)
        ->and($run->fresh()->actual_cost_micros)->toBe(700);

    expect(app(RecoverObjectiveRuns::class)->handle((int) $workspace->id))->toBe(0)
        ->and($run->fresh()->reserved_cost_micros)->toBe(0)
        ->and($run->fresh()->actual_cost_micros)->toBe(700)
        ->and($operation->fresh()->actual_cost_micros)->toBe(700)
        ->and(AiUsageLedger::query()->count())->toBe(1)
        ->and((int) AiUsageLedger::query()->sum('cost_micros'))->toBe(700)
        ->and((int) AiBudgetPeriod::query()->sum('committed_micros'))->toBe(700);
});
