<?php

use App\Domain\Ai\Enums\AiOperationStatus;
use App\Domain\Objectives\Enums\ObjectiveRunState;
use App\Domain\Objectives\Exceptions\ObjectiveRunConflict;
use App\Domain\Objectives\Services\ExecuteObjectiveRunPhase;
use App\Domain\Objectives\Services\RecoverObjectiveRuns;
use App\Domain\Objectives\Services\StartObjectiveRun;
use App\Models\ObjectiveRunArmDraft;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/ObjectiveScenarioFixtures.php';

it('keeps a paid result persistence failure under review and blocks a replacement charge', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $run = app(StartObjectiveRun::class)->handle($fixture['objective'], $fixture['experiment'], $fixture['owner'], 'paid-failure');
    $injected = false;
    Event::listen('eloquent.updating: '.ObjectiveRunArmDraft::class, function (ObjectiveRunArmDraft $draft) use (&$injected): void {
        if (! $injected && $draft->isDirty('output_hash') && $draft->output_hash !== null) {
            $injected = true;
            throw new RuntimeException('Simulated DB failure after provider settlement.');
        }
    });
    foreach (range(1, 4) as $_) {
        $run = $run->fresh();
        $dispatch = $run->dispatches()->where('state', 'pending')->firstOrFail();
        $phase = $run->phases()->where('phase', $dispatch->phase->value)->where('state', 'queued')->firstOrFail();
        app(ExecuteObjectiveRunPhase::class)->handle($run->workspace_id, $run->id, $phase->id, $dispatch->id);
    }
    $run = $run->fresh();
    $draft = $run->armDrafts()->sole();
    expect($injected)->toBeTrue()
        ->and($draft->providerOperation->status)->toBe(AiOperationStatus::Succeeded)
        ->and($draft->output_hash)->toBeNull()
        ->and($run->state)->toBe(ObjectiveRunState::NeedsReview)
        ->and($run->safe_error_code)->toBe('paid_result_lost')
        ->and($run->executionPlans()->count())->toBe(0);
    expect(fn () => app(StartObjectiveRun::class)->handle($fixture['objective'], $fixture['experiment'], $fixture['owner'], 'replacement-charge'))
        ->toThrow(ObjectiveRunConflict::class);
    expect(app(RecoverObjectiveRuns::class)->handle($run->workspace_id))->toBe(0);
    app(ExecuteObjectiveRunPhase::class)->handle($run->workspace_id, $run->id, $phase->id, $dispatch->id);
    expect($run->fresh()->state)->toBe(ObjectiveRunState::NeedsReview)
        ->and($draft->providerOperation()->count())->toBe(1);
});
