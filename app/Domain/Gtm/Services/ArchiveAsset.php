<?php

namespace App\Domain\Gtm\Services;

use App\Domain\Gtm\Services\Concerns\AssetMutationSupport;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ArchiveAsset
{
    use AssetMutationSupport;

    public function handle(Asset $asset, User $actor, int $expectedLockVersion, ?string $clientOperationId = null): Asset
    {
        $this->assertOwner($asset, $actor);
        $operationId = $this->operationId($clientOperationId);

        return DB::transaction(function () use ($asset, $actor, $expectedLockVersion, $operationId): Asset {
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

            $fingerprint = $this->fingerprint('archive', (string) $locked->id, [], $expectedLockVersion);
            $operation = $operationId === null
                ? null
                : $this->existingOperation($locked, $operationId);
            if ($operation !== null) {
                if (! hash_equals((string) $operation->server_fingerprint, $fingerprint)) {
                    throw new RuntimeException('operation_replay_mismatch');
                }

                return $this->operationResult($locked, $operation);
            }
            if ((int) $locked->lock_version !== $expectedLockVersion) {
                throw new RuntimeException('stale_version');
            }

            if ($locked->getRawOriginal('status') !== 'archived') {
                $newLockVersion = (int) $locked->lock_version + 1;
                $locked->forceFill([
                    'status' => 'archived',
                    'lock_version' => $newLockVersion,
                ])->save();
            } else {
                $newLockVersion = (int) $locked->lock_version;
            }
            if ($operationId !== null) {
                $operation = $this->recordOperation(
                    $locked,
                    $operationId,
                    $fingerprint,
                    $expectedLockVersion,
                    $newLockVersion,
                    $current->id,
                    $current->id,
                    [
                        'asset_id' => $locked->id,
                        'version' => $current->version,
                        'lock_version' => $newLockVersion,
                        'status' => 'archived',
                        'no_op' => $newLockVersion === $expectedLockVersion,
                    ],
                );
            }

            return $this->operationResult($locked, $operation);
        }, 3);
    }

    public function archive(Asset $asset, User $actor, int $expectedLockVersion, ?string $clientOperationId = null): Asset
    {
        return $this->handle($asset, $actor, $expectedLockVersion, $clientOperationId);
    }
}
