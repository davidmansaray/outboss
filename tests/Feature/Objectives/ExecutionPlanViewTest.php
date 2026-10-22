<?php

use App\Domain\Api\TokenScope;
use App\Domain\Objectives\Services\CompileExecutionPlan;
use App\Domain\Objectives\Services\CreateGtmExperiment;
use App\Domain\Objectives\Services\CreateObjective;
use App\Domain\Objectives\Services\CreateSegment;
use App\Domain\Objectives\Services\CreateStrategy;
use App\Domain\Objectives\Services\FreezeExperimentAssignments;
use App\Domain\Objectives\Services\ValidateDraftedMessage;
use App\Domain\Objectives\Services\VersionObjective;
use App\Models\Account;
use App\Models\AiBudgetEnvelope;
use App\Models\AiBudgetRun;
use App\Models\AudienceSnapshot;
use App\Models\AudienceSnapshotMember;
use App\Models\Campaign;
use App\Models\ConversionDefinition;
use App\Models\ConversionDefinitionVersion;
use App\Models\ExecutionPlan;
use App\Models\ExecutionPlanAction;
use App\Models\ExecutionPlanCompilation;
use App\Models\Mailbox;
use App\Models\ObjectiveRun;
use App\Models\ObjectiveRunArmDraft;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\OutreachPolicy;
use App\Models\Person;
use App\Models\ProviderConnection;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{owner: User, plan: ExecutionPlan, action: ExecutionPlanAction, omitted_member_id: string}
 */
function executionPlanViewFixture(): array
{
    $owner = User::factory()->create(['name' => 'Plan Owner']);
    $workspace = $owner->currentWorkspace();
    $objective = app(CreateObjective::class)->handle($workspace, $owner);
    $segment = app(CreateSegment::class)->handle(
        $workspace,
        $owner,
        'Approval audience',
        ['field' => 'account.domain_present', 'operator' => 'eq', 'value' => true],
    );
    $account = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Acme',
        'domain_normalized' => 'acme-plan-view.test',
    ]);
    $people = collect([
        Person::factory()->forAccount($account)->create([
            'full_name' => 'Ada Lovelace',
            'first_name' => 'Ada',
            'email_address' => 'ada@example.test',
            'email_normalized' => 'ada@example.test',
        ]),
        Person::factory()->forAccount($account)->create([
            'full_name' => 'Grace Hopper',
            'first_name' => 'Grace',
            'email_address' => 'grace@example.test',
            'email_normalized' => 'grace@example.test',
        ]),
    ]);
    $claimId = (string) Str::uuid();
    $evidenceId = str_repeat('e', 64);
    $snapshot = AudienceSnapshot::query()->create([
        'workspace_id' => $workspace->id,
        'segment_version_id' => $segment->current_version_id,
        'objective_id' => $objective->id,
        'purpose' => 'objective_execution',
        'status' => 'frozen',
        'evaluator_version' => 'segment_evaluator_v1',
        'criteria_hash' => str_repeat('1', 64),
        'evaluation_hash' => str_repeat('2', 64),
        'included_people_count' => 2,
        'excluded_people_count' => 3,
        'uncertain_people_count' => 4,
        'suppressed_people_count' => 5,
        'included_accounts_count' => 1,
        'excluded_accounts_count' => 2,
        'uncertain_accounts_count' => 3,
        'suppressed_accounts_count' => 4,
        'source_rights_snapshot' => [],
        'frozen_by_user_id' => $owner->id,
        'frozen_at' => now(),
    ]);
    $members = $people->map(fn (Person $person): AudienceSnapshotMember => AudienceSnapshotMember::query()->create([
        'workspace_id' => $workspace->id,
        'audience_snapshot_id' => $snapshot->id,
        'account_id' => $account->id,
        'person_id' => $person->id,
        'result' => 'included',
        'criterion_results' => [],
        'inclusion_reasons' => ['fixture'],
        'exclusion_reasons' => [],
        'missing_fields' => [],
        'suppression_snapshot' => ['suppressed' => false],
        'evidence_manifest' => [['claim_id' => $claimId, 'content_hash' => $evidenceId]],
        'source_lineage_hash' => str_repeat('3', 64),
        'member_hash' => hash('sha256', 'member-'.$person->id),
        'evidence_fresh_until' => now()->addDays(30),
        'evaluated_at' => now(),
    ]));

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
        'schema_version' => 2,
        'payload' => ['schema_version' => 2, 'cta' => ['action' => 'book_workshop']],
    ]);
    $offer->forceFill(['current_version_id' => $offerVersion->id])->save();

    $startsAt = now()->addDay()->startOfMinute();
    $endsAt = now()->addDays(10)->startOfMinute();
    $objectiveVersion = app(VersionObjective::class)->handle($objective, $owner, [
        'name' => 'Book qualified workshops',
        'conversion_definition_id' => $definition->id,
        'conversion_definition_version_id' => $definitionVersion->id,
        'segment_version_id' => $segment->current_version_id,
        'audience_snapshot_id' => $snapshot->id,
        'offer_id' => $offer->id,
        'offer_version_id' => $offerVersion->id,
        'target_kind' => 'count',
        'target_count' => 2,
        'starts_at' => $startsAt->toAtomString(),
        'ends_at' => $endsAt->toAtomString(),
        'attribution_window_days' => 30,
        'maximum_people' => 2,
        'maximum_accounts' => 1,
        'maximum_messages' => 2,
        'maximum_ai_cost_micros' => 500000,
        'required_qualification_outcomes' => ['contact'],
        'minimum_qualification_confidence' => 0.8,
        'maximum_evidence_age_days' => 30,
        'exclusions' => [],
        'authority_envelope' => ['approval_required' => true],
        'forecast_assumptions' => [],
    ], 1);
    $strategy = app(CreateStrategy::class)->handle($workspace, $owner, [
        'objective_version_id' => $objectiveVersion->id,
        'audience_snapshot_id' => $snapshot->id,
        'offer_version_id' => $offerVersion->id,
        'name' => 'Evidence-led control',
        'positioning' => 'Evidence-backed planning.',
        'problem_frame' => 'Research is difficult to verify.',
        'proof_summary' => 'A reviewed Claim supports the message.',
        'cta_kind' => 'book_workshop',
        'cta_text' => 'Book the workshop',
        'proof_claim_ids' => [$claimId],
        'personalisation_contract' => ['allowed_placeholders' => ['first_name', 'company']],
        'message_generation_contract' => ['message_count' => 1, 'maximum_words' => 120],
        'stop_conditions' => ['on_reply' => true, 'on_conversion' => true],
    ]);
    $experiment = app(CreateGtmExperiment::class)->handle($workspace, $owner, [
        'objective_version_id' => $objectiveVersion->id,
        'name' => 'Approval review experiment',
        'arms' => [[
            'strategy_version_id' => $strategy->current_version_id,
            'key' => 'control',
            'label' => 'Control',
            'allocation_basis_points' => 10000,
            'declared_differences' => [],
        ]],
    ]);
    $experiment = app(FreezeExperimentAssignments::class)->handle($experiment, $owner, 1);
    $arm = $experiment->arms->sole();
    $assignment = $experiment->assignments->sole();

    $envelope = AiBudgetEnvelope::query()->create([
        'workspace_id' => $workspace->id,
        'kind' => 'objective_run',
        'owner_key' => 'plan-view-fixture',
        'hard_limit_micros' => 500000,
        'currency' => 'USD',
        'pricing_version' => 1,
        'held_micros' => 0,
        'committed_micros' => 900,
        'state' => 'closed',
        'opened_at' => now()->subMinute(),
        'closed_at' => now(),
    ]);
    $budgetRun = AiBudgetRun::query()->create([
        'workspace_id' => $workspace->id,
        'task' => 'message_drafting',
        'budget_envelope_id' => $envelope->id,
        'initiated_by_user_id' => $owner->id,
        'limit_snapshot_micros' => 500000,
        'held_micros' => 0,
        'committed_micros' => 900,
        'status' => 'closed',
        'orchestration_owned' => true,
        'opened_at' => now()->subMinute(),
        'closed_at' => now(),
    ]);
    $runInputHash = str_repeat('4', 64);
    $run = ObjectiveRun::query()->create([
        'workspace_id' => $workspace->id,
        'objective_id' => $objective->id,
        'objective_version_id' => $objectiveVersion->id,
        'audience_snapshot_id' => $snapshot->id,
        'gtm_experiment_id' => $experiment->id,
        'run_number' => 1,
        'state' => 'waiting',
        'current_phase' => 'await_approval',
        'input_hash' => $runInputHash,
        'idempotency_key_hash' => str_repeat('5', 64),
        'schema_version' => 'objective_run_v1',
        'message_validator_version' => ValidateDraftedMessage::VERSION,
        'maximum_cost_micros' => 500000,
        'reserved_cost_micros' => 0,
        'actual_cost_micros' => 900,
        'ai_budget_envelope_id' => $envelope->id,
        'fencing_token' => 1,
        'created_by_user_id' => $owner->id,
        'started_at' => now()->subMinute(),
    ]);
    $draft = ObjectiveRunArmDraft::query()->create([
        'workspace_id' => $workspace->id,
        'objective_run_id' => $run->id,
        'gtm_experiment_arm_id' => $arm->id,
        'ai_budget_run_id' => $budgetRun->id,
        'state' => 'succeeded',
        'prompt_version' => 'objective_message_prompt_v1',
        'schema_version' => 'message_drafting_response_v1',
        'validator_version' => 'objective_message_validator_v1',
        'model_id' => 'openai/test-model',
        'task_policy_version' => 1,
        'input_hash' => str_repeat('6', 64),
        'subject_template' => 'A plan for Acme',
        'body_template' => "Hi Ada,\n\nWould you like to review the evidence?",
        'claim_manifest' => ['claim_ids' => [$claimId]],
        'cta_manifest' => ['kind' => 'book_workshop'],
        'output_hash' => str_repeat('7', 64),
        'template_hash' => str_repeat('8', 64),
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);
    $connection = ProviderConnection::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $workspace->id,
        'token_version' => 7,
    ]);
    $mailbox = Mailbox::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $workspace->id,
        'provider_connection_id' => $connection->id,
        'email_address' => 'owner@example.test',
    ]);
    $omittedMemberId = (string) $members->last()->id;
    $plan = ExecutionPlan::query()->create([
        'workspace_id' => $workspace->id,
        'objective_run_id' => $run->id,
        'version' => 1,
        'status' => 'partial',
        'objective_version_id' => $objectiveVersion->id,
        'audience_snapshot_id' => $snapshot->id,
        'gtm_experiment_id' => $experiment->id,
        'conversion_definition_version_id' => $definitionVersion->id,
        'offer_version_id' => $offerVersion->id,
        'outreach_policy_version_id' => OutreachPolicy::query()
            ->where('workspace_id', $workspace->id)
            ->firstOrFail()
            ->current_version_id,
        'model_id' => 'openai/test-model',
        'ai_task_version' => 'message_drafting_v1',
        'prompt_version' => 'objective_message_prompt_v1',
        'schema_version' => 'message_drafting_response_v1',
        'validator_version' => 'execution_plan_validator_v1',
        'arm_count' => 1,
        'account_count' => 1,
        'people_count' => 1,
        'action_count' => 1,
        'maximum_cost_micros' => 500000,
        'estimated_cost_micros' => 1200,
        'actual_cost_micros' => 900,
        'input_manifest' => [
            'run_input_hash' => $runInputHash,
            'omitted' => [['member_id' => $omittedMemberId, 'reason' => 'recipient_suppressed']],
        ],
        'evidence_manifest_hash' => str_repeat('9', 64),
        'compilation_manifest' => ['version' => 'execution_plan_compilation_v1', 'campaigns' => []],
        'canonical_payload_hash' => str_repeat('a', 64),
        'plan_hash' => str_repeat('b', 64),
        'created_by_user_id' => $owner->id,
    ]);
    $action = ExecutionPlanAction::query()->create([
        'workspace_id' => $workspace->id,
        'execution_plan_id' => $plan->id,
        'gtm_experiment_assignment_id' => $assignment->id,
        'gtm_experiment_arm_id' => $arm->id,
        'objective_run_arm_draft_id' => $draft->id,
        'strategy_version_id' => $strategy->current_version_id,
        'audience_snapshot_member_id' => $members->first()->id,
        'account_id' => $account->id,
        'person_id' => $people->first()->id,
        'offer_version_id' => $offerVersion->id,
        'conversion_definition_version_id' => $definitionVersion->id,
        'selected_claim_ids' => [$claimId],
        'selected_evidence_ids' => [$evidenceId],
        'selected_claim_safety_hashes' => [$claimId => str_repeat('7', 64)],
        'offer_content_hash' => (string) $offerVersion->content_hash,
        'offer_contract_hash' => str_repeat('6', 64),
        'cta_destination_url' => 'https://example.test/workshop',
        'cta_response_route' => 'calendar',
        'action_kind' => 'email_new_thread',
        'position' => 1,
        'destination_email_hash' => str_repeat('c', 64),
        'destination_email_address' => 'ada@example.test',
        'subject' => 'A plan for Acme',
        'body_text' => "Hi Ada,\n\nWould you like to review the evidence?",
        'body_html' => '<p>Hi Ada,</p><p>Would you like to review the evidence?</p>',
        'template_hash' => str_repeat('8', 64),
        'recipient_snapshot_hash' => str_repeat('d', 64),
        'content_hash' => str_repeat('e', 64),
        'mailbox_id' => $mailbox->id,
        'provider_connection_id' => $connection->id,
        'provider_connection_version' => 7,
        'sender_address_snapshot' => 'owner@example.test',
        'sender_display_name_snapshot' => 'Objective Owner',
        'reply_to_snapshot' => 'replies@example.test',
        'scheduled_not_before' => $startsAt,
        'scheduled_not_after' => $endsAt,
        'policy_input_hash' => str_repeat('f', 64),
        'action_fingerprint' => str_repeat('0', 64),
        'state' => 'awaiting_approval',
    ]);

    return compact('owner', 'plan', 'action', 'omittedMemberId') + [
        'omitted_member_id' => $omittedMemberId,
    ];
}

it('shows every frozen execution-plan detail without creating a campaign or authority', function (): void {
    $fixture = executionPlanViewFixture();

    $response = $this->actingAs($fixture['owner'])->get(route('execution-plans.show', $fixture['plan']));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('execution-plans/Show')
            ->where('plan.id', $fixture['plan']->id)
            ->where('plan.status', 'partial')
            ->where('plan.objective.name', 'Book qualified workshops')
            ->where('plan.lineage.experiment_name', 'Approval review experiment')
            ->where('plan.audience_counts', [
                'included' => 2,
                'excluded' => 3,
                'uncertain' => 4,
                'suppressed' => 5,
            ])
            ->where('plan.counts', [
                'arms' => 1,
                'accounts' => 1,
                'people' => 1,
                'actions' => 1,
                'omissions' => 1,
            ])
            ->where('plan.costs', [
                'maximum_micros' => 500000,
                'estimated_micros' => 1200,
                'actual_micros' => 900,
            ])
            ->where('plan.hashes.plan', str_repeat('b', 64))
            ->where('plan.omissions.0', [
                'member_id' => $fixture['omitted_member_id'],
                'reason' => 'recipient_suppressed',
            ])
            ->has('plan.actions', 1)
            ->where('plan.actions.0.recipient.address', 'ada@example.test')
            ->where('plan.actions.0.sender', [
                'address' => 'owner@example.test',
                'display_name' => 'Objective Owner',
                'reply_to' => 'replies@example.test',
                'mailbox_id' => $fixture['action']->mailbox_id,
                'provider_connection_id' => $fixture['action']->provider_connection_id,
                'provider_connection_version' => 7,
            ])
            ->where('plan.actions.0.subject', 'A plan for Acme')
            ->where('plan.actions.0.body_text', "Hi Ada,\n\nWould you like to review the evidence?")
            ->where('plan.actions.0.body_html', '<p>Hi Ada,</p><p>Would you like to review the evidence?</p>')
            ->where('plan.actions.0.selected_claim_ids', $fixture['action']->selected_claim_ids)
            ->where('plan.actions.0.selected_evidence_ids', $fixture['action']->selected_evidence_ids)
            ->where('plan.actions.0.selected_claim_safety_hashes', $fixture['action']->selected_claim_safety_hashes)
            ->where('plan.actions.0.evidence_review.reason_to_contact_version_id', null)
            ->where(
                'plan.actions.0.evidence_review.fresh_until',
                $fixture['action']->audienceSnapshotMember()->firstOrFail()->evidence_fresh_until?->toAtomString(),
            )
            ->where('plan.actions.0.offer', [
                'content_hash' => $fixture['action']->offer_content_hash,
                'contract_hash' => str_repeat('6', 64),
                'destination_url' => 'https://example.test/workshop',
                'response_route' => 'calendar',
            ])
            ->where('plan.actions.0.status', 'awaiting_approval')
            ->where('plan.actions.0.hashes', [
                'destination_email' => str_repeat('c', 64),
                'template' => str_repeat('8', 64),
                'recipient_snapshot' => str_repeat('d', 64),
                'content' => str_repeat('e', 64),
                'policy_input' => str_repeat('f', 64),
                'action_fingerprint' => str_repeat('0', 64),
            ])
            ->where('safety.creates_campaign', false)
            ->where('safety.grants_sending_authority', false)
            ->where('safety.compilation_sends_email', false)
            ->where('execution.is_owner', true)
            ->where('execution.state', 'partial')
            ->where('execution.lineage', [
                'exposures' => [],
                'conversations' => [],
                'conversions' => [],
            ])
            ->where('execution.available_actions', [])
            ->where('safety.available_actions', []));

    expect(Campaign::query()->count())->toBe(0);
});

it('returns every consequential frozen action field through the scoped REST detail', function (): void {
    $fixture = executionPlanViewFixture();
    $action = $fixture['action'];

    $this->withToken(apiToken($fixture['owner'], [TokenScope::ObjectivesRead->value]))
        ->getJson(route('api.v1.execution-plans.show', $fixture['plan']))
        ->assertOk()
        ->assertJsonPath('data.actions.0.position', $action->position)
        ->assertJsonPath('data.actions.0.objective_run_arm_draft_id', $action->objective_run_arm_draft_id)
        ->assertJsonPath('data.actions.0.strategy_version_id', $action->strategy_version_id)
        ->assertJsonPath('data.actions.0.audience_snapshot_member_id', $action->audience_snapshot_member_id)
        ->assertJsonPath('data.actions.0.offer_version_id', $action->offer_version_id)
        ->assertJsonPath('data.actions.0.conversion_definition_version_id', $action->conversion_definition_version_id)
        ->assertJsonPath('data.actions.0.body_html', $action->body_html)
        ->assertJsonPath('data.actions.0.sender_display_name', $action->sender_display_name_snapshot)
        ->assertJsonPath('data.actions.0.reply_to', $action->reply_to_snapshot)
        ->assertJsonPath('data.actions.0.provider_connection_id', $action->provider_connection_id)
        ->assertJsonPath('data.actions.0.provider_connection_version', $action->provider_connection_version)
        ->assertJsonPath('data.actions.0.template_hash', $action->template_hash)
        ->assertJsonPath('data.actions.0.recipient_snapshot_hash', $action->recipient_snapshot_hash)
        ->assertJsonPath('data.actions.0.policy_input_hash', $action->policy_input_hash)
        ->assertJsonPath('data.actions.0.action_fingerprint', $action->action_fingerprint)
        ->assertJsonPath('data.actions.0.selected_claim_safety_hashes', $action->selected_claim_safety_hashes)
        ->assertJsonPath('data.actions.0.reason_to_contact_version_id', null)
        ->assertJsonPath(
            'data.actions.0.evidence_fresh_until',
            $action->audienceSnapshotMember()->firstOrFail()->evidence_fresh_until?->toAtomString(),
        )
        ->assertJsonPath('data.actions.0.offer_contract_hash', $action->offer_contract_hash)
        ->assertJsonPath('data.actions.0.cta_destination_url', $action->cta_destination_url)
        ->assertJsonPath('data.execution.is_owner', true)
        ->assertJsonPath('data.execution.state', 'partial')
        ->assertJsonPath('data.execution.lineage.exposures', [])
        ->assertJsonPath('data.execution.lineage.conversations', [])
        ->assertJsonPath('data.execution.lineage.conversions', [])
        ->assertJsonPath('data.execution.available_actions', []);
});

it('allows a current workspace member to inspect the plan', function (): void {
    $fixture = executionPlanViewFixture();
    $member = User::factory()->withoutWorkspace()->create();
    WorkspaceMembership::query()->create([
        'workspace_id' => $fixture['owner']->currentWorkspace()->id,
        'user_id' => $member->id,
        'role' => 'member',
    ]);

    $this->actingAs($member)
        ->get(route('execution-plans.show', $fixture['plan']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('execution-plans/Show')
            ->where('plan.id', $fixture['plan']->id));
});

it('does not reveal a plan from another current workspace', function (): void {
    $fixture = executionPlanViewFixture();
    $foreignUser = User::factory()->create();

    $this->actingAs($foreignUser)
        ->get(route('execution-plans.show', $fixture['plan']))
        ->assertNotFound();
});

it('requires authentication', function (): void {
    $fixture = executionPlanViewFixture();

    $this->get(route('execution-plans.show', $fixture['plan']))
        ->assertRedirect(route('login'));
});

it('prepares the exact owner snapshot through a normal session redirect without sending', function (): void {
    $fixture = executionPlanViewFixture();
    $compilation = ExecutionPlanCompilation::query()->create([
        'workspace_id' => $fixture['plan']->workspace_id,
        'execution_plan_id' => $fixture['plan']->id,
        'succeeded_plan_id' => $fixture['plan']->id,
        'attempt' => 1,
        'state' => 'succeeded',
        'input_hash' => hash('sha256', 'execution-plan-view-compilation'),
        'output_hash' => hash('sha256', 'execution-plan-view-output'),
        'created_by_user_id' => $fixture['owner']->id,
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    $this->mock(CompileExecutionPlan::class)
        ->shouldReceive('handle')
        ->once()
        ->withArgs(fn (ExecutionPlan $plan, User $actor): bool => $plan->is($fixture['plan']) && $actor->is($fixture['owner']))
        ->andReturn($compilation);

    $this->actingAs($fixture['owner'])
        ->from(route('execution-plans.show', $fixture['plan']))
        ->post(route('execution-plans.compile', $fixture['plan']), [
            'plan_id' => (string) $fixture['plan']->id,
            'plan_hash' => (string) $fixture['plan']->plan_hash,
        ], ['Idempotency-Key' => 'prepare-exact-plan'])
        ->assertRedirect(route('execution-plans.show', $fixture['plan']))
        ->assertSessionHas('success', 'The approved plan was prepared as 0 campaigns. Nothing was sent.');

    expect(Campaign::query()->count())->toBe(0);
});

it('returns stale session compile and launch submissions to the plan with useful errors', function (string $operation): void {
    $fixture = executionPlanViewFixture();

    $this->actingAs($fixture['owner'])
        ->from(route('execution-plans.show', $fixture['plan']))
        ->post(route('execution-plans.'.$operation, $fixture['plan']), [
            'plan_id' => (string) Str::uuid(),
            'plan_hash' => str_repeat('0', 64),
        ], ['Idempotency-Key' => 'stale-'.$operation])
        ->assertRedirect(route('execution-plans.show', $fixture['plan']))
        ->assertSessionHasErrors([
            'plan' => 'This execution plan changed after it was loaded. Refresh and review it again.',
        ]);
})->with(['compile', 'launch']);

it('keeps session compile and launch mutations owner-only and workspace-scoped', function (string $operation): void {
    $fixture = executionPlanViewFixture();
    $payload = [
        'plan_id' => (string) $fixture['plan']->id,
        'plan_hash' => (string) $fixture['plan']->plan_hash,
    ];
    $member = User::factory()->withoutWorkspace()->create();
    WorkspaceMembership::query()->create([
        'workspace_id' => $fixture['plan']->workspace_id,
        'user_id' => $member->id,
        'role' => 'member',
    ]);

    $this->actingAs($member)
        ->post(route('execution-plans.'.$operation, $fixture['plan']), $payload, ['Idempotency-Key' => 'member-'.$operation])
        ->assertForbidden();

    $foreignOwner = User::factory()->create();
    $this->actingAs($foreignOwner)
        ->post(route('execution-plans.'.$operation, $fixture['plan']), $payload, ['Idempotency-Key' => 'foreign-'.$operation])
        ->assertNotFound();
})->with(['compile', 'launch']);

it('replays the exact REST compilation response and rejects changed payloads for the same key', function (): void {
    $fixture = executionPlanViewFixture();
    $compilation = ExecutionPlanCompilation::query()->create([
        'workspace_id' => $fixture['plan']->workspace_id,
        'execution_plan_id' => $fixture['plan']->id,
        'succeeded_plan_id' => $fixture['plan']->id,
        'attempt' => 1,
        'state' => 'succeeded',
        'input_hash' => hash('sha256', 'rest-compilation-input'),
        'output_hash' => hash('sha256', 'rest-compilation-output'),
        'created_by_user_id' => $fixture['owner']->id,
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    $this->mock(CompileExecutionPlan::class)
        ->shouldReceive('handle')
        ->once()
        ->andReturn($compilation);
    $token = apiToken($fixture['owner'], [TokenScope::ObjectivesApprove->value]);
    $payload = [
        'plan_id' => (string) $fixture['plan']->id,
        'plan_hash' => (string) $fixture['plan']->plan_hash,
    ];
    $headers = ['Idempotency-Key' => 'compile-plan-once'];

    $first = $this->withToken($token)
        ->postJson(route('api.v1.execution-plans.compile', $fixture['plan']), $payload, $headers)
        ->assertCreated()
        ->assertJsonPath('data.id', (string) $compilation->id)
        ->assertJsonPath('data.state', 'succeeded')
        ->assertJsonPath('data.campaigns', []);
    $this->withToken($token)
        ->postJson(route('api.v1.execution-plans.compile', $fixture['plan']), $payload, $headers)
        ->assertCreated()
        ->assertExactJson($first->json());
    $this->withToken($token)
        ->postJson(route('api.v1.execution-plans.compile', $fixture['plan']), [
            ...$payload,
            'plan_hash' => str_repeat('0', 64),
        ], $headers)
        ->assertConflict()
        ->assertJsonPath('error.type', 'idempotency_conflict');
});

it('returns stable REST conflicts for stale compile and launch snapshots', function (string $operation): void {
    $fixture = executionPlanViewFixture();
    $token = apiToken($fixture['owner'], [TokenScope::ObjectivesApprove->value]);

    $this->withToken($token)
        ->postJson(route('api.v1.execution-plans.'.$operation, $fixture['plan']), [
            'plan_id' => (string) Str::uuid(),
            'plan_hash' => str_repeat('0', 64),
        ], ['Idempotency-Key' => 'stale-rest-'.$operation])
        ->assertConflict()
        ->assertJsonPath('error.type', 'conflict')
        ->assertJsonPath('error.code', 'execution_plan_snapshot_changed');
})->with(['compile', 'launch']);
