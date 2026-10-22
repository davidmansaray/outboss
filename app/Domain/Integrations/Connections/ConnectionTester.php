<?php

namespace App\Domain\Integrations\Connections;

use App\Domain\Integrations\Enums\IntegrationConnectionStatus;
use App\Domain\Integrations\Enums\IntegrationCredentialStatus;
use App\Models\IntegrationConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Local, bounded credential test. Provider-specific network tests belong to
 * a later adapter and are intentionally not run by S1.
 */
final class ConnectionTester
{
    public function test(IntegrationConnection $connection, ?User $actor = null): IntegrationConnection
    {
        if ($actor !== null && $connection->workspace->membershipFor($actor) === null) {
            throw new RuntimeException('The actor is not a member of this workspace.');
        }

        return DB::transaction(function () use ($connection, $actor): IntegrationConnection {
            $locked = IntegrationConnection::query()
                ->with('activeCredentialVersion')
                ->whereKey($connection->id)
                ->lockForUpdate()
                ->firstOrFail();
            $version = $locked->activeCredentialVersion;
            if ($version === null
                || $version->status !== IntegrationCredentialStatus::Active
                || $version->wiped_at !== null
                || ! is_string($version->encrypted_secret)
                || $version->encrypted_secret === '') {
                $locked->forceFill([
                    'status' => IntegrationConnectionStatus::Misconfigured,
                    'last_error_code' => 'credential_unavailable',
                    'last_error_message' => 'The active credential is unavailable.',
                    'updated_by_user_id' => $actor?->id,
                ])->save();

                return $locked->fresh();
            }

            $locked->forceFill([
                'status' => IntegrationConnectionStatus::Healthy,
                'last_tested_at' => now(),
                'last_healthy_at' => now(),
                'retry_after_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
                'updated_by_user_id' => $actor?->id,
            ])->save();
            $version->forceFill(['tested_at' => now()])->save();

            return $locked->fresh();
        }, 3);
    }
}
