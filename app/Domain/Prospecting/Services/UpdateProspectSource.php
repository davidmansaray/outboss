<?php

namespace App\Domain\Prospecting\Services;

use App\Domain\Prospecting\DTOs\SourcePolicySnapshot;
use App\Domain\Prospecting\Enums\ProspectSourceKind;
use App\Domain\Prospecting\Enums\ProspectSourceStatus;
use App\Domain\Prospecting\Enums\SourceVersionOrigin;
use App\Domain\Prospecting\Exceptions\SourceRunConflict;
use App\Domain\Prospecting\Support\CanonicalSourceConfiguration;
use App\Domain\Prospecting\Support\SourceConfigurationValidator;
use App\Models\ProspectSource;
use App\Models\ProspectSourceVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateProspectSource
{
    public function __construct(private readonly SourceConfigurationValidator $validator) {}

    /**
     * Update lifecycle/name and, when supplied, append a new immutable source
     * version. A source version itself is never modified.
     *
     * @param  array<string,mixed>|null  $configuration
     * @param  array<string,mixed>|null  $policy
     */
    public function handle(
        ProspectSource $source,
        ?string $displayName = null,
        ProspectSourceStatus|string|null $status = null,
        ?array $configuration = null,
        ?array $policy = null,
        ?User $actor = null,
        string $origin = 'user',
    ): ProspectSource {
        return DB::transaction(function () use ($source, $displayName, $status, $configuration, $policy, $actor, $origin): ProspectSource {
            $locked = ProspectSource::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            $nextStatus = $status === null
                ? ProspectSourceStatus::from((string) $locked->getRawOriginal('status'))
                : ($status instanceof ProspectSourceStatus ? $status : ProspectSourceStatus::from($status));
            if ($locked->deletion_requested_at !== null && $nextStatus !== ProspectSourceStatus::Archived) {
                throw new SourceRunConflict('A source with queued deletion cannot be reactivated.');
            }

            $name = $displayName === null ? (string) $locked->display_name : trim(preg_replace('/\s+/u', ' ', $displayName) ?? $displayName);
            if ($name === '' || mb_strlen($name) > (int) config('prospecting.limits.source_name_max_length', 160)) {
                throw ValidationException::withMessages(['display_name' => 'Enter a source name of 160 characters or fewer.']);
            }
            $normalized = mb_strtolower($name);
            if ($nextStatus !== ProspectSourceStatus::Archived && ProspectSource::query()
                ->where('workspace_id', $locked->workspace_id)
                ->where('active_name_normalized', $normalized)
                ->where('id', '!=', $locked->id)
                ->exists()) {
                throw ValidationException::withMessages(['display_name' => 'Another active prospect source already uses this name.']);
            }

            $locked->forceFill([
                'display_name' => $name,
                'name_normalized' => $normalized,
                'active_name_normalized' => $nextStatus === ProspectSourceStatus::Archived ? null : $normalized,
                'status' => $nextStatus,
                'lock_version' => (int) $locked->lock_version + 1,
            ])->save();

            if ($configuration !== null || $policy !== null) {
                $current = $locked->currentVersion()->firstOrFail();
                $canonicalConfiguration = $this->validator->validate(
                    ProspectSourceKind::from((string) $locked->getRawOriginal('kind')),
                    $configuration ?? (array) $current->configuration,
                );
                $canonicalPolicy = $this->validator->validatePolicy($policy ?? (array) $current->policy_snapshot);
                $nextVersion = (int) $locked->versions()->max('version') + 1;
                $version = ProspectSourceVersion::query()->create([
                    'prospect_source_id' => $locked->id,
                    'workspace_id' => $locked->workspace_id,
                    'version' => $nextVersion,
                    'schema_version' => ProspectSourceVersion::SCHEMA_VERSION,
                    'configuration' => $canonicalConfiguration,
                    'configuration_hash' => CanonicalSourceConfiguration::hash($canonicalConfiguration),
                    'policy_snapshot' => $canonicalPolicy,
                    'policy_hash' => SourcePolicySnapshot::hashPolicy($canonicalPolicy),
                    'capability' => $canonicalConfiguration['capability'] ?? null,
                    'discovery_motion' => $canonicalConfiguration['discovery_motion'] ?? null,
                    'source_family' => $canonicalConfiguration['source_family'] ?? null,
                    'provider_registration_id' => $canonicalConfiguration['provider_registration_id'] ?? null,
                    'provider_registration_version' => $canonicalConfiguration['provider_registration_version'] ?? null,
                    'origin' => ($originEnum = SourceVersionOrigin::tryFrom($origin)) === null
                        ? SourceVersionOrigin::User->value
                        : $originEnum->value,
                    'actor_type' => $actor === null ? 'system' : 'user',
                    'actor_user_id' => $actor?->id,
                    'actor_display_name' => $actor?->name,
                    'created_at' => now(),
                ]);
                $locked->forceFill(['current_version_id' => $version->id])->save();
            }

            return $locked->fresh(['currentVersion']);
        }, 3);
    }

    public function archive(ProspectSource $source, ?User $actor = null): ProspectSource
    {
        return $this->handle($source, status: ProspectSourceStatus::Archived, actor: $actor);
    }
}
