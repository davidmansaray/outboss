<?php

namespace App\Domain\People\Support;

use App\Domain\Prospecting\DTOs\SourcePolicySnapshot;
use App\Domain\Prospecting\Support\SourcePolicyEnforcer;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only projection used by the web/API/MCP source surfaces. It keeps the
 * transport adapters independent from the write-side source aggregate while
 * filtering observations down to the bounded fields that are safe to expose.
 */
final class ProspectSourceSurface
{
    public static function available(): bool
    {
        return Schema::hasTable('prospect_sources');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function list(Workspace $workspace): array
    {
        if (! self::available()) {
            return [];
        }

        $payloads = [];
        $sources = DB::table('prospect_sources')
            ->where('workspace_id', $workspace->id)
            ->orderByRaw("case when status = 'archived' then 1 else 0 end")
            ->orderBy('display_name')
            ->get();

        foreach ($sources as $source) {
            $payloads[] = self::sourcePayload($workspace, $source);
        }

        return $payloads;
    }

    public static function find(Workspace $workspace, string $id): ?object
    {
        if (! self::available() || preg_match('/\A[0-9a-fA-F-]{36}\z/', $id) !== 1) {
            return null;
        }

        return DB::table('prospect_sources')
            ->where('workspace_id', $workspace->id)
            ->where('id', strtolower($id))
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Workspace $workspace, string $id): ?array
    {
        $source = self::find($workspace, $id);

        if ($source === null) {
            return null;
        }

        $payload = self::sourcePayload($workspace, $source);
        $payload['versions'] = self::versions($workspace, $id);
        $payload['runs'] = self::runs($workspace, $id)->all();

        return $payload;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public static function runs(Workspace $workspace, string $sourceId): Collection
    {
        if (! Schema::hasTable('source_runs')) {
            return collect();
        }

        return DB::table('source_runs')
            ->where('workspace_id', $workspace->id)
            ->where('prospect_source_id', $sourceId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (object $run): array => self::runPayload($run));
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{current_page: int, per_page: int, last_page: int, total: int}}
     */
    public static function observations(Workspace $workspace, string $runId, int $page = 1, int $perPage = 50): array
    {
        $empty = [
            'data' => [],
            'meta' => ['current_page' => $page, 'per_page' => $perPage, 'last_page' => 1, 'total' => 0],
        ];

        if (! Schema::hasTable('source_observations') || ! Schema::hasTable('source_runs')) {
            return $empty;
        }

        $run = DB::table('source_runs')
            ->where('workspace_id', $workspace->id)
            ->where('id', $runId)
            ->first(['id', 'prospect_source_id']);

        if ($run === null) {
            return $empty;
        }

        $query = DB::table('source_observations')
            ->where('workspace_id', $workspace->id)
            ->where('source_run_id', $run->id)
            ->orderBy('row_number')
            ->orderBy('id');
        $total = (clone $query)->count();
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $rows = $query->forPage($page, $perPage)->get();
        $policies = [];
        if (Schema::hasTable('prospect_source_versions')) {
            $versionIds = $rows
                ->map(fn (object $row): mixed => self::rowValue($row, 'prospect_source_version_id'))
                ->filter()
                ->map(fn (mixed $id): string => (string) $id)
                ->unique()
                ->values()
                ->all();
            if ($versionIds !== []) {
                $policies = DB::table('prospect_source_versions')
                    ->where('workspace_id', $workspace->id)
                    ->whereIn('id', $versionIds)
                    ->get(['id', 'policy_snapshot'])
                    ->mapWithKeys(fn (object $version): array => [
                        (string) self::rowValue($version, 'id') => self::policySnapshot(self::rowValue($version, 'policy_snapshot')),
                    ])
                    ->all();
            }
        }
        $payloads = [];
        foreach ($rows as $observation) {
            $versionId = self::rowValue($observation, 'prospect_source_version_id');
            $payloads[] = self::observationPayload(
                $observation,
                is_scalar($versionId) ? ($policies[(string) $versionId] ?? []) : [],
            );
        }

        return [
            'data' => $payloads,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'total' => $total,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function sourcePayload(Workspace $workspace, object $source): array
    {
        $sourceId = (string) self::rowValue($source, 'id');
        $runs = self::runs($workspace, $sourceId);
        $lastRun = $runs->first();
        $policy = self::policySummary($workspace, $sourceId, $source);
        $displayName = self::rowValue($source, 'display_name')
            ?? self::rowValue($source, 'name')
            ?? 'Unnamed source';
        $lastRunId = is_array($lastRun) ? ($lastRun['id'] ?? null) : null;
        $currentVersionId = self::rowValue($source, 'current_version_id');

        return [
            'id' => $sourceId,
            'display_name' => (string) $displayName,
            'kind' => (string) (self::rowValue($source, 'kind') ?? 'manual'),
            'status' => (string) (self::rowValue($source, 'status') ?? 'active'),
            'name_normalized' => self::rowValue($source, 'name_normalized'),
            'current_version_id' => $currentVersionId !== null ? (string) $currentVersionId : null,
            'last_run_id' => self::rowValue($source, 'last_run_id') !== null
                ? (string) self::rowValue($source, 'last_run_id')
                : $lastRunId,
            'last_run' => $lastRun,
            'run_count' => $runs->count(),
            'accepted_count' => is_array($lastRun) ? ($lastRun['accepted'] ?? 0) : 0,
            'created_count' => is_array($lastRun) ? ($lastRun['created'] ?? 0) : 0,
            'linked_count' => is_array($lastRun) ? ($lastRun['linked'] ?? 0) : 0,
            'suppressed_count' => is_array($lastRun) ? ($lastRun['suppressed'] ?? 0) : 0,
            'rejected_count' => is_array($lastRun) ? ($lastRun['rejected'] ?? 0) : 0,
            'restrictions' => $policy['restrictions'],
            'retention_warning' => $policy['retention_warning'],
            'created_at' => self::date(self::rowValue($source, 'created_at')),
            'updated_at' => self::date(self::rowValue($source, 'updated_at')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function runPayload(object $run): array
    {
        $counters = [
            'received', 'valid', 'accepted', 'created', 'linked', 'duplicate',
            'conflicted', 'suppressed', 'rejected', 'expired',
        ];
        $payload = [
            'id' => (string) self::rowValue($run, 'id'),
            'state' => (string) (self::rowValue($run, 'state') ?? 'draft'),
            'initiator_type' => self::rowValue($run, 'initiator_type') !== null
                ? (string) self::rowValue($run, 'initiator_type')
                : null,
            'finished_at' => self::date(self::rowValue($run, 'finished_at')),
            'created_at' => self::date(self::rowValue($run, 'created_at')),
        ];

        foreach ($counters as $counter) {
            $payload[$counter] = (int) (self::rowValue($run, $counter) ?? 0);
        }

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function versions(Workspace $workspace, string $sourceId): array
    {
        if (! Schema::hasTable('prospect_source_versions')) {
            return [];
        }

        $payloads = [];
        $versions = DB::table('prospect_source_versions')
            ->where('workspace_id', $workspace->id)
            ->where('prospect_source_id', $sourceId)
            ->orderByDesc('version')
            ->get(['id', 'version', 'schema_version', 'origin', 'created_at', 'policy_hash', 'configuration_hash']);

        foreach ($versions as $version) {
            $payloads[] = [
                'id' => (string) self::rowValue($version, 'id'),
                'version' => (int) self::rowValue($version, 'version'),
                'schema_version' => (int) self::rowValue($version, 'schema_version'),
                'origin' => (string) self::rowValue($version, 'origin'),
                'policy_hash' => (string) self::rowValue($version, 'policy_hash'),
                'configuration_hash' => (string) self::rowValue($version, 'configuration_hash'),
                'created_at' => self::date(self::rowValue($version, 'created_at')),
            ];
        }

        return $payloads;
    }

    /**
     * @return array{restrictions: list<string>, retention_warning: string|null}
     */
    private static function policySummary(Workspace $workspace, string $sourceId, object $source): array
    {
        if (! Schema::hasTable('prospect_source_versions')) {
            return ['restrictions' => [], 'retention_warning' => null];
        }

        $versionId = self::rowValue($source, 'current_version_id');
        $version = DB::table('prospect_source_versions')
            ->where('workspace_id', $workspace->id)
            ->where('prospect_source_id', $sourceId)
            ->when($versionId, fn ($query) => $query->where('id', $versionId))
            ->orderByDesc('version')
            ->first(['policy_snapshot']);

        $policySnapshot = $version === null ? null : self::rowValue($version, 'policy_snapshot');
        if (! is_string($policySnapshot)) {
            return ['restrictions' => [], 'retention_warning' => null];
        }

        $policy = json_decode($policySnapshot, true);
        if (! is_array($policy)) {
            return ['restrictions' => [], 'retention_warning' => null];
        }

        $restrictions = [];
        foreach (['prohibited_uses', 'display_requirements'] as $key) {
            if (isset($policy[$key]) && is_array($policy[$key]) && $policy[$key] !== []) {
                $restrictions[] = str_replace('_', ' ', $key);
            }
        }

        $geography = $policy['geography'] ?? null;
        if ((is_string($geography) && trim($geography) !== '') || (is_array($geography) && $geography !== [])) {
            $restrictions[] = 'geography';
        }

        $expiry = $policy['policy_expires_at'] ?? null;
        $warning = null;
        if (is_string($expiry) && trim($expiry) !== '') {
            try {
                $expiryAt = new \DateTimeImmutable($expiry);
                if ($expiryAt <= new \DateTimeImmutable('+30 days')) {
                    $warning = 'Policy review is due '.$expiryAt->format('j M Y').'.';
                }
            } catch (\Exception) {
                // Invalid policy snapshots are rejected by the write side;
                // keep the read surface bounded if an old row is malformed.
            }
        }

        return ['restrictions' => $restrictions, 'retention_warning' => $warning];
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return array<string, mixed>
     */
    private static function observationPayload(object $observation, array $policy = []): array
    {
        $rawValues = self::stringKeyedValues(self::rowValue($observation, 'observed_values'));
        $enforcer = new SourcePolicyEnforcer;
        $displayValues = $enforcer->filterFields($rawValues, $policy, 'display');
        $displayAllowed = $enforcer->allowsDisplay($policy) && ($rawValues === [] || $displayValues !== []);

        return [
            'id' => (string) self::rowValue($observation, 'id'),
            'row_number' => self::rowValue($observation, 'row_number') !== null
                ? (int) self::rowValue($observation, 'row_number')
                : null,
            'entity_type' => (string) (self::rowValue($observation, 'entity_type') ?? ''),
            'disposition' => (string) (self::rowValue($observation, 'disposition') ?? 'rejected'),
            'match_method' => self::rowValue($observation, 'match_method') !== null
                ? (string) self::rowValue($observation, 'match_method')
                : null,
            'safe_error_code' => self::rowValue($observation, 'safe_error_code') !== null
                ? (string) self::rowValue($observation, 'safe_error_code')
                : null,
            'display_classification' => (string) ($policy['display_classification']
                ?? self::rowValue($observation, 'display_classification')
                ?? 'standard'),
            'display_omission_reason' => $displayAllowed ? null : 'restricted_by_source_policy',
            'retrieved_at' => self::date(self::rowValue($observation, 'retrieved_at')),
            'provider_observed_at' => self::date(self::rowValue($observation, 'provider_observed_at')),
            'account_id' => $displayAllowed ? self::rowValue($observation, 'account_id') : null,
            'person_id' => $displayAllowed ? self::rowValue($observation, 'person_id') : null,
        ];
    }

    /** @return array<string, mixed> */
    private static function policySnapshot(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return SourcePolicySnapshot::native()->policy;
    }

    /** @return array<string, mixed> */
    private static function stringKeyedValues(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    private static function rowValue(object $row, string $key): mixed
    {
        return get_object_vars($row)[$key] ?? null;
    }

    private static function date(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return (new \DateTimeImmutable($value))->format(DATE_ATOM);
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }
}
