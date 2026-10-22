<?php

use App\Domain\Objectives\Services\CompileExecutionPlan;
use App\Domain\Objectives\Services\StartObjectiveRun;
use App\Models\ExecutionActionEvent;
use App\Models\ExecutionPlanActionCompilation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/ObjectiveScenarioFixtures.php';

it('records exactly one compilation event against each matching recipient across replay', function () {
    $this->travelTo(Carbon::parse('2026-09-07 10:00:00 UTC'));
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture(2);
    $run = app(StartObjectiveRun::class)->handle($fixture['objective'], $fixture['experiment'], $fixture['owner'], 'compilation-events');
    $plan = approveS3PlanForCompilerFixture(runS3IncrementThreeToApproval($run));
    app(CompileExecutionPlan::class)->handle($plan, $fixture['owner']);
    app(CompileExecutionPlan::class)->handle($plan->fresh(), $fixture['owner']);

    expect(ExecutionActionEvent::query()->where('event', 'compiled')->count())->toBe(2);
    foreach (ExecutionPlanActionCompilation::query()->get() as $mapping) {
        $event = ExecutionActionEvent::query()->where('event', 'compiled')
            ->where('execution_plan_action_id', $mapping->execution_plan_action_id)->sole();
        expect($event->campaign_recipient_id)->toBe($mapping->campaign_recipient_id);
    }
});
