<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Jobs\Campaigns\SendCampaignMessage as SendCampaignMessageJob;
use App\Jobs\Mail\SendMailboxMessage;
use App\Models\OutboxEvent;
use App\Models\SendAttempt;

class PublishOutboxEvents
{
    public function handle(): int
    {
        $published = 0;

        OutboxEvent::query()
            ->whereNull('published_at')
            ->where('available_at', '<=', now())
            ->orderBy('id')
            ->each(function (OutboxEvent $event) use (&$published): void {
                $attemptId = (int) ($event->payload['send_attempt_id'] ?? $event->aggregate_id);
                $attempt = SendAttempt::query()->find($attemptId);

                if ($attempt && in_array($attempt->status, [
                    SendAttemptStatus::Queued,
                    SendAttemptStatus::Scheduled,
                ], true)) {
                    if ($attempt->kind->value === 'campaign') {
                        SendCampaignMessageJob::dispatch($attemptId);
                    } else {
                        SendMailboxMessage::dispatch($attemptId);
                    }
                }

                $event->forceFill(['published_at' => now()])->save();
                $published++;
            });

        return $published;
    }
}
