<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Asset;
use App\Models\AssetVersion;
use Illuminate\Http\Request;

/**
 * Bounded Asset projection shared by REST and MCP.
 *
 * Access instructions, delivery restrictions and tracking metadata are
 * intentionally never returned. Restricted versions additionally omit their
 * URL and descriptive/source fields; callers can still identify the exact
 * immutable version and see its safe lifecycle state without receiving raw or
 * restricted material.
 */
class AssetResource extends ApiResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $asset = $this->model(Asset::class);
        $payload = [
            'id' => (string) $asset->id,
            'workspace_id' => (int) $asset->workspace_id,
            'status' => (string) $asset->getRawOriginal('status'),
            'lock_version' => (int) $asset->lock_version,
            'current_version_id' => $asset->current_version_id === null ? null : (string) $asset->current_version_id,
            'created_by_user_id' => $asset->created_by_user_id === null ? null : (int) $asset->created_by_user_id,
            'created_at' => $this->iso8601($asset->getAttribute('created_at')),
            'updated_at' => $this->iso8601($asset->getAttribute('updated_at')),
        ];

        if ($asset->relationLoaded('currentVersion') && $asset->currentVersion !== null) {
            $payload['current_version'] = $this->version($asset->currentVersion);
        }
        if ($asset->relationLoaded('versions')) {
            $payload['versions'] = $asset->versions
                ->sortByDesc('version')
                ->map(fn (AssetVersion $version): array => $this->version($version))
                ->values()
                ->all();
        }

        return $payload;
    }

    /** @return array<string,mixed> */
    private function version(AssetVersion $version): array
    {
        $classification = (string) $version->getRawOriginal('classification');
        $restricted = $classification === 'restricted';
        $payload = [
            'id' => (string) $version->id,
            'version' => (int) $version->version,
            'title' => (string) $version->title,
            'media_type' => (string) $version->media_type,
            'approval_state' => (string) $version->getRawOriginal('approval_state'),
            'availability_state' => (string) $version->getRawOriginal('availability_state'),
            'expires_at' => $this->iso8601($version->getAttribute('expires_at')),
            'classification' => $classification,
            'content_hash' => (string) $version->content_hash,
            'created_at' => $this->iso8601($version->getAttribute('created_at')),
            'restricted' => $restricted,
            'has_access_instructions' => $version->access_instructions !== [],
            'has_delivery_restrictions' => $version->delivery_restrictions !== [],
            'has_tracking_metadata' => $version->tracking_metadata !== [],
        ];

        if (! $restricted) {
            $payload += [
                'description' => $version->description,
                'external_url' => (string) $version->external_url,
                'source' => $version->source,
                'intended_audience' => array_values($version->intended_audience),
                'licence' => $version->licence,
            ];
        } else {
            $payload['omission_reason'] = 'restricted_asset_metadata';
        }

        return $payload;
    }
}
