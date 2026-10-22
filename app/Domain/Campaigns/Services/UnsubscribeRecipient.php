<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Mail\Enums\SuppressionReason;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\SuppressionEntry;
use Illuminate\Support\Facades\DB;

class UnsubscribeRecipient
{
    public function __construct(private StopCampaignRecipient $stop) {}

    public function handle(string $token): void
    {
        DB::transaction(function () use ($token): void {
            $source = CampaignRecipient::query()
                ->where('unsubscribe_token', $token)
                ->lockForUpdate()
                ->first();

            if (! $source) {
                return;
            }

            $workspaceId = (int) $source->getAttribute('workspace_id');
            $email = (string) $source->email_normalized;

            if ($workspaceId <= 0 || $email === '') {
                return;
            }

            $campaign = $source->campaign()
                ->where('workspace_id', $workspaceId)
                ->first();
            $userId = $campaign instanceof Campaign
                ? (int) $campaign->getAttribute('user_id')
                : null;

            if ($userId === null || $userId <= 0) {
                return;
            }

            SuppressionEntry::query()->firstOrCreate(
                [
                    'workspace_id' => $workspaceId,
                    'email_normalized' => $email,
                ],
                [
                    'user_id' => $userId,
                    'email_address' => $source->email_address,
                    'reason' => SuppressionReason::Unsubscribe,
                ],
            );

            $enrolments = CampaignRecipient::query()
                ->where('workspace_id', $workspaceId)
                ->where('email_normalized', $email)
                ->lockForUpdate()
                ->get();

            foreach ($enrolments as $enrolment) {
                $this->stop->handle($enrolment, RecipientStatus::Unsubscribed, 'unsubscribe');
            }
        });
    }
}
