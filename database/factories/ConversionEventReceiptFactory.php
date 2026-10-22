<?php

namespace Database\Factories;

use App\Models\ConversionEventReceipt;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ConversionEventReceipt> */
class ConversionEventReceiptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'workspace_id' => Workspace::factory(),
            'connection_id' => null,
            'connection_version_id' => null,
            'manual_actor_id' => null,
            'source_namespace' => 'fixture',
            'source_type' => 'manual',
            'external_event_id' => fake()->uuid(),
            'dedupe_key' => fake()->uuid(),
            'dedupe_hash' => hash('sha256', fake()->uuid()),
            'payload_hash' => str_repeat('0', 64),
            'schema_version' => 1,
            'received_at' => now(),
            'occurred_at' => now(),
            'verification_state' => 'not_required',
            'intake_state' => 'accepted',
            'safe_rejection_code' => null,
            'unmatched_identity_envelope' => null,
            'identity_expires_at' => null,
            'identity_redacted_at' => null,
            'conversion_event_id' => null,
            'actor_display_name' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
