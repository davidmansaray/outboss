<?php

use App\Domain\Objectives\Services\CreateGtmExperiment;
use App\Domain\Objectives\Services\FreezeExperimentAssignments;
use App\Domain\Objectives\Services\ObjectiveRunPreflight;
use App\Domain\Objectives\Services\StartObjectiveRun;
use App\Models\AiBudgetEnvelope;
use App\Models\AiProviderOperation;
use App\Models\ObjectiveRun;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/ObjectiveScenarioFixtures.php';

it('blocks an empty experiment arm before opening a run or spending any provider budget', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $treatment = s3IncrementTwoStrategy($fixture, 'Treatment', [
        'positioning' => 'Operational clarity for evidence-led revenue teams.',
    ]);
    $experiment = app(CreateGtmExperiment::class)->handle($fixture['owner']->currentWorkspace(), $fixture['owner'], [
        'objective_version_id' => $fixture['version']->id,
        'name' => 'One Account cannot fill two arms',
        'arms' => [
            [
                'strategy_version_id' => $fixture['experiment']->arms()->sole()->strategy_version_id,
                'key' => 'control', 'allocation_basis_points' => 5000, 'declared_differences' => [],
            ],
            [
                'strategy_version_id' => $treatment->current_version_id,
                'key' => 'treatment', 'allocation_basis_points' => 5000,
                'declared_differences' => ['positioning' => 'Use treatment positioning.'],
            ],
        ],
    ]);
    $experiment = app(FreezeExperimentAssignments::class)->handle($experiment, $fixture['owner'], 1);
    $beforeEnvelopes = AiBudgetEnvelope::query()->count();
    $beforeOperations = AiProviderOperation::query()->count();
    $assessment = app(ObjectiveRunPreflight::class)->handle($fixture['objective'], $experiment);

    expect($assessment->ready())->toBeFalse()
        ->and(array_column($assessment->blockers, 'code'))->toContain('experiment_arm_empty');
    expect(fn () => app(StartObjectiveRun::class)->handle($fixture['objective'], $experiment, $fixture['owner'], 'empty-arm'))
        ->toThrow(ValidationException::class);
    expect(ObjectiveRun::query()->count())->toBe(0)
        ->and(AiBudgetEnvelope::query()->count())->toBe($beforeEnvelopes)
        ->and(AiProviderOperation::query()->count())->toBe($beforeOperations)
        ->and($experiment->fresh()->assignments()->count())->toBe(1);
});
