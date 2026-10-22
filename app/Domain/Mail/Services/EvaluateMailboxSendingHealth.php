<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\Enums\BounceSubtype;
use App\Domain\Mail\Enums\MailboxSendingPausedReason;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\SendAttemptKind;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Enums\ThreadClassification;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\SendAttempt;

class EvaluateMailboxSendingHealth
{
    public function __construct(private readonly ManagedMailboxOperationalEventRecorder $events) {}

    public function handle(Mailbox $mailbox): void
    {
        if ($mailbox->getAttribute('sending_paused_at') !== null) {
            return;
        }

        $since = now()->subHours(max(1, (int) config('outboss.health.window_hours', 24)));
        $bounceThreshold = max(1, (int) config('outboss.health.hard_bounce_threshold', 3));
        $errorThreshold = max(1, (int) config('outboss.health.provider_error_threshold', 5));

        $hardBounces = Message::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('direction', MessageDirection::Inbound)
            ->where('classification', ThreadClassification::Bounce)
            ->where('bounce_subtype', BounceSubtype::Hard)
            ->whereNotNull('campaign_recipient_id')
            ->where('created_at', '>=', $since)
            ->count();

        if ($hardBounces >= $bounceThreshold) {
            $mailbox->forceFill([
                'sending_paused_at' => now(),
                'sending_paused_reason' => MailboxSendingPausedReason::BounceThreshold->value,
            ])->save();
            $this->events->record(
                $mailbox,
                'mailbox_sending_paused',
                fromState: 'active',
                toState: 'paused',
                metadata: [
                    'reason' => MailboxSendingPausedReason::BounceThreshold->value,
                    'observed_count' => $hardBounces,
                    'threshold' => $bounceThreshold,
                ],
            );

            return;
        }

        $providerErrors = SendAttempt::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('kind', SendAttemptKind::Campaign)
            ->where('updated_at', '>=', $since)
            ->where(function ($query): void {
                $query->where('status', SendAttemptStatus::Unknown)
                    ->orWhere(function ($failed): void {
                        $failed->where('status', SendAttemptStatus::Failed)
                            ->where('error_code', 'like', 'provider%');
                    });
            })
            ->count();

        if ($providerErrors >= $errorThreshold) {
            $mailbox->forceFill([
                'sending_paused_at' => now(),
                'sending_paused_reason' => MailboxSendingPausedReason::ProviderErrorThreshold->value,
            ])->save();
            $this->events->record(
                $mailbox,
                'mailbox_sending_paused',
                fromState: 'active',
                toState: 'paused',
                metadata: [
                    'reason' => MailboxSendingPausedReason::ProviderErrorThreshold->value,
                    'observed_count' => $providerErrors,
                    'threshold' => $errorThreshold,
                ],
            );
        }
    }
}
