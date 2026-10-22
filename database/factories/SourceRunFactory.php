<?php

namespace Database\Factories;

use App\Domain\Prospecting\Enums\SourceRunState;
use App\Models\ProspectSource;
use App\Models\ProspectSourceVersion;
use App\Models\SourceRun;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SourceRun> */
class SourceRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'workspace_id' => Workspace::factory(),
            'prospect_source_id' => ProspectSource::factory(),
            'prospect_source_version_id' => ProspectSourceVersion::factory(),
            'state' => SourceRunState::Draft,
            'initiator_type' => 'system',
            'initiated_by_user_id' => null,
            'initiator_display_name' => 'System',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', Str::uuid()->toString()),
            'retry_of_run_id' => null,
            'person_import_id' => null,
            'recipient_import_id' => null,
            'input_reference' => null,
            'checkpoint' => null,
            'received' => 0,
            'valid' => 0,
            'accepted' => 0,
            'created' => 0,
            'linked' => 0,
            'duplicate' => 0,
            'conflicted' => 0,
            'suppressed' => 0,
            'rejected' => 0,
            'expired' => 0,
            'estimated_cost_micros' => null,
            'reserved_cost_micros' => null,
            'actual_cost_micros' => null,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'retry_after_at' => null,
            'safe_error_code' => null,
            'safe_error_message' => null,
            'started_at' => null,
            'finished_at' => null,
            'cancelled_at' => null,
        ];
    }

    public function forSource(ProspectSource $source, ?ProspectSourceVersion $version = null): static
    {
        $version ??= $source->currentVersion;

        return $this->state(fn (): array => [
            'workspace_id' => $source->workspace_id,
            'prospect_source_id' => $source->id,
            'prospect_source_version_id' => $version?->id,
        ]);
    }
}
