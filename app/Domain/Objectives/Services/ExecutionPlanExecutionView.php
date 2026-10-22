<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Campaigns\Services\CampaignLaunchReadiness;
use App\Domain\Objectives\Enums\ExecutionPlanStatus;
use App\Models\Campaign;
use App\Models\ExecutionPlan;
use App\Models\ExecutionPlanCompilation;
use BackedEnum;
use Throwable;

/** Build the safe owner controls and progress projection for one execution plan. */
final class ExecutionPlanExecutionView
{
    public function __construct(
        private readonly CampaignLaunchReadiness $readiness,
        private readonly ObjectiveOutcomeSummary $outcomes,
    ) {}

    /**
     * @param  array<string, mixed>  $approval
     * @return array<string, mixed>
     */
    public function handle(ExecutionPlan $plan, bool $owner, array $approval): array
    {
        $plan->loadMissing([
            'actions',
            'compilations' => fn ($query) => $query->orderByDesc('attempt'),
            'compiledCampaigns.arm',
            'compiledCampaigns.campaign',
        ]);

        /** @var ExecutionPlanCompilation|null $latestCompilation */
        $latestCompilation = $plan->compilations->first();
        $blockers = [];
        $availableActions = [];

        if ($plan->status === ExecutionPlanStatus::Approved) {
            $approvalBlockers = is_array($approval['blockers'] ?? null) ? $approval['blockers'] : [];
            if (($approval['state'] ?? null) === 'approved' && $approvalBlockers === []) {
                if ($owner) {
                    $availableActions[] = 'compile';
                }
            } else {
                $blockers = $approvalBlockers !== [] ? $approvalBlockers : [[
                    'code' => 'approval_not_current',
                    'message' => 'The exact owner approval is no longer current.',
                ]];
            }
        } elseif ($plan->status === ExecutionPlanStatus::Compiled) {
            $blockers = $this->launchBlockers($plan);
            if ($owner && $blockers === []) {
                $availableActions[] = 'launch';
            }
        }

        return [
            'is_owner' => $owner,
            'state' => $plan->status->value,
            'plan_id' => (string) $plan->id,
            'plan_hash' => (string) $plan->plan_hash,
            'available_actions' => $availableActions,
            'blockers' => $blockers,
            'latest_compilation' => $latestCompilation === null ? null : [
                'id' => (string) $latestCompilation->id,
                'attempt' => (int) $latestCompilation->attempt,
                'state' => $latestCompilation->state->value,
                'safe_error_code' => $latestCompilation->safe_error_code,
                'safe_error_detail' => $latestCompilation->safe_error_detail,
                'started_at' => $latestCompilation->started_at->toAtomString(),
                'finished_at' => $latestCompilation->finished_at?->toAtomString(),
            ],
            'campaigns' => $this->campaigns($plan),
            'action_progress' => $this->actionProgress($plan),
            'outcomes' => $this->outcomes->forPlan($plan),
            'lineage' => $this->outcomes->forPlanLineage($plan),
            'consequence' => match ($plan->status) {
                ExecutionPlanStatus::Approved => 'Preparing creates draft campaigns for the exact approved actions. It does not launch or send email.',
                ExecutionPlanStatus::Compiled => 'Launching starts every experiment arm together. Messages are still sent only when their scheduled windows open.',
                ExecutionPlanStatus::Executing => 'Every experiment arm has launched. Delivery progress appears below.',
                ExecutionPlanStatus::Completed => 'Execution has completed for this plan.',
                default => 'This plan is not ready to prepare or launch.',
            },
        ];
    }

    /** @return list<array{code:string,message:string,campaign_id?:int}> */
    private function launchBlockers(ExecutionPlan $plan): array
    {
        if ($plan->compiledCampaigns->count() !== (int) $plan->arm_count) {
            return [[
                'code' => 'compiled_campaigns_incomplete',
                'message' => 'The prepared plan does not have one campaign for every experiment arm.',
            ]];
        }

        $blockers = [];
        foreach ($plan->compiledCampaigns as $mapping) {
            $campaign = $mapping->campaign;
            if (! $campaign instanceof Campaign) {
                $blockers[] = [
                    'code' => 'compiled_campaign_unavailable',
                    'message' => 'A prepared campaign is unavailable.',
                    'campaign_id' => (int) $mapping->campaign_id,
                ];

                continue;
            }

            try {
                foreach ($this->readiness->blockers($campaign) as $blocker) {
                    $blockers[] = [
                        'code' => (string) $blocker['id'],
                        'message' => (string) $blocker['message'],
                        'campaign_id' => (int) $campaign->id,
                    ];
                }
            } catch (Throwable) {
                $blockers[] = [
                    'code' => 'launch_readiness_unavailable',
                    'message' => 'Launch readiness could not be verified safely. Try again before launching.',
                    'campaign_id' => (int) $campaign->id,
                ];
            }
        }

        $unique = [];
        foreach ($blockers as $blocker) {
            $unique[$blocker['code'].':'.$blocker['campaign_id']] = $blocker;
        }

        return array_values($unique);
    }

    /** @return list<array{id:int,name:string,status:string,arm_id:string,arm_label:string,href:string}> */
    private function campaigns(ExecutionPlan $plan): array
    {
        $campaigns = [];
        foreach ($plan->compiledCampaigns as $mapping) {
            $campaign = $mapping->campaign;
            $status = $campaign->getAttribute('status');
            $campaigns[] = [
                'id' => (int) $mapping->campaign_id,
                'name' => (string) $campaign->name,
                'status' => $status instanceof BackedEnum ? (string) $status->value : (string) $status,
                'arm_id' => (string) $mapping->gtm_experiment_arm_id,
                'arm_label' => (string) $mapping->arm->label,
                'href' => '/campaigns/'.(int) $mapping->campaign_id,
            ];
        }
        usort($campaigns, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return $campaigns;
    }

    /** @return array<string, int> */
    private function actionProgress(ExecutionPlan $plan): array
    {
        $progress = [];
        foreach ($plan->actions as $action) {
            $state = $action->getAttribute('state');
            $key = $state instanceof BackedEnum ? (string) $state->value : (string) $state;
            $progress[$key] = ($progress[$key] ?? 0) + 1;
        }
        ksort($progress, SORT_STRING);

        return $progress;
    }
}
