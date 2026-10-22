<?php

namespace App\Domain\Conversions\Services;

use App\Domain\Conversions\Support\CanonicalConversionPayload;
use App\Domain\Conversions\Support\ConversionDefinitionValidator;
use App\Domain\Conversions\Support\ConversionServiceSupport;
use App\Models\ConversionDefinition;
use App\Models\ConversionDefinitionVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class VersionConversionDefinition
{
    use ConversionServiceSupport;

    public function __construct(
        private readonly ConversionDefinitionValidator $validator,
        private readonly CreateConversionDefinition $creator,
    ) {}

    /** @param array<string,mixed> $attributes */
    public function handle(
        ConversionDefinition $definition,
        User $actor,
        array $attributes,
        ?int $expectedLockVersion = null,
        ?string $clientOperationId = null,
    ): ConversionDefinition {
        $this->assertMember($definition->workspace, $actor);
        $locked = DB::transaction(function () use ($definition, $actor, $attributes, $expectedLockVersion): ConversionDefinition {
            $locked = ConversionDefinition::query()->whereKey($definition->id)->lockForUpdate()->firstOrFail();
            $this->assertMember($locked->workspace, $actor);
            if ($expectedLockVersion !== null && (int) $locked->lock_version !== $expectedLockVersion) {
                throw new RuntimeException('stale_version');
            }
            $current = $locked->currentVersion()->firstOrFail();
            $candidate = array_replace($this->payload($current), $attributes);
            $candidate['event_key'] = $locked->event_key;
            $candidate['display_name'] = $candidate['display_name'] ?? $current->display_name;
            $normalized = $this->validator->validate($candidate);
            if (hash_equals((string) $current->content_hash, CanonicalConversionPayload::hash($normalized))) {
                return $locked->fresh(['currentVersion']);
            }
            $version = $this->creator->writeVersion($locked, $normalized, $actor, 'owner_edit');
            $locked->forceFill([
                'current_version_id' => $version->id,
                'lock_version' => (int) $locked->lock_version + 1,
                'updated_by_user_id' => $actor->id,
            ])->save();

            return $locked->fresh(['currentVersion']);
        }, 3);

        return $locked;
    }

    /** @param array<string,mixed> $attributes */
    public function version(ConversionDefinition $definition, User $actor, array $attributes, ?int $expectedLockVersion = null, ?string $clientOperationId = null): ConversionDefinition
    {
        return $this->handle($definition, $actor, $attributes, $expectedLockVersion, $clientOperationId);
    }

    /** @return array<string,mixed> */
    private function payload(ConversionDefinitionVersion $version): array
    {
        return [
            'event_key' => $version->definition?->event_key,
            'display_name' => $version->display_name,
            'description' => $version->description,
            'subject_type' => $version->subject_type,
            'allowed_sources' => $version->allowed_sources,
            'property_schema' => $version->property_schema,
            'allowed_identity_keys' => $version->allowed_identity_keys,
            'dedupe_strategy' => $version->dedupe_strategy,
            'dedupe_configuration' => $version->dedupe_configuration,
            'attribution_window' => $version->attribution_window,
            'value_policy' => $version->value_policy,
            'correction_policy' => $version->correction_policy,
            'reversal_policy' => $version->reversal_policy,
            'retention_policy' => $version->retention_policy,
            'export_policy' => $version->export_policy,
        ];
    }
}
