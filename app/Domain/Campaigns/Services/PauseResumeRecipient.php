<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\Enums\MailboxAssignment;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Support\SendWindowCalculator;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\SendAttempt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PauseResumeRecipient
{
    public function __construct(
        private SendWindowCalculator $windows,
        private GovernedCampaignInputGuard $governedInputs,
    ) {}

    public function pause(CampaignRecipient $recipient): CampaignRecipient
    {
        if ($recipient->status->isTerminal() || $recipient->status === RecipientStatus::Paused) {
            return $recipient;
        }

        return $this->governedInputs->runRecipientPause($recipient, function () use ($recipient): CampaignRecipient {
            $remaining = $recipient->next_send_at
                ? max(0, now()->diffInSeconds($recipient->next_send_at, false))
                : 0;

            $recipient->forceFill([
                'blocked_from_status' => $recipient->status,
                'status' => RecipientStatus::Paused,
                'paused_at' => now(),
                'remaining_delay_seconds' => $remaining,
            ])->save();

            $this->defer($recipient);

            return $recipient->fresh();
        });
    }

    public function resume(CampaignRecipient $recipient): CampaignRecipient
    {
        if ($recipient->status !== RecipientStatus::Paused) {
            return $recipient;
        }

        return $this->governedInputs->runRecipientResume($recipient, function () use ($recipient): CampaignRecipient {
            $status = RecipientStatus::tryFrom((string) $recipient->blocked_from_status) ?? RecipientStatus::Active;
            $due = now()->addSeconds((int) $recipient->remaining_delay_seconds);
            $due = $this->windows->nextAllowed(
                $due,
                $recipient->campaign->timezone,
                (string) $recipient->campaign->send_window_start,
                (string) $recipient->campaign->send_window_end,
                $recipient->campaign->business_days_only,
            );

            $recipient->forceFill([
                'status' => $status === RecipientStatus::Paused ? RecipientStatus::Active : $status,
                'paused_at' => null,
                'blocked_from_status' => null,
                'remaining_delay_seconds' => null,
                'next_send_at' => $due,
            ])->save();

            return $recipient->fresh();
        });
    }

    public function pinMailbox(CampaignRecipient $recipient, Mailbox $mailbox): CampaignRecipient
    {
        $this->governedInputs->assertRecipientMutable($recipient);

        if ($recipient->first_sent_at) {
            throw new RuntimeException('mailbox_locked');
        }

        if ((int) $recipient->getAttribute('workspace_id') !== (int) $mailbox->getAttribute('workspace_id')) {
            throw new RuntimeException('workspace_mismatch');
        }

        $recipient->forceFill([
            'mailbox_id' => $mailbox->id,
            'mailbox_assignment' => MailboxAssignment::ManualPin,
        ])->save();

        return $recipient->fresh();
    }

    public function remove(CampaignRecipient $recipient): CampaignRecipient
    {
        $this->governedInputs->assertRecipientMutable($recipient);

        $recipient->forceFill([
            'status' => RecipientStatus::Removed,
            'removed_at' => now(),
            'stopped_at' => now(),
            'stop_reason' => 'removed',
            'next_send_at' => null,
        ])->save();

        $this->defer($recipient, SendAttemptStatus::Cancelled);
        DB::afterCommit(fn () => app(CompleteFinishedCampaign::class)->handle(
            (int) $recipient->getAttribute('workspace_id'), (int) $recipient->campaign_id,
        ));

        return $recipient->fresh();
    }

    private function defer(CampaignRecipient $recipient, SendAttemptStatus $status = SendAttemptStatus::Deferred): void
    {
        SendAttempt::query()
            ->where('campaign_recipient_id', $recipient->id)
            ->where('workspace_id', $recipient->getAttribute('workspace_id'))
            ->whereIn('status', $status === SendAttemptStatus::Cancelled
                ? [SendAttemptStatus::Scheduled, SendAttemptStatus::Queued, SendAttemptStatus::Deferred]
                : [SendAttemptStatus::Scheduled, SendAttemptStatus::Queued])
            ->update(['status' => $status, 'deferred_reason' => 'paused']);
    }
}
