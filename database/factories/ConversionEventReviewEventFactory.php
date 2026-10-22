<?php

namespace Database\Factories;

use App\Models\ConversionEvent;
use App\Models\ConversionEventReviewEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ConversionEventReviewEvent> */
class ConversionEventReviewEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'workspace_id' => fn (array $attributes): int => (int) ConversionEvent::query()->whereKey($attributes['conversion_event_id'])->value('workspace_id'),
            'conversion_event_id' => ConversionEvent::factory(),
            'action' => 'resolve',
            'actor_user_id' => null,
            'actor_display_name' => null,
            'reason' => null,
            'prior_state' => null,
            'projected_state' => null,
            'successor_event_id' => null,
            'successor_match_id' => null,
            'created_at' => now(),
        ];
    }
}
