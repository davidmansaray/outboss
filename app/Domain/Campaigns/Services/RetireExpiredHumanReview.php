<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Objectives\Enums\ApprovalBatchState;
use App\Domain\Objectives\Enums\ExecutionOrigin;
use App\Domain\Objectives\Enums\ExecutionPlanActionState;
use App\Domain\Objectives\Enums\ExecutionPlanStatus;
use App\Domain\Objectives\Enums\PolicyEvaluationStage;
use App\Domain\Objectives\Services\CancelObjectiveRun;
use App\Domain\Objectives\Services\GovernedExecutionCheckpoint;
use App\Domain\Objectives\Support\ApprovalManifest;
use App\Models\Campaign;
use App\Models\CampaignComposition;
use App\Models\ExecutionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Retire a non-executable review only before any compilation or delivery. */
final class RetireExpiredHumanReview
{
    /** @param list<string> $fingerprints */
    public function handle(Campaign $campaign, ExecutionPlan $plan, User $actor, string $planHash, string $manifestHash, array $fingerprints): void
    {
        DB::transaction(function () use ($campaign, $plan, $actor, $planHash, $manifestHash, $fingerprints): void {
            $workspace = $actor->currentWorkspace();
            abort_unless((int) $campaign->getAttribute('workspace_id') === (int) $workspace->id && (int) $plan->workspace_id === (int) $workspace->id, 404);
            abort_unless($workspace->isOwner($actor), 403);
            $plan = ExecutionPlan::query()->where('workspace_id', $workspace->id)->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $run = $plan->objectiveRun()->where('workspace_id', $workspace->id)->firstOrFail();
            abort_unless($plan->getAttribute('origin') === ExecutionOrigin::Human && $run->getAttribute('origin') === ExecutionOrigin::Human
                && CampaignComposition::query()->where('workspace_id', $workspace->id)
                    ->where('campaign_id', $campaign->id)->whereKey($run->getAttribute('campaign_composition_id'))->exists(), 404);
            $manifest = app(ApprovalManifest::class);
            sort($fingerprints, SORT_STRING);
            if (! hash_equals($plan->plan_hash, $planHash) || ! hash_equals($manifest->hash($plan), $manifestHash)
                || $manifest->actionFingerprints($plan) !== $fingerprints) {
                throw ValidationException::withMessages(['review' => 'Reload this review before returning to the draft.']);
            }
            if ($plan->status === ExecutionPlanStatus::Cancelled) {
                return;
            }
            $campaign = Campaign::query()->where('workspace_id', $workspace->id)->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            if (! in_array($plan->status, [ExecutionPlanStatus::Approved, ExecutionPlanStatus::Rejected, ExecutionPlanStatus::Revoked], true)
                || $plan->compilations()->exists() || $campaign->published_sequence_version_id !== null || $campaign->launched_at !== null
                || $campaign->recipients()->whereHas('sendAttempts')->exists()) {
                throw ValidationException::withMessages(['review' => 'This review has entered delivery. Its sending history cannot be returned to an editable draft.']);
            }
            $batch = $plan->approvalBatches()->where('workspace_id', $workspace->id)->lockForUpdate()->firstOrFail();
            if ($batch->state === ApprovalBatchState::Approved) {
                if ($batch->expires_at !== null && $batch->expires_at->isFuture()) {
                    throw ValidationException::withMessages(['review' => 'This approval is still active. It cannot be replaced through expired-review recovery.']);
                }
                // Persist expiry through the shared append-only approval event path.
                app(GovernedExecutionCheckpoint::class)->forPlan($plan, PolicyEvaluationStage::Approval);
                $batch->refresh();
            }
            if (! in_array($batch->state, [ApprovalBatchState::Expired, ApprovalBatchState::Invalidated, ApprovalBatchState::Rejected, ApprovalBatchState::Revoked], true)) {
                throw ValidationException::withMessages(['review' => 'The old approval must be non-executable before preparing a replacement.']);
            }
            app(CancelObjectiveRun::class)->handle($run, $actor, 'Owner returned a non-executable, uncompiled human review to its campaign draft.');
            DB::table('execution_plans')->where('workspace_id', $workspace->id)->where('id', $plan->id)
                ->update(['status' => ExecutionPlanStatus::Cancelled->value, 'updated_at' => now()]);
            DB::table('execution_plan_actions')->where('workspace_id', $workspace->id)->where('execution_plan_id', $plan->id)
                ->update(['state' => ExecutionPlanActionState::Cancelled->value, 'updated_at' => now()]);
            // Grant commitments remain intact: expiry is never an automatic capacity refund.
        }, 3);
    }
}
