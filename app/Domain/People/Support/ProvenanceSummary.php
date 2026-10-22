<?php

namespace App\Domain\People\Support;

use App\Domain\Prospecting\DTOs\SourcePolicySnapshot;
use App\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Builds the deliberately small provenance projection exposed alongside
 * canonical Account and Person records. This helper is migration-safe: old
 * deployments can serve existing resources while the additive S1 tables are
 * being rolled out.
 */
final class ProvenanceSummary
{
    /**
     * Return the policy-safe source columns used by canonical CSV exports.
     * Counts include every membership, while identifiers and names include
     * only sources whose current policy permits export.
     *
     * @return array{source_count: int, source_ids: string, source_names: string, latest_observed_at: string|null, source_omission_reason: string|null}
     */
    public static function forExport(Model $entity): array
    {
        $entityType = $entity instanceof Account ? 'account' : 'person';
        $membershipTable = $entityType === 'account'
            ? 'account_source_memberships'
            : 'person_source_memberships';
        $entityColumn = $entityType === 'account' ? 'account_id' : 'person_id';
        $empty = [
            'source_count' => 0,
            'source_ids' => '',
            'source_names' => '',
            'latest_observed_at' => null,
            'source_omission_reason' => null,
        ];

        if (! Schema::hasTable($membershipTable)
            || ! Schema::hasTable('prospect_sources')
            || ! Schema::hasTable('prospect_source_versions')) {
            return $empty;
        }

        $memberships = DB::table($membershipTable.' as memberships')
            ->join('prospect_sources as sources', function ($join): void {
                $join->on('sources.id', '=', 'memberships.prospect_source_id')
                    ->on('sources.workspace_id', '=', 'memberships.workspace_id');
            })
            ->leftJoin('prospect_source_versions as versions', function ($join): void {
                $join->on('versions.id', '=', 'sources.current_version_id')
                    ->on('versions.workspace_id', '=', 'memberships.workspace_id');
            })
            ->where('memberships.workspace_id', $entity->getAttribute('workspace_id'))
            ->where('memberships.'.$entityColumn, $entity->getKey())
            ->orderBy('memberships.first_observed_at')
            ->orderBy('memberships.id')
            ->get([
                'sources.id',
                'sources.display_name',
                'versions.policy_snapshot',
                'memberships.last_observed_at',
                'memberships.state',
            ]);

        if ($memberships->isEmpty()) {
            return $empty;
        }

        $allowed = $memberships->filter(function (object $membership): bool {
            if (in_array((string) ($membership->state ?? ''), ['restricted', 'expired', 'removed'], true)) {
                return false;
            }

            $policy = json_decode((string) ($membership->policy_snapshot ?? ''), true);

            if (! is_array($policy)) {
                return false;
            }

            $snapshot = SourcePolicySnapshot::from($policy);

            return $snapshot->allowsExport()
                && ! $snapshot->isExpired()
                && ! in_array((string) ($policy['export_classification'] ?? 'standard'), ['restricted', 'prohibited'], true);
        });
        $latestObserved = $allowed
            ->pluck('last_observed_at')
            ->filter()
            ->sortDesc()
            ->first();

        return [
            'source_count' => $memberships->count(),
            'source_ids' => $allowed->pluck('id')->map(fn (mixed $id): string => (string) $id)->implode('|'),
            'source_names' => $allowed->pluck('display_name')->map(fn (mixed $name): string => (string) $name)->implode('|'),
            'latest_observed_at' => self::iso8601($latestObserved),
            'source_omission_reason' => $allowed->count() < $memberships->count()
                ? 'restricted_by_source_policy'
                : null,
        ];
    }

    /**
     * @return array{primary_source: array{id: string, name: string}|null, source_count: int, last_observed_at: string|null, has_conflicts: bool, has_restrictions: bool, source_omission_reason: string|null}
     */
    public static function for(Model $entity): array
    {
        $entityType = $entity instanceof Account ? 'account' : 'person';
        $membershipTable = $entityType === 'account'
            ? 'account_source_memberships'
            : 'person_source_memberships';
        $entityColumn = $entityType === 'account' ? 'account_id' : 'person_id';

        $empty = [
            'primary_source' => null,
            'source_count' => 0,
            'last_observed_at' => null,
            'has_conflicts' => false,
            'has_restrictions' => false,
            'source_omission_reason' => null,
        ];

        if (! Schema::hasTable($membershipTable)
            || ! Schema::hasTable('prospect_sources')
            || ! Schema::hasTable('prospect_source_versions')) {
            return $empty;
        }

        $memberships = DB::table($membershipTable.' as memberships')
            ->join('prospect_sources as sources', function ($join): void {
                $join->on('sources.id', '=', 'memberships.prospect_source_id')
                    ->on('sources.workspace_id', '=', 'memberships.workspace_id');
            })
            ->leftJoin('prospect_source_versions as versions', function ($join): void {
                $join->on('versions.id', '=', 'sources.current_version_id')
                    ->on('versions.workspace_id', '=', 'memberships.workspace_id');
            })
            ->where('memberships.workspace_id', $entity->getAttribute('workspace_id'))
            ->where('memberships.'.$entityColumn, $entity->getKey())
            ->orderBy('memberships.first_observed_at')
            ->orderBy('memberships.id')
            ->get([
                'memberships.prospect_source_id',
                'memberships.first_observed_at',
                'memberships.last_observed_at',
                'memberships.state',
                'sources.id as source_id',
                'sources.display_name as source_name',
                'versions.policy_snapshot',
            ]);

        if ($memberships->isEmpty()) {
            return $empty;
        }

        $sourceIds = $memberships->pluck('prospect_source_id')->filter()->unique()->values();
        $visible = $memberships->filter(fn (object $membership): bool => self::allowsDisplay($membership));
        $first = $visible->first();
        $lastObserved = $visible
            ->pluck('last_observed_at')
            ->filter()
            ->sortDesc()
            ->first();
        $hasRestrictions = $memberships->contains(fn (object $membership): bool => in_array(
            (string) $membership->state,
            ['restricted', 'expired'],
            true,
        ));
        $hasConflicts = false;

        if (Schema::hasTable('source_observations')) {
            $observations = DB::table('source_observations')
                ->where('workspace_id', $entity->getAttribute('workspace_id'))
                ->where($entityColumn, $entity->getKey())
                ->get(['disposition', 'export_classification', 'display_classification']);
            $hasConflicts = $observations->contains(fn (object $observation): bool => (string) $observation->disposition === 'conflicted');
            $hasRestrictions = $hasRestrictions || $observations->contains(function (object $observation): bool {
                return in_array((string) ($observation->export_classification ?? ''), ['restricted', 'prohibited'], true)
                    || in_array((string) ($observation->display_classification ?? ''), ['restricted', 'prohibited'], true);
            });
        }

        return [
            'primary_source' => $first === null ? null : [
                'id' => (string) $first->source_id,
                'name' => (string) $first->source_name,
            ],
            'source_count' => $sourceIds->count(),
            'last_observed_at' => self::iso8601($lastObserved),
            'has_conflicts' => $hasConflicts,
            'has_restrictions' => $hasRestrictions || $visible->count() < $memberships->count(),
            'source_omission_reason' => $visible->count() < $memberships->count()
                ? 'restricted_by_source_policy'
                : null,
        ];
    }

    private static function allowsDisplay(object $membership): bool
    {
        if (in_array((string) ($membership->state ?? ''), ['restricted', 'expired', 'removed'], true)) {
            return false;
        }

        $policy = json_decode((string) ($membership->policy_snapshot ?? ''), true);
        if (! is_array($policy)) {
            return false;
        }

        $snapshot = SourcePolicySnapshot::from($policy);

        return ! $snapshot->isExpired()
            && ! in_array((string) ($policy['display_classification'] ?? 'standard'), ['restricted', 'prohibited'], true);
    }

    private static function iso8601(mixed $value): ?string
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
