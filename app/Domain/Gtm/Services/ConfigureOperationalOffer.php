<?php

namespace App\Domain\Gtm\Services;

use App\Models\AssetVersion;
use App\Models\Offer;
use App\Models\OfferVersionAsset;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConfigureOperationalOffer
{
    public function __construct(private readonly UpdateOffer $update) {}

    /** @param array<string, mixed> $payload */
    public function handle(Offer $offer, User $actor, array $payload, int $expectedLockVersion, string $operationId): Offer
    {
        $payload['schema_version'] = 2;

        return DB::transaction(function () use ($offer, $actor, $payload, $expectedLockVersion, $operationId): Offer {
            $updated = $this->update->handle($offer, $actor, $payload, $expectedLockVersion, $operationId);
            $updated->loadMissing('currentVersion');
            $ids = $updated->currentVersion?->payload['fulfilment']['asset_version_ids'] ?? [];
            $ids = is_array($ids) ? array_values(array_unique(array_filter($ids, 'is_string'))) : [];
            $assets = AssetVersion::query()
                ->where('workspace_id', $updated->workspace_id)
                ->whereIn('id', $ids)
                ->get()
                ->keyBy('id');
            if ($assets->count() !== count($ids)) {
                throw ValidationException::withMessages(['fulfilment.asset_version_ids' => 'Every Asset Version must belong to this workspace.']);
            }

            OfferVersionAsset::query()
                ->where('workspace_id', $updated->workspace_id)
                ->where('offer_version_id', $updated->current_version_id)
                ->delete();
            foreach ($ids as $id) {
                $asset = $assets->get($id);
                OfferVersionAsset::query()->create([
                    'workspace_id' => $updated->workspace_id,
                    'offer_id' => $updated->id,
                    'offer_version_id' => $updated->current_version_id,
                    'asset_id' => $asset->asset_id,
                    'asset_version_id' => $asset->id,
                    'role' => 'deliverable',
                    'delivery_rule' => ['mode' => 'exact_version'],
                ]);
            }

            $updated->forceFill([
                'availability_state' => 'incomplete',
                'current_readiness_evaluation_id' => null,
            ])->save();

            return $updated->fresh(['currentVersion']);
        }, 3);
    }
}
