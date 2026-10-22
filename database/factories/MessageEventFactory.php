<?php

namespace Database\Factories;

use App\Domain\Mail\Enums\MessageEventType;
use App\Models\CampaignRecipient;
use App\Models\Message;
use App\Models\MessageEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageEvent>
 */
class MessageEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'message_id' => Message::factory()->outbound(),
            'campaign_recipient_id' => CampaignRecipient::factory(),
            'type' => MessageEventType::Open,
            'event_bucket' => now()->utc()->format('Y-m-d\TH:i'),
            'occurred_at' => now(),
            'metadata' => [],
        ];
    }
}
