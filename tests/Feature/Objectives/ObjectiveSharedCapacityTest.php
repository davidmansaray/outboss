<?php

use App\Domain\Objectives\Exceptions\ApprovalConflict;
use App\Domain\Objectives\Services\AssertObjectiveSharedCapacity;
use App\Domain\Objectives\Services\StartObjectiveRun;
use App\Models\ObjectiveGoalVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/ObjectiveScenarioFixtures.php';

it('rejects over-cap approval atomically before creating any grants', function () {
    $this->travelTo(Carbon::parse('2026-09-07 10:00:00 UTC'));
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture(2);
    $run = app(StartObjectiveRun::class)->handle($fixture['objective'], $fixture['experiment'], $fixture['owner'], 'over-cap-approval');
    $run = runS3IncrementThreeToApproval($run);
    $goal = ObjectiveGoalVersion::query()->create([
        'workspace_id' => $run->workspace_id, 'objective_id' => $run->objective_id,
        'version' => 1, 'name' => 'One message only', 'measurement' => 'meeting_booked',
        'target_count' => 10, 'content_hash' => str_repeat('a', 64),
        'created_by_user_id' => $fixture['owner']->id,
        'execution_authority' => ['maximum_people' => 2, 'maximum_accounts' => 2, 'maximum_messages' => 1],
    ]);
    $fixture['objective']->forceFill(['current_goal_version_id' => $goal->id])->save();
    try {
        approveS3PlanForCompilerFixture($run);
        $this->fail('Over-cap approval must fail before creating grants.');
    } catch (ApprovalConflict $error) {
        expect($error->reasonCode)->toBe('objective_shared_capacity_exceeded');
        expect(array_column($error->blockers, 'code'))->toContain('objective_shared_capacity_exceeded');
    }
    expect(DB::table('approval_grants')->count())->toBe(0);
    expect(DB::table('approval_batches')->count())->toBe(0);
});

it('counts approved commitments across plans without double counting replay or refunding expiry', function () {
    $this->travelTo(Carbon::parse('2026-09-07 10:00:00 UTC'));
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture(2);
    $run = app(StartObjectiveRun::class)->handle($fixture['objective'], $fixture['experiment'], $fixture['owner'], 'shared-capacity');
    $plan = approveS3PlanForCompilerFixture(runS3IncrementThreeToApproval($run));
    // Attach a goal to the execution fixture to exercise the shared accounting
    // boundary independently of the forthcoming human plan/context builder.
    $goal = ObjectiveGoalVersion::query()->create([
        'workspace_id' => $plan->workspace_id, 'objective_id' => $run->objective_id,
        'version' => 1, 'name' => 'Shared meeting goal', 'measurement' => 'meeting_booked',
        'target_count' => 10, 'content_hash' => str_repeat('a', 64),
        'created_by_user_id' => $fixture['owner']->id,
        'execution_authority' => ['maximum_people' => 2, 'maximum_accounts' => 2, 'maximum_messages' => 2],
    ]);
    $fixture['objective']->forceFill(['current_goal_version_id' => $goal->id])->save();
    $service = app(AssertObjectiveSharedCapacity::class);
    DB::transaction(fn () => $service->handle($plan, $plan->actions));

    $candidate = $plan->replicate();
    $candidate->forceFill(['id' => (string) Str::uuid(), 'version' => 2, 'plan_hash' => str_repeat('b', 64)])->save();
    $action = $plan->actions->first()->replicate();
    $action->forceFill(['id' => (string) Str::uuid(), 'execution_plan_id' => $candidate->id, 'action_fingerprint' => str_repeat('c', 64)])->save();
    // Same people/accounts still consume another message, not another person.
    expect(fn () => DB::transaction(fn () => $service->handle($candidate, new Collection([$action]))))
        ->toThrow(ApprovalConflict::class, 'already committed');
    DB::table('approval_batches')->where('execution_plan_id', $plan->id)->update(['state' => 'expired']);
    expect(fn () => DB::transaction(fn () => $service->handle($candidate, new Collection([$action]))))
        ->toThrow(ApprovalConflict::class, 'already committed');
    // A later authority version must retain past usage; unique people/accounts
    // are not charged again merely because another message targets the same person.
    foreach ([
        [2, 2, 3, false],
        [1, 2, 3, true],
        [2, 1, 3, true],
    ] as $index => [$people, $accounts, $messages, $blocked]) {
        $revision = $goal->replicate();
        $revision->forceFill([
            'version' => $index + 2, 'content_hash' => hash('sha256', 'authority-'.$index),
            'execution_authority' => ['maximum_people' => $people, 'maximum_accounts' => $accounts, 'maximum_messages' => $messages],
        ])->save();
        $fixture['objective']->forceFill(['current_goal_version_id' => $revision->id])->save();
        $check = fn () => DB::transaction(fn () => $service->handle($candidate, new Collection([$action])));
        if ($blocked) {
            expect($check)->toThrow(ApprovalConflict::class, 'already committed');
        } else {
            $check();
        }
    }
});
