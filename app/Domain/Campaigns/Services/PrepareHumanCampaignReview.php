<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Objectives\Services\BuildHumanExecutionPlan;
use App\Domain\Objectives\Services\FreezeHumanCampaignAudience;
use App\Domain\Objectives\Services\FreezeHumanCampaignContext;
use App\Domain\Objectives\Services\StartHumanCampaignRun;
use App\Models\Campaign;
use App\Models\CampaignComposition;
use App\Models\ExecutionPlan;
use App\Models\ObjectiveRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Freeze the human review atomically. This does not approve, compile or send. */
final class PrepareHumanCampaignReview
{
    public function handle(Campaign $campaign, User $actor, int $expectedRevision, string $operationId): ExecutionPlan
    {
        $workspace = $actor->currentWorkspace();
        abort_unless((int) $campaign->getAttribute('workspace_id') === (int) $workspace->id, 404);
        abort_unless($workspace->isOwner($actor), 403);
        if (! Str::isUuid($operationId)) {
            throw ValidationException::withMessages(['operation_id' => 'A unique review request is required.']);
        }

        return DB::transaction(function () use ($campaign, $actor, $workspace, $expectedRevision, $operationId): ExecutionPlan {
            $campaign = Campaign::query()->where('workspace_id', $workspace->id)->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $composition = CampaignComposition::query()->where('workspace_id', $workspace->id)->where('campaign_id', $campaign->id)
                ->orderByDesc('revision')->lockForUpdate()->first();
            if ($composition === null || (int) $composition->revision !== $expectedRevision) {
                throw ValidationException::withMessages(['campaign' => 'Your campaign selections changed. Reload before preparing review.']);
            }
            if ($composition->objective_goal_version_id === null || $composition->offer_version_id === null) {
                throw ValidationException::withMessages(['campaign' => 'Choose and save an Objective and an Offer before review.']);
            }
            if ($composition->execution_authority_scope_id === null) {
                throw ValidationException::withMessages(['campaign' => 'Choose and save a sending allowance before review. Campaign start and deadline dates are optional.']);
            }
            $existing = ObjectiveRun::query()->where('workspace_id', $workspace->id)
                ->where('idempotency_key_hash', hash('sha256', 'human_campaign:'.$workspace->id.'|'.$operationId))
                ->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->getAttribute('campaign_composition_id') !== $composition->id) {
                    throw ValidationException::withMessages(['operation_id' => 'This review request belongs to different campaign selections.']);
                }
                $context = $existing->objectiveVersion()->where('workspace_id', $workspace->id)->firstOrFail();
            } else {
                if ($composition->sending_ends_at !== null || $campaign->daily_send_limit !== null) {
                    $forecast = app(CampaignSendingForecast::class)->handle($campaign, $composition);
                    if (in_array($forecast['status'], ['over_capacity', 'unsupported', 'unavailable', 'daily_limit_exceeded'], true)) {
                        throw ValidationException::withMessages([$forecast['status'] === 'daily_limit_exceeded' ? 'daily_send_limit' : 'sending_ends_at' => $forecast['message']]);
                    }
                }
                $snapshot = app(FreezeHumanCampaignAudience::class)->handle($campaign, $composition, $actor);
                $context = app(FreezeHumanCampaignContext::class)->handle($campaign, $composition, $snapshot, $actor);
            }
            $run = app(StartHumanCampaignRun::class)->handle($campaign, $composition, $context, $actor, $operationId);

            return app(BuildHumanExecutionPlan::class)->handle($campaign, $run, $actor);
        }, 3);
    }
}
