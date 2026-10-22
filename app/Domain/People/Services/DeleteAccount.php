<?php

namespace App\Domain\People\Services;

use App\Domain\Ai\Enums\AiOperationStatus;
use App\Domain\Campaigns\Services\RedactGovernedCampaignRecipients;
use App\Domain\Conversions\Enums\ConversionEventState;
use App\Domain\Intelligence\Services\CancelAccountResearch;
use App\Domain\Objectives\Services\CancelObjectiveRun;
use App\Models\Account;
use App\Models\ObjectiveRun;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Permanently removes one canonical Account and its account-owned S2 data.
 *
 * Account deletion is an aggregate boundary, just like workspace deletion.
 * Most S2 records are append-only through their Eloquent models, so this
 * service deliberately uses scoped query-builder deletes for the authorised
 * purge.  Audit rows belonging to a conversion event remain, but account
 * pointers are detached and the current event projection is made explicit.
 */
final class DeleteAccount
{
    /** @var list<string> */
    private const ACTIVE_RESEARCH_STATES = [
        'planned',
        'awaiting_provider',
        'awaiting_budget',
        'running',
        'paused',
        'cancelling',
    ];

    public function __construct(
        private readonly CancelAccountResearch $cancelResearch,
        private readonly CancelObjectiveRun $cancelObjectiveRun,
        private readonly RedactGovernedCampaignRecipients $governedRecipients,
    ) {}

    /**
     * Delete an Account and return the number of campaign recipients whose
     * historical rows were retained.  A missing row is an idempotent no-op.
     */
    public function handle(Account $account, User $actor): int
    {
        return DB::transaction(function () use ($account, $actor): int {
            $locked = Account::query()
                ->whereKey($account->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return 0;
            }

            $workspaceId = (int) $locked->workspace_id;
            $accountId = (int) $locked->getKey();
            $peopleCount = $locked->recipients()->count();
            $workspace = Workspace::query()->whereKey($workspaceId)->firstOrFail();

            // Explicitly stop and redact every compiled executable copy before
            // Account deletion can trigger nullable CampaignRecipient FKs.
            $this->governedRecipients->forAccount($workspaceId, $accountId);
            $this->invalidateObjectivePlans($workspace, $actor, $workspaceId, $accountId);
            $this->cancelResearchRuns($workspace, $workspaceId, $accountId);
            $this->detachObjectiveSnapshotMembers($workspaceId, $accountId);
            $this->detachConversionMatches($workspaceId, $accountId);
            $this->deleteFulfilmentData($workspaceId, $accountId);
            $this->deleteQualificationData($workspaceId, $accountId);
            $this->deleteResearchData($workspaceId, $accountId);
            $this->deleteLegacyAccountData($workspaceId, $accountId);

            // Account FKs on people and campaign recipients are intentionally
            // null-on-delete, preserving message and campaign history.
            $locked->delete();

            return $peopleCount;
        }, 3);
    }

    /**
     * Fence every Objective run whose frozen audience or exact plan contains
     * the Account, then cancel and redact the affected exact plans. Safe
     * hashes and provider receipts remain workspace-owned; unresolved
     * provider work blocks deletion until the existing reconciliation path
     * reaches a terminal state.
     */
    private function invalidateObjectivePlans(
        Workspace $workspace,
        User $actor,
        int $workspaceId,
        int $accountId,
    ): void {
        if (! $this->hasTable('objective_runs')) {
            return;
        }

        $runIds = collect();
        if ($this->hasTable('audience_snapshot_members')) {
            $snapshotIds = DB::table('audience_snapshot_members')
                ->where('workspace_id', $workspaceId)
                ->where('account_id', $accountId)
                ->pluck('audience_snapshot_id');
            if ($snapshotIds->isNotEmpty()) {
                $runIds = $runIds->merge(DB::table('objective_runs')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('audience_snapshot_id', $snapshotIds->all())
                    ->pluck('id'));
            }
        }

        $planIds = collect();
        if ($this->hasTable('execution_plan_actions') && $this->hasTable('execution_plans')) {
            $planIds = DB::table('execution_plan_actions')
                ->where('workspace_id', $workspaceId)
                ->where('account_id', $accountId)
                ->pluck('execution_plan_id');
            if ($planIds->isNotEmpty()) {
                $runIds = $runIds->merge(DB::table('execution_plans')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('id', $planIds->all())
                    ->pluck('objective_run_id'));
            }
        }

        /** @var list<string> $runIdList */
        $runIdList = $runIds
            ->filter(fn (mixed $runId): bool => is_string($runId) && $runId !== '')
            ->map(fn (mixed $runId): string => (string) $runId)
            ->unique()
            ->sort()
            ->values()
            ->all();
        if ($runIdList === []) {
            return;
        }

        if (! $workspace->isOwner($actor)) {
            throw new AuthorizationException('Only the workspace owner can delete an Account referenced by Objective runs or plans.');
        }

        foreach ($runIdList as $runId) {
            $run = ObjectiveRun::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($runId)
                ->first();
            if ($run !== null) {
                $this->cancelObjectiveRun->handle($run, $actor, 'Account deletion invalidated the frozen audience and exact plan.');
            }
        }

        if ($this->hasUnresolvedProviderWork($workspaceId, $runIdList)) {
            // The outer deletion transaction rolls the cancellations back.
            // Retry after the existing provider-operation reconciler reaches
            // an authoritative terminal state; never erase its evidence or
            // create a second provider boundary.
            throw ValidationException::withMessages([
                'account' => 'Account deletion is waiting for in-flight Objective provider work to reconcile. Retry after reconciliation.',
            ]);
        }

        DB::table('objective_run_arm_drafts')
            ->where('workspace_id', $workspaceId)
            ->whereIn('objective_run_id', $runIdList)
            ->update([
                'state' => 'cancelled',
                'subject_template' => null,
                'body_template' => null,
                'claim_manifest' => null,
                'cta_manifest' => null,
                'safe_error_code' => 'account_deleted',
                'safe_error_detail' => 'Exact drafting content was redacted because an Account in the frozen audience was deleted.',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        // The complete plan is cancelled because its canonical action set is
        // no longer executable. Retain safe hashes for audit, but redact the
        // deleted Account's pointers and exact personalized content.
        $allPlanIds = DB::table('execution_plans')
            ->where('workspace_id', $workspaceId)
            ->whereIn('objective_run_id', $runIdList)
            ->pluck('id');
        if ($allPlanIds->isEmpty()) {
            return;
        }
        DB::table('objectives')
            ->where('workspace_id', $workspaceId)
            ->whereIn('current_plan_id', $allPlanIds->all())
            ->update(['current_plan_id' => null, 'updated_at' => now()]);
        DB::table('execution_plan_actions')
            ->where('workspace_id', $workspaceId)
            ->whereIn('execution_plan_id', $allPlanIds->all())
            ->update(['state' => 'cancelled', 'updated_at' => now()]);
        DB::table('execution_plan_actions')
            ->where('workspace_id', $workspaceId)
            ->whereIn('execution_plan_id', $allPlanIds->all())
            ->where('account_id', $accountId)
            ->update([
                'account_id' => null,
                'person_id' => null,
                'destination_email_address' => null,
                'subject' => null,
                'body_text' => null,
                'body_html' => null,
                'updated_at' => now(),
            ]);
        DB::table('execution_plans')
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $allPlanIds->all())
            ->update(['status' => 'cancelled', 'updated_at' => now()]);
    }

    /** @param list<int|string> $runIds */
    private function hasUnresolvedProviderWork(int $workspaceId, array $runIds): bool
    {
        if ($runIds === [] || ! $this->hasTable('objective_run_arm_drafts') || ! $this->hasTable('ai_provider_operations')) {
            return false;
        }

        return DB::table('objective_run_arm_drafts as drafts')
            ->join('ai_provider_operations as operations', 'operations.id', '=', 'drafts.ai_provider_operation_id')
            ->where('drafts.workspace_id', $workspaceId)
            ->where('operations.workspace_id', $workspaceId)
            ->whereIn('drafts.objective_run_id', $runIds)
            ->whereIn('operations.status', [
                AiOperationStatus::Executing->value,
                AiOperationStatus::Uncertain->value,
                AiOperationStatus::Reconciling->value,
            ])
            ->exists();
    }

    private function detachObjectiveSnapshotMembers(int $workspaceId, int $accountId): void
    {
        if (! $this->hasTable('audience_snapshot_members')) {
            return;
        }
        if ($this->hasTable('gtm_experiment_assignments')) {
            $assignmentIds = DB::table('gtm_experiment_assignments')
                ->where('workspace_id', $workspaceId)
                ->where('account_id', $accountId)
                ->pluck('id');
            if ($assignmentIds->isNotEmpty() && $this->hasTable('gtm_experiment_assignment_members')) {
                DB::table('gtm_experiment_assignment_members')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('gtm_experiment_assignment_id', $assignmentIds->all())
                    ->update(['person_id' => null]);
            }
            if ($assignmentIds->isNotEmpty() && $this->hasTable('gtm_experiments')) {
                $experimentIds = DB::table('gtm_experiment_assignments')
                    ->whereIn('id', $assignmentIds->all())
                    ->pluck('gtm_experiment_id');
                DB::table('gtm_experiments')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('id', $experimentIds->all())
                    ->whereIn('status', ['assigned', 'approved', 'running'])
                    ->update(['status' => 'paused', 'updated_at' => now()]);
            }
            DB::table('gtm_experiment_assignments')
                ->where('workspace_id', $workspaceId)
                ->where('account_id', $accountId)
                ->update(['account_id' => null]);
        }
        $memberIds = $this->idsWhere('audience_snapshot_members', 'account_id', $accountId, $workspaceId);
        if ($memberIds !== [] && $this->hasTable('audience_snapshot_member_sources')) {
            DB::table('audience_snapshot_member_sources')
                ->where('workspace_id', $workspaceId)
                ->whereIn('audience_snapshot_member_id', $memberIds)
                ->update([
                    'account_source_membership_id' => null,
                    'person_source_membership_id' => null,
                    'rights_result' => 'account_deleted',
                ]);
        }
        DB::table('audience_snapshot_members')
            ->where('workspace_id', $workspaceId)
            ->where('account_id', $accountId)
            ->update([
                'account_id' => null,
                'person_id' => null,
                'qualification_decision_id' => null,
                'reason_to_contact_version_id' => null,
                'evidence_manifest' => json_encode([], JSON_THROW_ON_ERROR),
                'evidence_fresh_until' => null,
                'result' => 'excluded',
                'exclusion_reasons' => json_encode(['account_deleted'], JSON_THROW_ON_ERROR),
            ]);
    }

    public function delete(Account $account, User $actor): int
    {
        return $this->handle($account, $actor);
    }

    private function cancelResearchRuns(Workspace $workspace, int $workspaceId, int $accountId): void
    {
        if (! $this->hasTable('account_research_runs')) {
            return;
        }

        $runIds = DB::table('account_research_runs')
            ->where('workspace_id', $workspaceId)
            ->where('account_id', $accountId)
            ->whereIn('state', self::ACTIVE_RESEARCH_STATES)
            ->orderBy('id')
            ->pluck('id');

        foreach ($runIds as $runId) {
            // The cancellation boundary retires pending dispatches and
            // closes the run before its rows are removed below.  Provider
            // operations remain workspace-owned and are reconciled by the
            // existing AI reconciliation job rather than replayed here.
            $this->cancelResearch->handle($workspace, (string) $runId);
        }

        // CancelAccountResearch intentionally leaves attempts as an audit
        // record.  Since account deletion removes that record, close any
        // in-flight attempt first so a worker that races the delete cannot
        // mistake it for a live attempt during a retry window.
        if ($runIds->isNotEmpty() && $this->hasTable('account_research_attempts')) {
            DB::table('account_research_attempts')
                ->where('workspace_id', $workspaceId)
                ->whereIn('research_run_id', $runIds->all())
                ->whereIn('state', ['planned', 'running', 'provider_boundary', 'waiting_budget', 'ambiguous'])
                ->update([
                    'state' => 'cancelled',
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
        }
    }

    private function detachConversionMatches(int $workspaceId, int $accountId): void
    {
        if ($this->hasTable('conversion_events') && $this->hasTable('conversion_event_receipts')) {
            $receiptIds = DB::table('conversion_events')
                ->where('workspace_id', $workspaceId)
                ->where('matched_account_id', $accountId)
                ->whereNotNull('receipt_id')
                ->pluck('receipt_id');

            if ($receiptIds->isNotEmpty()) {
                // Matched events can still retain the temporary raw identity
                // envelope on their receipt.  Account erasure removes that
                // reversible material while retaining receipt hashes/state.
                DB::table('conversion_event_receipts')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('id', $receiptIds->all())
                    ->whereNotNull('unmatched_identity_envelope')
                    ->update([
                        'unmatched_identity_envelope' => null,
                        'identity_redacted_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        }

        if ($this->hasTable('conversion_event_matches')) {
            DB::table('conversion_event_matches')
                ->where('workspace_id', $workspaceId)
                ->where('matched_account_id', $accountId)
                ->update(['matched_account_id' => null]);
        }

        if ($this->hasTable('conversion_events')) {
            // Keep the immutable event and its review history, but make the
            // current projection truthful now that its canonical account is
            // gone.  The safe code explains why the match was detached.
            DB::table('conversion_events')
                ->where('workspace_id', $workspaceId)
                ->where('matched_account_id', $accountId)
                ->update([
                    'matched_account_id' => null,
                    'state' => ConversionEventState::Unmatched->value,
                    'safe_rejection_code' => 'account_deleted',
                    'updated_at' => now(),
                ]);
        }
    }

    private function deleteFulfilmentData(int $workspaceId, int $accountId): void
    {
        if (! $this->hasTable('fulfilment_requests')) {
            return;
        }

        $requestIds = DB::table('fulfilment_requests')
            ->where('workspace_id', $workspaceId)
            ->where('account_id', $accountId)
            ->pluck('id');

        if ($requestIds->isEmpty()) {
            return;
        }

        // Release capacity before removing reservations.  This keeps the
        // capability projection correct for the other accounts in the
        // workspace and serialises the update against new reservations.
        if ($this->hasTable('fulfilment_capacity_reservations') && $this->hasTable('fulfilment_capacity_windows')) {
            $reservations = DB::table('fulfilment_capacity_reservations')
                ->where('workspace_id', $workspaceId)
                ->whereIn('fulfilment_request_id', $requestIds->all())
                ->where('state', 'active')
                ->get(['capacity_window_id', 'units']);

            foreach ($reservations as $reservation) {
                $window = DB::table('fulfilment_capacity_windows')
                    ->where('workspace_id', $workspaceId)
                    ->where('id', $reservation->capacity_window_id)
                    ->lockForUpdate()
                    ->first();
                if ($window === null) {
                    continue;
                }

                $reserved = max(0, (int) $window->reserved_units - (int) $reservation->units);
                DB::table('fulfilment_capacity_windows')
                    ->where('workspace_id', $workspaceId)
                    ->where('id', $reservation->capacity_window_id)
                    ->update(['reserved_units' => $reserved, 'updated_at' => now()]);
            }
        }

        if ($this->hasTable('fulfilment_capacity_reservations')) {
            DB::table('fulfilment_capacity_reservations')
                ->where('workspace_id', $workspaceId)
                ->whereIn('fulfilment_request_id', $requestIds->all())
                ->delete();
        }

        DB::table('fulfilment_requests')
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $requestIds->all())
            ->delete();
    }

    private function deleteQualificationData(int $workspaceId, int $accountId): void
    {
        if (! $this->hasTable('qualification_decisions')) {
            return;
        }

        $decisionIds = $this->idsWhere('qualification_decisions', 'account_id', $accountId, $workspaceId);
        $reasonIds = $this->idsWhere('reason_to_contact_versions', 'account_id', $accountId, $workspaceId);

        if ($this->hasTable('qualification_review_events')) {
            if ($decisionIds !== []) {
                // A successor pointer can be held by a review event from a
                // prior projection. Null it before deleting the decision;
                // events owned by this account are removed as PII-linked
                // audit rows below.
                DB::table('qualification_review_events')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('successor_decision_id', $decisionIds)
                    ->update(['successor_decision_id' => null]);
                DB::table('qualification_review_events')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('decision_id', $decisionIds)
                    ->delete();
            }
            DB::table('qualification_review_events')
                ->where('workspace_id', $workspaceId)
                ->where('account_id', $accountId)
                ->delete();
        }

        if ($this->hasTable('reason_to_contact_claims') && $reasonIds !== []) {
            DB::table('reason_to_contact_claims')
                ->where('workspace_id', $workspaceId)
                ->whereIn('reason_to_contact_version_id', $reasonIds)
                ->delete();
        }
        if ($this->hasTable('reason_to_contact_versions') && $reasonIds !== []) {
            DB::table('reason_to_contact_versions')
                ->where('workspace_id', $workspaceId)
                ->whereIn('successor_id', $reasonIds)
                ->update(['successor_id' => null]);
            DB::table('reason_to_contact_versions')
                ->where('workspace_id', $workspaceId)
                ->whereIn('id', $reasonIds)
                ->delete();
        }

        if ($decisionIds !== []) {
            if ($this->hasTable('qualification_decision_claims')) {
                DB::table('qualification_decision_claims')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('decision_id', $decisionIds)
                    ->delete();
            }
            DB::table('qualification_decisions')
                ->where('workspace_id', $workspaceId)
                ->whereIn('prior_decision_id', $decisionIds)
                ->update(['prior_decision_id' => null]);
            DB::table('qualification_decisions')
                ->where('workspace_id', $workspaceId)
                ->whereIn('id', $decisionIds)
                ->delete();
        }
    }

    private function deleteResearchData(int $workspaceId, int $accountId): void
    {
        $runIds = $this->idsWhere('account_research_runs', 'account_id', $accountId, $workspaceId);
        $claimIds = $this->idsWhere('research_claims', 'account_id', $accountId, $workspaceId);
        $snapshotIds = $this->idsWhere('research_source_snapshots', 'account_id', $accountId, $workspaceId);

        if ($runIds !== [] && $this->hasTable('qualification_review_events')) {
            // A review event may belong to another projection while pointing
            // at this Account's run as its successor. Keep that audit row,
            // but remove the now-invalid successor edge.
            DB::table('qualification_review_events')
                ->where('workspace_id', $workspaceId)
                ->whereIn('successor_run_id', $runIds)
                ->update(['successor_run_id' => null]);
        }

        if ($this->hasTable('research_claim_events')) {
            if ($claimIds !== []) {
                DB::table('research_claim_events')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('successor_claim_id', $claimIds)
                    ->update(['successor_claim_id' => null]);
                DB::table('research_claim_events')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('claim_id', $claimIds)
                    ->delete();
            }
            DB::table('research_claim_events')
                ->where('workspace_id', $workspaceId)
                ->where('account_id', $accountId)
                ->delete();
        }

        if ($this->hasTable('research_claim_evidence')) {
            $evidence = DB::table('research_claim_evidence')->where('workspace_id', $workspaceId);
            if ($claimIds !== [] || $snapshotIds !== []) {
                $evidence->where(function (Builder $query) use ($claimIds, $snapshotIds): void {
                    if ($claimIds !== []) {
                        $query->whereIn('claim_id', $claimIds);
                    }
                    if ($snapshotIds !== []) {
                        $query->orWhereIn('source_snapshot_id', $snapshotIds);
                    }
                });
                $evidence->delete();
            }
        }

        if ($claimIds !== []) {
            // Claims can be referenced by a later decision/reason projection
            // even when that projection belongs to another account. Those
            // joins are disposable links; removing them keeps the surviving
            // decision/reason audit row valid without retaining deleted claim
            // identifiers as dangling foreign keys.
            if ($this->hasTable('qualification_decision_claims')) {
                DB::table('qualification_decision_claims')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('claim_id', $claimIds)
                    ->delete();
            }
            if ($this->hasTable('reason_to_contact_claims')) {
                DB::table('reason_to_contact_claims')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('claim_id', $claimIds)
                    ->delete();
            }
        }

        if ($this->hasTable('account_research_run_inputs')) {
            if ($runIds !== []) {
                DB::table('account_research_run_inputs')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('research_run_id', $runIds)
                    ->delete();
            }
            if ($claimIds !== []) {
                DB::table('account_research_run_inputs')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('prior_claim_id', $claimIds)
                    ->update(['prior_claim_id' => null]);
            }
        }

        if ($this->hasTable('research_source_snapshots') && $snapshotIds !== []) {
            DB::table('research_source_snapshots')
                ->where('workspace_id', $workspaceId)
                ->whereIn('id', $snapshotIds)
                ->delete();
        }
        if ($this->hasTable('research_claims') && $claimIds !== []) {
            DB::table('research_claims')
                ->where('workspace_id', $workspaceId)
                ->whereIn('supersedes_claim_id', $claimIds)
                ->update(['supersedes_claim_id' => null]);
            DB::table('research_claims')
                ->where('workspace_id', $workspaceId)
                ->whereIn('id', $claimIds)
                ->delete();
        }

        if ($runIds !== []) {
            if ($this->hasTable('account_research_dispatches')) {
                DB::table('account_research_dispatches')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('research_run_id', $runIds)
                    ->delete();
            }
            if ($this->hasTable('account_research_attempts')) {
                DB::table('account_research_attempts')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('research_run_id', $runIds)
                    ->delete();
            }
            if ($this->hasTable('account_research_stages')) {
                DB::table('account_research_stages')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('research_run_id', $runIds)
                    ->delete();
            }
            DB::table('account_research_runs')
                ->where('workspace_id', $workspaceId)
                ->whereIn('parent_run_id', $runIds)
                ->update(['parent_run_id' => null]);
            DB::table('account_research_runs')
                ->where('workspace_id', $workspaceId)
                ->whereIn('id', $runIds)
                ->delete();
        }
    }

    private function deleteLegacyAccountData(int $workspaceId, int $accountId): void
    {
        // These tables intentionally retain no account-level audit after the
        // canonical record is erased. Campaign recipient snapshots use
        // null-on-delete and therefore remain untouched.
        $this->deleteWhere('mail_experiment_assignments', $workspaceId, 'account_id', $accountId);
        $this->deleteWhere('account_source_memberships', $workspaceId, 'account_id', $accountId);
        $this->deleteWhere('canonical_field_provenance', $workspaceId, 'account_id', $accountId);
        $this->deleteWhere('external_identities', $workspaceId, 'account_id', $accountId);
    }

    private function hasTable(string $table): bool
    {
        return Schema::hasTable($table);
    }

    /** @return list<string> */
    private function idsWhere(string $table, string $column, int|string $value, int $workspaceId): array
    {
        if (! $this->hasTable($table)) {
            return [];
        }

        return array_values(DB::table($table)
            ->where('workspace_id', $workspaceId)
            ->where($column, $value)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all());
    }

    private function deleteWhere(string $table, int $workspaceId, string $column, int|string $value): void
    {
        if (! $this->hasTable($table)) {
            return;
        }

        DB::table($table)
            ->where('workspace_id', $workspaceId)
            ->where($column, $value)
            ->delete();
    }
}
