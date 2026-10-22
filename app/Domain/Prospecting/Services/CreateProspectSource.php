<?php

namespace App\Domain\Prospecting\Services;

use App\Domain\Prospecting\DTOs\SourcePolicySnapshot;
use App\Domain\Prospecting\Enums\ProspectSourceKind;
use App\Domain\Prospecting\Enums\ProspectSourceStatus;
use App\Domain\Prospecting\Enums\SourceVersionOrigin;
use App\Domain\Prospecting\Support\CanonicalSourceConfiguration;
use App\Domain\Prospecting\Support\SourceConfigurationValidator;
use App\Models\ProspectSource;
use App\Models\ProspectSourceVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateProspectSource
{
    public function __construct(private readonly SourceConfigurationValidator $validator) {}

    /**
     * Create a source aggregate and its first immutable configuration/policy
     * version. Existing workspace system sources are returned idempotently.
     *
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $policy
     */
    public function handle(
        Workspace $workspace,
        ProspectSourceKind|string $kind,
        string $displayName,
        array $configuration = [],
        array $policy = [],
        ?User $actor = null,
        string $origin = 'user',
        ?string $systemKey = null,
    ): ProspectSource {
        $kind = $kind instanceof ProspectSourceKind ? $kind : ProspectSourceKind::from(strtolower(trim($kind)));
        if (! $kind->isEnabledInS1()) {
            throw ValidationException::withMessages([
                'kind' => 'This prospect source kind is not enabled in S1.',
            ]);
        }
        $displayName = $this->name($displayName);
        $configuration = $this->validator->validate($kind, $configuration);
        $policy = $this->validator->validatePolicy($policy === [] ? SourcePolicySnapshot::native()->policy : $policy);
        $originEnum = SourceVersionOrigin::tryFrom($origin);
        $origin = $originEnum === null ? SourceVersionOrigin::User->value : $originEnum->value;
        $nameNormalized = $this->normalizeName($displayName);

        return DB::transaction(function () use (
            $workspace,
            $kind,
            $displayName,
            $configuration,
            $policy,
            $actor,
            $origin,
            $systemKey,
            $nameNormalized,
        ): ProspectSource {
            $existing = $systemKey === null ? null : ProspectSource::query()
                ->where('workspace_id', $workspace->id)
                ->where('system_key', $systemKey)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                $existingKind = ProspectSourceKind::from((string) $existing->getRawOriginal('kind'));
                if ($existingKind !== $kind || ! $existing->currentVersion()->exists()) {
                    throw ValidationException::withMessages(['system_key' => 'The workspace system source has incompatible configuration.']);
                }

                return $existing->fresh(['currentVersion']);
            }

            if (ProspectSource::query()
                ->where('workspace_id', $workspace->id)
                ->where('active_name_normalized', $nameNormalized)
                ->exists()) {
                throw ValidationException::withMessages([
                    'display_name' => 'Another active prospect source already uses this name.',
                ]);
            }

            $source = ProspectSource::query()->create([
                'workspace_id' => $workspace->id,
                'status' => ProspectSourceStatus::Active,
                'kind' => $kind,
                'system_key' => $systemKey,
                'display_name' => $displayName,
                'name_normalized' => $nameNormalized,
                'active_name_normalized' => $nameNormalized,
                'owner_user_id' => $actor?->id,
                'lock_version' => 0,
            ]);
            $version = $this->createVersion($source, $configuration, $policy, $actor, $origin);
            $source->forceFill(['current_version_id' => $version->id])->save();

            return $source->fresh(['currentVersion']);
        }, 3);
    }

    /**
     * Ensure a deterministic workspace system source exists.
     *
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $policy
     */
    public function ensureSystemSource(
        Workspace $workspace,
        string $systemKey,
        ?User $actor = null,
        ?ProspectSourceKind $kind = null,
        ?string $displayName = null,
        array $configuration = [],
        array $policy = [],
        string $origin = 'system',
    ): ProspectSource {
        $kind ??= match ($systemKey) {
            'rest_api', 'mcp' => ProspectSourceKind::ApiImport,
            'legacy_import' => ProspectSourceKind::CsvImport,
            'legacy_campaign_backfill', 'unknown_legacy_source' => ProspectSourceKind::ExistingAccounts,
            default => ProspectSourceKind::Manual,
        };
        $displayName ??= match ($systemKey) {
            'rest_api' => 'REST API',
            'mcp' => 'MCP',
            'legacy_manual' => 'Legacy manual',
            'legacy_import' => 'Legacy import',
            'legacy_campaign_backfill' => 'Legacy campaign backfill',
            'unknown_legacy_source' => 'Unknown legacy source',
            default => ucfirst(str_replace('_', ' ', $systemKey)),
        };

        return $this->handle($workspace, $kind, $displayName, $configuration, $policy, $actor, $origin, $systemKey);
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $policy
     */
    private function createVersion(
        ProspectSource $source,
        array $configuration,
        array $policy,
        ?User $actor,
        string $origin,
    ): ProspectSourceVersion {
        return ProspectSourceVersion::query()->create([
            'prospect_source_id' => $source->id,
            'workspace_id' => $source->workspace_id,
            'version' => 1,
            'schema_version' => ProspectSourceVersion::SCHEMA_VERSION,
            'configuration' => $configuration,
            'configuration_hash' => CanonicalSourceConfiguration::hash($configuration),
            'policy_snapshot' => $policy,
            'policy_hash' => SourcePolicySnapshot::hashPolicy($policy),
            'capability' => $configuration['capability'] ?? null,
            'discovery_motion' => $configuration['discovery_motion'] ?? null,
            'source_family' => $configuration['source_family'] ?? null,
            'provider_registration_id' => $configuration['provider_registration_id'] ?? null,
            'provider_registration_version' => $configuration['provider_registration_version'] ?? null,
            'origin' => $origin,
            'actor_type' => $actor === null ? 'system' : 'user',
            'actor_user_id' => $actor?->id,
            'actor_display_name' => $actor?->name,
            'created_at' => now(),
        ]);
    }

    private function name(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if ($name === '' || mb_strlen($name) > (int) config('prospecting.limits.source_name_max_length', 160)) {
            throw ValidationException::withMessages(['display_name' => 'Enter a source name of 160 characters or fewer.']);
        }

        return $name;
    }

    private function normalizeName(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
    }
}
