<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Models\Campaign;
use App\Models\SendAttempt;
use Illuminate\Support\Facades\DB;

/** Complete exhausted campaigns, never campaigns awaiting delivery resolution. */
final class CompleteFinishedCampaign
{
    public function handle(int $workspaceId, int $campaignId): void
    {
        DB::transaction(function () use ($workspaceId, $campaignId): void {
            $campaign = Campaign::query()->where('workspace_id', $workspaceId)->whereKey($campaignId)->lockForUpdate()->first();
            if ($campaign === null || ! in_array($campaign->getAttribute('status'), [CampaignStatus::Active, CampaignStatus::Paused], true)) {
                return;
            }
            $recipients = $campaign->recipients()->where('workspace_id', $workspaceId)->lockForUpdate()->get();
            if ($recipients->isEmpty() || $recipients->contains(fn ($recipient) => ! $recipient->getAttribute('status')->isCompletable())) {
                return;
            }
            if (SendAttempt::query()->where('workspace_id', $workspaceId)
                ->whereIn('campaign_recipient_id', $recipients->modelKeys())
                ->whereIn('status', [SendAttemptStatus::Scheduled, SendAttemptStatus::Queued,
                    SendAttemptStatus::Deferred, SendAttemptStatus::LeaseAcquired, SendAttemptStatus::Sending,
                    SendAttemptStatus::ProviderAccepted, SendAttemptStatus::Unknown, SendAttemptStatus::ManualReview])
                ->lockForUpdate()->exists()) {
                return;
            }
            app(GovernedCampaignInputGuard::class)->runCampaignComplete($campaign, function () use ($campaign): void {
                $campaign->forceFill(['status' => CampaignStatus::Completed, 'completed_at' => $campaign->completed_at ?? now()])->save();
            });
        }, 3);
    }
}
