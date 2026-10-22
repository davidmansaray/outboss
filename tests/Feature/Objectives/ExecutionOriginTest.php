<?php

use App\Domain\Objectives\Enums\ExecutionOrigin;
use App\Domain\Objectives\Enums\ObjectiveRunArmDraftState;
use App\Models\ExecutionPlan;
use App\Models\ObjectiveRun;
use App\Models\ObjectiveRunArmDraft;
use Illuminate\Support\Facades\Schema;

it('defaults legacy execution models to AI without inventing human provenance', function () {
    foreach ([new ObjectiveRun, new ObjectiveRunArmDraft, new ExecutionPlan] as $record) {
        expect($record->getAttribute('origin'))->toBe(ExecutionOrigin::Ai);
    }
    expect(Schema::hasColumns('objective_runs', ['origin', 'campaign_composition_id']))->toBeTrue();
    expect(Schema::hasColumns('objective_run_arm_drafts', ['origin', 'authored_by_user_id', 'authored_at']))->toBeTrue();
});

it('requires experiment lineage for AI and rejects it for human execution', function (string $model, string $field) {
    $record = new $model;
    expect(fn () => $record->save())->toThrow(LogicException::class, 'requires its experiment lineage');
    $record->forceFill(['origin' => ExecutionOrigin::Human, $field => 'an-experiment']);
    expect(fn () => $record->save())->toThrow(LogicException::class, 'not an experiment');
})->with([
    [ObjectiveRun::class, 'gtm_experiment_id'],
    [ObjectiveRunArmDraft::class, 'gtm_experiment_arm_id'],
    [ExecutionPlan::class, 'gtm_experiment_id'],
]);

it('rejects AI metadata on human records before database insertion', function (string $model, string $field, mixed $value) {
    $record = new $model;
    $record->forceFill(['origin' => ExecutionOrigin::Human, $field => $value]);
    expect(fn () => $record->save())->toThrow(LogicException::class);
})->with([
    [ObjectiveRun::class, 'maximum_cost_micros', 1],
    [ObjectiveRun::class, 'ai_budget_envelope_id', 'fake-budget'],
    [ObjectiveRunArmDraft::class, 'model_id', 'fake-model'],
    [ObjectiveRunArmDraft::class, 'ai_provider_operation_id', 'fake-provider'],
    [ExecutionPlan::class, 'prompt_version', 'fake-prompt'],
    [ExecutionPlan::class, 'actual_cost_micros', 1],
]);

it('requires composition and authorship instead of fake generation success', function () {
    $run = new ObjectiveRun;
    $run->forceFill(['origin' => ExecutionOrigin::Human]);
    expect(fn () => $run->save())->toThrow(LogicException::class, 'pinned campaign composition');
    $draft = new ObjectiveRunArmDraft;
    $draft->forceFill(['origin' => ExecutionOrigin::Human, 'state' => ObjectiveRunArmDraftState::Succeeded]);
    expect(fn () => $draft->save())->toThrow(LogicException::class, 'author and timestamp');
});

it('never allows an existing record to change its authorship origin', function () {
    $run = new ObjectiveRun;
    $run->syncOriginal();
    $run->exists = true;
    $run->forceFill(['origin' => ExecutionOrigin::Human]);
    expect(fn () => $run->save())->toThrow(LogicException::class, 'cannot change');
});
