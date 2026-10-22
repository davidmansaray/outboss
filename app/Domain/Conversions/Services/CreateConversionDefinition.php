<?php

namespace App\Domain\Conversions\Services;

use App\Domain\Conversions\Enums\ConversionDefinitionStatus;
use App\Domain\Conversions\Support\CanonicalConversionPayload;
use App\Domain\Conversions\Support\ConversionDefinitionValidator;
use App\Domain\Conversions\Support\ConversionServiceSupport;
use App\Models\ConversionDefinition;
use App\Models\ConversionDefinitionVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class CreateConversionDefinition
{
    use ConversionServiceSupport;

    public function __construct(private readonly ConversionDefinitionValidator $validator) {}

    /**
     * The normal call shape is (workspace, actor, attributes). The compact
     * (workspace, attributes) form is useful for system/template backfills.
     *
     * @param  User|array<string,mixed>|null  $actorOrAttributes
     * @param  array<string,mixed>|User|null  $attributesOrActor
     */
    public function handle(
        Workspace $workspace,
        User|array|null $actorOrAttributes,
        array|User|null $attributesOrActor = null,
        ?string $clientOperationId = null,
    ): ConversionDefinition {
        [$actor, $attributes] = $this->arguments($actorOrAttributes, $attributesOrActor);
        $this->assertMember($workspace, $actor);
        $normalized = $this->validator->validate($attributes);
        $this->requireBoundedJson($normalized, 'definition');

        return DB::transaction(function () use ($workspace, $actor, $normalized): ConversionDefinition {
            $existing = ConversionDefinition::query()
                ->where('workspace_id', $workspace->id)
                ->where('event_key', $normalized['event_key'])
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                // A caller may safely replay a template/create request only
                // when it asks for the same immutable first version.
                $current = $existing->currentVersion()->first();
                if ($current !== null && hash_equals((string) $current->content_hash, $this->validator->hash($normalized))) {
                    return $existing->fresh(['currentVersion']);
                }
                throw new RuntimeException('conversion_definition_already_exists');
            }

            $definition = ConversionDefinition::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'event_key' => $normalized['event_key'],
                'current_version_id' => null,
                'status' => ConversionDefinitionStatus::Draft,
                'lock_version' => 0,
                'created_by_user_id' => $actor?->id,
                'updated_by_user_id' => $actor?->id,
            ]);
            $version = $this->writeVersion($definition, $normalized, $actor, 'created');
            $definition->forceFill(['current_version_id' => $version->id])->save();

            return $definition->fresh(['currentVersion']);
        }, 3);
    }

    /** @param array<string,mixed> $attributes */
    public function create(Workspace $workspace, User $actor, array $attributes, ?string $clientOperationId = null): ConversionDefinition
    {
        return $this->handle($workspace, $actor, $attributes, $clientOperationId);
    }

    /**
     * @param  User|array<string,mixed>|null  $actorOrAttributes
     * @param  array<string,mixed>|User|null  $attributesOrActor
     * @return array{0:User|null,1:array<string,mixed>}
     */
    private function arguments(User|array|null $actorOrAttributes, array|User|null $attributesOrActor): array
    {
        if (is_array($actorOrAttributes)) {
            return [$attributesOrActor instanceof User ? $attributesOrActor : null, $actorOrAttributes];
        }
        if (! is_array($attributesOrActor)) {
            throw ValidationException::withMessages(['definition' => 'Definition attributes are required.']);
        }

        return [$actorOrAttributes, $attributesOrActor];
    }

    /** @param array<string,mixed> $payload */
    public function writeVersion(ConversionDefinition $definition, array $payload, ?User $actor = null, string $origin = 'created'): ConversionDefinitionVersion
    {
        $versionNumber = ((int) $definition->versions()->max('version')) + 1;

        return ConversionDefinitionVersion::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $definition->workspace_id,
            'conversion_definition_id' => $definition->id,
            'version' => $versionNumber,
            'schema_version' => ConversionDefinitionVersion::SCHEMA_VERSION,
            'display_name' => $payload['display_name'],
            'description' => $payload['description'],
            'subject_type' => $payload['subject_type'],
            'allowed_sources' => $payload['allowed_sources'],
            'property_schema' => $payload['property_schema'],
            'allowed_identity_keys' => $payload['allowed_identity_keys'],
            'dedupe_strategy' => $payload['dedupe_strategy'],
            'dedupe_configuration' => $payload['dedupe_configuration'],
            'attribution_window' => $payload['attribution_window'],
            'value_policy' => $payload['value_policy'],
            'correction_policy' => $payload['correction_policy'],
            'reversal_policy' => $payload['reversal_policy'],
            'retention_policy' => $payload['retention_policy'],
            'export_policy' => $payload['export_policy'],
            'content_hash' => CanonicalConversionPayload::hash($payload),
            'origin' => $origin,
            'actor_type' => $actor === null ? 'system' : 'owner',
            'actor_user_id' => $actor?->id,
            'actor_display_name' => $actor === null ? 'System' : $actor->name,
            'created_at' => now(),
        ]);
    }
}
