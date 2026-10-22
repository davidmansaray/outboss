<?php

namespace App\Domain\Workspaces\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permanently removes an unshared personal workspace and its data.
 *
 * Workspace foreign keys are intentionally restrictive. This service is the
 * explicit aggregate-purge boundary: it clears secret material first, then
 * traverses dependent source/provider rows before removing the workspace.
 * Query-builder deletes are deliberate here so immutable audit models cannot
 * veto an authorised aggregate purge.
 */
final class DeleteWorkspace
{
    public function handle(Workspace $workspace, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($workspace, $actor): bool {
            $locked = Workspace::query()
                ->whereKey($workspace->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || (string) $locked->getAttribute('type') !== 'personal') {
                return false;
            }

            $memberships = DB::table('workspace_memberships')
                ->where('workspace_id', $locked->id)
                ->count();
            if ($memberships !== 1) {
                return false;
            }

            $owner = DB::table('workspace_memberships')
                ->where('workspace_id', $locked->id)
                ->where('role', WorkspaceMembership::ROLE_OWNER)
                ->when($actor !== null, fn ($query) => $query->where('user_id', $actor->id))
                ->exists();
            if (! $owner) {
                return false;
            }

            $workspaceId = (int) $locked->id;
            $this->deleteWorkspaceData($workspaceId);

            return Workspace::query()->whereKey($workspaceId)->delete() > 0;
        }, 3);
    }

    public function delete(Workspace $workspace, ?User $actor = null): bool
    {
        return $this->handle($workspace, $actor);
    }

    private function deleteWorkspaceData(int $workspaceId): void
    {
        // Secret material is cleared while its owning rows still exist. The
        // entire purge is transactional, so a failed deletion leaves the
        // aggregate available for a safe retry.
        $this->wipeCredentialSecrets($workspaceId);

        // These aggregates have immutable rows and cross-links to provider
        // budgets/operations, so they must be purged before those providers.
        $this->deleteWhere('workspace_data_export_events', 'workspace_id', $workspaceId);
        $this->deleteObjectiveData($workspaceId);
        // Research runs and qualification versions point at GTM/Offer
        // versions, so clear the intelligence graph before those aggregates.
        $this->deleteAccountIntelligenceData($workspaceId);
        $this->deleteOfferFulfilmentData($workspaceId);
        $this->deleteConversionData($workspaceId);
        $this->deleteGtmData($workspaceId);
        $this->deleteMailExperimentData($workspaceId);
        $this->deleteImportData($workspaceId);
        $this->deleteLegacyMailData($workspaceId);
        $this->deleteIntegrationData($workspaceId);
        $this->deleteSourceData($workspaceId);
        $this->deleteCanonicalPeopleAndAccounts($workspaceId);
        $this->deleteAiData($workspaceId);

        // These rows are not otherwise reachable through a workspace model
        // relation, but their FKs still point at the workspace.
        $this->deleteWhere('personal_access_tokens', 'workspace_id', $workspaceId);
        $this->deleteWhere('workspace_client_operations', 'resulting_workspace_id', $workspaceId);
        $this->deleteWhere('workspace_memberships', 'workspace_id', $workspaceId);
    }

    /**
     * Remove the S3 targeting foundation before its Account, source and user
     * parents. Query-builder writes are the explicit aggregate-purge boundary
     * for otherwise immutable snapshot/version rows.
     */
    private function deleteObjectiveData(int $workspaceId): void
    {
        if ($this->hasTable('attribution_assessments')) {
            $this->deleteWhere('attribution_assessment_candidates', 'workspace_id', $workspaceId);
            $this->updateWhere('attribution_assessments', 'workspace_id', $workspaceId, [
                'prior_assessment_id' => null,
            ]);
            $this->deleteWhere('attribution_assessments', 'workspace_id', $workspaceId);
            $this->updateWhere('gtm_conversation_events', 'workspace_id', $workspaceId, [
                'prior_event_id' => null,
            ]);
            $this->deleteWhere('gtm_conversation_events', 'workspace_id', $workspaceId);
            $this->deleteWhere('gtm_conversations', 'workspace_id', $workspaceId);
            $this->deleteWhere('objective_outcome_progress', 'workspace_id', $workspaceId);
            // Exposures reference their append-only execution source event,
            // so remove the commercial projection before the action history.
            $this->deleteWhere('exposures', 'workspace_id', $workspaceId);
        }

        if ($this->hasTable('objective_runs')) {
            // Fence active workers before removing their durable state. Any
            // already-issued provider work remains governed by the workspace
            // AI purge below and cannot be replayed by an Objective worker.
            DB::table('objective_runs')->where('workspace_id', $workspaceId)->update([
                'state' => 'cancelled',
                'lease_owner' => null,
                'lease_expires_at' => null,
                'fencing_token' => DB::raw('fencing_token + 1'),
                'cancellation_reason_code' => 'workspace_deleted',
                'cancellation_detail' => 'The owning workspace was deleted.',
                'cancelled_at' => now(),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->deleteWhere('execution_action_events', 'workspace_id', $workspaceId);
            $this->deleteWhere('approval_events', 'workspace_id', $workspaceId);
            $this->deleteWhere('approval_grants', 'workspace_id', $workspaceId);
            $this->deleteWhere('approval_batches', 'workspace_id', $workspaceId);
            $this->deleteWhere('policy_evaluations', 'workspace_id', $workspaceId);
            $this->deleteWhere('execution_plan_action_compilations', 'workspace_id', $workspaceId);
            $this->deleteWhere('execution_plan_campaigns', 'workspace_id', $workspaceId);
            $this->deleteWhere('execution_plan_compilations', 'workspace_id', $workspaceId);
            $this->deleteWhere('execution_plan_actions', 'workspace_id', $workspaceId);
            $this->updateWhere('objectives', 'workspace_id', $workspaceId, ['current_plan_id' => null]);
            $this->deleteWhere('execution_plans', 'workspace_id', $workspaceId);
            $this->deleteWhere('objective_run_arm_drafts', 'workspace_id', $workspaceId);
            $this->deleteWhere('objective_run_dispatches', 'workspace_id', $workspaceId);
            $this->deleteWhere('objective_run_phases', 'workspace_id', $workspaceId);
            $this->updateWhere('objective_runs', 'workspace_id', $workspaceId, ['superseded_by_run_id' => null]);
            $this->deleteWhere('objective_runs', 'workspace_id', $workspaceId);
        }

        $this->deleteWhere('gtm_experiment_assignment_members', 'workspace_id', $workspaceId);
        $this->deleteWhere('gtm_experiment_assignments', 'workspace_id', $workspaceId);
        $this->deleteWhere('gtm_experiment_arms', 'workspace_id', $workspaceId);
        $this->deleteWhere('gtm_experiments', 'workspace_id', $workspaceId);

        $this->updateWhere('strategies', 'workspace_id', $workspaceId, ['current_version_id' => null]);
        $this->deleteWhere('strategy_versions', 'workspace_id', $workspaceId);
        $this->deleteWhere('strategies', 'workspace_id', $workspaceId);

        $this->deleteWhere('objective_state_events', 'workspace_id', $workspaceId);
        $this->updateWhere('objectives', 'workspace_id', $workspaceId, [
            'current_version_id' => null,
            'current_plan_id' => null,
        ]);
        $this->deleteWhere('objective_versions', 'workspace_id', $workspaceId);
        $this->deleteWhere('audience_snapshot_member_sources', 'workspace_id', $workspaceId);
        $this->deleteWhere('audience_snapshot_members', 'workspace_id', $workspaceId);
        $this->deleteWhere('audience_snapshots', 'workspace_id', $workspaceId);
        $this->deleteWhere('objectives', 'workspace_id', $workspaceId);

        $this->updateWhere('segments', 'workspace_id', $workspaceId, ['current_version_id' => null]);
        $this->deleteWhere('segment_versions', 'workspace_id', $workspaceId);
        $this->deleteWhere('segments', 'workspace_id', $workspaceId);

        $this->updateWhere('outreach_policies', 'workspace_id', $workspaceId, ['current_version_id' => null]);
        $this->deleteWhere('outreach_policy_versions', 'workspace_id', $workspaceId);
        $this->deleteWhere('outreach_policies', 'workspace_id', $workspaceId);
    }

    private function wipeCredentialSecrets(int $workspaceId): void
    {
        if ($this->hasTable('provider_connections')) {
            DB::table('provider_connections')
                ->where('workspace_id', $workspaceId)
                ->update([
                    'access_token' => null,
                    'refresh_token' => null,
                    'credential_payload' => null,
                    'provider_metadata' => null,
                ]);
        }

        if ($this->hasTable('oauth_attempts')) {
            DB::table('oauth_attempts')
                ->where('workspace_id', $workspaceId)
                ->update(['pkce_verifier' => null]);
        }

        if ($this->hasTable('integration_credential_versions')) {
            DB::table('integration_credential_versions')
                ->where('workspace_id', $workspaceId)
                ->update([
                    'encrypted_secret' => null,
                    'status' => 'wiped',
                    'wiped_at' => now(),
                ]);
        }

        if ($this->hasTable('ai_provider_credentials') && $this->hasTable('ai_provider_credential_versions')) {
            $credentialIds = $this->idsWhere('ai_provider_credentials', 'workspace_id', $workspaceId);
            $this->updateWhereIn(
                'ai_provider_credential_versions',
                'credential_id',
                $credentialIds,
                [
                    'encrypted_secret' => null,
                    'status' => 'wiped',
                    'wiped_at' => now(),
                ],
            );
        }

        // Conversion connection versions contain encrypted webhook/API
        // secrets.  Scrub them while the rows still exist; the subsequent
        // aggregate purge uses the same transaction and cannot leave a
        // secret-bearing row behind on a retry.
        if ($this->hasTable('conversion_source_connection_versions')) {
            DB::table('conversion_source_connection_versions')
                ->where('workspace_id', $workspaceId)
                ->update([
                    'encrypted_secret' => null,
                    'status' => 'wiped',
                    'wiped_at' => now(),
                ]);
        }

        // Unmatched conversion identity material is encrypted temporary
        // data.  It is not an audit handle and must be removed before its
        // append-only receipt is deleted.
        if ($this->hasTable('conversion_event_receipts')) {
            DB::table('conversion_event_receipts')
                ->where('workspace_id', $workspaceId)
                ->whereNotNull('unmatched_identity_envelope')
                ->update([
                    'unmatched_identity_envelope' => null,
                    'identity_redacted_at' => now(),
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Purge the S2 Offer/Asset/Fulfilment graph before deleting conversion
     * events, Offer Versions or the workspace.  Offer and Asset current
     * pointers are restrictive, so they are nulled before immutable versions
     * and readiness rows are removed.
     */
    private function deleteOfferFulfilmentData(int $workspaceId): void
    {
        $this->deleteWhere('fulfilment_capacity_reservations', 'workspace_id', $workspaceId);
        $this->deleteWhere('fulfilment_requests', 'workspace_id', $workspaceId);
        $this->deleteWhere('offer_version_assets', 'workspace_id', $workspaceId);

        // Both pointers are restrictive in the reverse direction.  Clear
        // them before deleting the immutable evaluation/version rows.
        $this->updateWhere('offers', 'workspace_id', $workspaceId, [
            'current_readiness_evaluation_id' => null,
            'current_version_id' => null,
        ]);
        $this->deleteWhere('offer_readiness_evaluations', 'workspace_id', $workspaceId);
        $this->deleteWhere('offer_version_review_events', 'workspace_id', $workspaceId);

        // Asset operation receipts reference immutable versions, so remove
        // them before clearing the version history below.
        if ($this->hasTable('asset_client_operations')) {
            $this->deleteWhere('asset_client_operations', 'workspace_id', $workspaceId);
        }
        $this->updateWhere('assets', 'workspace_id', $workspaceId, ['current_version_id' => null]);
        $this->deleteWhere('asset_versions', 'workspace_id', $workspaceId);
        $this->deleteWhere('assets', 'workspace_id', $workspaceId);

        $offerIds = $this->idsWhere('offers', 'workspace_id', $workspaceId);
        $this->deleteWhereIn('offer_client_operations', 'offer_id', $offerIds);
        $this->deleteWhereIn('offer_versions', 'offer_id', $offerIds);
        $this->deleteWhere('offers', 'workspace_id', $workspaceId);

        // Capacity windows reference capabilities, and reservations have
        // already been removed above.  Delete windows before capabilities.
        $this->deleteWhere('fulfilment_capacity_windows', 'workspace_id', $workspaceId);
        $this->deleteWhere('fulfilment_capabilities', 'workspace_id', $workspaceId);
    }

    /**
     * Delete the Conversion graph in dependency order.  Receipts and events
     * intentionally reference one another, while events also have three
     * restrictive self-lineage pointers.  Clear the receipt projection and
     * self references before deleting the append-only rows.
     */
    private function deleteConversionData(int $workspaceId): void
    {
        $this->deleteWhere('conversion_event_review_events', 'workspace_id', $workspaceId);
        $this->deleteWhere('conversion_event_matches', 'workspace_id', $workspaceId);

        $this->updateWhere('conversion_event_receipts', 'workspace_id', $workspaceId, [
            'conversion_event_id' => null,
        ]);
        $this->updateWhere('conversion_events', 'workspace_id', $workspaceId, [
            'lineage_root_id' => null,
            'corrects_event_id' => null,
            'reverses_event_id' => null,
        ]);
        $this->deleteWhere('conversion_events', 'workspace_id', $workspaceId);
        $this->deleteWhere('conversion_event_receipts', 'workspace_id', $workspaceId);

        $this->updateWhere('conversion_source_connections', 'workspace_id', $workspaceId, [
            'current_version_id' => null,
        ]);
        $this->deleteWhere('conversion_source_connection_versions', 'workspace_id', $workspaceId);
        $this->deleteWhere('conversion_source_connections', 'workspace_id', $workspaceId);

        $this->updateWhere('conversion_definitions', 'workspace_id', $workspaceId, [
            'current_version_id' => null,
        ]);
        $this->deleteWhere('conversion_definition_versions', 'workspace_id', $workspaceId);
        $this->deleteWhere('conversion_definitions', 'workspace_id', $workspaceId);
    }

    /**
     * Purge Account-owned research, evidence and qualification rows.  These
     * models are append-only at the application boundary; the aggregate
     * deletion is an authorised exception and therefore deliberately uses
     * query-builder operations.  Every self-reference is cleared before its
     * parent rows are removed.
     */
    private function deleteAccountIntelligenceData(int $workspaceId): void
    {
        $this->deleteWhere('qualification_review_events', 'workspace_id', $workspaceId);
        $this->deleteWhere('reason_to_contact_claims', 'workspace_id', $workspaceId);
        $this->updateWhere('reason_to_contact_versions', 'workspace_id', $workspaceId, ['successor_id' => null]);
        $this->deleteWhere('reason_to_contact_versions', 'workspace_id', $workspaceId);
        $this->deleteWhere('qualification_decision_claims', 'workspace_id', $workspaceId);
        $this->updateWhere('qualification_decisions', 'workspace_id', $workspaceId, ['prior_decision_id' => null]);
        $this->deleteWhere('qualification_decisions', 'workspace_id', $workspaceId);

        $this->updateWhere('qualification_rubrics', 'workspace_id', $workspaceId, ['current_version_id' => null]);
        $this->deleteWhere('qualification_rubric_versions', 'workspace_id', $workspaceId);
        $this->deleteWhere('qualification_rubrics', 'workspace_id', $workspaceId);

        $this->deleteWhere('research_claim_events', 'workspace_id', $workspaceId);
        $this->deleteWhere('research_claim_evidence', 'workspace_id', $workspaceId);
        $this->deleteWhere('account_research_run_inputs', 'workspace_id', $workspaceId);
        $this->deleteWhere('research_source_snapshots', 'workspace_id', $workspaceId);
        $this->updateWhere('research_claims', 'workspace_id', $workspaceId, ['supersedes_claim_id' => null]);
        $this->deleteWhere('research_claims', 'workspace_id', $workspaceId);
        $this->deleteWhere('account_research_dispatches', 'workspace_id', $workspaceId);
        $this->deleteWhere('account_research_attempts', 'workspace_id', $workspaceId);
        $this->deleteWhere('account_research_stages', 'workspace_id', $workspaceId);
        $this->updateWhere('account_research_runs', 'workspace_id', $workspaceId, ['parent_run_id' => null]);
        $this->deleteWhere('account_research_runs', 'workspace_id', $workspaceId);
    }

    private function deleteGtmData(int $workspaceId): void
    {
        $profileIds = $this->idsWhere('gtm_profiles', 'workspace_id', $workspaceId);
        $offerIds = $this->idsWhere('offers', 'workspace_id', $workspaceId);

        $this->deleteWhere('workspace_client_operations', 'resulting_workspace_id', $workspaceId);

        if ($profileIds !== []) {
            $researchRunIds = $this->idsWhereIn('gtm_profile_research_runs', 'id', 'gtm_profile_id', $profileIds);
            $phaseIds = $this->idsWhereIn('gtm_profile_research_phases', 'id', 'research_run_id', $researchRunIds);

            $this->deleteWhereIn('gtm_profile_sources', 'gtm_profile_id', $profileIds);
            $this->deleteWhereIn('gtm_profile_review_items', 'gtm_profile_id', $profileIds);
            $this->deleteWhereIn('gtm_profile_client_operations', 'gtm_profile_id', $profileIds);
            $this->deleteWhereIn('gtm_profile_research_dispatches', 'gtm_profile_id', $profileIds);
            $this->deleteWhereIn('gtm_profile_research_attempts', 'research_run_id', $researchRunIds);
            $this->deleteWhereIn('gtm_profile_research_attempts', 'phase_id', $phaseIds);
            $this->deleteWhereIn('gtm_profile_research_phases', 'research_run_id', $researchRunIds);

            $this->updateWhereIn('gtm_profile_research_runs', 'parent_run_id', $researchRunIds, ['parent_run_id' => null]);
            $this->updateWhereIn('gtm_profile_research_runs', 'id', $researchRunIds, ['parent_run_id' => null]);
            $this->deleteWhereIn('gtm_profile_research_runs', 'id', $researchRunIds);

            $this->updateWhereIn('gtm_profiles', 'id', $profileIds, [
                'current_version_id' => null,
                'current_research_run_id' => null,
            ]);
            $this->deleteWhereIn('gtm_profile_versions', 'gtm_profile_id', $profileIds);
            $this->deleteWhereIn('gtm_profiles', 'id', $profileIds);
        }

        if ($offerIds !== []) {
            $this->deleteWhereIn('offer_client_operations', 'offer_id', $offerIds);
            $this->updateWhereIn('offers', 'id', $offerIds, ['current_version_id' => null]);
            $this->deleteWhereIn('offer_versions', 'offer_id', $offerIds);
            $this->deleteWhereIn('offers', 'id', $offerIds);
        }
    }

    private function deleteMailExperimentData(int $workspaceId): void
    {
        $experimentIds = $this->idsWhere('mail_experiments', 'workspace_id', $workspaceId);
        if ($experimentIds === []) {
            return;
        }

        $cohortIds = $this->idsWhereIn('mail_experiment_cohorts', 'id', 'mail_experiment_id', $experimentIds);
        $this->deleteWhere('mail_experiment_assignments', 'workspace_id', $workspaceId);
        $this->deleteWhereIn('mail_experiment_cohort_mailbox', 'mail_experiment_id', $experimentIds);
        $this->deleteWhereIn('mail_experiment_cohorts', 'id', $cohortIds);
        $this->deleteWhereIn('mail_experiments', 'id', $experimentIds);
    }

    private function deleteImportData(int $workspaceId): void
    {
        $recipientImportIds = $this->idsWhere('recipient_imports', 'workspace_id', $workspaceId);
        $this->deleteWhereIn('recipient_import_rows', 'recipient_import_id', $recipientImportIds);
        $this->deleteWhereIn('recipient_imports', 'id', $recipientImportIds);

        $personImportIds = $this->idsWhere('person_imports', 'workspace_id', $workspaceId);
        $this->deleteWhereIn('person_import_rows', 'person_import_id', $personImportIds);
        $this->deleteWhereIn('person_imports', 'id', $personImportIds);

        $this->deleteWhere('suppression_entries', 'workspace_id', $workspaceId);
    }

    private function deleteLegacyMailData(int $workspaceId): void
    {
        $this->deleteManagedMailData($workspaceId);

        $messageIds = $this->idsWhere('messages', 'workspace_id', $workspaceId);
        $sendAttemptIds = $this->idsWhere('send_attempts', 'workspace_id', $workspaceId);
        $campaignIds = $this->idsWhere('campaigns', 'workspace_id', $workspaceId);
        $mailboxIds = $this->idsWhere('mailboxes', 'workspace_id', $workspaceId);
        $sequenceVersionIds = $this->idsWhereIn('sequence_versions', 'id', 'campaign_id', $campaignIds);

        $this->deleteWhereIn('message_events', 'message_id', $messageIds);
        $this->deleteWhereIn('send_attempt_resolutions', 'send_attempt_id', $sendAttemptIds);
        $this->deleteWhere('send_attempts', 'workspace_id', $workspaceId);

        // Messages have a nullable self-reference. Nulling it explicitly
        // keeps this purge portable across SQLite/MySQL FK implementations.
        $this->updateWhere('messages', 'workspace_id', $workspaceId, ['reply_to_message_id' => null]);
        $this->deleteWhere('messages', 'workspace_id', $workspaceId);
        $this->deleteWhere('threads', 'workspace_id', $workspaceId);
        $this->deleteWhere('campaign_recipients', 'workspace_id', $workspaceId);
        $this->deleteWhereIn('campaign_mailbox', 'campaign_id', $campaignIds);

        $this->deleteWhereIn('sequence_steps', 'sequence_version_id', $sequenceVersionIds);
        $this->updateWhere('campaigns', 'workspace_id', $workspaceId, [
            'draft_sequence_version_id' => null,
            'published_sequence_version_id' => null,
        ]);
        $this->deleteWhereIn('sequence_versions', 'id', $sequenceVersionIds);
        $this->deleteWhere('campaigns', 'workspace_id', $workspaceId);

        $this->deleteWhereIn('mailbox_sync_states', 'mailbox_id', $mailboxIds);
        $this->deleteWhere('oauth_attempts', 'workspace_id', $workspaceId);
        $this->deleteWhere('mailboxes', 'workspace_id', $workspaceId);
        $this->deleteWhere('provider_connections', 'workspace_id', $workspaceId);
    }

    private function deleteManagedMailData(int $workspaceId): void
    {
        $domainIds = $this->idsWhere('managed_mail_domains', 'workspace_id', $workspaceId);
        // Events are scoped directly to the workspace and may outlive a
        // domain, so they must be removed even when there are no domain rows.
        $this->deleteWhere('managed_mail_events', 'workspace_id', $workspaceId);
        $this->deleteWhereIn('managed_mailbox_accounts', 'managed_mail_domain_id', $domainIds);
        $this->deleteWhereIn('managed_mail_domains', 'id', $domainIds);
    }

    private function deleteIntegrationData(int $workspaceId): void
    {
        $operationIds = $this->idsWhere('external_operations', 'workspace_id', $workspaceId);
        $this->deleteWhere('external_usage_entries', 'workspace_id', $workspaceId);
        $this->deleteWhereIn('external_operation_attempts', 'operation_id', $operationIds);
        $this->deleteWhere('external_operations', 'workspace_id', $workspaceId);

        $this->deleteWhere('integration_capability_routes', 'workspace_id', $workspaceId);
        $this->deleteWhere('integration_provider_registrations', 'workspace_id', $workspaceId);

        // The active-credential pointer is restrictive in both directions.
        $this->updateWhere('integration_connections', 'workspace_id', $workspaceId, [
            'active_credential_version_id' => null,
        ]);
        $this->deleteWhere('integration_credential_versions', 'workspace_id', $workspaceId);
        $this->deleteWhere('integration_connections', 'workspace_id', $workspaceId);
        $this->deleteWhere('integration_budget_periods', 'workspace_id', $workspaceId);
        $this->deleteWhere('integration_budget_settings', 'workspace_id', $workspaceId);
    }

    private function deleteSourceData(int $workspaceId): void
    {
        $this->deleteWhere('canonical_field_provenance', 'workspace_id', $workspaceId);
        $this->deleteWhere('external_identities', 'workspace_id', $workspaceId);
        $this->deleteWhere('account_source_memberships', 'workspace_id', $workspaceId);
        $this->deleteWhere('person_source_memberships', 'workspace_id', $workspaceId);

        // Both source pointers and retry self-references are restrictive.
        $this->updateWhere('prospect_sources', 'workspace_id', $workspaceId, [
            'current_version_id' => null,
            'last_run_id' => null,
        ]);
        $this->updateWhere('source_runs', 'workspace_id', $workspaceId, ['retry_of_run_id' => null]);
        $this->deleteWhere('source_observations', 'workspace_id', $workspaceId);
        $this->deleteWhere('source_runs', 'workspace_id', $workspaceId);
        $this->deleteWhere('prospect_source_versions', 'workspace_id', $workspaceId);
        $this->deleteWhere('prospect_sources', 'workspace_id', $workspaceId);
    }

    private function deleteCanonicalPeopleAndAccounts(int $workspaceId): void
    {
        $this->deleteWhere('people', 'workspace_id', $workspaceId);
        $this->deleteWhere('accounts', 'workspace_id', $workspaceId);
    }

    private function deleteAiData(int $workspaceId): void
    {
        $operationIds = $this->idsWhere('ai_provider_operations', 'workspace_id', $workspaceId);
        $this->deleteWhere('ai_usage_ledger', 'workspace_id', $workspaceId);
        $this->deleteWhereIn('ai_provider_call_attempts', 'operation_id', $operationIds);
        $this->deleteWhere('ai_provider_operations', 'workspace_id', $workspaceId);
        $this->deleteWhere('ai_budget_runs', 'workspace_id', $workspaceId);
        $this->deleteWhere('ai_budget_envelopes', 'workspace_id', $workspaceId);
        $this->deleteWhere('ai_budget_periods', 'workspace_id', $workspaceId);
        $this->deleteWhere('ai_task_settings', 'workspace_id', $workspaceId);
        $this->deleteWhere('ai_budget_settings', 'workspace_id', $workspaceId);
        $this->deleteWhere('ai_provider_events', 'workspace_id', $workspaceId);

        $credentialIds = $this->idsWhere('ai_provider_credentials', 'workspace_id', $workspaceId);
        $this->updateWhere('ai_provider_credentials', 'workspace_id', $workspaceId, [
            'active_version_id' => null,
        ]);
        $this->deleteWhereIn('ai_provider_credential_versions', 'credential_id', $credentialIds);
        $this->deleteWhere('ai_provider_credentials', 'workspace_id', $workspaceId);
    }

    private function hasTable(string $table): bool
    {
        return Schema::hasTable($table);
    }

    private function deleteWhere(string $table, string $column, int|string $value): void
    {
        if (! $this->hasTable($table)) {
            return;
        }

        DB::table($table)->where($column, $value)->delete();
    }

    /**
     * @param  list<int|string>  $values
     */
    private function deleteWhereIn(string $table, string $column, array $values): void
    {
        if ($values === [] || ! $this->hasTable($table)) {
            return;
        }

        DB::table($table)->whereIn($column, $values)->delete();
    }

    /**
     * @param  list<int|string>  $values
     * @param  array<string, mixed>  $updates
     */
    private function updateWhereIn(string $table, string $column, array $values, array $updates): void
    {
        if ($values === [] || ! $this->hasTable($table)) {
            return;
        }

        DB::table($table)->whereIn($column, $values)->update($updates);
    }

    /** @param array<string, mixed> $updates */
    private function updateWhere(string $table, string $column, int|string $value, array $updates): void
    {
        if (! $this->hasTable($table)) {
            return;
        }

        DB::table($table)->where($column, $value)->update($updates);
    }

    /**
     * @return list<string>
     */
    private function idsWhere(string $table, string $column, int|string $value): array
    {
        if (! $this->hasTable($table)) {
            return [];
        }

        $ids = [];
        foreach (DB::table($table)->where($column, $value)->pluck('id') as $id) {
            $ids[] = (string) $id;
        }

        return $ids;
    }

    /**
     * @param  list<int|string>  $values
     * @return list<string>
     */
    private function idsWhereIn(string $table, string $idColumn, string $whereColumn, array $values): array
    {
        if ($values === [] || ! $this->hasTable($table)) {
            return [];
        }

        $ids = [];
        foreach (DB::table($table)->whereIn($whereColumn, $values)->pluck($idColumn) as $id) {
            $ids[] = (string) $id;
        }

        return $ids;
    }
}
