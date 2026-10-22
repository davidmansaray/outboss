<?php

namespace Database\Factories;

use App\Models\OutboxEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutboxEvent>
 */
class OutboxEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => 'send_attempt.created',
            'aggregate_type' => 'send_attempt',
            'aggregate_id' => 1,
            'payload' => ['send_attempt_id' => 1],
            'available_at' => now(),
        ];
    }
}
