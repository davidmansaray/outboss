<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ConversionDefinition;
use App\Models\ConversionDefinitionVersion;
use BackedEnum;
use Illuminate\Http\Request;

class ConversionDefinitionResource extends ApiResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $definition = $this->model(ConversionDefinition::class);
        $payload = [
            'id' => (string) $definition->id,
            'workspace_id' => (int) $definition->workspace_id,
            'event_key' => (string) $definition->event_key,
            'status' => $this->s2EnumValue($definition->status) ?? (string) $definition->getRawOriginal('status'),
            'lock_version' => (int) $definition->lock_version,
            'current_version_id' => $definition->current_version_id === null ? null : (string) $definition->current_version_id,
            'created_by_user_id' => $definition->created_by_user_id === null ? null : (int) $definition->created_by_user_id,
        ];

        if ($definition->relationLoaded('currentVersion') && $definition->currentVersion !== null) {
            $payload['current_version'] = $this->version($definition->currentVersion);
        }
        if ($definition->relationLoaded('versions')) {
            $payload['versions'] = $definition->versions->map(fn (ConversionDefinitionVersion $version): array => $this->version($version))->values()->all();
        }

        return $payload;
    }

    /** @return array<string,mixed> */
    private function version(ConversionDefinitionVersion $version): array
    {
        return [
            'id' => (string) $version->id,
            'version' => (int) $version->version,
            'schema_version' => (int) $version->schema_version,
            'display_name' => $version->display_name,
            'description' => $version->description,
            'subject_type' => $version->subject_type,
            'allowed_sources' => $this->arrayValue($version->getAttribute('allowed_sources'), true),
            'property_schema' => $this->arrayValue($version->getAttribute('property_schema')),
            'allowed_identity_keys' => $this->arrayValue($version->getAttribute('allowed_identity_keys'), true),
            'dedupe_strategy' => (string) $version->dedupe_strategy,
            'dedupe_configuration' => $this->arrayValue($version->getAttribute('dedupe_configuration')),
            'attribution_window' => $this->arrayValue($version->getAttribute('attribution_window')),
            'value_policy' => $this->arrayValue($version->getAttribute('value_policy')),
            'correction_policy' => $this->arrayValue($version->getAttribute('correction_policy')),
            'reversal_policy' => $this->arrayValue($version->getAttribute('reversal_policy')),
            'retention_policy' => $this->arrayValue($version->getAttribute('retention_policy')),
            'export_policy' => $this->arrayValue($version->getAttribute('export_policy')),
            'content_hash' => (string) $version->content_hash,
            'origin' => $this->s2EnumValue($version->origin) ?? (string) $version->getRawOriginal('origin'),
            'actor_type' => $version->actor_type,
            'actor_user_id' => $version->actor_user_id === null ? null : (int) $version->actor_user_id,
            'actor_display_name' => $version->actor_display_name,
            'created_at' => $this->iso8601($version->getAttribute('created_at')),
        ];
    }

    /** @return array<mixed> */
    private function arrayValue(mixed $value, bool $list = false): array
    {
        if (! is_array($value)) {
            return [];
        }

        return $list ? array_values($value) : $value;
    }

    private function s2EnumValue(mixed $value): ?string
    {
        return $value instanceof BackedEnum ? (string) $value->value : (is_string($value) ? $value : null);
    }
}
