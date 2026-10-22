<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ConversionSourceConnection;
use Illuminate\Http\Request;

class ConversionSourceConnectionResource extends ApiResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $connection = $this->model(ConversionSourceConnection::class);
        $publicId = (string) $connection->public_id;
        $kind = $this->enumValue($connection->kind) ?? (string) $connection->getRawOriginal('kind');
        $payload = [
            'id' => (string) $connection->id,
            'public_id' => $publicId,
            'webhook_url' => $kind === 'webhook' ? url('/webhooks/v1/conversions/'.$publicId) : null,
            'workspace_id' => (int) $connection->workspace_id,
            'kind' => $kind,
            'display_name' => (string) $connection->display_name,
            'status' => $this->enumValue($connection->status) ?? (string) $connection->getRawOriginal('status'),
            'lock_version' => (int) $connection->lock_version,
            'current_version_id' => $connection->current_version_id === null ? null : (string) $connection->current_version_id,
            'last_used_at' => $this->iso8601($connection->last_used_at),
            'disabled_at' => $this->iso8601($connection->disabled_at),
            'created_by_user_id' => $connection->created_by_user_id === null ? null : (int) $connection->created_by_user_id,
            'updated_by_user_id' => $connection->updated_by_user_id === null ? null : (int) $connection->updated_by_user_id,
        ];
        if ($connection->relationLoaded('currentVersion') && $connection->currentVersion !== null) {
            $version = $connection->currentVersion;
            $payload['current_version'] = [
                'id' => (string) $version->id,
                'version' => (int) $version->version,
                'status' => $this->enumValue($version->status) ?? (string) $version->getRawOriginal('status'),
                'allowed_conversion_definition_ids' => $this->stringList($version->allowed_conversion_definition_ids),
                'allowed_source_types' => $this->stringList($version->allowed_source_types),
                'signature_algorithm' => (string) $version->signature_algorithm,
                'payload_limit_bytes' => (int) $version->payload_limit_bytes,
                'replay_window_seconds' => (int) $version->replay_window_seconds,
                'valid_from' => $this->iso8601($version->valid_from),
                'overlap_until' => $this->iso8601($version->overlap_until),
            ];
        }

        return $payload;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (is_string($item) || is_int($item) || is_float($item)) {
                $result[] = (string) $item;
            }
        }

        return $result;
    }
}
