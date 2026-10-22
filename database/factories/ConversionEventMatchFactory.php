<?php

namespace Database\Factories;

use App\Models\ConversionEvent;
use App\Models\ConversionEventMatch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ConversionEventMatch> */
class ConversionEventMatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'workspace_id' => fn (array $attributes): int => (int) ConversionEvent::query()->whereKey($attributes['conversion_event_id'])->value('workspace_id'),
            'conversion_event_id' => ConversionEvent::factory(),
            'attempt_number' => 1,
            'supplied_identity_hashes' => [],
            'identity_handles' => [],
            'resolution_path' => 'fixture',
            'candidate_count' => 0,
            'result' => 'unmatched',
            'matched_account_id' => null,
            'actor_user_id' => null,
            'actor_display_name' => null,
            'reason' => null,
            'created_at' => now(),
        ];
    }
}
