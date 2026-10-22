<?php

namespace Database\Factories;

use App\Domain\Mail\Enums\SendAttemptKind;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\SendAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SendAttempt>
 */
class SendAttemptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'message_id' => Message::factory()->outbound(),
            'mailbox_id' => fn (array $attributes) => Message::query()->findOrFail($attributes['message_id'])->mailbox_id,
            'user_id' => fn (array $attributes) => Mailbox::query()->findOrFail($attributes['mailbox_id'])->user_id,
            'workspace_id' => fn (array $attributes) => Mailbox::query()
                ->whereKey($attributes['mailbox_id'])
                ->firstOrFail()
                ->getAttribute('workspace_id'),
            'kind' => SendAttemptKind::Compose,
            'idempotency_key' => (string) Str::uuid(),
            'client_operation_id' => (string) Str::uuid(),
            'status' => SendAttemptStatus::Queued,
        ];
    }
}
