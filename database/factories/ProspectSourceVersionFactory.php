<?php

namespace Database\Factories;

use App\Domain\Prospecting\Enums\SourceVersionOrigin;
use App\Models\ProspectSource;
use App\Models\ProspectSourceVersion;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProspectSourceVersion> */
class ProspectSourceVersionFactory extends Factory
{
    public function definition(): array
    {
        $configuration = ['ingress' => 'web', 'system_managed' => true];
        $policy = [
            'provenance_class' => 'native',
            'allowed_purposes' => ['prospecting'],
            'prohibited_uses' => [],
            'personal_data_classification' => 'standard',
            'raw_retention_days' => 0,
            'normalized_retention_days' => 365,
            'export_allowed' => true,
            'contact_eligibility' => 'eligible',
        ];

        return [
            'id' => (string) Str::uuid(),
            'prospect_source_id' => ProspectSource::factory(),
            'workspace_id' => Workspace::factory(),
            'version' => 1,
            'schema_version' => ProspectSourceVersion::SCHEMA_VERSION,
            'configuration' => $configuration,
            'configuration_hash' => hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR)),
            'policy_snapshot' => $policy,
            'policy_hash' => hash('sha256', json_encode($policy, JSON_THROW_ON_ERROR)),
            'capability' => null,
            'discovery_motion' => null,
            'source_family' => null,
            'provider_registration_id' => null,
            'provider_registration_version' => null,
            'origin' => SourceVersionOrigin::System,
            'actor_type' => 'system',
            'actor_user_id' => null,
            'actor_display_name' => 'System',
            'created_at' => now(),
        ];
    }

    public function forSource(ProspectSource $source): static
    {
        return $this->state(fn (): array => [
            'prospect_source_id' => $source->id,
            'workspace_id' => $source->workspace_id,
        ]);
    }
}
