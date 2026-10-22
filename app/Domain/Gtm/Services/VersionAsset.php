<?php

namespace App\Domain\Gtm\Services;

use App\Domain\Gtm\Services\Concerns\AssetMutationSupport;
use App\Domain\Gtm\Support\CanonicalAssetPayload;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class VersionAsset
{
    use AssetMutationSupport;

    /** @param array<string,mixed> $attributes */
    public function handle(
        Asset $asset,
        User $actor,
        array $attributes,
        int $expectedLockVersion,
        ?string $clientOperationId = null,
    ): Asset {
        $this->assertOwner($asset, $actor);
        $operationId = $this->operationId($clientOperationId);

        return DB::transaction(function () use ($asset, $actor, $attributes, $expectedLockVersion, $operationId): Asset {
            $locked = Asset::query()
                ->where('workspace_id', $asset->workspace_id)
                ->whereKey($asset->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertOwner($locked, $actor);
            $current = $locked->currentVersion()->first();
            if ($current === null) {
                throw new RuntimeException('asset_version_missing');
            }

            $operation = $operationId === null
                ? null
                : $this->existingOperation($locked, $operationId);
            $fingerprintBase = $operation?->expectedVersion()->first() ?? $current;
            $payload = $this->assetPayload($attributes, $fingerprintBase);
            $fingerprint = $this->fingerprint('version', (string) $locked->id, $payload, $expectedLockVersion);
            if ($operation !== null) {
                if (! hash_equals((string) $operation->server_fingerprint, $fingerprint)) {
                    throw new RuntimeException('operation_replay_mismatch');
                }

                return $this->operationResult($locked, $operation);
            }
            if ((int) $locked->lock_version !== $expectedLockVersion) {
                throw new RuntimeException('stale_version');
            }
            if ($locked->getRawOriginal('status') === 'archived') {
                throw ValidationException::withMessages(['status' => 'Archived Assets cannot receive new versions.']);
            }

            if (CanonicalAssetPayload::hash($payload) === (string) $current->content_hash) {
                if ($operationId !== null) {
                    $operation = $this->recordOperation(
                        $locked,
                        $operationId,
                        $fingerprint,
                        $expectedLockVersion,
                        (int) $locked->lock_version,
                        $current->id,
                        $current->id,
                        [
                            'asset_id' => $locked->id,
                            'version' => $current->version,
                            'lock_version' => $locked->lock_version,
                            'status' => (string) $locked->getRawOriginal('status'),
                            'no_op' => true,
                        ],
                    );
                }

                return $this->operationResult($locked, $operation);
            }

            $version = $this->writeVersion($locked, $actor, $payload);
            $newLockVersion = (int) $locked->lock_version + 1;
            $locked->forceFill([
                'current_version_id' => $version->id,
                'lock_version' => $newLockVersion,
            ])->save();
            if ($operationId !== null) {
                $operation = $this->recordOperation(
                    $locked,
                    $operationId,
                    $fingerprint,
                    $expectedLockVersion,
                    $newLockVersion,
                    $current->id,
                    $version->id,
                    [
                        'asset_id' => $locked->id,
                        'version' => $version->version,
                        'lock_version' => $newLockVersion,
                        'status' => (string) $locked->getRawOriginal('status'),
                        'no_op' => false,
                    ],
                );
            }

            return $this->operationResult($locked, $operation);
        }, 3);
    }

    /** @param array<string,mixed> $attributes */
    public function version(Asset $asset, User $actor, array $attributes, int $expectedLockVersion, ?string $clientOperationId = null): Asset
    {
        return $this->handle($asset, $actor, $attributes, $expectedLockVersion, $clientOperationId);
    }
}
