<?php

namespace App\Domain\Integrations\Connections;

use App\Domain\Integrations\Enums\IntegrationConnectionStatus;
use App\Domain\Integrations\Enums\IntegrationCredentialStatus;
use App\Domain\Integrations\Exceptions\CapabilityUnavailable;
use App\Models\IntegrationConnection;
use App\Models\IntegrationCredentialVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ConnectionManager
{
    public function connect(
        Workspace $workspace,
        string $provider,
        string $secret,
        ?User $actor = null,
    ): IntegrationConnection {
        $this->assertActor($workspace, $actor);
        if (trim($secret) === '') {
            throw new RuntimeException('An integration credential is required.');
        }
        $this->assertProviderEnabled($provider);

        return DB::transaction(function () use ($workspace, $provider, $secret, $actor): IntegrationConnection {
            $connection = IntegrationConnection::query()
                ->where('workspace_id', $workspace->id)
                ->where('provider', $provider)
                ->lockForUpdate()
                ->first();
            if ($connection === null) {
                $connection = IntegrationConnection::query()->create([
                    'workspace_id' => $workspace->id,
                    'provider' => $provider,
                    'status' => IntegrationConnectionStatus::Pending,
                    'created_by_user_id' => $actor?->id,
                    'updated_by_user_id' => $actor?->id,
                ]);
            }

            $previous = $connection->activeCredentialVersion;
            $nextVersion = ((int) $connection->credentialVersions()->max('version')) + 1;
            $version = $connection->credentialVersions()->create([
                'workspace_id' => $workspace->id,
                'version' => $nextVersion,
                'encrypted_secret' => $secret,
                'status' => IntegrationCredentialStatus::Active,
                'created_by_user_id' => $actor?->id,
                'tested_at' => null,
                'activated_at' => now(),
                'retire_after' => null,
                'wiped_at' => null,
            ]);
            if ($previous !== null && $previous->id !== $version->id && $previous->wiped_at === null) {
                $previous->forceFill([
                    'status' => IntegrationCredentialStatus::Retiring,
                    'retire_after' => now()->addDays((int) config('integrations.credential_rotation_grace_days', 7)),
                ])->save();
            }

            $connection->forceFill([
                'active_credential_version_id' => $version->id,
                'status' => IntegrationConnectionStatus::Healthy,
                'last_tested_at' => null,
                'last_healthy_at' => now(),
                'retry_after_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
                'updated_by_user_id' => $actor?->id,
            ])->save();

            return $connection->fresh();
        }, 3);
    }

    public function rotate(
        Workspace $workspace,
        string $provider,
        string $secret,
        ?User $actor = null,
    ): IntegrationConnection {
        return $this->connect($workspace, $provider, $secret, $actor);
    }

    public function disable(IntegrationConnection $connection, ?User $actor = null): IntegrationConnection
    {
        $this->assertActor($connection->workspace, $actor);

        return DB::transaction(function () use ($connection, $actor): IntegrationConnection {
            $locked = IntegrationConnection::query()->whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'status' => IntegrationConnectionStatus::Disabled,
                'updated_by_user_id' => $actor?->id,
            ])->save();

            return $locked->fresh();
        }, 3);
    }

    public function remove(IntegrationConnection $connection, ?User $actor = null): IntegrationConnection
    {
        $this->assertActor($connection->workspace, $actor);

        return DB::transaction(function () use ($connection, $actor): IntegrationConnection {
            $locked = IntegrationConnection::query()->whereKey($connection->id)->lockForUpdate()->firstOrFail();
            foreach ($locked->credentialVersions()->whereNull('wiped_at')->lockForUpdate()->get() as $version) {
                $version->forceFill([
                    'encrypted_secret' => null,
                    'status' => IntegrationCredentialStatus::Wiped,
                    'wiped_at' => now(),
                ])->save();
            }
            $locked->forceFill([
                'status' => IntegrationConnectionStatus::Removed,
                'active_credential_version_id' => null,
                'updated_by_user_id' => $actor?->id,
            ])->save();

            return $locked->fresh();
        }, 3);
    }

    public function activeCredential(IntegrationConnection $connection): IntegrationCredentialVersion
    {
        $connection = $connection->fresh(['activeCredentialVersion']) ?? $connection;
        $version = $connection->activeCredentialVersion;
        if (! $connection->status->usable()
            || $version === null
            || ! $version->isUsable()) {
            throw new RuntimeException('The integration connection is not healthy.');
        }

        return $version;
    }

    private function assertProviderEnabled(string $provider): void
    {
        $definition = config("integrations.providers.{$provider}");
        if (! is_array($definition) || ! ($definition['installed'] ?? false) || ! ($definition['enabled'] ?? false)) {
            $reason = is_array($definition) ? (string) ($definition['availability_reason'] ?? 'unavailable') : 'unknown_provider';
            throw new CapabilityUnavailable($provider, $reason);
        }
    }

    private function assertActor(Workspace $workspace, ?User $actor): void
    {
        if ($actor !== null && $workspace->membershipFor($actor) === null) {
            throw new RuntimeException('The actor is not a member of this workspace.');
        }
    }
}
