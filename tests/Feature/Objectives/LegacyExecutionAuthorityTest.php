<?php

use App\Domain\Campaigns\Services\CreateCampaignService;
use App\Domain\Campaigns\Services\SaveCampaignComposition;
use App\Domain\Objectives\Enums\ExecutionOrigin;
use App\Domain\Objectives\Enums\PolicyEvaluationStage;
use App\Domain\Objectives\Exceptions\ApprovalConflict;
use App\Domain\Objectives\Services\AssertObjectiveSharedCapacity;
use App\Domain\Objectives\Services\GovernedExecutionCheckpoint;
use App\Domain\Objectives\Services\ImportLegacyExecutionAuthority;
use App\Domain\Objectives\Services\RevokeExecutionAuthorityScope;
use App\Domain\Objectives\Services\StartObjectiveRun;
use App\Domain\Objectives\Support\ApprovalManifest;
use App\Models\ExecutionAuthorityLegacyMapping;
use App\Models\ExecutionAuthorityScope;
use App\Models\ObjectiveGoalVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../../Support/ObjectiveScenarioFixtures.php';

it('imports historical authority explicitly through the owner web action without renewing it', function () {
    $fixture = legacyScopeFixture();
    $objective = $fixture['objective'];
    $owner = $fixture['owner'];
    $url = '/objectives/'.$objective->id.'/import-authority';
    $this->actingAs($owner)->post($url)->assertSessionHasNoErrors()->assertRedirect('/objectives/'.$objective->id);
    $this->post($url)->assertSessionHasNoErrors();
    $scope = ExecutionAuthorityScope::query()->sole();
    expect($scope->authority)->toBe($fixture['goal']->execution_authority);
    $this->get('/objectives/'.$objective->id)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('legacy_scope_id', $scope->id)->where('can_import_authority', true));
    $other = User::factory()->create();
    $this->actingAs($other)->post($url)->assertNotFound();
    expect(ExecutionAuthorityScope::query()->count())->toBe(1);
});

function legacyScopeFixture(): array
{
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture(2);
    $run = app(StartObjectiveRun::class)->handle($fixture['objective'], $fixture['experiment'], $fixture['owner'], 'legacy-scope');
    $plan = approveS3PlanForCompilerFixture(runS3IncrementThreeToApproval($run));
    $goal = ObjectiveGoalVersion::query()->create([
        'workspace_id' => $run->workspace_id, 'objective_id' => $run->objective_id,
        'version' => 1, 'name' => 'Legacy meetings', 'measurement' => 'meeting_booked',
        'target_count' => 10, 'content_hash' => str_repeat('a', 64), 'created_by_user_id' => $fixture['owner']->id,
        'execution_authority' => [
            'starts_at' => now()->toAtomString(), 'ends_at' => now()->addDays(20)->toAtomString(),
            'maximum_people' => 2, 'maximum_accounts' => 2, 'maximum_messages' => 2,
            'maximum_ai_cost_micros' => 0, 'approval_required' => true,
        ],
    ]);
    $fixture['objective']->forceFill(['current_goal_version_id' => $goal->id])->save();

    return [...$fixture, 'run' => $run, 'plan' => $plan, 'goal' => $goal];
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-07 10:00:00 UTC'));
});

it('maps historical commitments once and never refunds them on migration or expiry', function () {
    $fixture = legacyScopeFixture();
    $import = app(ImportLegacyExecutionAuthority::class);
    $scope = $import->handle($fixture['objective'], $fixture['owner']);
    expect($import->handle($fixture['objective'], $fixture['owner'])->id)->toBe($scope->id);
    expect(ExecutionAuthorityScope::query()->count())->toBe(1)
        ->and(ExecutionAuthorityLegacyMapping::query()->count())->toBe(1)
        ->and($fixture['goal']->fresh()->content_hash)->toBe(str_repeat('a', 64))
        ->and(DB::table('approval_grants')->count())->toBe(2);
    $check = app(AssertObjectiveSharedCapacity::class);
    DB::transaction(fn () => $check->handle($fixture['plan'], $fixture['plan']->actions));
    $candidate = $fixture['plan']->replicate();
    $candidate->forceFill(['version' => 2, 'plan_hash' => str_repeat('b', 64)])->save();
    $action = $fixture['plan']->actions->first()->replicate();
    $action->forceFill(['execution_plan_id' => $candidate->id, 'action_fingerprint' => str_repeat('c', 64)])->save();
    expect(fn () => DB::transaction(fn () => $check->handle($candidate, new Collection([$action]))))
        ->toThrow(ApprovalConflict::class, 'already committed');
    $this->travel(21)->days();
    expect($import->handle($fixture['objective'], $fixture['owner'])->id)->toBe($scope->id);
    expect(fn () => DB::transaction(fn () => $check->handle($fixture['plan'], $fixture['plan']->actions)))
        ->toThrow(ApprovalConflict::class);
    expect(DB::table('approval_grants')->count())->toBe(2);
});

it('rejects conflicting historical allowances without creating a mapping', function () {
    $fixture = legacyScopeFixture();
    $revision = $fixture['goal']->replicate();
    $revision->forceFill(['version' => 2, 'execution_authority' => [
        ...$fixture['goal']->execution_authority, 'maximum_messages' => 1,
    ]])->save();
    $fixture['objective']->forceFill(['current_goal_version_id' => $revision->id])->save();
    expect(fn () => app(ImportLegacyExecutionAuthority::class)->handle($fixture['objective'], $fixture['owner']))
        ->toThrow(ValidationException::class, 'Historical authority limits differ');
    expect(ExecutionAuthorityScope::query()->count())->toBe(0)
        ->and(ExecutionAuthorityLegacyMapping::query()->count())->toBe(0);
});

it('revokes an allowance idempotently without deleting its committed grants', function () {
    $fixture = legacyScopeFixture();
    $scope = app(ImportLegacyExecutionAuthority::class)->handle($fixture['objective'], $fixture['owner']);
    $service = app(RevokeExecutionAuthorityScope::class);
    $other = User::factory()->create();
    expect(fn () => app(ImportLegacyExecutionAuthority::class)->handle($fixture['objective'], $other))
        ->toThrow(AuthorizationException::class);
    expect(fn () => $service->handle($scope, $other, 'Foreign stop'))->toThrow(AuthorizationException::class);
    $revocation = $service->handle($scope, $fixture['owner'], 'Stop this allowance');
    expect($service->handle($scope, $fixture['owner'], 'Repeated stop')->id)->toBe($revocation->id);
    expect(fn () => DB::transaction(fn () => app(AssertObjectiveSharedCapacity::class)->handle($fixture['plan'], $fixture['plan']->actions)))
        ->toThrow(ApprovalConflict::class, 'was revoked');
    expect(DB::table('approval_grants')->count())->toBe(2);
    $decision = app(GovernedExecutionCheckpoint::class)->forPlan($fixture['plan'], PolicyEvaluationStage::Approval);
    expect($decision->allowed())->toBeFalse()
        ->and(array_column($decision->blockers, 'code'))->toContain('execution_scope_revoked');
    expect(fn () => $revocation->delete())->toThrow(LogicException::class);
});

it('includes old grants when a human campaign selects the migrated scope and displays the scope in approval', function () {
    $fixture = legacyScopeFixture();
    $scope = app(ImportLegacyExecutionAuthority::class)->handle($fixture['objective'], $fixture['owner']);
    $campaign = app(CreateCampaignService::class)->handle($fixture['owner'], 'Human scoped campaign');
    $composition = app(SaveCampaignComposition::class)->handle($campaign, $fixture['owner'], [
        'objective_goal_version_id' => $fixture['goal']->id, 'offer_version_id' => $fixture['version']->offer_version_id,
        'execution_authority_scope_id' => $scope->id, 'target_contribution_count' => 3,
        'sending_starts_at' => $scope->authority['starts_at'], 'sending_ends_at' => $scope->authority['ends_at'],
        'expected_revision' => 0, 'operation_id' => (string) Str::uuid(),
    ]);
    // Exercise capacity and display contracts independently of the unfinished
    // full human compiler. This fixture is not an executable human plan.
    $run = $fixture['run']->replicate();
    $run->forceFill([
        'origin' => ExecutionOrigin::Human, 'campaign_composition_id' => $composition->id,
        'gtm_experiment_id' => null,
        'run_number' => 2, 'idempotency_key_hash' => str_repeat('d', 64),
        'maximum_cost_micros' => 0, 'reserved_cost_micros' => 0, 'actual_cost_micros' => 0,
        'ai_budget_envelope_id' => null, 'message_validator_version' => null,
    ])->save();
    $plan = $fixture['plan']->replicate();
    $plan->forceFill([
        'origin' => ExecutionOrigin::Human, 'objective_run_id' => $run->id, 'plan_hash' => str_repeat('e', 64),
        'gtm_experiment_id' => null,
        'maximum_cost_micros' => 0, 'estimated_cost_micros' => 0, 'actual_cost_micros' => 0,
        'model_id' => null, 'ai_task_version' => null, 'prompt_version' => null,
    ])->save();
    $action = $fixture['plan']->actions->first()->replicate();
    $action->forceFill(['execution_plan_id' => $plan->id, 'action_fingerprint' => str_repeat('f', 64)])->save();
    $actions = new Collection([$action]);
    expect(fn () => DB::transaction(fn () => app(AssertObjectiveSharedCapacity::class)->handle($plan, $actions)))
        ->toThrow(ApprovalConflict::class, 'already committed');
    $display = app(ApprovalManifest::class)->build($plan, $actions);
    expect($display['execution_authority']['scope_id'])->toBe($scope->id)
        ->and($display['execution_authority']['content_hash'])->toBe($scope->content_hash)
        ->and($display['target_contribution_count'])->toBe(3)
        ->and($display['campaign_window']['ends_at'])->toBe($composition->sending_ends_at->toAtomString());
    // A proposed action outside the campaign window is rejected before admission,
    // even if it is still within some broader external allowance.
    $action->scheduled_not_after = $composition->sending_ends_at->copy()->addSecond();
    try {
        DB::transaction(fn () => app(AssertObjectiveSharedCapacity::class)->handle($plan, $actions));
        $this->fail('Out-of-window action must be rejected.');
    } catch (ApprovalConflict $error) {
        expect($error->reasonCode)->toBe('campaign_window_exceeded');
    }
});
