<?php

use App\Domain\Ai\Enums\AiCredentialStatus;
use App\Domain\Ai\Enums\AiProviderName;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Services\AiBudgetGateway;
use App\Domain\Ai\Services\AiWorkspaceSettings;
use App\Domain\People\Services\DeleteAccount;
use App\Domain\Workspaces\Services\DeleteWorkspace;
use App\Domain\Workspaces\Services\WorkspaceExportService;
use App\Models\Account;
use App\Models\AccountResearchAttempt;
use App\Models\AccountResearchDispatch;
use App\Models\AccountResearchRun;
use App\Models\AccountResearchStage;
use App\Models\AiProviderCredential;
use App\Models\ConversionDefinition;
use App\Models\ConversionDefinitionVersion;
use App\Models\ConversionEvent;
use App\Models\ConversionEventMatch;
use App\Models\ConversionEventReceipt;
use App\Models\ConversionSourceConnection;
use App\Models\ConversionSourceConnectionVersion;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('cancels and removes an account S2 graph while preserving conversion audit history', function (): void {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $account = Account::factory()->create(['workspace_id' => $workspace->id]);

    $run = AccountResearchRun::factory()->create([
        'workspace_id' => $workspace->id,
        'account_id' => $account->id,
        'state' => 'running',
    ]);
    $stage = AccountResearchStage::factory()->create([
        'workspace_id' => $workspace->id,
        'research_run_id' => $run->id,
        'state' => 'running',
    ]);
    AccountResearchAttempt::factory()->create([
        'workspace_id' => $workspace->id,
        'research_run_id' => $run->id,
        'stage_id' => $stage->id,
        'state' => 'provider_boundary',
    ]);
    AccountResearchDispatch::factory()->create([
        'workspace_id' => $workspace->id,
        'research_run_id' => $run->id,
        'state' => 'leased',
        'publish_lease_expires_at' => now()->subMinute(),
    ]);

    $claimId = (string) Str::uuid();
    $snapshotId = (string) Str::uuid();
    DB::table('research_source_snapshots')->insert(s2SnapshotRow($snapshotId, $workspace->id, $account->id, $run->id, $stage->id));
    DB::table('research_claims')->insert(s2ClaimRow($claimId, $workspace->id, $account->id, $run->id));
    DB::table('research_claim_evidence')->insert([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'claim_id' => $claimId,
        'source_snapshot_id' => $snapshotId,
        'edge_type' => 'supports',
        'locator' => null,
        'locator_hash' => str_repeat('a', 64),
        'quote_hash' => str_repeat('b', 64),
        'evidence_weight' => 1,
        'extraction_version' => 'fixture',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('research_claim_events')->insert([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'account_id' => $account->id,
        'claim_id' => $claimId,
        'action' => 'created',
        'actor_snapshot' => json_encode(['type' => 'system'], JSON_THROW_ON_ERROR),
        'actor_user_id' => null,
        'reason' => null,
        'successor_claim_id' => null,
        'prior_status' => null,
        'projected_status' => 'supported',
        'created_at' => now(),
    ]);
    DB::table('account_research_run_inputs')->insert([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'research_run_id' => $run->id,
        'input_type' => 'account',
        'source_observation_id' => null,
        'prior_claim_id' => $claimId,
        'source_policy_hash' => str_repeat('c', 64),
        'llm_use_classification' => 'allowed',
        'display_classification' => 'standard',
        'export_classification' => 'standard',
        'observed_at' => now(),
        'expires_at' => now()->addDay(),
        'content_hash' => str_repeat('d', 64),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $rubricId = (string) Str::uuid();
    $rubricVersionId = (string) Str::uuid();
    $decisionId = (string) Str::uuid();
    $reasonId = (string) Str::uuid();
    DB::table('qualification_rubrics')->insert([
        'id' => $rubricId,
        'workspace_id' => $workspace->id,
        'name' => 'Fixture rubric',
        'state' => 'active',
        'current_version_id' => null,
        'lock_version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('qualification_rubric_versions')->insert([
        'id' => $rubricVersionId,
        'workspace_id' => $workspace->id,
        'rubric_id' => $rubricId,
        'version' => 1,
        'schema_version' => 1,
        'gtm_profile_version_id' => null,
        'offer_version_id' => null,
        'dimensions' => json_encode([], JSON_THROW_ON_ERROR),
        'required_facts' => json_encode([], JSON_THROW_ON_ERROR),
        'disqualifiers' => json_encode([], JSON_THROW_ON_ERROR),
        'confidence_bands' => json_encode([], JSON_THROW_ON_ERROR),
        'sensitive_predicates' => json_encode([], JSON_THROW_ON_ERROR),
        'decision_rules' => json_encode([], JSON_THROW_ON_ERROR),
        'content_hash' => str_repeat('3', 64),
        'actor_snapshot' => json_encode(['type' => 'system'], JSON_THROW_ON_ERROR),
        'actor_user_id' => null,
        'created_at' => now(),
    ]);
    DB::table('qualification_rubrics')->where('id', $rubricId)->update(['current_version_id' => $rubricVersionId]);
    DB::table('qualification_decisions')->insert([
        'id' => $decisionId,
        'workspace_id' => $workspace->id,
        'account_id' => $account->id,
        'research_run_id' => $run->id,
        'rubric_version_id' => $rubricVersionId,
        'gtm_profile_version_id' => null,
        'offer_version_id' => null,
        'prior_decision_id' => null,
        'input_hash' => str_repeat('4', 64),
        'outcome' => 'needs_review',
        'confidence' => 0.5,
        'calibration_band' => 'medium',
        'missing_facts' => json_encode([], JSON_THROW_ON_ERROR),
        'disqualifiers' => json_encode([], JSON_THROW_ON_ERROR),
        'explanation' => 'fixture',
        'model_version' => null,
        'prompt_version' => null,
        'policy_version' => null,
        'catalogue_version' => null,
        'provider_operation_id' => null,
        'provider_cost_micros' => 0,
        'review_state' => 'pending',
        'content_hash' => str_repeat('5', 64),
        'generated_at' => now(),
        'reviewed_at' => null,
        'corrected_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('qualification_decision_claims')->insert([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'decision_id' => $decisionId,
        'claim_id' => $claimId,
        'role' => 'supports',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('reason_to_contact_versions')->insert([
        'id' => $reasonId,
        'workspace_id' => $workspace->id,
        'account_id' => $account->id,
        'qualification_decision_id' => $decisionId,
        'successor_id' => null,
        'why_account' => 'fixture',
        'why_offer' => null,
        'why_now' => null,
        'factual_basis' => null,
        'internal_rationale' => null,
        'confidence' => 0.5,
        'fresh_until' => now()->addDay(),
        'expires_at' => now()->addDay(),
        'safe_for_message' => false,
        'sensitive_inference' => false,
        'prohibited_inference' => false,
        'content_hash' => str_repeat('6', 64),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('reason_to_contact_claims')->insert([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'reason_to_contact_version_id' => $reasonId,
        'claim_id' => $claimId,
        'role' => 'factual_basis',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('qualification_review_events')->insert([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'account_id' => $account->id,
        'decision_id' => $decisionId,
        'action' => 'request_research',
        'actor_snapshot' => json_encode(['type' => 'system'], JSON_THROW_ON_ERROR),
        'actor_user_id' => null,
        'reason' => null,
        'prior_review_state' => 'pending',
        'projected_review_state' => 'research_requested',
        'successor_decision_id' => null,
        'successor_run_id' => $run->id,
        'created_at' => now(),
    ]);

    $definition = ConversionDefinition::factory()->create(['workspace_id' => $workspace->id]);
    $definitionVersion = ConversionDefinitionVersion::factory()->create([
        'workspace_id' => $workspace->id,
        'conversion_definition_id' => $definition->id,
    ]);
    $definition->forceFill(['current_version_id' => $definitionVersion->id])->save();
    $receipt = ConversionEventReceipt::factory()->create([
        'workspace_id' => $workspace->id,
        'unmatched_identity_envelope' => ['email' => 'temporary@example.test'],
        'identity_expires_at' => now()->addDay(),
    ]);
    $event = ConversionEvent::factory()->create([
        'workspace_id' => $workspace->id,
        'conversion_definition_id' => $definition->id,
        'conversion_definition_version_id' => $definitionVersion->id,
        'receipt_id' => $receipt->id,
        'matched_account_id' => $account->id,
        'state' => 'matched',
    ]);
    DB::table('conversion_event_receipts')->where('id', $receipt->id)->update(['conversion_event_id' => $event->id]);
    ConversionEventMatch::factory()->create([
        'workspace_id' => $workspace->id,
        'conversion_event_id' => $event->id,
        'matched_account_id' => $account->id,
        'result' => 'matched',
    ]);

    $capabilityId = (string) Str::uuid();
    DB::table('fulfilment_capabilities')->insert([
        'id' => $capabilityId,
        'workspace_id' => $workspace->id,
        'display_name' => 'Human support',
        'mode' => 'human_assisted',
        'status' => 'active',
        'configuration_version' => 1,
        'configuration' => json_encode([], JSON_THROW_ON_ERROR),
        'owner_user_id' => $user->id,
        'response_sla_hours' => 24,
        'capacity_limit' => 5,
        'capacity_window' => 'day',
        'health_state' => 'healthy',
        'last_checked_at' => now(),
        'safe_blocker_code' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $windowId = DB::table('fulfilment_capacity_windows')->insertGetId([
        'workspace_id' => $workspace->id,
        'fulfilment_capability_id' => $capabilityId,
        'window_key' => 'day:'.now()->format('Y-m-d'),
        'limit_units' => 5,
        'reserved_units' => 2,
        'consumed_units' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $offer = Offer::factory()->create(['workspace_id' => $workspace->id]);
    $requestId = (string) Str::uuid();
    DB::table('fulfilment_requests')->insert([
        'id' => $requestId,
        'workspace_id' => $workspace->id,
        'offer_id' => $offer->id,
        'offer_version_id' => $offer->current_version_id,
        'conversion_event_id' => $event->id,
        'account_id' => $account->id,
        'fulfilment_capability_id' => $capabilityId,
        'idempotency_key' => (string) Str::uuid(),
        'required_inputs' => json_encode([], JSON_THROW_ON_ERROR),
        'due_at' => now()->addDay(),
        'assigned_user_id' => $user->id,
        'state' => 'pending',
        'result_references' => null,
        'safe_failure_code' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('fulfilment_capacity_reservations')->insert([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'fulfilment_request_id' => $requestId,
        'fulfilment_capability_id' => $capabilityId,
        'capacity_window_id' => $windowId,
        'units' => 2,
        'state' => 'active',
        'reserved_at' => now(),
        'released_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(app(DeleteAccount::class)->handle($account, $user))->toBe(0);
    expect(Account::query()->whereKey($account->id)->exists())->toBeFalse()
        ->and(AccountResearchRun::query()->whereKey($run->id)->exists())->toBeFalse()
        ->and(DB::table('research_claims')->where('id', $claimId)->exists())->toBeFalse()
        ->and(DB::table('research_source_snapshots')->where('id', $snapshotId)->exists())->toBeFalse()
        ->and(DB::table('qualification_decisions')->where('id', $decisionId)->exists())->toBeFalse()
        ->and(DB::table('reason_to_contact_versions')->where('id', $reasonId)->exists())->toBeFalse()
        ->and(DB::table('qualification_review_events')->where('account_id', $account->id)->exists())->toBeFalse()
        ->and(DB::table('fulfilment_requests')->where('id', $requestId)->exists())->toBeFalse()
        ->and(DB::table('fulfilment_capacity_reservations')->where('fulfilment_request_id', $requestId)->exists())->toBeFalse()
        ->and(DB::table('fulfilment_capacity_windows')->where('id', $windowId)->value('reserved_units'))->toBe(0)
        ->and(ConversionEvent::query()->whereKey($event->id)->value('matched_account_id'))->toBeNull()
        ->and(DB::table('conversion_events')->where('id', $event->id)->value('state'))->toBe('unmatched')
        ->and(ConversionEventMatch::query()->where('conversion_event_id', $event->id)->value('matched_account_id'))->toBeNull()
        ->and(DB::table('conversion_event_receipts')->where('id', $receipt->id)->value('unmatched_identity_envelope'))->toBeNull()
        ->and(DB::table('conversion_event_receipts')->where('id', $receipt->id)->value('identity_redacted_at'))->not->toBeNull();
});

it('deletes an S2 workspace graph with restrictive pointers in dependency order', function (): void {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $account = Account::factory()->create(['workspace_id' => $workspace->id]);
    $offer = Offer::factory()->create(['workspace_id' => $workspace->id]);

    $run = AccountResearchRun::factory()->create([
        'workspace_id' => $workspace->id,
        'account_id' => $account->id,
        'offer_version_id' => $offer->current_version_id,
    ]);
    AccountResearchStage::factory()->create([
        'workspace_id' => $workspace->id,
        'research_run_id' => $run->id,
    ]);

    $readinessId = (string) Str::uuid();
    DB::table('offer_readiness_evaluations')->insert([
        'id' => $readinessId,
        'workspace_id' => $workspace->id,
        'offer_id' => $offer->id,
        'offer_version_id' => $offer->current_version_id,
        'evaluator_version' => 1,
        'state' => 'approved',
        'blockers' => json_encode([], JSON_THROW_ON_ERROR),
        'dependency_snapshot' => json_encode([], JSON_THROW_ON_ERROR),
        'dependency_hash' => str_repeat('a', 64),
        'trigger' => 'fixture',
        'evaluated_at' => now(),
        'valid_until' => now()->addDay(),
        'created_at' => now(),
    ]);
    DB::table('offers')->where('id', $offer->id)->update(['current_readiness_evaluation_id' => $readinessId]);
    DB::table('offer_version_review_events')->insert([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'offer_id' => $offer->id,
        'offer_version_id' => $offer->current_version_id,
        'action' => 'approve',
        'from_state' => 'in_review',
        'to_state' => 'approved',
        'actor_user_id' => $user->id,
        'actor_display_name' => 'Fixture',
        'reason' => null,
        'policy_hash' => str_repeat('b', 64),
        'created_at' => now(),
    ]);

    $assetId = (string) Str::uuid();
    $assetVersionId = (string) Str::uuid();
    DB::table('assets')->insert([
        'id' => $assetId,
        'workspace_id' => $workspace->id,
        'current_version_id' => null,
        'status' => 'active',
        'lock_version' => 0,
        'created_by_user_id' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('asset_versions')->insert([
        'id' => $assetVersionId,
        'workspace_id' => $workspace->id,
        'asset_id' => $assetId,
        'version' => 1,
        'title' => 'Fixture asset',
        'description' => null,
        'external_url' => 'https://example.test/asset',
        'media_type' => 'text/plain',
        'source' => 'fixture',
        'content_hash' => str_repeat('c', 64),
        'access_instructions' => json_encode([], JSON_THROW_ON_ERROR),
        'approval_state' => 'approved',
        'intended_audience' => json_encode([], JSON_THROW_ON_ERROR),
        'expires_at' => null,
        'availability_state' => 'available',
        'licence' => null,
        'classification' => 'public',
        'delivery_restrictions' => json_encode([], JSON_THROW_ON_ERROR),
        'tracking_metadata' => json_encode([], JSON_THROW_ON_ERROR),
        'actor_user_id' => $user->id,
        'actor_display_name' => 'Fixture',
        'created_at' => now(),
    ]);
    DB::table('assets')->where('id', $assetId)->update(['current_version_id' => $assetVersionId]);
    DB::table('offer_version_assets')->insert([
        'workspace_id' => $workspace->id,
        'offer_id' => $offer->id,
        'offer_version_id' => $offer->current_version_id,
        'asset_id' => $assetId,
        'asset_version_id' => $assetVersionId,
        'role' => 'proof',
        'delivery_rule' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $connection = ConversionSourceConnection::factory()->create(['workspace_id' => $workspace->id]);
    $connectionVersion = ConversionSourceConnectionVersion::factory()->create([
        'workspace_id' => $workspace->id,
        'connection_id' => $connection->id,
        'encrypted_secret' => 'workspace-secret',
    ]);
    $connection->forceFill(['current_version_id' => $connectionVersion->id])->save();

    $definition = ConversionDefinition::factory()->create(['workspace_id' => $workspace->id]);
    $definitionVersion = ConversionDefinitionVersion::factory()->create([
        'workspace_id' => $workspace->id,
        'conversion_definition_id' => $definition->id,
    ]);
    $definition->forceFill(['current_version_id' => $definitionVersion->id])->save();
    $receipt = ConversionEventReceipt::factory()->create([
        'workspace_id' => $workspace->id,
        'connection_id' => $connection->id,
        'connection_version_id' => $connectionVersion->id,
        'unmatched_identity_envelope' => ['email' => 'workspace-secret@example.test'],
    ]);
    $event = ConversionEvent::factory()->create([
        'workspace_id' => $workspace->id,
        'conversion_definition_id' => $definition->id,
        'conversion_definition_version_id' => $definitionVersion->id,
        'receipt_id' => $receipt->id,
        'matched_account_id' => $account->id,
        'state' => 'matched',
    ]);
    DB::table('conversion_event_receipts')->where('id', $receipt->id)->update(['conversion_event_id' => $event->id]);
    ConversionEventMatch::factory()->create([
        'workspace_id' => $workspace->id,
        'conversion_event_id' => $event->id,
        'matched_account_id' => $account->id,
        'result' => 'matched',
    ]);
    DB::table('conversion_event_review_events')->insert([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'conversion_event_id' => $event->id,
        'action' => 'resolve',
        'actor_user_id' => $user->id,
        'actor_display_name' => 'Fixture',
        'reason' => null,
        'prior_state' => 'received',
        'projected_state' => 'matched',
        'successor_event_id' => null,
        'successor_match_id' => null,
        'created_at' => now(),
    ]);

    $capabilityId = (string) Str::uuid();
    DB::table('fulfilment_capabilities')->insert([
        'id' => $capabilityId,
        'workspace_id' => $workspace->id,
        'display_name' => 'Human support',
        'mode' => 'human_assisted',
        'status' => 'active',
        'configuration_version' => 1,
        'configuration' => json_encode([], JSON_THROW_ON_ERROR),
        'owner_user_id' => $user->id,
        'response_sla_hours' => 24,
        'capacity_limit' => 5,
        'capacity_window' => 'day',
        'health_state' => 'healthy',
        'last_checked_at' => now(),
        'safe_blocker_code' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $windowId = DB::table('fulfilment_capacity_windows')->insertGetId([
        'workspace_id' => $workspace->id,
        'fulfilment_capability_id' => $capabilityId,
        'window_key' => 'day:'.now()->format('Y-m-d'),
        'limit_units' => 5,
        'reserved_units' => 1,
        'consumed_units' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $requestId = (string) Str::uuid();
    DB::table('fulfilment_requests')->insert([
        'id' => $requestId,
        'workspace_id' => $workspace->id,
        'offer_id' => $offer->id,
        'offer_version_id' => $offer->current_version_id,
        'conversion_event_id' => $event->id,
        'account_id' => $account->id,
        'fulfilment_capability_id' => $capabilityId,
        'idempotency_key' => (string) Str::uuid(),
        'required_inputs' => json_encode([], JSON_THROW_ON_ERROR),
        'due_at' => now()->addDay(),
        'assigned_user_id' => $user->id,
        'state' => 'pending',
        'result_references' => null,
        'safe_failure_code' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('fulfilment_capacity_reservations')->insert([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'fulfilment_request_id' => $requestId,
        'fulfilment_capability_id' => $capabilityId,
        'capacity_window_id' => $windowId,
        'units' => 1,
        'state' => 'active',
        'reserved_at' => now(),
        'released_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(app(DeleteWorkspace::class)->handle($workspace, $user))->toBeTrue()
        ->and(DB::table('workspaces')->where('id', $workspace->id)->exists())->toBeFalse()
        ->and(DB::table('account_research_runs')->where('id', $run->id)->exists())->toBeFalse()
        ->and(DB::table('offers')->where('workspace_id', $workspace->id)->exists())->toBeFalse()
        ->and(DB::table('offer_versions')->where('workspace_id', $workspace->id)->exists())->toBeFalse()
        ->and(DB::table('conversion_source_connections')->where('workspace_id', $workspace->id)->exists())->toBeFalse()
        ->and(DB::table('conversion_source_connection_versions')->where('workspace_id', $workspace->id)->exists())->toBeFalse()
        ->and(DB::table('offer_readiness_evaluations')->where('workspace_id', $workspace->id)->exists())->toBeFalse()
        ->and(DB::table('offer_version_assets')->where('workspace_id', $workspace->id)->exists())->toBeFalse()
        ->and(DB::table('asset_versions')->where('workspace_id', $workspace->id)->exists())->toBeFalse()
        ->and(DB::table('fulfilment_requests')->where('workspace_id', $workspace->id)->exists())->toBeFalse()
        ->and(DB::table('fulfilment_capacity_reservations')->where('workspace_id', $workspace->id)->exists())->toBeFalse();
});

it('exports S2 sections without secrets, raw identities, prompts, or redacted content', function (): void {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $account = Account::factory()->create(['workspace_id' => $workspace->id]);
    $run = AccountResearchRun::factory()->create([
        'workspace_id' => $workspace->id,
        'account_id' => $account->id,
        'account_snapshot' => ['name' => 'do not export this snapshot'],
    ]);
    $stage = AccountResearchStage::factory()->create([
        'workspace_id' => $workspace->id,
        'research_run_id' => $run->id,
    ]);
    $exportableId = (string) Str::uuid();
    $redactedId = (string) Str::uuid();
    DB::table('research_source_snapshots')->insert([
        s2SnapshotRow($exportableId, $workspace->id, $account->id, $run->id, $stage->id),
        array_replace(s2SnapshotRow($redactedId, $workspace->id, $account->id, $run->id, $stage->id), [
            'export_classification' => 'restricted',
            'content' => 'must not export',
        ]),
    ]);
    $connection = ConversionSourceConnection::factory()->create(['workspace_id' => $workspace->id]);
    $version = ConversionSourceConnectionVersion::factory()->create([
        'workspace_id' => $workspace->id,
        'connection_id' => $connection->id,
        'encrypted_secret' => 'never export this',
    ]);

    $export = app(WorkspaceExportService::class);
    $research = $export->records($export->researchHeaders(), $export->researchRows((int) $workspace->id));
    $snapshots = $export->records($export->researchSnapshotHeaders(), $export->researchSnapshotRows((int) $workspace->id));
    $connectionVersions = $export->records($export->conversionConnectionVersionHeaders(), $export->conversionConnectionVersionRows((int) $workspace->id));
    $sections = $export->s2Sections((int) $workspace->id);

    expect($research[0])->not->toHaveKey('account_snapshot')
        ->and($research[0]['policy_hash'])->not->toBeNull()
        ->and($snapshots)->toHaveCount(2)
        ->and(collect($snapshots)->firstWhere('snapshot_id', $exportableId)['content'])->toBe('fixture snapshot')
        ->and(collect($snapshots)->firstWhere('snapshot_id', $redactedId)['content'])->toBeNull()
        ->and($connectionVersions[0])->not->toHaveKey('encrypted_secret')
        ->and($connectionVersions[0])->not->toHaveKey('allowed_conversion_definition_ids')
        ->and($version->encrypted_secret)->toBe('never export this')
        ->and(array_keys($sections))->toContain('research_runs', 'conversion_events', 'offer_readiness', 'fulfilment');
});

it('exports settled Account research operation costs from the linked budget run', function (): void {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);
    $account = Account::factory()->create(['workspace_id' => $workspace->id]);
    $run = AccountResearchRun::factory()->create([
        'workspace_id' => $workspace->id,
        'account_id' => $account->id,
        'reserved_cost_micros' => 0,
        'actual_cost_micros' => 0,
    ]);
    $gateway = app(AiBudgetGateway::class);
    $envelope = $gateway->openEnvelope(
        $workspace,
        $user,
        'account_research_export',
        (string) $run->id,
        100_000,
    );
    $budgetRun = $gateway->openRun($workspace, $user, AiTask::AccountResearch, $envelope);
    $run->forceFill([
        'budget_envelope_id' => $envelope->id,
        'research_budget_run_id' => $budgetRun->id,
    ])->save();
    $credential = AiProviderCredential::query()->create([
        'workspace_id' => $workspace->id,
        'capability' => 'research',
        'provider' => AiProviderName::Tavily,
        'status' => AiCredentialStatus::Healthy,
    ]);
    $version = $credential->versions()->create([
        'version' => 1,
        'encrypted_secret' => 'tvly-export-test',
        'status' => 'active',
    ]);
    $credential->forceFill(['active_version_id' => $version->id])->save();
    $operation = $gateway->reserve(
        $workspace,
        $credential,
        $version,
        $user,
        AiTask::AccountResearch,
        'search',
        'tavily/search',
        1,
        12_000,
        'export-cost-projection-operation',
        hash('sha256', 'export-cost-projection-request'),
        $budgetRun->id,
    );
    $gateway->settle($operation, 4_700, 1, 'provider_exact');

    $export = app(WorkspaceExportService::class);
    $research = $export->records($export->researchHeaders(), $export->researchRows((int) $workspace->id));

    expect($research)->toHaveCount(1)
        ->and($research[0]['reserved_cost_micros'])->toBe(12_000)
        ->and($research[0]['actual_cost_micros'])->toBe(4_700);
});

it('registers research recovery and unmatched identity expiry schedules', function (): void {
    $events = collect(app(Schedule::class)->events())->keyBy('description');

    expect($events->get('outboss-account-research-dispatch-sweep'))->not->toBeNull()
        ->and($events->get('outboss-account-research-recovery'))->not->toBeNull()
        ->and($events->get('outboss-expire-unmatched-conversion-identities'))->not->toBeNull()
        ->and($events->get('outboss-account-research-dispatch-sweep')->withoutOverlapping)->toBeTrue()
        ->and($events->get('outboss-account-research-recovery')->withoutOverlapping)->toBeTrue()
        ->and($events->get('outboss-expire-unmatched-conversion-identities')->withoutOverlapping)->toBeTrue();
});

/** @return array<string, mixed> */
function s2SnapshotRow(string $id, int $workspaceId, int $accountId, string $runId, string $stageId): array
{
    return [
        'id' => $id,
        'workspace_id' => $workspaceId,
        'account_id' => $accountId,
        'research_run_id' => $runId,
        'stage_id' => $stageId,
        'source_type' => 'website',
        'canonical_public_url' => 'https://example.test',
        'registrable_domain' => 'example.test',
        'retrieved_at' => now(),
        'observed_at' => now(),
        'content_hash' => str_repeat('e', 64),
        'content' => 'fixture snapshot',
        'payload_reference' => null,
        'mime_type' => 'text/html',
        'byte_count' => 16,
        'language' => 'en',
        'retrieval_capability' => 'fixture',
        'retrieval_version' => 1,
        'provider_operation_id' => null,
        'provider_request_id' => null,
        'provider_item_rank' => null,
        'retrieval_policy_hash' => str_repeat('f', 64),
        'display_classification' => 'standard',
        'llm_use_classification' => 'allowed',
        'export_classification' => 'standard',
        'retention_classification' => 'standard',
        'expires_at' => now()->addDay(),
        'redacted_at' => null,
        'state' => 'available',
        'safe_failure_code' => null,
        'result_identity' => $id,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

/** @return array<string, mixed> */
function s2ClaimRow(string $id, int $workspaceId, int $accountId, string $runId): array
{
    return [
        'id' => $id,
        'workspace_id' => $workspaceId,
        'account_id' => $accountId,
        'research_run_id' => $runId,
        'supersedes_claim_id' => null,
        'subject_type' => 'account',
        'predicate' => 'business_model',
        'value_type' => 'string',
        'value_json' => json_encode(['value' => 'fixture'], JSON_THROW_ON_ERROR),
        'value_hash' => str_repeat('1', 64),
        'status' => 'supported',
        'confidence' => 0.8,
        'calibration_band' => 'high',
        'observed_at' => now(),
        'fresh_until' => now()->addDay(),
        'expires_at' => now()->addDay(),
        'extractor_kind' => 'fixture',
        'extractor_version' => '1',
        'prompt_version' => 'fixture-prompt',
        'model_version' => 'fixture-model',
        'policy_version' => 'fixture-policy',
        'catalogue_version' => 'fixture-catalogue',
        'safe_for_message' => false,
        'content_hash' => str_repeat('2', 64),
        'created_at' => now(),
        'updated_at' => now(),
    ];
}
