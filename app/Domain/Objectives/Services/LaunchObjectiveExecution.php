<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Campaigns\DTOs\GovernedCampaignLaunchContext;
use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Exceptions\GovernedExecutionBlocked;
use App\Domain\Campaigns\Services\CampaignLaunchReadiness;
use App\Domain\Campaigns\Services\LaunchCampaignService;
use App\Domain\Objectives\DTOs\ApprovalCheckpointDecision;
use App\Domain\Objectives\Enums\ExecutionOrigin;
use App\Domain\Objectives\Enums\ExecutionPlanActionState;
use App\Domain\Objectives\Enums\ExecutionPlanStatus;
use App\Domain\Objectives\Enums\GtmExperimentStatus;
use App\Domain\Objectives\Enums\ObjectiveRunPhaseName;
use App\Domain\Objectives\Enums\ObjectiveRunPhaseState;
use App\Domain\Objectives\Enums\ObjectiveRunState;
use App\Domain\Objectives\Enums\ObjectiveStatus;
use App\Domain\Objectives\Enums\PolicyEvaluationStage;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Models\Campaign;
use App\Models\ExecutionPlan;
use App\Models\ExecutionPlanCampaign;
use App\Models\GtmExperiment;
use App\Models\Objective;
use App\Models\ObjectiveRun;
use App\Models\ObjectiveRunPhase;
use App\Models\ObjectiveStateEvent;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Owner-only all-arm launch/resume boundary for one exact compiled plan. */
final class LaunchObjectiveExecution
{
    public function __construct(
        private readonly GovernedExecutionCheckpoint $checkpoint,
        private readonly CampaignLaunchReadiness $readiness,
        private readonly LaunchCampaignService $campaigns,
    ) {}

    public function handle(ExecutionPlan $plan, User $actor): ExecutionPlan
    {
        $workspaceId = $this->ownerWorkspaceId($plan, $actor);
        $blocked = null;

        $launched = DB::transaction(function () use ($plan, $actor, $workspaceId, &$blocked): ?ExecutionPlan {
            $plan = ExecutionPlan::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($plan->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($plan->status, [ExecutionPlanStatus::Compiled, ExecutionPlanStatus::Executing], true)) {
                $blocked = [[
                    'code' => 'plan_not_compiled',
                    'message' => 'The exact plan must be compiled before it can launch.',
                ]];

                return null;
            }

            $decision = $this->checkpoint->forPlan($plan, PolicyEvaluationStage::Launch);
            if (! $decision->allowed()) {
                $blocked = $decision;

                return null;
            }

            /** @var Collection<int, ExecutionPlanCampaign> $mappings */
            $mappings = ExecutionPlanCampaign::query()
                ->where('workspace_id', $workspaceId)
                ->where('execution_plan_id', $plan->id)
                ->orderBy('campaign_id')
                ->lockForUpdate()
                ->get();
            $campaignIds = array_values($mappings->pluck('campaign_id')->map(intval(...))->unique()->sort()->all());
            $expectedCampaigns = $plan->getAttribute('origin') === ExecutionOrigin::Human ? 1 : (int) $plan->arm_count;
            if ($mappings->count() !== $expectedCampaigns
                || count($campaignIds) !== $expectedCampaigns) {
                $blocked = [[
                    'code' => 'governed_action_missing',
                    'message' => 'The compiled plan no longer has one Campaign for every experiment arm.',
                ]];

                return null;
            }

            /** @var Collection<int, Campaign> $campaigns */
            $campaigns = Campaign::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('id', $campaignIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($campaigns->count() !== count($campaignIds)) {
                $blocked = [[
                    'code' => 'governed_action_missing',
                    'message' => 'A compiled experiment-arm Campaign is unavailable.',
                ]];

                return null;
            }

            foreach ($campaigns as $campaign) {
                $readiness = $this->readiness->blockers($campaign);
                if ($readiness !== []) {
                    $blocked = array_map(static fn (array $item): array => [
                        'code' => (string) $item['id'],
                        'message' => (string) $item['message'],
                    ], $readiness);

                    return null;
                }
            }

            // A second launch request is a revalidated no-op, not another
            // publication or a rewrite of immutable sequence timestamps.
            if ($plan->status === ExecutionPlanStatus::Executing
                && $campaigns->every(fn (Campaign $campaign): bool => $campaign->getAttribute('status') === CampaignStatus::Active)) {
                return $plan->load([
                    'compiledCampaigns' => fn ($query) => $query->lockForUpdate(),
                    'compiledCampaigns.campaign' => fn ($query) => $query->lockForUpdate(),
                    'actions' => fn ($query) => $query->lockForUpdate(),
                ]);
            }

            GovernedCampaignLaunchContext::run(
                $workspaceId,
                (string) $plan->id,
                (int) $actor->id,
                $campaignIds,
                function (GovernedCampaignLaunchContext $context) use ($campaigns, $plan): void {
                    foreach ($campaigns as $campaign) {
                        $this->campaigns->handleGoverned($campaign, (string) $plan->id, $context);
                    }
                },
            );

            $this->projectRunningState($plan, $actor);

            return $plan->fresh(['compiledCampaigns.campaign', 'actions']) ?? $plan;
        }, 3);

        if ($blocked instanceof ApprovalCheckpointDecision) {
            throw new GovernedExecutionBlocked($blocked->primaryCode(), $blocked->blockers);
        }
        if (is_array($blocked)) {
            throw new GovernedExecutionBlocked(
                (string) $blocked[0]['code'],
                $blocked,
            );
        }
        if (! $launched instanceof ExecutionPlan) {
            throw new GovernedExecutionBlocked('governed_execution_blocked');
        }

        return $launched;
    }

    private function projectRunningState(ExecutionPlan $plan, User $actor): void
    {
        $now = now();
        $run = ObjectiveRun::query()->where('workspace_id', $plan->workspace_id)
            ->whereKey($plan->objective_run_id)->lockForUpdate()->firstOrFail();
        $objective = Objective::query()->where('workspace_id', $plan->workspace_id)
            ->whereKey($run->objective_id)->lockForUpdate()->firstOrFail();
        $experiment = $plan->getAttribute('origin') === ExecutionOrigin::Ai
            ? GtmExperiment::query()->where('workspace_id', $plan->workspace_id)
                ->whereKey($plan->gtm_experiment_id)->lockForUpdate()->firstOrFail()
            : null;

        DB::table('execution_plans')->where('workspace_id', $plan->workspace_id)->where('id', $plan->id)->update([
            'status' => ExecutionPlanStatus::Executing->value,
            'updated_at' => $now,
        ]);
        DB::table('execution_plan_actions')->where('workspace_id', $plan->workspace_id)
            ->where('execution_plan_id', $plan->id)
            ->whereIn('state', [ExecutionPlanActionState::Compiled->value, ExecutionPlanActionState::Queued->value])
            ->update(['state' => ExecutionPlanActionState::Queued->value, 'updated_at' => $now]);

        $from = $objective->status;
        if ($experiment !== null && $from !== ObjectiveStatus::Active) {
            $objective->forceFill([
                'status' => ObjectiveStatus::Active,
                'status_reason_code' => 'objective_execution_launched',
                'last_actor_user_id' => $actor->id,
                'lock_version' => (int) $objective->lock_version + 1,
                'activated_at' => $objective->activated_at ?? $now,
                'paused_at' => null,
            ])->save();
            ObjectiveStateEvent::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $objective->workspace_id,
                'objective_id' => $objective->id,
                'objective_version_id' => $objective->current_version_id,
                'from_state' => $from,
                'to_state' => ObjectiveStatus::Active,
                'reason_code' => 'objective_execution_launched',
                'context' => [
                    'execution_plan_id' => (string) $plan->id,
                    'plan_hash' => (string) $plan->plan_hash,
                ],
                'actor_user_id' => $actor->id,
                'created_at' => $now,
            ]);
        }

        $experiment?->forceFill(['status' => GtmExperimentStatus::Running])->save();
        $launchOutput = CanonicalObjectivePayload::hash([
            'version' => 'objective_launch_v1',
            'plan_id' => (string) $plan->id,
            'plan_hash' => (string) $plan->plan_hash,
        ]);
        ObjectiveRunPhase::query()->where('workspace_id', $plan->workspace_id)
            ->where('objective_run_id', $run->id)
            ->where('phase', ObjectiveRunPhaseName::Launch)
            ->update([
                'state' => ObjectiveRunPhaseState::Succeeded,
                'output_hash' => $launchOutput,
                'started_at' => $now,
                'finished_at' => $now,
                'updated_at' => $now,
            ]);
        ObjectiveRunPhase::query()->firstOrCreate([
            'workspace_id' => $plan->workspace_id,
            'objective_run_id' => $run->id,
            'phase' => ObjectiveRunPhaseName::Execute,
            'attempt_number' => 1,
        ], [
            'id' => (string) Str::uuid(),
            'state' => ObjectiveRunPhaseState::Waiting,
            'input_hash' => $launchOutput,
            'fencing_token' => 0,
            'queued_at' => $now,
        ]);
        $run->forceFill([
            'state' => ObjectiveRunState::Running,
            'current_phase' => ObjectiveRunPhaseName::Execute,
            'safe_error_code' => null,
            'safe_error_detail' => null,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'heartbeat_at' => $now,
        ])->save();
    }

    private function ownerWorkspaceId(ExecutionPlan $plan, User $actor): int
    {
        $workspaceId = (int) $actor->currentWorkspace()->id;
        if ($workspaceId < 1
            || (int) $plan->workspace_id !== $workspaceId
            || ! WorkspaceMembership::query()
                ->where('workspace_id', $workspaceId)
                ->where('user_id', $actor->id)
                ->where('role', WorkspaceMembership::ROLE_OWNER)
                ->exists()) {
            throw new AuthorizationException('Only an owner of the plan workspace may launch governed execution.');
        }

        return $workspaceId;
    }
}
