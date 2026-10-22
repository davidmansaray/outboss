<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\SendAttempt;

class CampaignOutcomeCounts
{
    /**
     * @return array{queued: int, sent: int, replied: int, interested: int, bounced: int, unsubscribed: int, needs_review: int}
     */
    public function counts(Campaign $campaign): array
    {
        $workspaceId = (int) $campaign->getAttribute('workspace_id');
        $recipients = CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('workspace_id', $workspaceId);

        return [
            'queued' => (clone $recipients)->queued()->count(),
            'sent' => (clone $recipients)->sent()->count(),
            'replied' => (clone $recipients)->replied()->count(),
            'interested' => (clone $recipients)->interested()->count(),
            'bounced' => (clone $recipients)->bounced()->count(),
            'unsubscribed' => (clone $recipients)->unsubscribed()->count(),
            'needs_review' => SendAttempt::query()
                ->where('workspace_id', $workspaceId)
                ->where('status', SendAttemptStatus::ManualReview)
                ->whereHas('recipient', fn ($recipient) => $recipient
                    ->where('campaign_id', $campaign->id)
                    ->where('workspace_id', $workspaceId))
                ->count(),
        ];
    }
}
