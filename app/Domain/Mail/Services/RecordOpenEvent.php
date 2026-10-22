<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\Enums\MessageEventType;
use App\Domain\Mail\Enums\SendAttemptKind;
use App\Models\Message;
use App\Models\MessageEvent;
use Illuminate\Support\Facades\DB;

class RecordOpenEvent
{
    public function handle(string $token, ?string $ip, ?string $userAgent): void
    {
        $message = Message::query()->where('tracking_token', $token)->first();

        if (! $message || ! $message->campaign_recipient_id) {
            return;
        }

        if ($message->sendAttempts()->where('kind', SendAttemptKind::Test)->exists()) {
            return;
        }

        $bucket = now()->utc()->format('Y-m-d\TH:i');
        $count = MessageEvent::query()->where('message_id', $message->id)->count();

        if ($count >= 100) {
            return;
        }

        DB::transaction(function () use ($message, $bucket, $ip, $userAgent): void {
            MessageEvent::query()->firstOrCreate(
                [
                    'message_id' => $message->id,
                    'type' => MessageEventType::Open,
                    'event_bucket' => $bucket,
                ],
                [
                    'campaign_recipient_id' => $message->campaign_recipient_id,
                    'occurred_at' => now(),
                    'ip_hash' => $ip ? hash('sha256', config('outboss.tracking_hash_salt').$ip) : null,
                    'user_agent_hash' => $userAgent ? hash('sha256', config('outboss.tracking_hash_salt').$userAgent) : null,
                    'metadata' => [],
                ],
            );

            if ($message->recipient && $message->recipient->first_opened_at === null) {
                $message->recipient->forceFill(['first_opened_at' => now()])->save();
            }
        });
    }
}
