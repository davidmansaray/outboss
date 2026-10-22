<?php

namespace Database\Factories;

use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\MessageStatus;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Thread;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'local_key' => (string) Str::uuid(),
            'thread_id' => Thread::factory(),
            'mailbox_id' => fn (array $attributes) => Thread::query()->findOrFail($attributes['thread_id'])->mailbox_id,
            'workspace_id' => fn (array $attributes) => Mailbox::query()
                ->whereKey($attributes['mailbox_id'])
                ->firstOrFail()
                ->getAttribute('workspace_id'),
            'remote_message_id' => fake()->uuid(),
            'direction' => MessageDirection::Inbound,
            'status' => MessageStatus::Received,
            'from_name' => fake()->name(),
            'from_email' => fake()->safeEmail(),
            'to_recipients' => [['email' => fake()->safeEmail()]],
            'cc_recipients' => [],
            'bcc_recipients' => [],
            'subject' => fake()->sentence(4),
            'body_text' => fake()->paragraph(),
            'provider_metadata' => [],
            'received_at' => now(),
        ];
    }

    public function outbound(): static
    {
        return $this->state(fn (): array => [
            'direction' => MessageDirection::Outbound,
            'status' => MessageStatus::Queued,
            'client_message_marker' => (string) Str::uuid(),
            'received_at' => null,
        ]);
    }
}
