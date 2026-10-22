<?php

use App\Domain\Ai\Enums\AiCredentialStatus;
use App\Domain\Ai\Enums\AiProviderName;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Api\TokenScope;
use App\Domain\Objectives\Services\CreateObjective;
use App\Domain\Objectives\Services\CreateSegment;
use App\Domain\People\Services\PersonEditor;
use App\Models\Account;
use App\Models\AiBudgetPeriod;
use App\Models\AiBudgetRun;
use App\Models\AiProviderCredential;
use App\Models\AiProviderOperation;
use App\Models\AudienceSnapshot;
use App\Models\AudienceSnapshotMember;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\ConversionDefinition;
use App\Models\ConversionDefinitionVersion;
use App\Models\ExecutionPlan;
use App\Models\ExecutionPlanAction;
use App\Models\GtmExperiment;
use App\Models\GtmExperimentArm;
use App\Models\GtmExperimentAssignment;
use App\Models\GtmExperimentAssignmentMember;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Objective;
use App\Models\ObjectiveRun;
use App\Models\ObjectiveRunArmDraft;
use App\Models\ObjectiveVersion;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\Person;
use App\Models\ProviderConnection;
use App\Models\Strategy;
use App\Models\StrategyVersion;
use App\Models\SuppressionEntry;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Build the smallest valid Increment 3 graph needed to exercise direct
 * Person deletion without coupling this People test to the full drafting
 * integration fixture.
 *
 * @return array{
 *   owner: User,
 *   account: Account,
 *   person: Person,
 *   member: AudienceSnapshotMember,
 *   assignmentMember: GtmExperimentAssignmentMember,
 *   experiment: GtmExperiment,
 *   run: ObjectiveRun,
 *   draft: ObjectiveRunArmDraft,
 *   plan: ExecutionPlan,
 *   action: ExecutionPlanAction,
 *   objective: Objective,
 *   operation: AiProviderOperation|null
 * }
 */
function personDeletionObjectiveFixture(bool $ambiguousProviderWork = false): array
{
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $account = Account::factory()->create(['workspace_id' => $workspace->id]);
    $person = Person::factory()->create([
        'workspace_id' => $workspace->id,
        'account_id' => $account->id,
        'full_name' => 'Maya Chen',
        'email_address' => 'maya@person-deletion.test',
        'email_normalized' => 'maya@person-deletion.test',
    ]);
    $segment = app(CreateSegment::class)->handle(
        $workspace,
        $owner,
        'Person deletion fixture',
        ['field' => 'account.domain_present', 'operator' => 'eq', 'value' => true],
    );
    $objective = app(CreateObjective::class)->handle($workspace, $owner);
    $snapshot = AudienceSnapshot::query()->create([
        'workspace_id' => $workspace->id,
        'segment_version_id' => $segment->current_version_id,
        'objective_id' => $objective->id,
        'purpose' => 'objective_execution',
        'status' => 'frozen',
        'evaluator_version' => 'segment_evaluator_v1',
        'criteria_hash' => hash('sha256', 'person-deletion-criteria'),
        'evaluation_hash' => hash('sha256', 'person-deletion-evaluation-'.$person->id),
        'included_people_count' => 1,
        'excluded_people_count' => 0,
        'uncertain_people_count' => 0,
        'suppressed_people_count' => 0,
        'included_accounts_count' => 1,
        'excluded_accounts_count' => 0,
        'uncertain_accounts_count' => 0,
        'suppressed_accounts_count' => 0,
        'source_rights_snapshot' => [],
        'frozen_by_user_id' => $owner->id,
        'frozen_at' => now(),
    ]);
    $member = AudienceSnapshotMember::query()->create([
        'workspace_id' => $workspace->id,
        'audience_snapshot_id' => $snapshot->id,
        'account_id' => $account->id,
        'person_id' => $person->id,
        'result' => 'included',
        'qualification_decision_id' => null,
        'reason_to_contact_version_id' => null,
        'criterion_results' => [['field' => 'person.email', 'value' => $person->email_address]],
        'inclusion_reasons' => ['email_present'],
        'exclusion_reasons' => [],
        'missing_fields' => [],
        'suppression_snapshot' => ['suppressed' => false],
        'evidence_manifest' => [['claim_id' => (string) Str::uuid(), 'content_hash' => hash('sha256', 'evidence')]],
        'qualification_content_hash' => hash('sha256', 'qualification'),
        'reason_content_hash' => hash('sha256', 'reason'),
        'source_lineage_hash' => hash('sha256', 'source-lineage'),
        'member_hash' => hash('sha256', 'member-'.$person->id),
        'evidence_fresh_until' => now()->addMonth(),
        'evaluated_at' => now(),
    ]);

    $definition = ConversionDefinition::factory()->create(['workspace_id' => $workspace->id]);
    $definitionVersion = ConversionDefinitionVersion::factory()->create([
        'workspace_id' => $workspace->id,
        'conversion_definition_id' => $definition->id,
    ]);
    $definition->forceFill(['current_version_id' => $definitionVersion->id])->save();
    $offer = Offer::factory()->withoutSeededVersion()->create(['workspace_id' => $workspace->id]);
    $offerVersion = OfferVersion::factory()->create([
        'workspace_id' => $workspace->id,
        'offer_id' => $offer->id,
    ]);
    $objectiveVersion = ObjectiveVersion::query()->create([
        'workspace_id' => $workspace->id,
        'objective_id' => $objective->id,
        'version' => 1,
        'name' => 'Person deletion objective',
        'conversion_definition_id' => $definition->id,
        'conversion_definition_version_id' => $definitionVersion->id,
        'segment_version_id' => $segment->current_version_id,
        'audience_snapshot_id' => $snapshot->id,
        'offer_id' => $offer->id,
        'offer_version_id' => $offerVersion->id,
        'target_kind' => 'count',
        'target_count' => 1,
        'starts_at' => now(),
        'ends_at' => now()->addMonth(),
        'attribution_window_days' => 30,
        'maximum_people' => 1,
        'maximum_accounts' => 1,
        'maximum_messages' => 1,
        'maximum_ai_cost_micros' => 1000,
        'required_qualification_outcomes' => ['contact'],
        'minimum_qualification_confidence' => 0.8,
        'maximum_evidence_age_days' => 30,
        'exclusions' => [],
        'authority_envelope' => ['approval_required' => true],
        'forecast_assumptions' => [],
        'forecast_projection' => [],
        'schema_version' => 'objective_v1',
        'content_hash' => hash('sha256', 'objective-version-'.$person->id),
        'created_by_user_id' => $owner->id,
    ]);
    $objective->forceFill(['current_version_id' => $objectiveVersion->id])->save();
    $strategy = Strategy::query()->create([
        'workspace_id' => $workspace->id,
        'status' => 'active',
        'lock_version' => 1,
        'created_by_user_id' => $owner->id,
    ]);
    $strategyVersion = StrategyVersion::query()->create([
        'workspace_id' => $workspace->id,
        'strategy_id' => $strategy->id,
        'version' => 1,
        'objective_version_id' => $objectiveVersion->id,
        'audience_snapshot_id' => $snapshot->id,
        'offer_version_id' => $offerVersion->id,
        'name' => 'Person deletion strategy',
        'positioning' => 'Safe deletion',
        'problem_frame' => 'Retained personal content',
        'proof_summary' => 'Fixture evidence',
        'cta_kind' => 'reply',
        'cta_text' => 'Reply',
        'proof_claim_ids' => [],
        'personalisation_contract' => [],
        'message_generation_contract' => [],
        'stop_conditions' => [],
        'channel' => 'email',
        'sequence_contract' => 'one_new_thread_v1',
        'schema_version' => 'strategy_v1',
        'content_hash' => hash('sha256', 'strategy-version-'.$person->id),
        'created_by_user_id' => $owner->id,
    ]);
    $strategy->forceFill(['current_version_id' => $strategyVersion->id])->save();
    $experiment = GtmExperiment::query()->create([
        'workspace_id' => $workspace->id,
        'objective_id' => $objective->id,
        'objective_version_id' => $objectiveVersion->id,
        'audience_snapshot_id' => $snapshot->id,
        'conversion_definition_id' => $definition->id,
        'conversion_definition_version_id' => $definitionVersion->id,
        'name' => 'Person deletion experiment',
        'status' => 'assigned',
        'assignment_unit' => 'account',
        'assignment_seed' => 'fixture-seed',
        'assignment_seed_hash' => hash('sha256', 'fixture-seed'),
        'assignment_algorithm_version' => 'hmac_sha256_basis_points_v1',
        'allocation_version' => 1,
        'guardrails' => [],
        'starts_at' => now(),
        'ends_at' => now()->addMonth(),
        'lock_version' => 1,
        'created_by_user_id' => $owner->id,
        'assigned_at' => now(),
    ]);
    $arm = GtmExperimentArm::query()->create([
        'workspace_id' => $workspace->id,
        'gtm_experiment_id' => $experiment->id,
        'strategy_version_id' => $strategyVersion->id,
        'offer_version_id' => $offerVersion->id,
        'key' => 'control',
        'label' => 'Control',
        'allocation_basis_points' => 10000,
        'declared_differences' => [],
    ]);
    $assignment = GtmExperimentAssignment::query()->create([
        'workspace_id' => $workspace->id,
        'gtm_experiment_id' => $experiment->id,
        'gtm_experiment_arm_id' => $arm->id,
        'audience_snapshot_id' => $snapshot->id,
        'account_id' => $account->id,
        'assignment_unit_key_hash' => hash('sha256', 'account-'.$account->id),
        'bucket' => 1,
        'allocation_version' => 1,
        'algorithm_version' => 'hmac_sha256_basis_points_v1',
        'eligibility_snapshot_hash' => hash('sha256', 'eligible-'.$person->id),
        'assigned_at' => now(),
    ]);
    $assignmentMember = GtmExperimentAssignmentMember::query()->create([
        'workspace_id' => $workspace->id,
        'gtm_experiment_assignment_id' => $assignment->id,
        'audience_snapshot_member_id' => $member->id,
        'person_id' => $person->id,
        'member_hash' => $member->member_hash,
    ]);
    $run = ObjectiveRun::query()->create([
        'workspace_id' => $workspace->id,
        'objective_id' => $objective->id,
        'objective_version_id' => $objectiveVersion->id,
        'audience_snapshot_id' => $snapshot->id,
        'gtm_experiment_id' => $experiment->id,
        'run_number' => 1,
        'state' => 'waiting',
        'current_phase' => 'await_approval',
        'input_hash' => hash('sha256', 'run-input-'.$person->id),
        'idempotency_key_hash' => hash('sha256', 'run-key-'.$person->id),
        'schema_version' => 'objective_run_v1',
        'message_validator_version' => 'message_validator_v1',
        'maximum_cost_micros' => 1000,
        'reserved_cost_micros' => 0,
        'actual_cost_micros' => 0,
        'fencing_token' => 0,
        'created_by_user_id' => $owner->id,
    ]);
    $budgetRun = AiBudgetRun::query()->create([
        'workspace_id' => $workspace->id,
        'task' => AiTask::MessageDrafting,
        'initiated_by_user_id' => $owner->id,
        'limit_snapshot_micros' => 1000,
        'held_micros' => $ambiguousProviderWork ? 10 : 0,
        'committed_micros' => 0,
        'status' => $ambiguousProviderWork ? 'open' : 'closed',
        'orchestration_owned' => true,
        'opened_at' => now(),
        'closed_at' => $ambiguousProviderWork ? null : now(),
    ]);
    $draft = ObjectiveRunArmDraft::query()->create([
        'workspace_id' => $workspace->id,
        'objective_run_id' => $run->id,
        'gtm_experiment_arm_id' => $arm->id,
        'ai_budget_run_id' => $budgetRun->id,
        'state' => 'succeeded',
        'prompt_version' => 'fixture_v1',
        'schema_version' => 'fixture_v1',
        'validator_version' => 'fixture_v1',
        'model_id' => 'fixture/model',
        'task_policy_version' => 1,
        'input_hash' => hash('sha256', 'draft-input-'.$person->id),
        'subject_template' => 'A personal subject for Maya',
        'body_template' => 'A personal body for Maya',
        'claim_manifest' => ['claim' => 'personal evidence'],
        'cta_manifest' => ['cta' => 'reply'],
        'output_hash' => hash('sha256', 'draft-output-'.$person->id),
        'template_hash' => hash('sha256', 'draft-template-'.$person->id),
        'finished_at' => now(),
    ]);
    $policyVersion = $workspace->outreachPolicy()->with('currentVersion')->firstOrFail()->currentVersion;
    $connection = ProviderConnection::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $workspace->id,
    ]);
    $mailbox = Mailbox::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $workspace->id,
        'provider_connection_id' => $connection->id,
    ]);
    $plan = ExecutionPlan::query()->create([
        'workspace_id' => $workspace->id,
        'objective_run_id' => $run->id,
        'version' => 1,
        'status' => 'awaiting_approval',
        'objective_version_id' => $objectiveVersion->id,
        'audience_snapshot_id' => $snapshot->id,
        'gtm_experiment_id' => $experiment->id,
        'conversion_definition_version_id' => $definitionVersion->id,
        'offer_version_id' => $offerVersion->id,
        'outreach_policy_version_id' => $policyVersion->id,
        'model_id' => 'fixture/model',
        'ai_task_version' => 'fixture_v1',
        'prompt_version' => 'fixture_v1',
        'schema_version' => 'fixture_v1',
        'validator_version' => 'fixture_v1',
        'arm_count' => 1,
        'account_count' => 1,
        'people_count' => 1,
        'action_count' => 1,
        'maximum_cost_micros' => 1000,
        'estimated_cost_micros' => 10,
        'actual_cost_micros' => 0,
        'input_manifest' => [],
        'evidence_manifest_hash' => hash('sha256', 'plan-evidence-'.$person->id),
        'compilation_manifest' => [],
        'canonical_payload_hash' => hash('sha256', 'plan-payload-'.$person->id),
        'plan_hash' => hash('sha256', 'plan-'.$person->id),
        'created_by_user_id' => $owner->id,
    ]);
    $action = ExecutionPlanAction::query()->create([
        'workspace_id' => $workspace->id,
        'execution_plan_id' => $plan->id,
        'gtm_experiment_assignment_id' => $assignment->id,
        'gtm_experiment_arm_id' => $arm->id,
        'objective_run_arm_draft_id' => $draft->id,
        'strategy_version_id' => $strategyVersion->id,
        'audience_snapshot_member_id' => $member->id,
        'account_id' => $account->id,
        'person_id' => $person->id,
        'offer_version_id' => $offerVersion->id,
        'conversion_definition_version_id' => $definitionVersion->id,
        'selected_claim_ids' => [],
        'selected_evidence_ids' => [],
        'selected_claim_safety_hashes' => [],
        'offer_content_hash' => hash('sha256', 'offer-content'),
        'offer_contract_hash' => hash('sha256', 'offer-contract'),
        'cta_destination_url' => 'https://example.test/reply',
        'cta_response_route' => 'reply',
        'action_kind' => 'email_new_thread',
        'position' => 1,
        'destination_email_hash' => hash('sha256', $person->email_normalized),
        'destination_email_address' => $person->email_address,
        'subject' => 'Hello Maya',
        'body_text' => 'Hi Maya, this exact body is personal.',
        'body_html' => '<p>Hi Maya, this exact body is personal.</p>',
        'template_hash' => hash('sha256', 'action-template'),
        'recipient_snapshot_hash' => hash('sha256', 'recipient-'.$person->id),
        'content_hash' => hash('sha256', 'action-content-'.$person->id),
        'mailbox_id' => $mailbox->id,
        'provider_connection_id' => $connection->id,
        'provider_connection_version' => 1,
        'sender_address_snapshot' => $mailbox->email_address,
        'sender_display_name_snapshot' => 'Owner',
        'reply_to_snapshot' => $mailbox->email_address,
        'scheduled_not_before' => now(),
        'scheduled_not_after' => now()->addDay(),
        'policy_input_hash' => hash('sha256', 'policy-input'),
        'action_fingerprint' => hash('sha256', 'action-'.$person->id),
        'state' => 'awaiting_approval',
    ]);
    $objective->forceFill(['current_plan_id' => $plan->id])->save();

    $operation = null;
    if ($ambiguousProviderWork) {
        $period = AiBudgetPeriod::query()->create([
            'workspace_id' => $workspace->id,
            'period_start_utc' => now()->startOfMonth(),
            'period_end_utc' => now()->endOfMonth(),
            'limit_snapshot_micros' => 1000,
            'held_micros' => 10,
            'committed_micros' => 0,
            'settings_version' => 1,
        ]);
        $credential = AiProviderCredential::query()->create([
            'workspace_id' => $workspace->id,
            'capability' => AiProviderName::OpenRouter->capability(),
            'provider' => AiProviderName::OpenRouter,
            'status' => AiCredentialStatus::Healthy,
            'created_by_user_id' => $owner->id,
            'updated_by_user_id' => $owner->id,
        ]);
        $credentialVersion = $credential->versions()->create([
            'version' => 1,
            'encrypted_secret' => 'fixture-secret',
            'status' => 'active',
            'created_by_user_id' => $owner->id,
        ]);
        $credential->forceFill(['active_version_id' => $credentialVersion->id])->save();
        $operation = AiProviderOperation::query()->create([
            'workspace_id' => $workspace->id,
            'budget_period_id' => $period->id,
            'budget_run_id' => $budgetRun->id,
            'credential_id' => $credential->id,
            'credential_version_id' => $credentialVersion->id,
            'provider' => AiProviderName::OpenRouter,
            'capability' => AiProviderName::OpenRouter->capability(),
            'task' => AiTask::MessageDrafting,
            'operation' => 'chat_completion',
            'initiated_by_user_id' => $owner->id,
            'idempotency_key' => 'person-deletion-'.$person->id,
            'request_fingerprint' => hash('sha256', 'request-'.$person->id),
            'model_or_endpoint' => 'fixture/model',
            'policy_version' => 1,
            'pricing_version' => 1,
            'status' => 'uncertain',
            'reserved_native_units' => 1,
            'reserved_cost_micros' => 10,
            'fencing_token' => 1,
            'safe_error_code' => 'provider_uncertain',
            'safe_error_message' => 'Waiting for reconciliation.',
            'started_at' => now(),
            'reconcile_after' => now(),
        ]);
        $draft->forceFill(['ai_provider_operation_id' => $operation->id])->save();
    }

    return compact(
        'owner',
        'account',
        'person',
        'member',
        'assignmentMember',
        'experiment',
        'run',
        'draft',
        'plan',
        'action',
        'objective',
        'operation',
    );
}

it('creates a person with a normalised email and derived first name', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'name' => 'Stripe',
    ]);

    $this->actingAs($user)
        ->post(route('people.store'), [
            'email' => 'Maya@Stripe.com',
            'full_name' => 'Maya Chen',
            'job_title' => 'Founder',
            'account_id' => $account->id,
            'tags' => ['saas', 'saas', 'gtm'],
        ])
        ->assertRedirect(route('prospects.index'));

    $person = Person::query()->where('workspace_id', $user->currentWorkspace()->id)->first();

    expect($person)->not->toBeNull()
        ->and($person->email_address)->toBe('Maya@Stripe.com')
        ->and($person->email_normalized)->toBe('maya@stripe.com')
        ->and($person->first_name)->toBe('Maya')
        ->and($person->account_id)->toBe($account->id)
        ->and($person->tags)->toBe(['saas', 'gtm'])
        ->and($person->source)->toBe(Person::SOURCE_MANUAL)
        ->and($person->created_by_user_id)->toBe($user->id);
});

it('rejects a duplicate email in the same workspace and names the existing person', function () {
    $user = User::factory()->create();
    Person::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'full_name' => 'Maya Chen',
        'email_address' => 'maya@stripe.com',
        'email_normalized' => 'maya@stripe.com',
    ]);

    $this->actingAs($user)
        ->from(route('prospects.index'))
        ->post(route('people.store'), [
            'email' => 'Maya@Stripe.com',
            'full_name' => 'Maya Two',
        ])
        ->assertRedirect(route('prospects.index'))
        ->assertSessionHasErrors([
            'email' => 'Another person, ‘Maya Chen’, already uses maya@stripe.com.',
        ]);

    expect(Person::query()->where('workspace_id', $user->currentWorkspace()->id)->count())->toBe(1);
});

it('allows the same email in a different workspace', function () {
    $alpha = User::factory()->create();
    $beta = User::factory()->create();
    Person::factory()->create([
        'workspace_id' => $alpha->currentWorkspace()->id,
        'email_address' => 'maya@stripe.com',
        'email_normalized' => 'maya@stripe.com',
    ]);

    $this->actingAs($beta)
        ->post(route('people.store'), [
            'email' => 'maya@stripe.com',
        ])
        ->assertRedirect(route('prospects.index'));

    expect(Person::query()->where('email_normalized', 'maya@stripe.com')->count())->toBe(2);
});

it('does not rewrite recipient snapshots when a person email changes', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'full_name' => 'Maya Chen',
        'email_address' => 'maya@acme.test',
        'email_normalized' => 'maya@acme.test',
    ]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $mailbox = Mailbox::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $user->currentWorkspace()->id,
    ]);
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $user->currentWorkspace()->id,
        'mailbox_id' => $mailbox->id,
        'person_id' => $person->id,
        'email_address' => 'maya@acme.test',
        'email_normalized' => 'maya@acme.test',
        'full_name' => 'Maya Chen',
    ]);

    $this->actingAs($user)
        ->patch(route('people.update', $person), [
            'email' => 'maya@new.test',
            'full_name' => 'Maya Chen',
        ])
        ->assertRedirect(route('prospects.index'));

    expect($person->fresh()->email_normalized)->toBe('maya@new.test')
        ->and($recipient->fresh()->email_address)->toBe('maya@acme.test')
        ->and($recipient->fresh()->email_normalized)->toBe('maya@acme.test');
});

it('rejects changing a person email to one already used in the workspace', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'email_address' => 'one@acme.test',
        'email_normalized' => 'one@acme.test',
    ]);
    Person::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'full_name' => 'Theo Martin',
        'email_address' => 'theo@acme.test',
        'email_normalized' => 'theo@acme.test',
    ]);

    $this->actingAs($user)
        ->from(route('prospects.index'))
        ->patch(route('people.update', $person), [
            'email' => 'theo@acme.test',
        ])
        ->assertRedirect(route('prospects.index'))
        ->assertSessionHasErrors([
            'email' => 'Another person, ‘Theo Martin’, already uses theo@acme.test.',
        ]);
});

it('rejects an account from another workspace', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $foreign = Account::factory()->create([
        'workspace_id' => $other->currentWorkspace()->id,
    ]);

    $this->actingAs($user)
        ->from(route('prospects.index'))
        ->post(route('people.store'), [
            'email' => 'maya@acme.test',
            'account_id' => $foreign->id,
        ])
        ->assertRedirect(route('prospects.index'))
        ->assertSessionHasErrors(['account_id']);
});

it('deletes a person without touching recipient history', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'email_address' => 'maya@acme.test',
        'email_normalized' => 'maya@acme.test',
    ]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $mailbox = Mailbox::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $user->currentWorkspace()->id,
    ]);
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $user->currentWorkspace()->id,
        'mailbox_id' => $mailbox->id,
        'person_id' => $person->id,
        'email_address' => 'maya@acme.test',
        'email_normalized' => 'maya@acme.test',
        'full_name' => 'Maya Chen',
    ]);

    $this->actingAs($user)
        ->delete(route('people.destroy', $person))
        ->assertRedirect(route('prospects.index'))
        ->assertSessionHas('success', 'Person deleted; their campaign history is preserved.');

    expect(Person::query()->whereKey($person->id)->exists())->toBeFalse()
        ->and($recipient->fresh()->person_id)->toBeNull()
        ->and($recipient->fresh()->email_address)->toBe('maya@acme.test');

    $this->actingAs($user)
        ->post(route('people.store'), [
            'email' => 'maya@acme.test',
        ])
        ->assertRedirect(route('prospects.index'));

    expect(Person::query()->where('workspace_id', $user->currentWorkspace()->id)->where('email_normalized', 'maya@acme.test')->exists())->toBeTrue();
});

it('cancels and redacts an affected Objective plan before deleting a Person', function () {
    $fixture = personDeletionObjectiveFixture();
    $hashes = [
        'destination' => $fixture['action']->destination_email_hash,
        'recipient' => $fixture['action']->recipient_snapshot_hash,
        'content' => $fixture['action']->content_hash,
        'fingerprint' => $fixture['action']->action_fingerprint,
        'member' => $fixture['member']->member_hash,
        'source_lineage' => $fixture['member']->source_lineage_hash,
        'draft_output' => $fixture['draft']->output_hash,
        'draft_template' => $fixture['draft']->template_hash,
    ];
    $campaignCount = Campaign::query()->count();
    $messageCount = Message::query()->count();

    app(PersonEditor::class)->delete($fixture['person'], $fixture['owner']);

    $action = $fixture['action']->fresh();
    $member = $fixture['member']->fresh();
    $draft = $fixture['draft']->fresh();
    expect(Person::query()->whereKey($fixture['person']->id)->exists())->toBeFalse()
        ->and($fixture['account']->fresh())->not->toBeNull()
        ->and($fixture['run']->fresh()->state->value)->toBe('cancelled')
        ->and($fixture['run']->fresh()->fencing_token)->toBe(1)
        ->and($fixture['plan']->fresh()->status->value)->toBe('cancelled')
        ->and($fixture['objective']->fresh()->current_plan_id)->toBeNull()
        ->and($fixture['experiment']->fresh()->status->value)->toBe('paused')
        ->and($fixture['assignmentMember']->fresh()->person_id)->toBeNull()
        ->and($action->state->value)->toBe('cancelled')
        ->and($action->account_id)->toBe($fixture['account']->id)
        ->and($action->person_id)->toBeNull()
        ->and($action->destination_email_address)->toBeNull()
        ->and($action->subject)->toBeNull()
        ->and($action->body_text)->toBeNull()
        ->and($action->body_html)->toBeNull()
        ->and($action->destination_email_hash)->toBe($hashes['destination'])
        ->and($action->recipient_snapshot_hash)->toBe($hashes['recipient'])
        ->and($action->content_hash)->toBe($hashes['content'])
        ->and($action->action_fingerprint)->toBe($hashes['fingerprint'])
        ->and($draft->state->value)->toBe('cancelled')
        ->and($draft->subject_template)->toBeNull()
        ->and($draft->body_template)->toBeNull()
        ->and($draft->claim_manifest)->toBeNull()
        ->and($draft->cta_manifest)->toBeNull()
        ->and($draft->output_hash)->toBe($hashes['draft_output'])
        ->and($draft->template_hash)->toBe($hashes['draft_template'])
        ->and($member->account_id)->toBe($fixture['account']->id)
        ->and($member->person_id)->toBeNull()
        ->and($member->result->value)->toBe('excluded')
        ->and($member->criterion_results)->toBe([])
        ->and($member->inclusion_reasons)->toBe([])
        ->and($member->exclusion_reasons)->toBe(['person_deleted'])
        ->and($member->suppression_snapshot)->toBe([])
        ->and($member->evidence_manifest)->toBe([])
        ->and($member->evidence_fresh_until)->toBeNull()
        ->and($member->member_hash)->toBe($hashes['member'])
        ->and($member->source_lineage_hash)->toBe($hashes['source_lineage'])
        ->and(Campaign::query()->count())->toBe($campaignCount)
        ->and(Message::query()->count())->toBe($messageCount);
});

it('rejects member Account and Person deletion across session and REST without mutating an affected Objective graph', function (): void {
    $fixture = personDeletionObjectiveFixture();
    $workspace = $fixture['owner']->currentWorkspace();
    $member = User::factory()->create();
    WorkspaceMembership::query()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $member->id,
        'role' => 'member',
    ]);

    $session = [User::ACTIVE_WORKSPACE_SESSION_KEY => $workspace->id];
    $this->actingAs($member)
        ->withSession($session)
        ->delete(route('accounts.destroy', $fixture['account']))
        ->assertForbidden();
    $this->actingAs($member)
        ->withSession($session)
        ->delete(route('people.destroy', $fixture['person']))
        ->assertForbidden();

    $newToken = $member->createToken('member-objective-delete', [TokenScope::PeopleWrite->value]);
    $newToken->accessToken->forceFill(['workspace_id' => $workspace->id])->save();
    $this->app['auth']->forgetGuards();
    $this->withToken($newToken->plainTextToken)
        ->deleteJson('/api/v1/accounts/'.$fixture['account']->id)
        ->assertForbidden()
        ->assertJsonPath('error.type', 'forbidden');
    $this->withToken($newToken->plainTextToken)
        ->deleteJson('/api/v1/people/'.$fixture['person']->id)
        ->assertForbidden()
        ->assertJsonPath('error.type', 'forbidden');

    expect(Account::query()->whereKey($fixture['account']->id)->exists())->toBeTrue()
        ->and(Person::query()->whereKey($fixture['person']->id)->exists())->toBeTrue()
        ->and($fixture['run']->fresh()->state->value)->toBe('waiting')
        ->and($fixture['run']->fresh()->fencing_token)->toBe(0)
        ->and($fixture['plan']->fresh()->status->value)->toBe('awaiting_approval')
        ->and($fixture['objective']->fresh()->current_plan_id)->toBe($fixture['plan']->id)
        ->and($fixture['experiment']->fresh()->status->value)->toBe('assigned')
        ->and($fixture['assignmentMember']->fresh()->person_id)->toBe($fixture['person']->id)
        ->and($fixture['action']->fresh()->account_id)->toBe($fixture['account']->id)
        ->and($fixture['action']->fresh()->person_id)->toBe($fixture['person']->id)
        ->and($fixture['action']->fresh()->destination_email_address)->toBe('maya@person-deletion.test')
        ->and($fixture['action']->fresh()->subject)->toBe('Hello Maya')
        ->and($fixture['action']->fresh()->body_text)->toContain('exact body is personal')
        ->and($fixture['draft']->fresh()->state->value)->toBe('succeeded')
        ->and($fixture['draft']->fresh()->subject_template)->toBe('A personal subject for Maya')
        ->and($fixture['member']->fresh()->account_id)->toBe($fixture['account']->id)
        ->and($fixture['member']->fresh()->person_id)->toBe($fixture['person']->id)
        ->and($fixture['member']->fresh()->evidence_manifest)->not->toBeEmpty()
        ->and(Campaign::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0);
});

it('rolls Person deletion back while linked provider work is ambiguous', function () {
    $fixture = personDeletionObjectiveFixture(ambiguousProviderWork: true);

    expect(fn () => app(PersonEditor::class)->delete($fixture['person'], $fixture['owner']))
        ->toThrow(ValidationException::class, 'waiting for in-flight Objective provider work')
        ->and(Person::query()->whereKey($fixture['person']->id)->exists())->toBeTrue()
        ->and($fixture['run']->fresh()->state->value)->toBe('waiting')
        ->and($fixture['run']->fresh()->fencing_token)->toBe(0)
        ->and($fixture['plan']->fresh()->status->value)->toBe('awaiting_approval')
        ->and($fixture['objective']->fresh()->current_plan_id)->toBe($fixture['plan']->id)
        ->and($fixture['experiment']->fresh()->status->value)->toBe('assigned')
        ->and($fixture['assignmentMember']->fresh()->person_id)->toBe($fixture['person']->id)
        ->and($fixture['action']->fresh()->person_id)->toBe($fixture['person']->id)
        ->and($fixture['action']->fresh()->destination_email_address)->toBe('maya@person-deletion.test')
        ->and($fixture['action']->fresh()->subject)->toBe('Hello Maya')
        ->and($fixture['action']->fresh()->body_text)->toContain('exact body is personal')
        ->and($fixture['action']->fresh()->body_html)->toContain('exact body is personal')
        ->and($fixture['draft']->fresh()->state->value)->toBe('succeeded')
        ->and($fixture['draft']->fresh()->subject_template)->toBe('A personal subject for Maya')
        ->and($fixture['member']->fresh()->person_id)->toBe($fixture['person']->id)
        ->and($fixture['member']->fresh()->evidence_manifest)->not->toBeEmpty()
        ->and($fixture['operation']->fresh()->status->value)->toBe('uncertain')
        ->and(Campaign::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0);
});

it('exports only the current workspace people as csv', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $account = Account::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'name' => 'Northstar',
    ]);
    Person::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'account_id' => $account->id,
        'full_name' => 'Maya Chen',
        'email_address' => 'maya@northstar.studio',
        'email_normalized' => 'maya@northstar.studio',
        'job_title' => 'Founder',
        'notes' => 'Design lead',
        'tags' => ['design', 'saas'],
        'source' => Person::SOURCE_MANUAL,
    ]);
    Person::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'full_name' => 'Blocked Person',
        'email_address' => 'blocked@acme.test',
        'email_normalized' => 'blocked@acme.test',
    ]);
    SuppressionEntry::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $user->currentWorkspace()->id,
        'email_address' => 'blocked@acme.test',
        'email_normalized' => 'blocked@acme.test',
    ]);
    Person::factory()->create([
        'workspace_id' => $other->currentWorkspace()->id,
        'full_name' => 'Secret Person',
        'email_address' => 'secret@other.test',
        'email_normalized' => 'secret@other.test',
    ]);

    $response = $this->actingAs($user)->get(route('people.export'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toStartWith('text/csv');

    $csv = $response->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    expect($lines[0])->toBe('name,email,job_title,account,notes,tags,source,source_count,source_ids,source_names,latest_observed_at,source_omission_reason,eligibility,campaigns_count,created_at,updated_at')
        ->and($csv)->toContain('Maya Chen')
        ->and($csv)->toContain('maya@northstar.studio')
        ->and($csv)->toContain('design|saas')
        ->and($csv)->toContain('Suppressed')
        ->and($csv)->not->toContain('Secret Person');
});
