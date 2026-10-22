<?php

namespace Database\Factories;

use App\Domain\Mail\Enums\ThreadClassification;
use App\Domain\Mail\Enums\ThreadLocalState;
use App\Models\Mailbox;
use App\Models\Thread;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Thread>
 */
class ThreadFactory extends Factory
{
    public function definition(): array
    {
        $subject = fake()->sentence(4);

        return [
            'mailbox_id' => Mailbox::factory(),
            'user_id' => fn (array $attributes) => Mailbox::query()->findOrFail($attributes['mailbox_id'])->user_id,
            'workspace_id' => fn (array $attributes) => Mailbox::query()
                ->whereKey($attributes['mailbox_id'])
                ->firstOrFail()
                ->getAttribute('workspace_id'),
            'local_key' => (string) Str::uuid(),
            'remote_thread_id' => fake()->uuid(),
            'subject' => $subject,
            'subject_normalized' => strtolower(trim($subject)),
            'snippet' => fake()->sentence(),
            'participant_name' => fake()->name(),
            'participant_email' => fake()->safeEmail(),
            'last_message_at' => now(),
            'classification' => ThreadClassification::Unknown,
            'local_state' => ThreadLocalState::Open,
            'is_unread' => true,
        ];
    }
}
