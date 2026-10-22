<?php

use App\Domain\Ai\Enums\AiCredentialStatus;
use App\Domain\Ai\Enums\AiProviderName;
use App\Domain\Ai\Providers\DeterministicOpenRouterAdapter;
use App\Domain\Ai\Services\AiWorkspaceSettings;
use App\Domain\Conversions\Services\ActivateConversionDefinition;
use App\Domain\Gtm\Enums\OfferStatus;
use App\Domain\Gtm\Services\ChangeOfferStatus;
use App\Domain\Gtm\Services\ConfigureOperationalOffer;
use App\Domain\Gtm\Services\CreateOffer;
use App\Domain\Gtm\Services\ReviewOfferVersion;
use App\Domain\Intelligence\Services\EnsureQualificationRubric;
use App\Domain\Intelligence\Support\CanonicalIntelligence;
use App\Domain\Mail\Services\MailboxSenderIdentityService;
use App\Domain\Objectives\Services\ApproveExecutionPlan;
use App\Domain\Objectives\Services\CompileExecutionPlan;
use App\Domain\Objectives\Services\CreateGtmExperiment;
use App\Domain\Objectives\Services\CreateObjective;
use App\Domain\Objectives\Services\CreateSegment;
use App\Domain\Objectives\Services\CreateStrategy;
use App\Domain\Objectives\Services\ExecuteObjectiveRunPhase;
use App\Domain\Objectives\Services\FreezeExperimentAssignments;
use App\Domain\Objectives\Services\StartObjectiveRun;
use App\Domain\Objectives\Services\TransitionObjective;
use App\Domain\Objectives\Services\VersionObjective;
use App\Domain\Objectives\Support\ApprovalManifest;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Domain\Objectives\Support\MessageEvidenceContract;
use App\Models\Account;
use App\Models\AccountResearchRun;
use App\Models\AiProviderCredential;
use App\Models\AudienceSnapshot;
use App\Models\AudienceSnapshotMember;
use App\Models\AudienceSnapshotMemberSource;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\ConversionDefinition;
use App\Models\ConversionDefinitionVersion;
use App\Models\ExecutionPlan;
use App\Models\ExecutionPlanCampaign;
use App\Models\GtmExperiment;
use App\Models\GtmProfile;
use App\Models\Mailbox;
use App\Models\Objective;
use App\Models\ObjectiveRun;
use App\Models\ObjectiveRunDispatch;
use App\Models\ObjectiveRunPhase;
use App\Models\ObjectiveVersion;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\OutreachPolicy;
use App\Models\OutreachPolicyVersion;
use App\Models\Person;
use App\Models\PersonSourceMembership;
use App\Models\ProspectSource;
use App\Models\ProspectSourceVersion;
use App\Models\ProviderConnection;
use App\Models\QualificationDecision;
use App\Models\ReasonToContactClaim;
use App\Models\ReasonToContactVersion;
use App\Models\ResearchClaim;
use App\Models\ResearchClaimEvidence;
use App\Models\ResearchSourceSnapshot;
use App\Models\SequenceStep;
use App\Models\SequenceVersion;
use App\Models\SourceObservation;
use App\Models\SourceRun;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * @return array{owner:User,objective:Objective,version:ObjectiveVersion,claim_id:string,accounts:list<Account>}
 */
function s3IncrementTwoObjectiveFixture(
    int $accountCount = 2,
    int $peoplePerAccount = 2,
    int $maximumAiCostMicros = 100000,
): array {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $objective = app(CreateObjective::class)->handle($workspace, $owner);
    $segment = app(CreateSegment::class)->handle(
        $workspace,
        $owner,
        'Frozen pilot audience',
        ['field' => 'account.domain_present', 'operator' => 'eq', 'value' => true],
    );
    $claimId = (string) Str::uuid();
    $accounts = [];
    $people = [];
    foreach (range(1, $accountCount) as $accountNumber) {
        $account = Account::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => $accountNumber === 1 ? 'Acme' : "Example Account {$accountNumber}",
            'domain_normalized' => "s3-{$owner->id}-{$accountNumber}.example",
        ]);
        $accounts[] = $account;
        foreach (range(1, $peoplePerAccount) as $personNumber) {
            $people[] = Person::factory()->create([
                'workspace_id' => $workspace->id,
                'account_id' => $account->id,
                'email_address' => "person-{$accountNumber}-{$personNumber}-{$owner->id}@example.test",
                'email_normalized' => "person-{$accountNumber}-{$personNumber}-{$owner->id}@example.test",
            ]);
        }
    }
    $snapshot = AudienceSnapshot::query()->create([
        'workspace_id' => $workspace->id,
        'segment_version_id' => $segment->current_version_id,
        'objective_id' => $objective->id,
        'purpose' => 'objective_execution',
        'status' => 'frozen',
        'evaluator_version' => 'segment_evaluator_v1',
        'criteria_hash' => str_repeat('1', 64),
        'evaluation_hash' => hash('sha256', 'fixture-'.$objective->id),
        'included_people_count' => count($people),
        'excluded_people_count' => 0,
        'uncertain_people_count' => 0,
        'suppressed_people_count' => 0,
        'included_accounts_count' => count($accounts),
        'excluded_accounts_count' => 0,
        'uncertain_accounts_count' => 0,
        'suppressed_accounts_count' => 0,
        'source_rights_snapshot' => [],
        'frozen_by_user_id' => $owner->id,
        'frozen_at' => now(),
    ]);
    foreach ($people as $person) {
        AudienceSnapshotMember::query()->create([
            'workspace_id' => $workspace->id,
            'audience_snapshot_id' => $snapshot->id,
            'account_id' => $person->account_id,
            'person_id' => $person->id,
            'result' => 'included',
            'qualification_decision_id' => null,
            'reason_to_contact_version_id' => null,
            'criterion_results' => [],
            'inclusion_reasons' => ['fixture'],
            'exclusion_reasons' => [],
            'missing_fields' => [],
            'suppression_snapshot' => ['suppressed' => false],
            'evidence_manifest' => [['claim_id' => $claimId, 'content_hash' => str_repeat('2', 64)]],
            'qualification_content_hash' => null,
            'reason_content_hash' => null,
            'source_lineage_hash' => str_repeat('3', 64),
            'member_hash' => hash('sha256', 'member-'.$person->id),
            'evidence_fresh_until' => now()->addDays(30),
            'evaluated_at' => now(),
        ]);
    }

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

    $version = app(VersionObjective::class)->handle($objective, $owner, [
        'name' => 'Book qualified workshops',
        'conversion_definition_id' => $definition->id,
        'conversion_definition_version_id' => $definitionVersion->id,
        'segment_version_id' => $segment->current_version_id,
        'audience_snapshot_id' => $snapshot->id,
        'offer_id' => $offer->id,
        'offer_version_id' => $offerVersion->id,
        'target_kind' => 'count',
        'target_count' => 2,
        'starts_at' => now()->addDay()->toAtomString(),
        'ends_at' => now()->addDays(20)->toAtomString(),
        'attribution_window_days' => 30,
        'maximum_people' => count($people),
        'maximum_accounts' => count($accounts),
        'maximum_messages' => count($people),
        'maximum_ai_cost_micros' => $maximumAiCostMicros,
        'required_qualification_outcomes' => ['contact'],
        'minimum_qualification_confidence' => 0.8,
        'maximum_evidence_age_days' => 30,
        'exclusions' => [],
        'authority_envelope' => ['approval_required' => true],
        'forecast_assumptions' => ['reply_to_conversion_rate' => ['low' => 0.1, 'base' => 0.2, 'high' => 0.3]],
    ], 1);

    return [
        'owner' => $owner,
        'objective' => $objective,
        'version' => $version,
        'claim_id' => $claimId,
        'accounts' => $accounts,
    ];
}

/**
 * @param  array{owner:User,version:ObjectiveVersion,claim_id:string,claim_ids?:list<string>}  $fixture
 * @param  array<string, mixed>  $changes
 */
function s3IncrementTwoStrategy(array $fixture, string $name, array $changes = []): Strategy
{
    $version = $fixture['version'];

    return app(CreateStrategy::class)->handle($fixture['owner']->currentWorkspace(), $fixture['owner'], [
        'objective_version_id' => $version->id,
        'audience_snapshot_id' => $version->audience_snapshot_id,
        'offer_version_id' => $version->offer_version_id,
        'name' => $name,
        'positioning' => 'Evidence-backed planning for revenue leaders.',
        'problem_frame' => 'Account research is difficult to verify.',
        'proof_summary' => 'The frozen Claim supports the message.',
        'cta_kind' => 'book_workshop',
        'cta_text' => 'Book the workshop',
        'proof_claim_ids' => $fixture['claim_ids'] ?? [$fixture['claim_id']],
        'personalisation_contract' => ['allowed_placeholders' => ['first_name', 'company']],
        'message_generation_contract' => ['message_count' => 1, 'maximum_words' => 120],
        'stop_conditions' => ['on_reply' => true, 'on_conversion' => true],
        ...$changes,
    ]);
}

/**
 * Upgrade the compact Increment 2 fixture with real S1/S2 readiness so the
 * Increment 3 run can cross every deterministic boundary.
 *
 * @return array{owner:User,objective:Objective,version:ObjectiveVersion,claim_id:string,claim_ids:list<string>,claim_values:array<string,string>,evidence_ids:array<string,string>,accounts:list<Account>,experiment:GtmExperiment}
 */
function s3IncrementThreeReadyFixture(int $accountCount = 1, int $maximumAiCostMicros = 100000, int $startDelayMinutes = 1440): array
{
    config()->set('intelligence.testing.fake_providers', true);
    $fixture = s3IncrementTwoObjectiveFixture($accountCount, 1, $maximumAiCostMicros);
    $owner = $fixture['owner'];
    $workspace = $owner->currentWorkspace();
    $baseVersion = $fixture['version'];
    $definition = ConversionDefinition::query()->whereKey($baseVersion->conversion_definition_id)->firstOrFail();
    app(ActivateConversionDefinition::class)->handle($definition, $owner);

    $offer = app(CreateOffer::class)->handle($workspace, $owner, [
        'name' => 'Evidence workshop',
        'promise' => 'Turn reviewed evidence into one practical action.',
        'target_audience' => 'Qualified B2B teams.',
        'desired_outcome' => 'A reviewed action brief.',
        'call_to_action' => 'Book one workshop.',
        'notes' => 'Used by the deterministic S3 test.',
    ], 's3-run-offer-create');
    $configured = app(ConfigureOperationalOffer::class)->handle($offer, $owner, [
        'schema_version' => 2,
        'name' => 'Evidence workshop',
        'promise' => 'Turn reviewed evidence into one practical action.',
        'target_audience' => 'Qualified B2B teams.',
        'desired_outcome' => 'A reviewed action brief.',
        'call_to_action' => 'Book one workshop.',
        'notes' => 'Used by the deterministic S3 test.',
        'proposition' => [
            'problem_or_opportunity' => 'Account evidence is difficult to action.',
            'value' => 'A traceable recommendation.',
            'proof' => 'Every fact remains linked to reviewed evidence.',
        ],
        'requested_commitment' => 'Book one 30-minute workshop.',
        'cta' => [
            'action' => 'book_workshop',
            'destination_url' => 'https://example.test/workshop',
            'response_route' => 'calendar',
            'required_inputs' => [],
            'acceptance_conversion_definition_version_id' => $definition->current_version_id,
        ],
        'fulfilment' => [
            'mode' => 'external_destination',
            'capability_id' => null,
            'asset_version_ids' => [],
            'owner_user_id' => null,
            'response_sla_hours' => null,
            'capacity_units' => 1,
        ],
        'eligibility' => ['includes' => ['Qualified Account'], 'excludes' => ['Suppressed Account']],
        'commercial_authority' => ['approved' => true, 'notes' => 'Approved for deterministic evaluation.'],
        'approved_claims' => ['Reviewed evidence supports the workshop.'],
        'prohibited_claims' => ['Guaranteed outcomes.'],
        'expires_at' => now()->addMonth()->toIso8601String(),
        'agent_parameter_policy' => ['allowed_keys' => []],
    ], 0, 's3-run-offer-configure');
    app(ReviewOfferVersion::class)->handle($configured, $owner, 'submit');
    $approved = app(ReviewOfferVersion::class)->handle($configured, $owner, 'approve');
    $activeOffer = app(ChangeOfferStatus::class)->handle($approved, $owner, OfferStatus::Active, 1, 's3-run-offer-activate');

    $profile = GtmProfile::query()->where('workspace_id', $workspace->id)->with('currentVersion')->firstOrFail();
    $rubric = app(EnsureQualificationRubric::class)->handle($workspace, $owner, $profile->currentVersion, $activeOffer->currentVersion);
    $source = ProspectSource::factory()->create([
        'workspace_id' => $workspace->id,
        'owner_user_id' => $owner->id,
    ]);
    $sourceVersion = ProspectSourceVersion::factory()->forSource($source)->create();
    $source->forceFill(['current_version_id' => $sourceVersion->id])->save();
    $sourceRun = SourceRun::factory()->forSource($source, $sourceVersion)->create(['state' => 'completed']);

    $claimIds = [];
    $claimValues = [];
    $evidenceIds = [];
    foreach (AudienceSnapshotMember::query()->where('audience_snapshot_id', $baseVersion->audience_snapshot_id)->orderBy('account_id')->get() as $index => $member) {
        $account = Account::query()->whereKey($member->account_id)->firstOrFail();
        $person = Person::query()->whereKey($member->person_id)->firstOrFail();
        $researchRun = AccountResearchRun::factory()->create([
            'workspace_id' => $workspace->id,
            'account_id' => $account->id,
            'gtm_profile_version_id' => $profile->current_version_id,
            'offer_version_id' => $activeOffer->current_version_id,
            'state' => 'completed',
        ]);
        $claimId = $index === 0 ? $fixture['claim_id'] : (string) Str::uuid();
        $claimValue = "Reviewed B2B category {$account->id}-{$owner->id}";
        $sourceSnapshot = ResearchSourceSnapshot::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'account_id' => $account->id,
            'research_run_id' => $researchRun->id,
            'stage_id' => null,
            'source_type' => 'website',
            'canonical_public_url' => "https://evidence.example/{$account->id}",
            'registrable_domain' => 'evidence.example',
            'retrieved_at' => now(),
            'observed_at' => now(),
            'content_hash' => hash('sha256', 'source-content-'.$account->id),
            'content' => $claimValue,
            'mime_type' => 'text/plain',
            'byte_count' => strlen($claimValue),
            'result_identity' => 's3-source-'.$account->id,
            'expires_at' => now()->addDays(30),
            'state' => 'available',
        ]);
        $claim = ResearchClaim::query()->create([
            'id' => $claimId,
            'workspace_id' => $workspace->id,
            'account_id' => $account->id,
            'research_run_id' => $researchRun->id,
            'supersedes_claim_id' => null,
            'subject_type' => 'account',
            'predicate' => 'company.category',
            'value_type' => 'string',
            'value_json' => $claimValue,
            'value_hash' => CanonicalIntelligence::valueHash($claimValue),
            'status' => 'supported',
            'confidence' => 1,
            'calibration_band' => 'high',
            'observed_at' => now(),
            'fresh_until' => now()->addDays(30),
            'expires_at' => now()->addDays(30),
            'extractor_kind' => 'deterministic_fixture',
            'extractor_version' => '1',
            'safe_for_message' => true,
            'content_hash' => hash('sha256', 'claim-content-'.$account->id),
        ]);
        $evidenceId = (string) Str::uuid();
        ResearchClaimEvidence::query()->create([
            'id' => $evidenceId,
            'workspace_id' => $workspace->id,
            'claim_id' => $claim->id,
            'source_snapshot_id' => $sourceSnapshot->id,
            'edge_type' => 'supports',
            'locator' => 'Reviewed category',
            'locator_hash' => hash('sha256', 'Reviewed category'),
            'quote_hash' => hash('sha256', $claimValue),
            'evidence_weight' => 1,
            'extraction_version' => '1',
        ]);
        $claimManifest = app(MessageEvidenceContract::class)->currentManifest(
            $claim->load('evidence'),
            (int) $workspace->id,
            (int) $account->id,
        );
        if ($claimManifest === null) {
            throw new RuntimeException('The deterministic fixture requires a valid evidence manifest.');
        }
        $claimIds[] = $claimId;
        $claimValues[$claimId] = $claimValue;
        $evidenceIds[$claimId] = $evidenceId;
        $decision = QualificationDecision::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'account_id' => $account->id,
            'research_run_id' => $researchRun->id,
            'rubric_version_id' => $rubric->id,
            'gtm_profile_version_id' => $profile->current_version_id,
            'offer_version_id' => $activeOffer->current_version_id,
            'input_hash' => hash('sha256', 'decision-'.$account->id),
            'outcome' => 'contact',
            'confidence' => 0.95,
            'calibration_band' => 'high',
            'missing_facts' => [],
            'disqualifiers' => [],
            'explanation' => 'Reviewed deterministic qualification.',
            'provider_cost_micros' => 0,
            'review_state' => 'accepted',
            'content_hash' => hash('sha256', 'decision-content-'.$account->id),
            'generated_at' => now(),
            'reviewed_at' => now(),
        ]);
        $reason = ReasonToContactVersion::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'account_id' => $account->id,
            'qualification_decision_id' => $decision->id,
            'why_account' => 'The account matches the reviewed audience.',
            'why_offer' => 'The workshop addresses the reviewed problem.',
            'why_now' => 'The evidence is current.',
            'factual_basis' => 'Reviewed qualification and frozen evidence.',
            'internal_rationale' => null,
            'confidence' => 0.95,
            'fresh_until' => now()->addDays(30),
            'expires_at' => now()->addDays(30),
            'safe_for_message' => true,
            'sensitive_inference' => false,
            'prohibited_inference' => false,
            'content_hash' => hash('sha256', 'reason-'.$account->id),
        ]);
        ReasonToContactClaim::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'reason_to_contact_version_id' => $reason->id,
            'claim_id' => $claim->id,
            'role' => 'basis',
        ]);
        $observation = SourceObservation::factory()->forSourceRun($sourceRun)->person()->create([
            'person_id' => $person->id,
            'account_id' => null,
            'disposition' => 'linked',
        ]);
        $membership = PersonSourceMembership::factory()->forObservation($observation, $person)->create([
            'state' => 'active',
            'expired_at' => null,
        ]);
        DB::table('audience_snapshot_members')->where('id', $member->id)->update([
            'qualification_decision_id' => $decision->id,
            'reason_to_contact_version_id' => $reason->id,
            'qualification_content_hash' => $decision->content_hash,
            'reason_content_hash' => $reason->content_hash,
            'evidence_manifest' => json_encode([$claimManifest], JSON_THROW_ON_ERROR),
            'evidence_fresh_until' => now()->addDays(30),
        ]);
        AudienceSnapshotMemberSource::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'audience_snapshot_id' => $baseVersion->audience_snapshot_id,
            'audience_snapshot_member_id' => $member->id,
            'prospect_source_id' => $source->id,
            'prospect_source_version_id' => $sourceVersion->id,
            'source_observation_id' => $observation->id,
            'person_source_membership_id' => $membership->id,
            'policy_hash' => $sourceVersion->policy_hash,
            'rights_result' => 'allowed',
            'observed_at' => now(),
            'expires_at' => now()->addYear(),
        ]);
    }

    $fixture['claim_id'] = $claimIds[0];
    $fixture['claim_ids'] = $claimIds;

    $version = app(VersionObjective::class)->handle($fixture['objective'], $owner, [
        'name' => 'Book qualified workshops',
        'conversion_definition_id' => $definition->id,
        'conversion_definition_version_id' => $definition->current_version_id,
        'segment_version_id' => $baseVersion->segment_version_id,
        'audience_snapshot_id' => $baseVersion->audience_snapshot_id,
        'offer_id' => $activeOffer->id,
        'offer_version_id' => $activeOffer->current_version_id,
        'target_kind' => 'count',
        'target_count' => $accountCount,
        'starts_at' => now()->addMinutes($startDelayMinutes)->toAtomString(),
        'ends_at' => now()->addDays(20)->toAtomString(),
        'attribution_window_days' => 30,
        'maximum_people' => $accountCount,
        'maximum_accounts' => $accountCount,
        'maximum_messages' => $accountCount,
        'maximum_ai_cost_micros' => $maximumAiCostMicros,
        'required_qualification_outcomes' => ['contact'],
        'minimum_qualification_confidence' => 0.8,
        'maximum_evidence_age_days' => 30,
        'exclusions' => [],
        'authority_envelope' => ['approval_required' => true],
        'forecast_assumptions' => ['reply_to_conversion_rate' => ['low' => 0.1, 'base' => 0.2, 'high' => 0.3]],
    ], 2);
    $fixture['version'] = $version;
    $strategy = s3IncrementTwoStrategy($fixture, 'Deterministic control');
    $experiment = app(CreateGtmExperiment::class)->handle($workspace, $owner, [
        'objective_version_id' => $version->id,
        'name' => 'One arm plan',
        'arms' => [[
            'strategy_version_id' => $strategy->current_version_id,
            'key' => 'control',
            'label' => 'Control',
            'allocation_basis_points' => 10000,
            'declared_differences' => [],
        ]],
    ]);
    $experiment = app(FreezeExperimentAssignments::class)->handle($experiment, $owner, 1);

    $connection = ProviderConnection::factory()->create(['user_id' => $owner->id, 'workspace_id' => $workspace->id, 'token_version' => 3, 'authorization_version' => 3]);
    $senderEmail = 'owner-'.$owner->id.'@example.test';
    $senderKey = MailboxSenderIdentityService::keyForEmail($senderEmail);
    Mailbox::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $workspace->id,
        'provider_connection_id' => $connection->id,
        'email_address' => $senderEmail,
        'sender_identities' => [[
            'key' => $senderKey,
            'email' => $senderEmail,
            'name' => 'Objective Owner',
            'remote_id' => 'sender-1',
            'mode' => 'primary',
            'active' => true,
        ]],
        'selected_sender_identity_key' => $senderKey,
        'sending_paused_at' => null,
    ]);
    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);
    $credential = AiProviderCredential::query()->create([
        'workspace_id' => $workspace->id,
        'capability' => AiProviderName::OpenRouter->capability(),
        'provider' => AiProviderName::OpenRouter,
        'status' => AiCredentialStatus::Healthy,
        'last_tested_at' => now(),
        'last_healthy_at' => now(),
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
    ]);
    $credentialVersion = $credential->versions()->create([
        'version' => 1,
        'encrypted_secret' => DeterministicOpenRouterAdapter::FIXTURE_ID,
        'status' => 'active',
        'created_by_user_id' => $owner->id,
        'tested_at' => now(),
        'activated_at' => now(),
    ]);
    $credential->forceFill(['active_version_id' => $credentialVersion->id])->save();
    $objective = app(TransitionObjective::class)->handle($fixture['objective']->fresh(), $owner, 'ready', 3);

    return [
        'owner' => $owner,
        'objective' => $objective,
        'version' => $version,
        'claim_id' => $fixture['claim_id'],
        'claim_ids' => $claimIds,
        'claim_values' => $claimValues,
        'evidence_ids' => $evidenceIds,
        'accounts' => $fixture['accounts'],
        'experiment' => $experiment,
    ];
}

function s3SetOutreachPolicyMaximum(User $owner, int $maximumAiCostMicros): OutreachPolicy
{
    $workspace = $owner->currentWorkspace();
    $policy = OutreachPolicy::query()
        ->where('workspace_id', $workspace->id)
        ->with('currentVersion')
        ->firstOrFail();
    $current = $policy->currentVersion()->firstOrFail();
    $values = collect($current->toArray())->only([
        'maximum_people',
        'maximum_accounts',
        'maximum_messages',
        'maximum_ai_cost_micros',
        'approval_ttl_hours',
        'send_window',
        'allowed_source_purposes',
        'qualification_constraints',
        'evidence_constraints',
        'offer_constraints',
        'fulfilment_constraints',
        'schema_version',
    ])->all();
    $values['maximum_ai_cost_micros'] = $maximumAiCostMicros;
    $version = OutreachPolicyVersion::query()->create([
        'workspace_id' => $workspace->id,
        'outreach_policy_id' => $policy->id,
        'version' => (int) $current->version + 1,
        ...$values,
        'content_hash' => CanonicalObjectivePayload::hash($values),
        'created_by_user_id' => $owner->id,
    ]);
    $policy->forceFill([
        'current_version_id' => $version->id,
        'lock_version' => (int) $policy->lock_version + 1,
    ])->save();

    return $policy->fresh('currentVersion');
}

/**
 * @param  array{owner:User,version:ObjectiveVersion}  $fixture
 */
function s3InvalidateSelectedReason(array $fixture, string $condition): void
{
    $members = AudienceSnapshotMember::query()
        ->where('workspace_id', $fixture['owner']->currentWorkspace()->id)
        ->where('audience_snapshot_id', $fixture['version']->audience_snapshot_id)
        ->orderBy('account_id')
        ->get();
    $member = $members->firstOrFail();
    $reason = ReasonToContactVersion::query()->whereKey($member->reason_to_contact_version_id)->firstOrFail();

    if (in_array($condition, ['expired', 'stale'], true)) {
        DB::table('reason_to_contact_versions')->where('id', $reason->id)->update([
            $condition === 'expired' ? 'expires_at' : 'fresh_until' => now()->subSecond(),
        ]);

        return;
    }

    if ($condition === 'hash_mismatch') {
        DB::table('audience_snapshot_members')->where('id', $member->id)->update([
            'reason_content_hash' => hash('sha256', 'substituted-reason-hash'),
        ]);

        return;
    }

    $otherMember = $members->last();
    if ((string) $otherMember->id === (string) $member->id) {
        throw new RuntimeException('The reason-integrity fixture requires two Accounts.');
    }
    if ($condition === 'cross_account') {
        $otherReason = ReasonToContactVersion::query()
            ->whereKey($otherMember->reason_to_contact_version_id)
            ->firstOrFail();
        DB::table('audience_snapshot_members')->where('id', $member->id)->update([
            'reason_to_contact_version_id' => $otherReason->id,
            'reason_content_hash' => $otherReason->content_hash,
        ]);

        return;
    }

    if ($condition !== 'cross_decision') {
        throw new InvalidArgumentException('Unknown Reason-to-Contact invalidation condition.');
    }

    $substitute = ReasonToContactVersion::query()->create([
        'workspace_id' => $reason->workspace_id,
        'account_id' => $reason->account_id,
        'qualification_decision_id' => $otherMember->qualification_decision_id,
        'successor_id' => null,
        'why_account' => $reason->why_account,
        'why_offer' => $reason->why_offer,
        'why_now' => $reason->why_now,
        'factual_basis' => $reason->factual_basis,
        'internal_rationale' => null,
        'confidence' => $reason->confidence,
        'fresh_until' => now()->addDays(30),
        'expires_at' => now()->addDays(30),
        'safe_for_message' => true,
        'sensitive_inference' => false,
        'prohibited_inference' => false,
        'content_hash' => hash('sha256', 'cross-decision-reason-'.$member->id),
    ]);
    DB::table('audience_snapshot_members')->where('id', $member->id)->update([
        'reason_to_contact_version_id' => $substitute->id,
        'reason_content_hash' => $substitute->content_hash,
    ]);
}

function runS3IncrementThreeToApproval(ObjectiveRun $run): ObjectiveRun
{
    foreach (range(1, 5) as $_) {
        $run = $run->fresh();
        $dispatch = ObjectiveRunDispatch::query()->where('objective_run_id', $run->id)
            ->where('state', 'pending')->oldest()->firstOrFail();
        $phase = ObjectiveRunPhase::query()->where('objective_run_id', $run->id)
            ->where('phase', $dispatch->phase->value)->where('state', 'queued')->latest('attempt_number')->firstOrFail();
        app(ExecuteObjectiveRunPhase::class)->handle(
            (int) $run->workspace_id,
            (string) $run->id,
            (string) $phase->id,
            (string) $dispatch->id,
        );
    }

    return $run->fresh(['phases', 'armDrafts', 'executionPlans.actions']);
}

function approveS3PlanForCompilerFixture(ObjectiveRun $run): ExecutionPlan
{
    $plan = $run->executionPlans()->sole();
    $actions = $plan->actions()->orderBy('id')->get();
    $manifest = app(ApprovalManifest::class);
    app(ApproveExecutionPlan::class)->handle(
        $plan,
        $run->createdBy,
        (string) $plan->plan_hash,
        $manifest->hash($plan, $actions),
        $manifest->actionFingerprints($plan, $actions),
        'Approved by the deterministic compiler fixture.',
    );

    return $plan->fresh('actions');
}

/**
 * @return array{owner:User,plan:ExecutionPlan,run:ObjectiveRun,campaign:Campaign,step:SequenceStep,version:SequenceVersion,recipient:CampaignRecipient,mailbox:Mailbox,person:Person,account:Account}
 */
function compiledS3GuardFixture(int $startDelayMinutes = 0): array
{
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture(startDelayMinutes: $startDelayMinutes);
    $run = app(StartObjectiveRun::class)->handle(
        $fixture['objective'],
        $fixture['experiment'],
        $fixture['owner'],
        's3-guard-'.Str::uuid(),
    );
    $plan = approveS3PlanForCompilerFixture(runS3IncrementThreeToApproval($run));
    app(CompileExecutionPlan::class)->handle($plan, $fixture['owner']);
    $mapping = ExecutionPlanCampaign::query()->sole();
    $campaign = Campaign::query()->whereKey($mapping->campaign_id)->sole();
    $step = SequenceStep::query()->whereKey($mapping->sequence_step_id)->sole();
    $version = SequenceVersion::query()->whereKey($mapping->sequence_version_id)->sole();
    $recipient = CampaignRecipient::query()->where('campaign_id', $campaign->id)->sole();
    $mailbox = Mailbox::query()->whereKey($recipient->mailbox_id)->sole();
    $person = Person::query()->whereKey($recipient->person_id)->sole();
    $account = Account::query()->whereKey($recipient->account_id)->sole();

    return [
        'owner' => $fixture['owner'],
        'plan' => $plan,
        'run' => $run,
        'campaign' => $campaign,
        'step' => $step,
        'version' => $version,
        'recipient' => $recipient,
        'mailbox' => $mailbox,
        'person' => $person,
        'account' => $account,
    ];
}

/**
 * @return array{owner:User,objective:Objective,version:ObjectiveVersion,experiment:GtmExperiment}
 */
function s3IncrementFourTwoArmFixture(): array
{
    $fixture = s3IncrementThreeReadyFixture(20);
    $workspace = $fixture['owner']->currentWorkspace();
    $controlVersionId = $fixture['experiment']->arms()->sole()->strategy_version_id;
    $treatment = s3IncrementTwoStrategy($fixture, 'Compiler treatment', [
        'positioning' => 'Operational clarity for evidence-led revenue teams.',
    ]);
    $experiment = app(CreateGtmExperiment::class)->handle($workspace, $fixture['owner'], [
        'objective_version_id' => $fixture['version']->id,
        'name' => 'Two arm compiler fixture',
        'arms' => [
            [
                'strategy_version_id' => $controlVersionId,
                'key' => 'control',
                'allocation_basis_points' => 5000,
                'declared_differences' => [],
            ],
            [
                'strategy_version_id' => $treatment->current_version_id,
                'key' => 'treatment',
                'allocation_basis_points' => 5000,
                'declared_differences' => ['positioning' => 'Use the treatment positioning.'],
            ],
        ],
    ]);
    $fixture['experiment'] = app(FreezeExperimentAssignments::class)->handle(
        $experiment,
        $fixture['owner'],
        1,
    );
    if ($fixture['experiment']->assignments->pluck('gtm_experiment_arm_id')->unique()->count() !== 2) {
        throw new RuntimeException('The deterministic fixture requires two assigned arms.');
    }

    return $fixture;
}
