<?php

namespace App\Domain\Mail\Services;

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Exceptions\SenderIdentityUnavailable;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\SendAttempt;

class RestoreBlockedRecipients
{
    public function __construct(private MailboxSenderIdentityService $senderIdentities) {}

    public function handle(Mailbox $mailbox): void
    {
        if ($mailbox->getAttribute('status') !== ConnectionStatus::Connected) {
            return;
        }

        try {
            $this->senderIdentities->effective($mailbox);
        } catch (SenderIdentityUnavailable) {
            return;
        }

        CampaignRecipient::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('workspace_id', $mailbox->getAttribute('workspace_id'))
            ->where('status', RecipientStatus::BlockedMailbox)
            ->each(function (CampaignRecipient $recipient) use ($mailbox): void {
                if (! $this->snapshotsAreAllowed($recipient, $mailbox)) {
                    return;
                }

                $status = RecipientStatus::tryFrom((string) $recipient->blocked_from_status) ?? RecipientStatus::Active;
                $nextSendAt = $recipient->remaining_delay_seconds !== null
                    ? now()->addSeconds($recipient->remaining_delay_seconds)
                    : $recipient->next_send_at;

                $recipient->forceFill([
                    'status' => $status,
                    'next_send_at' => $nextSendAt,
                    'blocked_at' => null,
                    'blocked_from_status' => null,
                    'remaining_delay_seconds' => null,
                ])->save();
            });
    }

    private function snapshotsAreAllowed(CampaignRecipient $recipient, Mailbox $mailbox): bool
    {
        $attempts = SendAttempt::query()
            ->where('campaign_recipient_id', $recipient->id)
            ->where('workspace_id', $mailbox->getAttribute('workspace_id'))
            ->where('status', SendAttemptStatus::Deferred)
            ->where('deferred_reason', 'sender_identity_unavailable')
            ->get();

        foreach ($attempts as $attempt) {
            $message = Message::query()
                ->where('workspace_id', $mailbox->getAttribute('workspace_id'))
                ->find($attempt->message_id);

            try {
                $this->senderIdentities->assertSnapshotAllowed($mailbox, $message?->from_email);
            } catch (SenderIdentityUnavailable) {
                return false;
            }
        }

        return true;
    }
}
