<?php

namespace App\Domain\Conversions\Services;

use App\Domain\Conversions\Enums\ConversionDefinitionStatus;
use App\Domain\Conversions\Support\ConversionDefinitionTemplateCatalog;
use App\Domain\Conversions\Support\ConversionDefinitionValidator;
use App\Domain\Conversions\Support\ConversionServiceSupport;
use App\Models\ConversionDefinition;
use App\Models\ConversionDefinitionVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Copy system suggestions into ordinary editable workspace aggregates. */
final class CopyConversionDefinitionTemplates
{
    use ConversionServiceSupport;

    public function __construct(private readonly ConversionDefinitionValidator $validator) {}

    /**
     * @param  list<string>|null  $templateKeys
     * @return Collection<int,ConversionDefinition>
     */
    public function handle(Workspace $workspace, ?User $actor = null, ?array $templateKeys = null): Collection
    {
        $this->assertMember($workspace, $actor);
        $templates = ConversionDefinitionTemplateCatalog::all();
        if ($templateKeys !== null) {
            $templates = array_intersect_key($templates, array_flip($templateKeys));
        }

        return DB::transaction(function () use ($workspace, $actor, $templates): Collection {
            $copied = collect();
            foreach ($templates as $template) {
                $normalized = $this->validator->validate($template);
                $definition = ConversionDefinition::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('event_key', $normalized['event_key'])
                    ->lockForUpdate()
                    ->first();

                if ($definition === null) {
                    $definition = ConversionDefinition::query()->create([
                        'id' => (string) Str::uuid(),
                        'workspace_id' => $workspace->id,
                        'event_key' => $normalized['event_key'],
                        'current_version_id' => null,
                        'status' => ConversionDefinitionStatus::Active,
                        'lock_version' => 0,
                        'created_by_user_id' => $actor?->id,
                        'updated_by_user_id' => $actor?->id,
                    ]);
                    $version = ConversionDefinitionVersion::query()->create([
                        'id' => (string) Str::uuid(),
                        'workspace_id' => $workspace->id,
                        'conversion_definition_id' => $definition->id,
                        'version' => 1,
                        'schema_version' => ConversionDefinitionVersion::SCHEMA_VERSION,
                        'display_name' => $normalized['display_name'],
                        'description' => $normalized['description'],
                        'subject_type' => $normalized['subject_type'],
                        'allowed_sources' => $normalized['allowed_sources'],
                        'property_schema' => $normalized['property_schema'],
                        'allowed_identity_keys' => $normalized['allowed_identity_keys'],
                        'dedupe_strategy' => $normalized['dedupe_strategy'],
                        'dedupe_configuration' => $normalized['dedupe_configuration'],
                        'attribution_window' => $normalized['attribution_window'],
                        'value_policy' => $normalized['value_policy'],
                        'correction_policy' => $normalized['correction_policy'],
                        'reversal_policy' => $normalized['reversal_policy'],
                        'retention_policy' => $normalized['retention_policy'],
                        'export_policy' => $normalized['export_policy'],
                        'content_hash' => $this->validator->hash($normalized),
                        'origin' => 'system_template',
                        'actor_type' => 'system',
                        'actor_user_id' => null,
                        'actor_display_name' => 'System template',
                        'created_at' => now(),
                    ]);
                    $definition->forceFill(['current_version_id' => $version->id])->save();
                }
                $copied->push($definition->fresh(['currentVersion']));
            }

            return $copied;
        }, 3);
    }

    /**
     * @param  list<string>|null  $templateKeys
     * @return Collection<int,ConversionDefinition>
     */
    public function copy(Workspace $workspace, ?User $actor = null, ?array $templateKeys = null): Collection
    {
        return $this->handle($workspace, $actor, $templateKeys === null ? null : array_map('strval', $templateKeys));
    }
}
