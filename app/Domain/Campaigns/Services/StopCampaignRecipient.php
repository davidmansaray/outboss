<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Models\CampaignRecipient;
use App\Models\SendAttempt;
use Illuminate\Support\Facades\DB;

class StopCampaignRecipient
{
    public function __construct(private readonly GovernedCampaignInputGuard $governedInputs) {}

    public function handle(
        CampaignRecipient $recipient,
        RecipientStatus $status,
        string $reason,
        bool $allowCorrection = false,
    ): bool {
        $locked = CampaignRecipient::query()
            ->whereKey($recipient->id)
            ->lockForUpdate()
            ->first();

        if (! $locked instanceof CampaignRecipient) {
            return false;
        }

        $current = $locked->getAttribute('status');
        $currentStatus = $current instanceof RecipientStatus
            ? $current
            : RecipientStatus::tryFrom((string) $current);

        if ($currentStatus === null || $currentStatus === RecipientStatus::Removed) {
            if ($currentStatus === RecipientStatus::Removed) {
                $this->reconcileCompletion($locked);
            }

            return false;
        }

        if (! $allowCorrection && $currentStatus->isTerminal()) {
            $this->reconcileCompletion($locked);

            return false;
        }

        $recipient = $locked;
        $this->governedInputs->runRecipientStop($recipient, function () use ($recipient, $status, $reason): void {
            $recipient->forceFill([
                'status' => $status,
                'replied_at' => $status === RecipientStatus::Replied ? now() : $recipient->replied_at,
                'stopped_at' => now(),
                'stop_reason' => $reason,
                'next_send_at' => null,
            ])->save();

            SendAttempt::query()
                ->where('workspace_id', $recipient->getAttribute('workspace_id'))
                ->where('campaign_recipient_id', $recipient->id)
                ->whereIn('status', [
                    SendAttemptStatus::Scheduled,
                    SendAttemptStatus::Queued,
                    SendAttemptStatus::Deferred,
                ])
                ->update(['status' => SendAttemptStatus::Cancelled]);
        });

        // Run after the enclosing stop transaction releases recipient locks.
        // Concurrent stops serialize through the campaign completion lock.
        $this->reconcileCompletion($recipient);

        return true;
    }

    private function reconcileCompletion(CampaignRecipient $recipient): void
    {
        DB::afterCommit(fn () => app(CompleteFinishedCampaign::class)->handle(
            (int) $recipient->getAttribute('workspace_id'), (int) $recipient->campaign_id,
        ));
    }
}
