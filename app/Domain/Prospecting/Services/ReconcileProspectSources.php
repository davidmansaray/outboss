<?php

namespace App\Domain\Prospecting\Services;

use App\Models\Account;
use App\Models\AccountSourceMembership;
use App\Models\Person;
use App\Models\PersonSourceMembership;
use App\Models\SourceRun;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

final class ReconcileProspectSources
{
    public function __construct(private readonly ReconcileSourceRunCounts $runCounts) {}

    /** @return array<string,mixed> */
    public function handle(?Workspace $workspace = null, bool $repair = false): array
    {
        $workspaces = $workspace === null ? Workspace::query()->orderBy('id')->get() : collect([$workspace]);
        $reports = [];
        foreach ($workspaces as $current) {
            $reports[(string) $current->id] = $this->workspaceReport($current, $repair);
        }

        $summary = [
            'workspace_count' => count($reports),
            'mismatch_count' => array_sum(array_map(static fn (array $report): int => (int) $report['mismatch_count'], $reports)),
            'workspaces' => $reports,
        ];

        return $summary;
    }

    /** @return array<string,mixed> */
    private function workspaceReport(Workspace $workspace, bool $repair): array
    {
        $accountCount = Account::query()->where('workspace_id', $workspace->id)->count();
        $accountMembershipCount = AccountSourceMembership::query()->where('workspace_id', $workspace->id)->count();
        $personCount = Person::query()->where('workspace_id', $workspace->id)->count();
        $personMembershipCount = PersonSourceMembership::query()->where('workspace_id', $workspace->id)->count();
        $orphanAccounts = Account::query()->where('workspace_id', $workspace->id)->whereDoesntHave('sourceMemberships')->count();
        $orphanPeople = Person::query()->where('workspace_id', $workspace->id)->whereDoesntHave('sourceMemberships')->count();
        $membershipObservationMismatches = AccountSourceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where(function ($query): void {
                $query->whereDoesntHave('firstObservation')->orWhereDoesntHave('lastObservation');
            })->count()
            + PersonSourceMembership::query()
                ->where('workspace_id', $workspace->id)
                ->where(function ($query): void {
                    $query->whereDoesntHave('firstObservation')->orWhereDoesntHave('lastObservation');
                })->count();

        $counterMismatches = 0;
        SourceRun::query()->where('workspace_id', $workspace->id)->orderBy('id')->each(function (SourceRun $run) use (&$counterMismatches, $repair): void {
            $before = [$run->received, $run->valid, $run->accepted, $run->created, $run->linked, $run->duplicate, $run->conflicted, $run->suppressed, $run->rejected, $run->expired];
            $after = $this->runCounts->handle($run);
            $actual = [$after->received, $after->valid, $after->accepted, $after->created, $after->linked, $after->duplicate, $after->conflicted, $after->suppressed, $after->rejected, $after->expired];
            if ($before !== $actual) {
                $counterMismatches++;
                if (! $repair) {
                    // Reconciliation is read-only unless explicitly asked to
                    // repair. The run service has already rebuilt the in-memory
                    // values, so restore only the projection when not repairing.
                    $run->forceFill(array_combine(['received', 'valid', 'accepted', 'created', 'linked', 'duplicate', 'conflicted', 'suppressed', 'rejected', 'expired'], $before))->saveQuietly();
                }
            }
        });

        $crossWorkspace = $this->crossWorkspaceMismatches($workspace);
        $legacyUnknown = Account::query()->where('workspace_id', $workspace->id)->where(function ($query): void {
            $query->whereNull('source')->orWhereNotIn('source', ['manual', 'import', 'campaign_backfill']);
        })->whereDoesntHave('sourceMemberships', function ($query): void {
            $query->whereHas('source', fn ($source) => $source->where('system_key', 'unknown_legacy_source'));
        })->count()
            + Person::query()->where('workspace_id', $workspace->id)->where(function ($query): void {
                $query->whereNull('source')->orWhereNotIn('source', ['manual', 'import', 'campaign_backfill']);
            })->whereDoesntHave('sourceMemberships', function ($query): void {
                $query->whereHas('source', fn ($source) => $source->where('system_key', 'unknown_legacy_source'));
            })->count();
        $mismatchCount = $orphanAccounts + $orphanPeople + $membershipObservationMismatches + $counterMismatches + $crossWorkspace + $legacyUnknown;

        return [
            'workspace_id' => (int) $workspace->id,
            'accounts' => $accountCount,
            'account_memberships' => $accountMembershipCount,
            'people' => $personCount,
            'person_memberships' => $personMembershipCount,
            'orphan_accounts' => $orphanAccounts,
            'orphan_people' => $orphanPeople,
            'membership_observation_mismatches' => $membershipObservationMismatches,
            'run_counter_mismatches' => $counterMismatches,
            'cross_workspace_mismatches' => $crossWorkspace,
            'unknown_legacy_sources' => $legacyUnknown,
            'mismatch_count' => $mismatchCount,
            'repaired' => $repair,
        ];
    }

    private function crossWorkspaceMismatches(Workspace $workspace): int
    {
        return (int) (
            DB::table('source_runs as r')->join('prospect_sources as s', 's.id', '=', 'r.prospect_source_id')->where('r.workspace_id', $workspace->id)->whereColumn('r.workspace_id', '<>', 's.workspace_id')->count()
            + DB::table('source_observations as o')->join('source_runs as r', 'r.id', '=', 'o.source_run_id')->where('o.workspace_id', $workspace->id)->whereColumn('o.workspace_id', '<>', 'r.workspace_id')->count()
            + DB::table('account_source_memberships as m')->join('accounts as a', 'a.id', '=', 'm.account_id')->where('m.workspace_id', $workspace->id)->whereColumn('m.workspace_id', '<>', 'a.workspace_id')->count()
            + DB::table('person_source_memberships as m')->join('people as p', 'p.id', '=', 'm.person_id')->where('m.workspace_id', $workspace->id)->whereColumn('m.workspace_id', '<>', 'p.workspace_id')->count()
        );
    }
}
