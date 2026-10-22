<?php

namespace App\Domain\Prospecting\Services;

use App\Domain\Prospecting\Exceptions\SourcePolicyViolation;
use App\Models\Account;
use App\Models\AccountSourceMembership;
use App\Models\CanonicalFieldProvenance;
use App\Models\ExternalIdentity;
use App\Models\Person;
use App\Models\PersonSourceMembership;
use App\Models\ProspectSource;
use App\Models\SourceObservation;
use App\Models\SourceRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class DeleteProspectSourceData
{
    private const TARGET_TABLE = 'prospect_source_deletion_targets';

    /**
     * Record the deletion decision and canonical targets, then archive the
     * source before dispatching its destructive work. The source row lock
     * makes the lifecycle transition atomic with the policy check, so no new
     * run can be admitted in the gap before the worker runs.
     */
    public function prepareForDeletion(ProspectSource $source, bool $deleteCanonical = false): ProspectSource
    {
        return DB::transaction(function () use ($source, $deleteCanonical): ProspectSource {
            $locked = ProspectSource::query()
                ->where('workspace_id', $source->workspace_id)
                ->whereKey($source->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->recordDeletionRequest($locked, $deleteCanonical);

            return $locked->fresh(['currentVersion']);
        }, 3);
    }

    /**
     * Delete policy-controlled source lineage while retaining canonical
     * records by default. A source's immutable versions remain for audit of
     * the aggregate; callers can archive it separately.
     */
    public function handle(ProspectSource $source, bool $deleteCanonical = false): int
    {
        return DB::transaction(function () use ($source, $deleteCanonical): int {
            $locked = ProspectSource::query()
                ->where('workspace_id', $source->workspace_id)
                ->whereKey($source->id)
                ->lockForUpdate()
                ->firstOrFail();
            // Jobs created before the request-side preparation (and direct
            // service callers) are still safe: initialize the durable request
            // while holding the same source lock before removing any rows.
            $this->recordDeletionRequest($locked, $deleteCanonical);

            $deleteCanonical = (bool) $locked->deletion_delete_canonical;
            $canonicalTargets = $this->canonicalTargets($locked);
            $observations = SourceObservation::query()->where('workspace_id', $locked->workspace_id)->where('prospect_source_id', $locked->id)->get(['id', 'account_id', 'person_id']);
            $observationIds = array_values($observations->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all());
            $this->detachObjectiveSnapshotSources($locked);
            if ($observationIds !== []) {
                CanonicalFieldProvenance::query()->whereIn('source_observation_id', $observationIds)->delete();
                $this->removeOrRetainExternalIdentities($locked, $observationIds);
            }
            AccountSourceMembership::query()->where('workspace_id', $locked->workspace_id)->where('prospect_source_id', $locked->id)->delete();
            PersonSourceMembership::query()->where('workspace_id', $locked->workspace_id)->where('prospect_source_id', $locked->id)->delete();
            $deleted = SourceObservation::query()->where('workspace_id', $locked->workspace_id)->where('prospect_source_id', $locked->id)->delete();
            SourceRun::query()->where('workspace_id', $locked->workspace_id)->where('prospect_source_id', $locked->id)->update([
                'state' => 'expired',
            ]);
            $locked->forceFill(['last_run_id' => null, 'status' => 'archived'])->save();

            if ($deleteCanonical) {
                // Only remove records that no longer have an independent source
                // membership. Existing campaign/recipient FKs still decide
                // whether a canonical record is safe to delete.
                $this->deleteCanonicalTargets($locked, $canonicalTargets);
            }

            return (int) $deleted;
        }, 3);
    }

    private function detachObjectiveSnapshotSources(ProspectSource $source): void
    {
        if (! Schema::hasTable('audience_snapshot_member_sources')) {
            return;
        }

        DB::table('audience_snapshot_member_sources')
            ->where('workspace_id', $source->workspace_id)
            ->where('prospect_source_id', $source->id)
            ->update([
                'prospect_source_id' => null,
                'prospect_source_version_id' => null,
                'source_observation_id' => null,
                'account_source_membership_id' => null,
                'person_source_membership_id' => null,
                'rights_result' => 'source_deleted',
            ]);
    }

    public function delete(ProspectSource $source, bool $deleteCanonical = false): int
    {
        return $this->handle($source, $deleteCanonical);
    }

    private function assertDeletionAllowed(ProspectSource $source): void
    {
        $version = $source->currentVersion()->first();
        if ($version !== null && ($version->policy_snapshot['deletion_behavior'] ?? 'delete_source_data') === 'retain') {
            throw new SourcePolicyViolation(
                'This source policy requires its lineage to be retained.',
                'deletion_forbidden',
            );
        }
    }

    /**
     * Persist the strongest deletion intent and all canonical IDs before any
     * lineage rows can disappear. A later true request upgrades a false one;
     * a later false request can never weaken an already-approved true one.
     */
    private function recordDeletionRequest(ProspectSource $source, bool $deleteCanonical): void
    {
        $requested = $source->deletion_requested_at !== null;
        if (! $requested) {
            $version = $source->currentVersion()->first();
            $approvedBehavior = (string) ($version?->policy_snapshot['deletion_behavior'] ?? 'delete_source_data');
            if ($approvedBehavior === 'retain') {
                $this->assertDeletionAllowed($source);
            }

            $source->forceFill([
                'deletion_requested_at' => now(),
                'deletion_approved_version_id' => $version?->id,
                'deletion_approved_policy_hash' => $version?->policy_hash,
                'deletion_approved_behavior' => $approvedBehavior,
                'deletion_delete_canonical' => $deleteCanonical,
            ]);
        } else {
            $approvedBehavior = (string) ($source->deletion_approved_behavior ?? '');
            if ($approvedBehavior === '') {
                // Be defensive for rows created during a rolling deployment
                // before the approved-decision columns were populated.
                $version = $source->currentVersion()->first();
                $approvedBehavior = (string) ($version?->policy_snapshot['deletion_behavior'] ?? 'delete_source_data');
                if ($approvedBehavior === 'retain') {
                    $this->assertDeletionAllowed($source);
                }
                $source->forceFill([
                    'deletion_approved_version_id' => $version?->id,
                    'deletion_approved_policy_hash' => $version?->policy_hash,
                    'deletion_approved_behavior' => $approvedBehavior,
                ]);
            } elseif ($approvedBehavior === 'retain') {
                throw new SourcePolicyViolation(
                    'This source policy requires its lineage to be retained.',
                    'deletion_forbidden',
                );
            }

            if ($deleteCanonical && ! (bool) $source->deletion_delete_canonical) {
                $source->forceFill(['deletion_delete_canonical' => true]);
            }
        }

        // Keep the archive transition in the same transaction as the request
        // snapshot. This preserves the run-admission barrier for every caller,
        // including a worker invoked directly without the HTTP controller.
        if ((string) $source->getRawOriginal('status') !== 'archived') {
            $source->forceFill([
                'status' => 'archived',
                'active_name_normalized' => null,
            ]);
        }

        if ($source->isDirty()) {
            $source->forceFill(['lock_version' => (int) $source->lock_version + 1])->save();
        }
        $this->captureCanonicalTargets($source);
    }

    /**
     * Capture account/person IDs in small batches so a large source does not
     * turn one JSON payload into an unbounded row. The target table is an
     * append-only set, making retries and false-then-true upgrades idempotent.
     */
    private function captureCanonicalTargets(ProspectSource $source): void
    {
        $workspaceId = (int) $source->workspace_id;
        $sourceId = (string) $source->id;

        $this->insertCanonicalTargets(
            $workspaceId,
            $sourceId,
            'account',
            SourceObservation::query()
                ->where('workspace_id', $workspaceId)
                ->where('prospect_source_id', $sourceId)
                ->whereNotNull('account_id')
                ->distinct()
                ->pluck('account_id'),
        );
        $this->insertCanonicalTargets(
            $workspaceId,
            $sourceId,
            'person',
            SourceObservation::query()
                ->where('workspace_id', $workspaceId)
                ->where('prospect_source_id', $sourceId)
                ->whereNotNull('person_id')
                ->distinct()
                ->pluck('person_id'),
        );
        $this->insertCanonicalTargets(
            $workspaceId,
            $sourceId,
            'account',
            AccountSourceMembership::query()
                ->where('workspace_id', $workspaceId)
                ->where('prospect_source_id', $sourceId)
                ->distinct()
                ->pluck('account_id'),
        );
        $this->insertCanonicalTargets(
            $workspaceId,
            $sourceId,
            'person',
            PersonSourceMembership::query()
                ->where('workspace_id', $workspaceId)
                ->where('prospect_source_id', $sourceId)
                ->distinct()
                ->pluck('person_id'),
        );
    }

    /**
     * @param  iterable<int|string|null>  $ids
     */
    private function insertCanonicalTargets(int $workspaceId, string $sourceId, string $entityType, iterable $ids): void
    {
        $rows = [];
        foreach ($ids as $id) {
            if ($id === null || ! is_numeric($id)) {
                continue;
            }
            $rows[] = [
                'workspace_id' => $workspaceId,
                'prospect_source_id' => $sourceId,
                'entity_type' => $entityType,
                'entity_id' => (int) $id,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (count($rows) === 500) {
                DB::table(self::TARGET_TABLE)->insertOrIgnore($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table(self::TARGET_TABLE)->insertOrIgnore($rows);
        }
    }

    /**
     * @return array{account:array<int,int>,person:array<int,int>}
     */
    private function canonicalTargets(ProspectSource $source): array
    {
        $targets = DB::table(self::TARGET_TABLE)
            ->where('workspace_id', $source->workspace_id)
            ->where('prospect_source_id', $source->id)
            ->get(['entity_type', 'entity_id']);

        return [
            'account' => $targets->where('entity_type', 'account')->pluck('entity_id')->map(static fn (mixed $id): int => (int) $id)->values()->all(),
            'person' => $targets->where('entity_type', 'person')->pluck('entity_id')->map(static fn (mixed $id): int => (int) $id)->values()->all(),
        ];
    }

    /** @param array{account:array<int,int>,person:array<int,int>} $targets */
    private function deleteCanonicalTargets(ProspectSource $source, array $targets): void
    {
        if ($targets['account'] !== []) {
            $accounts = array_filter(
                $targets['account'],
                fn (int $id): bool => ! AccountSourceMembership::query()
                    ->where('workspace_id', $source->workspace_id)
                    ->where('account_id', $id)
                    ->exists(),
            );
            if ($accounts !== []) {
                Account::query()
                    ->where('workspace_id', $source->workspace_id)
                    ->whereIn('id', $accounts)
                    ->delete();
            }
        }
        if ($targets['person'] !== []) {
            $people = array_filter(
                $targets['person'],
                fn (int $id): bool => ! PersonSourceMembership::query()
                    ->where('workspace_id', $source->workspace_id)
                    ->where('person_id', $id)
                    ->exists(),
            );
            if ($people !== []) {
                Person::query()
                    ->where('workspace_id', $source->workspace_id)
                    ->whereIn('id', $people)
                    ->delete();
            }
        }
    }

    /** @return array{observations:int,memberships:int,identities:int,provenance:int,runs:int} */
    public function deleteWithSummary(ProspectSource $source, bool $deleteCanonical = false): array
    {
        $before = [
            'observations' => SourceObservation::query()->where('workspace_id', $source->workspace_id)->where('prospect_source_id', $source->id)->count(),
            'memberships' => AccountSourceMembership::query()->where('workspace_id', $source->workspace_id)->where('prospect_source_id', $source->id)->count()
                + PersonSourceMembership::query()->where('workspace_id', $source->workspace_id)->where('prospect_source_id', $source->id)->count(),
            'identities' => ExternalIdentity::query()->where('workspace_id', $source->workspace_id)->where(function ($query) use ($source): void {
                $query->whereHas('firstObservation', fn ($q) => $q->where('prospect_source_id', $source->id))
                    ->orWhereHas('lastObservation', fn ($q) => $q->where('prospect_source_id', $source->id));
            })->count(),
            'provenance' => 0,
            'runs' => SourceRun::query()->where('workspace_id', $source->workspace_id)->where('prospect_source_id', $source->id)->count(),
        ];
        $before['provenance'] = CanonicalFieldProvenance::query()->where('workspace_id', $source->workspace_id)->whereIn('source_observation_id', SourceObservation::query()->where('workspace_id', $source->workspace_id)->where('prospect_source_id', $source->id)->select('id'))->count();
        $this->handle($source, $deleteCanonical);

        return $before;
    }

    /** @param list<string> $observationIds */
    private function removeOrRetainExternalIdentities(ProspectSource $source, array $observationIds): void
    {
        $identities = ExternalIdentity::query()
            ->where('workspace_id', $source->workspace_id)
            ->where(function ($query) use ($observationIds): void {
                $query->whereIn('first_source_observation_id', $observationIds)
                    ->orWhereIn('last_source_observation_id', $observationIds);
            })
            ->lockForUpdate()
            ->get();
        foreach ($identities as $identity) {
            $identityColumn = $identity->identity_type === 'provider_record_id'
                ? 'provider_record_id'
                : 'canonical_url';
            $remaining = SourceObservation::query()
                ->where('workspace_id', $source->workspace_id)
                ->where('prospect_source_id', '!=', $source->id)
                ->whereNull('expired_at')
                ->where($identity->entity_type === 'account' ? 'account_id' : 'person_id', $identity->entity_type === 'account' ? $identity->account_id : $identity->person_id)
                ->whereNotNull($identityColumn)
                ->with('version')
                ->orderBy('retrieved_at')
                ->orderBy('id')
                ->get()
                ->filter(fn (SourceObservation $observation): bool => $this->matchesExternalIdentity($identity, $observation, $identityColumn))
                ->values();
            if ($remaining->isEmpty()) {
                $identity->delete();

                continue;
            }
            $identity->forceFill([
                'first_source_observation_id' => $remaining->first()->id,
                'last_source_observation_id' => $remaining->last()->id,
                'first_observed_at' => $remaining->first()->retrieved_at,
                'last_observed_at' => $remaining->last()->retrieved_at,
            ])->save();
        }
    }

    private function matchesExternalIdentity(ExternalIdentity $identity, SourceObservation $observation, string $identityColumn): bool
    {
        if ($this->observationIdentityType($observation) !== (string) $identity->identity_type) {
            return false;
        }

        $configuration = $observation->version?->getAttribute('configuration');
        $provider = (string) (is_array($configuration) ? ($configuration['provider'] ?? null) : null);
        $provider = $provider !== '' ? $provider : 'source:'.$observation->prospect_source_id;

        return $provider === (string) $identity->provider
            && hash_equals(
                (string) $identity->value_hash,
                hash('sha256', strtolower(trim((string) $observation->{$identityColumn}))),
            );
    }

    private function observationIdentityType(SourceObservation $observation): string
    {
        return $observation->provider_record_id !== null
            ? 'provider_record_id'
            : 'canonical_url';
    }
}
