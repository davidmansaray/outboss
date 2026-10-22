<?php

namespace App\Domain\Conversions\Services;

use App\Domain\Conversions\Enums\ConversionConnectionKind;
use App\Domain\Conversions\Enums\ConversionConnectionStatus;
use App\Domain\Conversions\Enums\ConversionCredentialStatus;
use App\Domain\Conversions\Support\ConversionDefinitionValidator;
use App\Domain\Conversions\Support\ConversionServiceSupport;
use App\Models\ConversionDefinition;
use App\Models\ConversionSourceConnection;
use App\Models\ConversionSourceConnectionVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Owns per-source connection lifecycle and encrypted HMAC secret rotation. */
final class ConversionSourceConnectionManager
{
    use ConversionServiceSupport;

    /**
     * Connect a new source. The shape mirrors the existing integration
     * ConnectionManager: (workspace, kind, secret, actor, options).
     *
     * @param  array<string,mixed>  $options
     */
    public function connect(
        Workspace $workspace,
        string $kind,
        string $secret,
        ?User $actor = null,
        array $options = [],
    ): ConversionSourceConnection {
        $this->assertOwner($workspace, $actor);
        $kind = $this->kind($kind);
        $this->assertSecret($secret);
        $options = $this->options($workspace, $kind, $options);

        return DB::transaction(function () use ($workspace, $kind, $secret, $actor, $options): ConversionSourceConnection {
            $connection = ConversionSourceConnection::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'public_id' => (string) Str::uuid(),
                'kind' => $kind,
                'display_name' => $options['display_name'],
                'status' => ConversionConnectionStatus::Active,
                'current_version_id' => null,
                'last_used_at' => null,
                'disabled_at' => null,
                'created_by_user_id' => $actor?->id,
                'updated_by_user_id' => $actor?->id,
                'lock_version' => 0,
            ]);
            $version = $this->writeVersion($connection, $secret, $actor, $options, 1);
            $connection->forceFill(['current_version_id' => $version->id])->save();

            return $connection->fresh(['currentVersion']);
        }, 3);
    }

    /** @param array<string,mixed> $options */
    public function create(Workspace $workspace, User $actor, string $kind, string $secret, array $options = []): ConversionSourceConnection
    {
        return $this->connect($workspace, $kind, $secret, $actor, $options);
    }

    /**
     * Rotate a connection secret/configuration. The previous version remains
     * verifiable only through its bounded overlap interval.
     *
     * @param  array<string,mixed>  $options
     */
    public function rotate(
        ConversionSourceConnection $connection,
        string $secret,
        ?User $actor = null,
        array $options = [],
    ): ConversionSourceConnection {
        $this->assertSecret($secret);
        $workspace = $connection->workspace;
        $this->assertOwner($workspace, $actor);
        $options = $this->options($workspace, (string) $connection->getRawOriginal('kind'), $options);

        return DB::transaction(function () use ($connection, $secret, $actor, $options): ConversionSourceConnection {
            $locked = ConversionSourceConnection::query()->whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $this->assertOwner($locked->workspace, $actor);
            if ($locked->getRawOriginal('status') === ConversionConnectionStatus::Disabled->value) {
                throw new RuntimeException('conversion_connection_disabled');
            }
            $previous = $locked->currentVersion()->first();
            $nextVersion = ((int) $locked->versions()->max('version')) + 1;
            $now = now();
            $overlapUntil = $now->copy()->addSeconds((int) $options['rotation_overlap_seconds']);
            if ($previous !== null && $previous->getRawOriginal('status') !== ConversionCredentialStatus::Wiped->value) {
                // Secret/configuration rows are append-only; lifecycle metadata
                // is the one operational projection updated during rotation.
                $previous->forceFill([
                    'status' => ConversionCredentialStatus::Retiring,
                    'overlap_until' => $overlapUntil,
                    'retired_at' => $now,
                ])->save();
            }
            $version = $this->writeVersion($locked, $secret, $actor, $options, $nextVersion);
            $locked->forceFill([
                'current_version_id' => $version->id,
                'status' => ConversionConnectionStatus::Active,
                'disabled_at' => null,
                'lock_version' => (int) $locked->lock_version + 1,
                'updated_by_user_id' => $actor?->id,
            ])->save();

            return $locked->fresh(['currentVersion']);
        }, 3);
    }

    /** @param array<string,mixed> $options */
    public function rotateSecret(ConversionSourceConnection $connection, User $actor, string $secret, array $options = []): ConversionSourceConnection
    {
        return $this->rotate($connection, $secret, $actor, $options);
    }

    public function disable(ConversionSourceConnection $connection, ?User $actor = null): ConversionSourceConnection
    {
        $this->assertOwner($connection->workspace, $actor);

        return DB::transaction(function () use ($connection, $actor): ConversionSourceConnection {
            $locked = ConversionSourceConnection::query()->whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $this->assertOwner($locked->workspace, $actor);
            $locked->forceFill([
                'status' => ConversionConnectionStatus::Disabled,
                'disabled_at' => now(),
                'lock_version' => (int) $locked->lock_version + 1,
                'updated_by_user_id' => $actor?->id,
            ])->save();

            return $locked->fresh(['currentVersion']);
        }, 3);
    }

    public function remove(ConversionSourceConnection $connection, ?User $actor = null): ConversionSourceConnection
    {
        return $this->disable($connection, $actor);
    }

    /** @return list<ConversionSourceConnectionVersion> */
    public function verificationVersions(ConversionSourceConnection $connection, \DateTimeInterface $timestamp): array
    {
        if ($connection->getRawOriginal('status') !== ConversionConnectionStatus::Active->value) {
            return [];
        }

        $versions = $connection->versions()
            ->whereIn('status', [ConversionCredentialStatus::Active->value, ConversionCredentialStatus::Retiring->value])
            ->orderByDesc('version')
            ->get()
            ->all();
        $valid = [];
        foreach ($versions as $version) {
            if ($version->canVerifyAt($timestamp)) {
                $valid[] = $version;
            }
        }

        return $valid;
    }

    /** @param array<string,mixed> $options */
    private function writeVersion(ConversionSourceConnection $connection, string $secret, ?User $actor, array $options, int $versionNumber): ConversionSourceConnectionVersion
    {
        return ConversionSourceConnectionVersion::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $connection->workspace_id,
            'connection_id' => $connection->id,
            'version' => $versionNumber,
            'status' => ConversionCredentialStatus::Active,
            'encrypted_secret' => $secret,
            'allowed_conversion_definition_ids' => $options['allowed_conversion_definition_ids'],
            'allowed_source_types' => $options['allowed_source_types'],
            'signature_algorithm' => $options['signature_algorithm'],
            'payload_limit_bytes' => $options['payload_limit_bytes'],
            'replay_window_seconds' => $options['replay_window_seconds'],
            'valid_from' => now(),
            'overlap_until' => null,
            'ip_policy' => $options['ip_policy'],
            'rate_policy' => $options['rate_policy'],
            'created_by_user_id' => $actor?->id,
            'created_at' => now(),
            'retired_at' => null,
            'wiped_at' => null,
        ]);
    }

    private function kind(string $kind): string
    {
        $resolved = ConversionConnectionKind::tryFrom(strtolower(trim($kind)));
        if ($resolved === null) {
            throw ValidationException::withMessages(['kind' => 'Conversion connections must be api or webhook.']);
        }

        return $resolved->value;
    }

    private function assertSecret(string $secret): void
    {
        if (trim($secret) === '' || mb_strlen($secret) > 4096) {
            throw ValidationException::withMessages(['secret' => 'A bounded connection secret is required.']);
        }
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function options(Workspace $workspace, string $kind, array $options): array
    {
        $allowedIds = $options['allowed_conversion_definition_ids'] ?? ($options['definition_ids'] ?? []);
        if (! is_array($allowedIds) || ! array_is_list($allowedIds) || count($allowedIds) > 100) {
            throw ValidationException::withMessages(['allowed_conversion_definition_ids' => 'Definition allowlists must be bounded lists.']);
        }
        $normalizedIds = [];
        foreach ($allowedIds as $id) {
            if ($id instanceof ConversionDefinition) {
                if ((int) $id->workspace_id !== (int) $workspace->id) {
                    throw ValidationException::withMessages(['allowed_conversion_definition_ids' => 'A definition belongs to another workspace.']);
                }
                $id = $id->id;
            }
            if (! is_string($id) || trim($id) === '') {
                throw ValidationException::withMessages(['allowed_conversion_definition_ids' => 'Definition IDs must be bounded UUIDs.']);
            }
            $exists = ConversionDefinition::query()->where('workspace_id', $workspace->id)->whereKey($id)->exists();
            if (! $exists) {
                throw ValidationException::withMessages(['allowed_conversion_definition_ids' => 'Choose definitions from this workspace.']);
            }
            $normalizedIds[] = $id;
        }
        $sourceTypes = $options['allowed_source_types'] ?? ($options['source_types'] ?? [$kind]);
        if (! is_array($sourceTypes) || ! array_is_list($sourceTypes) || $sourceTypes === []) {
            throw ValidationException::withMessages(['allowed_source_types' => 'Source types must be a non-empty list.']);
        }
        foreach ($sourceTypes as $sourceType) {
            if (! is_string($sourceType) || ! in_array($sourceType, ConversionDefinitionValidator::sourceValues(), true)) {
                throw ValidationException::withMessages(['allowed_source_types' => 'A source type is invalid.']);
            }
        }
        $algorithm = (string) ($options['signature_algorithm'] ?? 'hmac_sha256');
        if ($algorithm !== 'hmac_sha256') {
            throw ValidationException::withMessages(['signature_algorithm' => 'Only HMAC-SHA256 signatures are supported in S2.']);
        }
        $payloadLimit = (int) ($options['payload_limit_bytes'] ?? 262144);
        $replayWindow = (int) ($options['replay_window_seconds'] ?? 300);
        $overlap = (int) ($options['rotation_overlap_seconds'] ?? config('conversions.rotation_overlap_seconds', 300));
        if ($payloadLimit < 1024 || $payloadLimit > 5_242_880) {
            throw ValidationException::withMessages(['payload_limit_bytes' => 'Payload limits must be between 1KB and 5MB.']);
        }
        if ($replayWindow < 30 || $replayWindow > 3600) {
            throw ValidationException::withMessages(['replay_window_seconds' => 'Replay windows must be between 30 seconds and one hour.']);
        }
        if ($overlap < 0 || $overlap > 86400) {
            throw ValidationException::withMessages(['rotation_overlap_seconds' => 'Secret overlap must be at most one day.']);
        }

        return [
            'display_name' => trim((string) ($options['display_name'] ?? ucfirst($kind).' conversion source')),
            'allowed_conversion_definition_ids' => array_values(array_unique($normalizedIds)),
            'allowed_source_types' => array_values(array_unique($sourceTypes)),
            'signature_algorithm' => $algorithm,
            'payload_limit_bytes' => $payloadLimit,
            'replay_window_seconds' => $replayWindow,
            'rotation_overlap_seconds' => $overlap,
            'ip_policy' => is_array($options['ip_policy'] ?? null) ? $options['ip_policy'] : null,
            'rate_policy' => is_array($options['rate_policy'] ?? null) ? $options['rate_policy'] : null,
        ];
    }
}
