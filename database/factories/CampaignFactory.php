<?php

namespace Database\Factories;

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\SendMode;
use App\Domain\Campaigns\Enums\SequenceSendAs;
use App\Domain\Campaigns\Enums\SequenceVersionStatus;
use App\Models\Campaign;
use App\Models\SequenceStep;
use App\Models\SequenceVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'workspace_id' => fn (array $attributes) => User::query()
                ->whereKey($attributes['user_id'])
                ->firstOrFail()
                ->currentWorkspace()
                ->getKey(),
            'name' => fake()->words(3, true),
            'status' => CampaignStatus::Draft,
            'send_mode' => SendMode::Rotate,
            'timezone' => 'UTC',
            'send_window_start' => '09:00:00',
            'send_window_end' => '17:00:00',
            'business_days_only' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Campaign $campaign): void {
            if ($campaign->draft_sequence_version_id) {
                return;
            }

            $version = SequenceVersion::factory()->create([
                'campaign_id' => $campaign->id,
                'version' => 1,
                'status' => SequenceVersionStatus::Draft,
            ]);

            SequenceStep::factory()->create([
                'sequence_version_id' => $version->id,
                'position' => 1,
                'title' => 'Intro',
                'delay_minutes' => 0,
                'send_as' => SequenceSendAs::NewThread,
            ]);

            $campaign->forceFill(['draft_sequence_version_id' => $version->id])->save();
        });
    }
}
