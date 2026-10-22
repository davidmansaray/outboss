<?php

use App\Domain\Objectives\Enums\GtmExperimentStatus;
use App\Domain\Objectives\Enums\ObjectiveRunDispatchState;
use App\Domain\Objectives\Enums\ObjectiveRunPhaseName;
use App\Domain\Objectives\Enums\ObjectiveRunPhaseState;
use App\Domain\Objectives\Enums\ObjectiveRunState;
use App\Domain\Objectives\Services\CancelObjectiveRun;
use App\Domain\Objectives\Services\CreateObjective;
use App\Domain\Objectives\Services\CreateSegment;
use App\Domain\Objectives\Services\SweepObjectiveRunDispatches;
use App\Domain\Objectives\Services\VersionObjective;
use App\Jobs\Objectives\PublishObjectiveRunDispatches;
use App\Models\AudienceSnapshot;
use App\Models\ConversionDefinition;
use App\Models\ConversionDefinitionVersion;
use App\Models\GtmExperiment;
use App\Models\ObjectiveRun;
use App\Models\ObjectiveRunDispatch;
use App\Models\ObjectiveRunPhase;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * @return array{User,ObjectiveRun,ObjectiveRunPhase,ObjectiveRunDispatch}
 */
function objectiveDispatchPublicationFixture(): array
{
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $objective = app(CreateObjective::class)->handle($workspace, $owner);
    $segment = app(CreateSegment::class)->handle(
        $workspace,
        $owner,
        'Dispatch publication fixture',
        ['field' => 'account.domain_present', 'operator' => 'eq', 'value' => true],
    );
    $snapshot = AudienceSnapshot::query()->create([
        'workspace_id' => $workspace->id,
        'segment_version_id' => $segment->current_version_id,
        'objective_id' => $objective->id,
        'purpose' => 'objective_execution',
        'status' => 'frozen',
        'evaluator_version' => 'segment_evaluator_v1',
        'criteria_hash' => str_repeat('1', 64),
        'evaluation_hash' => hash('sha256', 'dispatch-publication-'.$objective->id),
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
    $offer = Offer::factory()->create(['workspace_id' => $workspace->id])->fresh();
    $objectiveVersion = app(VersionObjective::class)->handle($objective, $owner, [
        'name' => 'Verify durable dispatch publication',
        'conversion_definition_id' => $definition->id,
        'conversion_definition_version_id' => $definitionVersion->id,
        'segment_version_id' => $segment->current_version_id,
        'audience_snapshot_id' => $snapshot->id,
        'offer_id' => $offer->id,
        'offer_version_id' => $offer->current_version_id,
        'target_kind' => 'count',
        'target_count' => 1,
        'starts_at' => now()->addDay()->toAtomString(),
        'ends_at' => now()->addDays(2)->toAtomString(),
        'attribution_window_days' => 30,
        'maximum_people' => 1,
        'maximum_accounts' => 1,
        'maximum_messages' => 1,
        'maximum_ai_cost_micros' => 1000,
        'required_qualification_outcomes' => ['contact'],
        'minimum_qualification_confidence' => 0.8,
        'maximum_evidence_age_days' => 30,
        'exclusions' => [],
        'authority_envelope' => ['approval_required' => true],
        'forecast_assumptions' => [],
    ], 1);
    $experiment = GtmExperiment::query()->create([
        'workspace_id' => $workspace->id,
        'objective_id' => $objective->id,
        'objective_version_id' => $objectiveVersion->id,
        'audience_snapshot_id' => $snapshot->id,
        'conversion_definition_id' => $definition->id,
        'conversion_definition_version_id' => $definitionVersion->id,
        'name' => 'Dispatch publication fixture',
        'status' => GtmExperimentStatus::Draft,
        'assignment_unit' => 'account',
        'assignment_algorithm_version' => 'hmac_sha256_basis_points_v1',
        'allocation_version' => 1,
        'guardrails' => [],
        'starts_at' => $objectiveVersion->starts_at,
        'ends_at' => $objectiveVersion->ends_at,
        'lock_version' => 1,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
    ]);
    $inputHash = hash('sha256', 'dispatch-input-'.$objective->id);
    $run = ObjectiveRun::query()->create([
        'workspace_id' => $workspace->id,
        'objective_id' => $objective->id,
        'objective_version_id' => $objectiveVersion->id,
        'audience_snapshot_id' => $snapshot->id,
        'gtm_experiment_id' => $experiment->id,
        'run_number' => 1,
        'state' => ObjectiveRunState::Planned,
        'current_phase' => ObjectiveRunPhaseName::ValidateInputs,
        'input_hash' => $inputHash,
        'idempotency_key_hash' => hash('sha256', 'dispatch-idempotency-'.$objective->id),
        'schema_version' => 'objective_run_v1',
        'message_validator_version' => 'message_validator_v1',
        'maximum_cost_micros' => 1000,
        'fencing_token' => 0,
        'created_by_user_id' => $owner->id,
    ]);
    $phase = ObjectiveRunPhase::query()->create([
        'workspace_id' => $workspace->id,
        'objective_run_id' => $run->id,
        'phase' => ObjectiveRunPhaseName::ValidateInputs,
        'attempt_number' => 1,
        'state' => ObjectiveRunPhaseState::Queued,
        'input_hash' => $inputHash,
        'fencing_token' => 0,
        'queued_at' => now(),
    ]);
    $dispatch = ObjectiveRunDispatch::query()->create([
        'workspace_id' => $workspace->id,
        'objective_run_id' => $run->id,
        'phase' => ObjectiveRunPhaseName::ValidateInputs,
        'input_hash' => $inputHash,
        'state' => ObjectiveRunDispatchState::Pending,
        'attempts' => 0,
        'available_at' => now(),
    ]);

    return [$owner, $run, $phase, $dispatch];
}

it('does not resurrect a dispatch cancelled while its publisher was failing', function (): void {
    [$owner, $run, , $dispatch] = objectiveDispatchPublicationFixture();
    app(CancelObjectiveRun::class)->handle($run, $owner, 'Stop the queued run.');

    (new PublishObjectiveRunDispatches((string) $dispatch->id))->failed(new RuntimeException('Queue unavailable'));

    expect($run->fresh()->state)->toBe(ObjectiveRunState::Cancelled)
        ->and($dispatch->fresh()->state)->toBe(ObjectiveRunDispatchState::Cancelled);

    Queue::fake();
    expect(app(SweepObjectiveRunDispatches::class)->handle())->toBe(0);
    Queue::assertNothingPushed();
});

it('does not resurrect a dispatch cancelled by supersession while its publisher was failing', function (): void {
    [, $run, $phase, $dispatch] = objectiveDispatchPublicationFixture();
    DB::transaction(function () use ($run, $phase, $dispatch): void {
        $run->forceFill([
            'state' => ObjectiveRunState::Superseded,
            'superseded_at' => now(),
            'finished_at' => now(),
        ])->save();
        $phase->forceFill([
            'state' => ObjectiveRunPhaseState::Cancelled,
            'finished_at' => now(),
        ])->save();
        $dispatch->forceFill(['state' => ObjectiveRunDispatchState::Cancelled])->save();
    });

    (new PublishObjectiveRunDispatches((string) $dispatch->id))->failed(new RuntimeException('Queue unavailable'));

    expect($run->fresh()->state)->toBe(ObjectiveRunState::Superseded)
        ->and($dispatch->fresh()->state)->toBe(ObjectiveRunDispatchState::Cancelled);

    Queue::fake();
    expect(app(SweepObjectiveRunDispatches::class)->handle())->toBe(0);
    Queue::assertNothingPushed();
});

it('does not reschedule a dispatch without its exact active queued phase lineage', function (string $mismatch): void {
    [, $run, $phase, $dispatch] = objectiveDispatchPublicationFixture();
    $dispatch->forceFill([
        'state' => ObjectiveRunDispatchState::Published,
        'published_at' => now(),
    ])->save();

    match ($mismatch) {
        'inactive_run' => $run->forceFill(['state' => ObjectiveRunState::Paused])->save(),
        'current_phase' => $run->forceFill(['current_phase' => ObjectiveRunPhaseName::RecheckExclusions])->save(),
        'phase_input' => $phase->forceFill(['input_hash' => hash('sha256', 'different-input')])->save(),
        'phase_state' => $phase->forceFill(['state' => ObjectiveRunPhaseState::Running])->save(),
        default => throw new InvalidArgumentException("Unknown dispatch mismatch fixture [{$mismatch}]."),
    };

    (new PublishObjectiveRunDispatches((string) $dispatch->id))->failed(new RuntimeException('Queue unavailable'));

    expect($dispatch->fresh()->state)->toBe(ObjectiveRunDispatchState::Published)
        ->and($dispatch->fresh()->safe_error_code)->toBeNull();
})->with(['inactive_run', 'current_phase', 'phase_input', 'phase_state']);

it('delays an ordinary transient publication retry for the exact active dispatch', function (string $startingState): void {
    $now = Carbon::parse('2026-09-04 12:00:00');
    Carbon::setTestNow($now);
    [, , , $dispatch] = objectiveDispatchPublicationFixture();
    $state = ObjectiveRunDispatchState::from($startingState);
    $dispatch->forceFill([
        'state' => $state,
        'attempts' => 2,
        'published_at' => $state === ObjectiveRunDispatchState::Published ? now() : null,
    ])->save();
    $job = new PublishObjectiveRunDispatches((string) $dispatch->id);

    $job->failed(new RuntimeException('Queue unavailable'));

    $retried = $dispatch->fresh();
    expect($job->tries)->toBe(3)
        ->and($retried->state)->toBe(ObjectiveRunDispatchState::Pending)
        ->and($retried->attempts)->toBe(2)
        ->and($retried->available_at->equalTo(now()->addSeconds(30)))->toBeTrue()
        ->and($retried->published_at)->toBeNull()
        ->and($retried->safe_error_code)->toBe('dispatch_publish_failed');

    Queue::fake();
    expect(app(SweepObjectiveRunDispatches::class)->handle())->toBe(0);
    Carbon::setTestNow($now->copy()->addSeconds(30));
    expect(app(SweepObjectiveRunDispatches::class)->handle())->toBe(1);
    Queue::assertPushed(
        PublishObjectiveRunDispatches::class,
        fn (PublishObjectiveRunDispatches $queued): bool => $queued->dispatchId === $dispatch->id,
    );
    Carbon::setTestNow();
})->with([
    ObjectiveRunDispatchState::Pending->value,
    ObjectiveRunDispatchState::Published->value,
]);
