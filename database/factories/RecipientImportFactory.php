<?php

namespace Database\Factories;

use App\Domain\Campaigns\Enums\ImportSource;
use App\Domain\Campaigns\Enums\ImportStatus;
use App\Models\Campaign;
use App\Models\RecipientImport;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RecipientImport>
 */
class RecipientImportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'user_id' => fn (array $attributes) => Campaign::query()
                ->whereKey($attributes['campaign_id'])
                ->firstOrFail()
                ->getAttribute('user_id'),
            'workspace_id' => fn (array $attributes) => Campaign::query()
                ->whereKey($attributes['campaign_id'])
                ->firstOrFail()
                ->getAttribute('workspace_id'),
            'source' => ImportSource::Paste,
            'source_hash' => hash('sha256', Str::random(20)),
            'headers' => ['email', 'name', 'company'],
            'mapping' => ['email' => 'email', 'name' => 'name', 'company' => 'company'],
            'mapping_version' => 1,
            'status' => ImportStatus::Previewed,
            'expires_at' => now()->addDay(),
        ];
    }
}
