<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** @property mixed $resource */
class ProspectSourceResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $source = is_array($this->resource)
            ? $this->resource
            : ($this->resource instanceof Model
                ? $this->resource->getAttributes()
                : (is_object($this->resource) ? get_object_vars($this->resource) : []));

        $payload = [
            'id' => isset($source['id']) ? (string) $source['id'] : null,
            'display_name' => $source['display_name'] ?? $source['name'] ?? null,
            'kind' => $source['kind'] ?? null,
            'status' => $source['status'] ?? null,
            'current_version_id' => isset($source['current_version_id']) ? (string) $source['current_version_id'] : null,
            'last_run_id' => isset($source['last_run_id']) ? (string) $source['last_run_id'] : null,
            'last_run' => $source['last_run'] ?? null,
            'run_count' => (int) ($source['run_count'] ?? 0),
            'accepted_count' => (int) ($source['accepted_count'] ?? 0),
            'created_count' => (int) ($source['created_count'] ?? 0),
            'linked_count' => (int) ($source['linked_count'] ?? 0),
            'suppressed_count' => (int) ($source['suppressed_count'] ?? 0),
            'rejected_count' => (int) ($source['rejected_count'] ?? 0),
            'restrictions' => array_values(array_filter((array) ($source['restrictions'] ?? []), 'is_string')),
            'retention_warning' => $source['retention_warning'] ?? null,
            'created_at' => $source['created_at'] ?? null,
            'updated_at' => $source['updated_at'] ?? null,
        ];

        if (array_key_exists('versions', $source)) {
            $payload['versions'] = $this->safeVersions($source['versions']);
        }

        if (array_key_exists('runs', $source)) {
            $payload['runs'] = $this->safeRuns($source['runs']);
        }

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function safeVersions(mixed $versions): array
    {
        if (! is_array($versions)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $version): array => is_array($version) ? [
                'id' => isset($version['id']) ? (string) $version['id'] : null,
                'version' => isset($version['version']) ? (int) $version['version'] : null,
                'schema_version' => isset($version['schema_version']) ? (int) $version['schema_version'] : null,
                'origin' => $version['origin'] ?? null,
                'policy_hash' => $version['policy_hash'] ?? null,
                'configuration_hash' => $version['configuration_hash'] ?? null,
                'created_at' => $version['created_at'] ?? null,
            ] : [],
            $versions,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function safeRuns(mixed $runs): array
    {
        if (! is_array($runs)) {
            return [];
        }

        $counters = [
            'received',
            'valid',
            'accepted',
            'created',
            'linked',
            'duplicate',
            'conflicted',
            'suppressed',
            'rejected',
            'expired',
        ];

        return array_values(array_map(
            static function (mixed $run) use ($counters): array {
                if (! is_array($run)) {
                    return [];
                }

                $payload = [
                    'id' => isset($run['id']) ? (string) $run['id'] : null,
                    'state' => $run['state'] ?? null,
                    'initiator_type' => $run['initiator_type'] ?? null,
                    'created_at' => $run['created_at'] ?? null,
                    'finished_at' => $run['finished_at'] ?? null,
                ];

                foreach ($counters as $counter) {
                    $payload[$counter] = (int) ($run[$counter] ?? 0);
                }

                return $payload;
            },
            $runs,
        ));
    }
}
