<?php

use App\Domain\Campaigns\Services\CampaignSendingForecast;
use App\Domain\Campaigns\Services\CreateCampaignService;
use App\Domain\Campaigns\Services\GovernedCampaignExecutionGuard;
use App\Domain\Campaigns\Services\PrepareHumanCampaignReview;
use App\Domain\Campaigns\Services\SaveCampaignComposition;
use App\Domain\Campaigns\Services\ScheduleDueCampaignSends;
use App\Domain\Campaigns\Services\SendCampaignMessage;
use App\Domain\Conversions\Services\InitializeMeetingMeasurement;
use App\Domain\Gtm\Support\CanonicalOfferPayload;
use App\Domain\Mail\Providers\FakeMailProvider;
use App\Domain\Mail\Services\ReconcileUnknownSend;
use App\Domain\Objectives\Enums\PolicyEvaluationStage;
use App\Domain\Objectives\Exceptions\ApprovalConflict;
use App\Domain\Objectives\Services\ApproveExecutionPlan;
use App\Domain\Objectives\Services\BuildHumanExecutionPlan;
use App\Domain\Objectives\Services\BuildObjectiveDraftPrompt;
use App\Domain\Objectives\Services\CompileExecutionPlan;
use App\Domain\Objectives\Services\CreateExecutionAuthorityScope;
use App\Domain\Objectives\Services\ExactExecutionPlanValidator;
use App\Domain\Objectives\Services\FreezeHumanCampaignAudience;
use App\Domain\Objectives\Services\FreezeHumanCampaignContext;
use App\Domain\Objectives\Services\FreezeHumanCampaignDraft;
use App\Domain\Objectives\Services\LaunchObjectiveExecution;
use App\Domain\Objectives\Services\RevokeExecutionAuthorityScope;
use App\Domain\Objectives\Services\SaveObjectiveGoal;
use App\Domain\Objectives\Services\StartHumanCampaignRun;
use App\Domain\Objectives\Support\ApprovalManifest;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Domain\Objectives\Support\ExecutionPlanFingerprint;
use App\Models\Account;
use App\Models\AudienceSnapshot;
use App\Models\Campaign;
use App\Models\CampaignComposition;
use App\Models\CampaignRecipient;
use App\Models\ConversionDefinition;
use App\Models\ExecutionPlanCampaign;
use App\Models\Mailbox;
use App\Models\ObjectiveVersion;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\Person;
use App\Models\PersonSourceMembership;
use App\Models\ProspectSource;
use App\Models\ProspectSourceVersion;
use App\Models\SendAttempt;
use App\Models\StrategyVersion;
use App\Models\SuppressionEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../../Support/ObjectiveScenarioFixtures.php';

function humanContextFixture(): array
{
    $fixture = s3IncrementThreeReadyFixture(2);
    $owner = $fixture['owner'];
    app(InitializeMeetingMeasurement::class)->handle($owner->currentWorkspace(), $owner);
    $definition = ConversionDefinition::query()->where('workspace_id', $owner->currentWorkspace()->id)->where('event_key', 'meeting_booked')->sole();
    $goal = app(SaveObjectiveGoal::class)->handle($owner->currentWorkspace(), $owner, [
        'name' => 'Evergreen meetings', 'measurement' => 'meeting_booked', 'creation_key' => 'human-context',
        'conversion_definition_version_id' => $definition->current_version_id,
    ]);
    $scope = app(CreateExecutionAuthorityScope::class)->handle($owner->currentWorkspace(), $owner, [
        'name' => 'Shared', 'operation_id' => (string) Str::uuid(), 'authority' => [
            'starts_at' => now()->toAtomString(), 'ends_at' => now()->addDays(10)->toAtomString(),
            'maximum_people' => 10, 'maximum_accounts' => 10, 'maximum_messages' => 20,
            'maximum_ai_cost_micros' => 0, 'approval_required' => true,
        ],
    ]);

    return [...$fixture, 'goal' => $goal, 'scope' => $scope];
}

beforeEach(function () {
    // Keep scheduling assertions inside business hours, regardless of when CI runs.
    $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'UTC'));
    // Production uses the database clock; fixtures must share the test clock
    // so undated compositions retain a stable, covered start time.
    CampaignComposition::creating(function (CampaignComposition $composition): void {
        $composition->created_at ??= now();
    });
});

function humanApprovalFixture(bool $runUntilFinished = false): array
{
    $fixture = humanContextFixture();
    $owner = $fixture['owner'];
    $campaign = app(CreateCampaignService::class)->handle($owner, 'Exact human approval');
    $composition = app(SaveCampaignComposition::class)->handle($campaign, $owner, [
        'objective_goal_version_id' => $fixture['goal']->current_goal_version_id,
        'offer_version_id' => $fixture['version']->offer_version_id,
        'execution_authority_scope_id' => $fixture['scope']->id,
        'sending_starts_at' => $runUntilFinished ? null : now()->toAtomString(),
        'sending_ends_at' => $runUntilFinished ? null : now()->addDays(2)->toAtomString(),
        'expected_revision' => 0, 'operation_id' => (string) Str::uuid(),
    ]);
    $person = Person::query()->where('workspace_id', $campaign->workspace_id)->firstOrFail();
    $mailbox = Mailbox::query()->where('workspace_id', $campaign->workspace_id)->whereNotNull('selected_sender_identity_key')->firstOrFail();
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id, 'person_id' => $person->id, 'account_id' => $person->account_id,
        'email_address' => $person->email_address, 'email_normalized' => $person->email_normalized,
        'mailbox_id' => $mailbox->id, 'first_name' => 'Alex', 'company' => 'Example',
    ]);
    $step = $campaign->draftVersion->activeSteps->sole();
    $step->forceFill(['subject_template' => 'Hello {{first_name}}', 'body_template' => 'Would a consultation help {{company}}?',
        'subject_spintax_enabled' => false, 'body_spintax_enabled' => false])->save();
    $plan = app(PrepareHumanCampaignReview::class)->handle($campaign, $owner, $composition->revision, (string) Str::uuid());

    return [...$fixture, ...compact('campaign', 'composition', 'person', 'mailbox', 'recipient', 'step', 'plan')];
}

it('preserves the original time limited approval contract for legacy human plans', function () {
    $fixture = humanApprovalFixture(true);
    $plan = $fixture['plan'];
    $draft = $plan->objectiveRun->armDrafts()->sole();
    $action = $plan->actions()->sole();
    // Reconstruct the persisted v1 wire contract, before any approval exists.
    $policyHash = CanonicalObjectivePayload::hash([
        'version' => BuildHumanExecutionPlan::LEGACY_VERSION,
        'policy_version_id' => $plan->outreach_policy_version_id,
        'context_hash' => $plan->objectiveVersion->content_hash,
        'draft_input_hash' => $draft->input_hash,
        'recipient_snapshot_hash' => $action->recipient_snapshot_hash,
        'mailbox_id' => $action->mailbox_id, 'provider_connection_id' => $action->provider_connection_id,
        'provider_connection_version' => $action->provider_connection_version,
        'provenance_hash' => $draft->claim_manifest['members'][0]['rendered']['provenance_hash'],
    ]);
    $payload = [];
    foreach (ExecutionPlanFingerprint::ACTION_FIELDS as $field) {
        $payload[$field] = $action->getAttribute($field);
    }
    $payload['policy_input_hash'] = $policyHash;
    $fingerprint = ExecutionPlanFingerprint::action($payload);
    DB::table('execution_plan_actions')->where('id', $action->id)->update(['policy_input_hash' => $policyHash, 'action_fingerprint' => $fingerprint]);
    $input = [...$plan->input_manifest, 'version' => BuildHumanExecutionPlan::LEGACY_VERSION, 'action_fingerprints' => [$fingerprint]];
    $canonical = CanonicalObjectivePayload::hash($input);
    DB::table('execution_plans')->where('id', $plan->id)->update([
        'schema_version' => BuildHumanExecutionPlan::LEGACY_VERSION, 'validator_version' => BuildHumanExecutionPlan::LEGACY_VERSION,
        'input_manifest' => json_encode($input, JSON_THROW_ON_ERROR), 'canonical_payload_hash' => $canonical,
        'plan_hash' => ExecutionPlanFingerprint::plan($plan->id, $canonical, [[...$payload,
            'id' => $action->id, 'execution_plan_id' => $plan->id, 'action_fingerprint' => $fingerprint]]),
    ]);
    $plan = $plan->fresh();
    $manifest = app(ApprovalManifest::class);
    expect($manifest->build($plan))->not->toHaveKey('approval_lifetime');
    $batch = app(ApproveExecutionPlan::class)->handle($plan, $fixture['owner'], $plan->plan_hash, $manifest->hash($plan), $manifest->actionFingerprints($plan));
    $expiry = $batch->expires_at->toAtomString();
    expect($batch->expires_at->lessThanOrEqualTo(now()->addMinutes(15)))->toBeTrue();
    $this->travel(16)->minutes();
    expect(app(ExactExecutionPlanValidator::class)->handle($plan)->allowed())->toBeFalse()
        ->and($batch->fresh()->expires_at->toAtomString())->toBe($expiry)
        ->and(FakeMailProvider::$store['sends'] ?? [])->toBe([]);
});

it('rechecks cached Offer readiness for new human approvals without approving the Offer', function () {
    $fixture = humanApprovalFixture();
    $version = OfferVersion::query()->findOrFail($fixture['version']->offer_version_id);
    $offer = Offer::query()->findOrFail($version->offer_id);
    DB::table('offer_readiness_evaluations')->where('id', $offer->current_readiness_evaluation_id)
        ->update(['valid_until' => now()->subMinute()]);
    expect(fn () => app(BuildObjectiveDraftPrompt::class)->offerContract($version))
        ->toThrow(ValidationException::class, 'Check readiness');
    $this->actingAs($fixture['owner'])->get('/campaigns/'.$fixture['campaign']->id.'/review')
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('review.can_approve', true)->where('review.can_launch', false));
    expect(DB::table('approval_grants')->count())->toBe(0);
});

it('reports a missing active outreach policy as a recoverable campaign validation error', function () {
    $fixture = humanApprovalFixture();
    DB::table('outreach_policies')->where('workspace_id', $fixture['campaign']->workspace_id)
        ->update(['status' => 'paused']);

    try {
        DB::transaction(fn () => app(FreezeHumanCampaignContext::class)->handle(
            $fixture['campaign'], $fixture['composition'], $fixture['plan']->audienceSnapshot,
            $fixture['owner'], existingOnly: true,
        ));
        $this->fail('Expected a recoverable policy validation error.');
    } catch (ValidationException $error) {
        expect($error->errors())->toBe([
            'campaign_context' => ['An active workspace outreach policy is required.'],
        ]);
    }
    expect(DB::table('approval_grants')->count())->toBe(0)
        ->and(DB::table('send_attempts')->count())->toBe(0);
});

it('shows a human execution plan without requiring an experiment or changing sending authority', function () {
    $fixture = humanApprovalFixture();
    $plan = $fixture['plan'];
    $this->actingAs($fixture['owner'])->get(route('execution-plans.show', $plan))
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('execution-plans/Show')
        ->where('plan.id', $plan->id)
        ->where('plan.lineage.gtm_experiment_id', null)
        ->where('plan.lineage.experiment_name', null)
        ->has('plan.actions', 1));
    expect(DB::table('approval_grants')->count())->toBe(0)
        ->and(DB::table('send_attempts')->count())->toBe(0);
    $this->actingAs(User::factory()->create())->get(route('execution-plans.show', $plan))->assertNotFound();
});

it('reviews approves compiles and sends one exact human campaign through the deterministic provider', function (bool $runUntilFinished) {
    $fixture = humanApprovalFixture($runUntilFinished);
    $plan = $fixture['plan'];
    $validator = app(ExactExecutionPlanValidator::class);
    $counts = fn () => collect(['objective_versions', 'objective_runs', 'objective_run_arm_drafts', 'execution_plans', 'execution_action_events'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
    $before = $counts();
    $validation = $validator->handle($plan);
    expect($validation->blockers)->toBe([])->and($validation->validUntil->isFuture())->toBeTrue();
    expect($validator->handle($plan)->dependencyHash)->toBe($validation->dependencyHash)
        ->and($counts())->toBe($before);
    $manifest = app(ApprovalManifest::class);
    $approve = fn ($hash) => app(ApproveExecutionPlan::class)->handle(
        $plan, $fixture['owner'], $hash, $manifest->hash($plan), $manifest->actionFingerprints($plan),
    );
    expect(fn () => $approve(str_repeat('0', 64)))->toThrow(ApprovalConflict::class);
    expect(DB::table('approval_grants')->count())->toBe(0);
    $batch = $approve($plan->plan_hash);
    expect($approve($plan->plan_hash)->id)->toBe($batch->id)
        ->and(DB::table('approval_grants')->count())->toBe(1)
        ->and($plan->fresh()->status->value)->toBe('approved')
        ->and($validator->handle($plan)->dependencyHash)->toBe($validation->dependencyHash)
        ->and(DB::table('ai_provider_operations')->count())->toBe(0)
        ->and(DB::table('send_attempts')->count())->toBe(0);
    CampaignRecipient::factory()->create(['campaign_id' => $fixture['campaign']->id, 'status' => 'removed', 'person_id' => null]);
    $campaignCount = Campaign::query()->count();
    $recipientCount = CampaignRecipient::query()->count();
    $compiler = app(CompileExecutionPlan::class);
    $compiled = $compiler->handle($plan->fresh(), $fixture['owner']);
    expect($compiled->state->value)->toBe('succeeded')
        ->and($compiled->campaigns->sole()->campaign_id)->toBe($fixture['campaign']->id)
        ->and($compiled->campaigns->sole()->gtm_experiment_arm_id)->toBeNull()
        ->and($compiled->actions->sole()->campaign_recipient_id)->toBe($fixture['recipient']->id)
        ->and($plan->fresh()->status->value)->toBe('compiled')
        ->and($fixture['recipient']->fresh()->governed_subject_snapshot)->toBe('Hello Alex')
        ->and($compiler->handle($plan->fresh(), $fixture['owner'])->id)->toBe($compiled->id)
        ->and(Campaign::query()->count())->toBe($campaignCount)
        ->and(CampaignRecipient::query()->count())->toBe($recipientCount)
        ->and(DB::table('execution_plan_compilations')->count())->toBe(1)
        ->and(DB::table('approval_grants')->count())->toBe(1)
        ->and(DB::table('send_attempts')->count())->toBe(0);
    expect(app(GovernedCampaignExecutionGuard::class)->forCampaign(
        $fixture['campaign']->fresh(), PolicyEvaluationStage::Approval,
    )->blockers)->toBe([]);
    expect($batch->fresh()->state->value)->toBe('approved');
    expect($batch->expires_at->toAtomString())->toBe($plan->objectiveVersion->ends_at->toAtomString());
    $otherWorkspace = User::factory()->create()->currentWorkspace();
    $otherWorkspace->memberships()->create(['user_id' => $fixture['owner']->id, 'role' => 'owner']);
    request()->setLaravelSession(app('session.store'));
    session()->put(User::ACTIVE_WORKSPACE_SESSION_KEY, $otherWorkspace->id);
    expect($fixture['owner']->currentWorkspace()->id)->toBe($otherWorkspace->id)
        ->and($validator->handle($plan->fresh())->blockers)->toBe([]);
    session()->put(User::ACTIVE_WORKSPACE_SESSION_KEY, $plan->workspace_id);
    config()->set('outboss.campaign_sending_enabled', true);
    $launched = app(LaunchObjectiveExecution::class)->handle($plan->fresh(), $fixture['owner']);
    expect($launched->status->value)->toBe('executing')
        ->and($fixture['campaign']->fresh()->status->value)->toBe('active')
        ->and($fixture['goal']->fresh()->current_plan_id)->toBeNull()
        ->and($fixture['goal']->fresh()->status->value)->toBe('draft');
    $publishedAt = $fixture['campaign']->fresh()->publishedVersion->published_at->toAtomString();
    app(LaunchObjectiveExecution::class)->handle($plan->fresh(), $fixture['owner']);
    $this->travel(1)->seconds();
    app(LaunchObjectiveExecution::class)->handle($plan->fresh(), $fixture['owner']);
    expect($fixture['campaign']->fresh()->publishedVersion->published_at->toAtomString())->toBe($publishedAt)
        ->and(SendAttempt::query()->count())->toBe(0);
    // The same immutable approval must survive both the readiness cache and
    // the old approval TTL, without another owner decision or paid operation.
    $this->travel(1)->days();
    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(1);
    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(1)
        ->and(SendAttempt::query()->count())->toBe(1);
    $attempt = SendAttempt::query()->sole();
    app(SendCampaignMessage::class)->handle($attempt->id);
    app(SendCampaignMessage::class)->handle($attempt->id);
    expect($attempt->fresh()->status->value)->toBe('sent')
        ->and($fixture['campaign']->fresh()->status->value)->toBe('completed')
        ->and(FakeMailProvider::$store['sends'] ?? [])->toHaveCount(1)
        ->and($attempt->message->subject)->toBe($plan->actions()->sole()->subject)
        ->and($attempt->message->body_text)->toBe($plan->actions()->sole()->body_text)
        ->and($plan->actions()->sole()->state->value)->toBe('sent');
    foreach (['launch', 'schedule', 'lease', 'pre_send'] as $stage) {
        expect(DB::table('policy_evaluations')->where('execution_plan_id', $plan->id)->where('stage', $stage)->where('result', 'allowed')->exists())->toBeTrue();
    }
})->with(['optional deadline' => false, 'run until finished' => true]);

it('exposes separate owner approval and launch with exact browser echoes', function () {
    config()->set('outboss.campaign_sending_enabled', true);
    $fixture = humanApprovalFixture();
    $plan = $fixture['plan'];
    $uri = '/campaigns/'.$fixture['campaign']->id.'/review';
    $manifest = app(ApprovalManifest::class);
    $input = ['expected_revision' => $fixture['composition']->revision, 'plan_hash' => $plan->plan_hash,
        'display_manifest_hash' => $manifest->hash($plan), 'action_fingerprints' => $manifest->actionFingerprints($plan), 'confirmed' => true];
    $this->actingAs($fixture['owner'])->get($uri)->assertOk()->assertInertia(fn ($page) => $page
        ->where('review.can_approve', true)->where('review.can_launch', false));
    $this->postJson($uri.'/'.$plan->id.'/launch', $input)->assertUnprocessable();
    $this->postJson($uri.'/'.$plan->id.'/approve', [...$input, 'confirmed' => false])->assertUnprocessable();
    $this->postJson($uri.'/'.$plan->id.'/approve', [...$input, 'plan_hash' => str_repeat('0', 64)])->assertUnprocessable();
    expect(DB::table('approval_grants')->count())->toBe(0);
    $this->post($uri.'/'.$plan->id.'/approve', $input)->assertRedirect($uri);
    $this->post($uri.'/'.$plan->id.'/approve', $input)->assertRedirect($uri);
    $this->postJson($uri.'/'.$plan->id.'/discard', $input)->assertUnprocessable();
    expect(DB::table('approval_grants')->count())->toBe(1)->and(DB::table('send_attempts')->count())->toBe(0);
    $this->get($uri)->assertInertia(fn ($page) => $page->where('review.can_approve', false)->where('review.can_launch', true));
    $this->actingAs(User::factory()->create())->postJson($uri.'/'.$plan->id.'/launch', $input)->assertNotFound();
    $this->actingAs($fixture['owner'])->postJson($uri.'/'.$plan->id.'/launch', [...$input, 'display_manifest_hash' => str_repeat('0', 64)])->assertUnprocessable();
    $this->post($uri.'/'.$plan->id.'/launch', $input)->assertRedirect($uri);
    $this->get($uri)->assertInertia(fn ($page) => $page->where('review.status', 'executing')->where('review.can_launch', false));
    expect($fixture['campaign']->fresh()->status->value)->toBe('active')->and(DB::table('send_attempts')->count())->toBe(0);
});

it('lets the owner replace a stale unapproved review while retaining the draft and history', function () {
    $fixture = humanApprovalFixture();
    $plan = $fixture['plan'];
    $campaign = $fixture['campaign'];
    $owner = $fixture['owner'];
    $composition = app(SaveCampaignComposition::class)->handle($campaign, $owner, [
        'objective_goal_version_id' => $fixture['goal']->current_goal_version_id,
        'offer_version_id' => $fixture['version']->offer_version_id, 'execution_authority_scope_id' => $fixture['scope']->id,
        'sending_starts_at' => now()->toAtomString(), 'sending_ends_at' => now()->addDays(3)->toAtomString(),
        'expected_revision' => $fixture['composition']->revision, 'operation_id' => (string) Str::uuid(),
    ]);
    $uri = '/campaigns/'.$campaign->id.'/review';
    $this->actingAs($owner)->get($uri)->assertInertia(fn ($page) => $page->where('review.id', $plan->id)->where('review.can_approve', false)->where('review.can_discard', true));
    $this->post($uri.'/'.$plan->id.'/discard', ['expected_revision' => $composition->revision])->assertRedirect('/campaigns/'.$campaign->id.'?step=sequence');
    expect($plan->fresh()->status->value)->toBe('cancelled')->and($campaign->fresh()->status->value)->toBe('draft')
        ->and($fixture['recipient']->fresh()->email_address)->toBe($fixture['recipient']->email_address);
    $this->get($uri)->assertInertia(fn ($page) => $page->where('review', null));
    $this->post($uri, ['expected_revision' => $composition->revision, 'operation_id' => (string) Str::uuid()])->assertRedirect($uri);
    expect(DB::table('execution_plans')->count())->toBe(2)->and(DB::table('approval_grants')->count())->toBe(0);
});

it('replaces an unapproved review atomically after a daily pace change', function () {
    $f = humanApprovalFixture(true);
    $f['campaign']->forceFill(['daily_send_limit' => 1])->save();
    $uri = '/campaigns/'.$f['campaign']->id.'/review';
    $input = ['expected_revision' => $f['composition']->revision, 'operation_id' => (string) Str::uuid(), 'replace_plan_id' => $f['plan']->id];
    $this->actingAs($f['owner'])->post($uri, $input)->assertSessionHasNoErrors()->assertRedirect($uri);
    expect($f['plan']->fresh()->status->value)->toBe('cancelled');
    $this->get($uri)->assertInertia(fn ($page) => $page->where('review.can_approve', true)->where('review.blockers', []));
    $this->post($uri, $input)->assertSessionHasNoErrors();
    expect(DB::table('execution_plans')->count())->toBe(2)->and(DB::table('approval_grants')->count())->toBe(0);
});

it('retains the old review when replacement preparation fails', function () {
    $f = humanApprovalFixture(true);
    $f['campaign']->forceFill(['daily_send_limit' => 1000000])->save();
    $this->actingAs($f['owner'])->post('/campaigns/'.$f['campaign']->id.'/review', [
        'expected_revision' => $f['composition']->revision, 'operation_id' => (string) Str::uuid(), 'replace_plan_id' => $f['plan']->id,
    ])->assertSessionHasErrors('daily_send_limit');
    expect($f['plan']->fresh()->status->value)->toBe('awaiting_approval');
});

it('resumes an unchanged governed campaign through exact review without duplicate grants or compilations', function (string $stop) {
    config()->set('outboss.campaign_sending_enabled', true);
    $f = humanApprovalFixture();
    $plan = $f['plan'];
    $uri = '/campaigns/'.$f['campaign']->id.'/review';
    $manifest = app(ApprovalManifest::class);
    $input = ['expected_revision' => $f['composition']->revision, 'plan_hash' => $plan->plan_hash,
        'display_manifest_hash' => $manifest->hash($plan), 'action_fingerprints' => $manifest->actionFingerprints($plan), 'confirmed' => true];
    $this->actingAs($f['owner'])->post($uri.'/'.$plan->id.'/approve', $input)->assertRedirect();
    $forecast = app(CampaignSendingForecast::class)->handle($f['campaign']->fresh(), $f['composition']);
    expect($forecast['allowance']['quantities']['messages']['committed'])->toBe(1)
        ->and($forecast['allowance']['quantities']['messages']['projected'])->toBe(1);
    $this->post($uri.'/'.$plan->id.'/launch', $input)->assertRedirect();
    $this->post('/campaigns/'.$f['campaign']->id.'/'.$stop)->assertRedirect();
    $this->get($uri)->assertInertia(fn ($page) => $page->where('review.can_launch', true));
    $this->post($uri.'/'.$plan->id.'/launch', $input)->assertRedirect();
    expect($f['campaign']->fresh()->status->value)->toBe('active')
        ->and(DB::table('approval_grants')->count())->toBe(1)
        ->and(DB::table('execution_plan_compilations')->count())->toBe(1)
        ->and(DB::table('send_attempts')->count())->toBe(0);
})->with(['pause', 'complete']);

it('edits a reused human Objective without rewriting the campaign or exact review', function () {
    $fixture = humanApprovalFixture();
    $goal = $fixture['goal']->fresh();
    $pinnedId = $fixture['composition']->objective_goal_version_id;
    $plan = $fixture['plan'];
    $hash = $plan->plan_hash;
    $this->actingAs($fixture['owner'])->get('/objectives/'.$goal->id)->assertInertia(fn ($page) => $page->where('can_edit', true));
    $this->patch('/objectives/'.$goal->id, ['name' => 'Reusable renamed outcome', 'measurement' => 'meeting_booked', 'lock_version' => $goal->lock_version])
        ->assertSessionHasNoErrors()->assertRedirect();
    expect($goal->fresh()->current_goal_version_id)->not->toBe($pinnedId)
        ->and($fixture['composition']->fresh()->objective_goal_version_id)->toBe($pinnedId)
        ->and($plan->fresh()->plan_hash)->toBe($hash)
        ->and(app(ExactExecutionPlanValidator::class)->handle($plan->fresh())->allowed())->toBeTrue();
});

it('returns an expired uncompiled review to its draft without renewing or deleting approval', function () {
    $fixture = humanApprovalFixture();
    $plan = $fixture['plan'];
    DB::table('offer_readiness_evaluations')->where('offer_version_id', $plan->offer_version_id)
        ->update(['valid_until' => now()->addDays(2)]);
    $manifest = app(ApprovalManifest::class);
    $batch = app(ApproveExecutionPlan::class)->handle($plan, $fixture['owner'], $plan->plan_hash, $manifest->hash($plan), $manifest->actionFingerprints($plan));
    $input = ['expected_revision' => $fixture['composition']->revision, 'plan_hash' => $plan->plan_hash,
        'display_manifest_hash' => $manifest->hash($plan), 'action_fingerprints' => $manifest->actionFingerprints($plan)];
    $uri = '/campaigns/'.$fixture['campaign']->id.'/review';
    $this->actingAs($fixture['owner'])->postJson($uri.'/'.$plan->id.'/return-to-draft', $input)->assertUnprocessable();
    $this->travelTo($batch->expires_at->copy()->addSecond());
    $this->get($uri)->assertInertia(fn ($page) => $page->where('review.can_return_to_draft', true)->where('review.can_launch', false));
    $this->postJson($uri.'/'.$plan->id.'/return-to-draft', [...$input, 'plan_hash' => str_repeat('0', 64)])->assertUnprocessable();
    $this->actingAs(User::factory()->create())->postJson($uri.'/'.$plan->id.'/return-to-draft', $input)->assertNotFound();
    $this->actingAs($fixture['owner'])->post($uri.'/'.$plan->id.'/return-to-draft', $input)->assertRedirect('/campaigns/'.$fixture['campaign']->id);
    $this->post($uri.'/'.$plan->id.'/return-to-draft', $input)->assertRedirect();
    expect($plan->fresh()->status->value)->toBe('cancelled')
        ->and($plan->objectiveRun->fresh()->state->value)->toBe('cancelled')
        ->and($batch->fresh()->state->value)->toBe('expired')
        ->and(DB::table('approval_grants')->count())->toBe(1)
        ->and(DB::table('approval_events')->where('event', 'expired')->count())->toBe(1)
        ->and(DB::table('send_attempts')->count())->toBe(0);
    $this->get($uri)->assertInertia(fn ($page) => $page->where('review', null));
    // The explicit deadline has now passed as well. A new review needs a new
    // saved schedule; returning to draft must not silently extend it.
    $composition = app(SaveCampaignComposition::class)->handle($fixture['campaign']->fresh(), $fixture['owner'], [
        'objective_goal_version_id' => $fixture['composition']->objective_goal_version_id,
        'offer_version_id' => $fixture['composition']->offer_version_id,
        'execution_authority_scope_id' => $fixture['scope']->id,
        'sending_starts_at' => null, 'sending_ends_at' => null,
        'expected_revision' => $fixture['composition']->revision, 'operation_id' => (string) Str::uuid(),
    ]);
    $this->post($uri, ['expected_revision' => $composition->revision, 'operation_id' => (string) Str::uuid()])->assertSessionHasNoErrors()->assertRedirect($uri);
    expect(DB::table('execution_plans')->count())->toBe(2)->and(DB::table('approval_grants')->count())->toBe(1);
    expect(fn () => app(CompileExecutionPlan::class)->handle($plan->fresh(), $fixture['owner']))->toThrow(ValidationException::class);
});

it('never returns a compiled human review to a mutable draft after approval expires', function () {
    $fixture = humanApprovalFixture();
    $plan = $fixture['plan'];
    $manifest = app(ApprovalManifest::class);
    $batch = app(ApproveExecutionPlan::class)->handle($plan, $fixture['owner'], $plan->plan_hash, $manifest->hash($plan), $manifest->actionFingerprints($plan));
    app(CompileExecutionPlan::class)->handle($plan->fresh(), $fixture['owner']);
    $this->travelTo($batch->expires_at->copy()->addSecond());
    $uri = '/campaigns/'.$fixture['campaign']->id.'/review';
    $this->actingAs($fixture['owner'])->get($uri)->assertInertia(fn ($page) => $page->where('review.can_return_to_draft', false));
    $this->postJson($uri.'/'.$plan->id.'/return-to-draft', ['expected_revision' => $fixture['composition']->revision,
        'plan_hash' => $plan->plan_hash, 'display_manifest_hash' => $manifest->hash($plan), 'action_fingerprints' => $manifest->actionFingerprints($plan)])->assertUnprocessable();
    expect($plan->fresh()->status->value)->toBe('compiled')->and(DB::table('execution_plan_campaigns')->count())->toBe(1);
});

it('rechecks current safety after human launch before calling the provider', function (string $change) {
    config()->set('outboss.campaign_sending_enabled', true);
    $fixture = humanApprovalFixture();
    $plan = $fixture['plan'];
    $manifest = app(ApprovalManifest::class);
    app(ApproveExecutionPlan::class)->handle($plan, $fixture['owner'], $plan->plan_hash, $manifest->hash($plan), $manifest->actionFingerprints($plan));
    app(CompileExecutionPlan::class)->handle($plan->fresh(), $fixture['owner']);
    app(LaunchObjectiveExecution::class)->handle($plan->fresh(), $fixture['owner']);
    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(1);
    $attempt = SendAttempt::query()->sole();
    match ($change) {
        'source' => DB::table('person_source_memberships')->where('person_id', $fixture['person']->id)->update(['state' => 'restricted']),
        'suppression' => SuppressionEntry::factory()->create(['workspace_id' => $plan->workspace_id, 'email_normalized' => $fixture['recipient']->email_normalized]),
        'mailbox' => DB::table('mailboxes')->where('id', $fixture['mailbox']->id)->update(['sending_paused_at' => now()]),
        'scope' => app(RevokeExecutionAuthorityScope::class)->handle($fixture['scope'], $fixture['owner'], 'Stop before delivery'),
        'offer_review' => DB::table('offer_version_review_events')->where('offer_version_id', $plan->offer_version_id)->update(['to_state' => 'blocked']),
        'policy_pause' => DB::table('outreach_policies')->where('workspace_id', $plan->workspace_id)->update(['execution_paused_at' => now()]),
    };
    app(SendCampaignMessage::class)->handle($attempt->id);
    app(SendCampaignMessage::class)->handle($attempt->id);
    expect(FakeMailProvider::$store['sends'] ?? [])->toBe([])
        ->and($attempt->fresh()->status->value)->not->toBe('sent')
        ->and(DB::table('approval_grants')->count())->toBe(1)
        ->and(DB::table('send_attempts')->count())->toBe(1);
})->with(['source', 'suppression', 'mailbox', 'scope', 'offer_review', 'policy_pause']);

it('retains an unknown human delivery outcome without automatically resending', function () {
    config()->set('outboss.campaign_sending_enabled', true);
    $fixture = humanApprovalFixture();
    $plan = $fixture['plan'];
    $manifest = app(ApprovalManifest::class);
    app(ApproveExecutionPlan::class)->handle($plan, $fixture['owner'], $plan->plan_hash, $manifest->hash($plan), $manifest->actionFingerprints($plan));
    app(CompileExecutionPlan::class)->handle($plan->fresh(), $fixture['owner']);
    app(LaunchObjectiveExecution::class)->handle($plan->fresh(), $fixture['owner']);
    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(1);
    $attempt = SendAttempt::query()->sole();
    FakeMailProvider::$timeoutAfterSend = true;
    app(SendCampaignMessage::class)->handle($attempt->id);
    app(SendCampaignMessage::class)->handle($attempt->id);
    expect($attempt->fresh()->status->value)->toBe('unknown')
        ->and(FakeMailProvider::$store['sends'] ?? [])->toHaveCount(1)
        ->and($plan->actions()->sole()->state->value)->toBe('uncertain');
    app(ReconcileUnknownSend::class)->handle($attempt->id);
    app(SendCampaignMessage::class)->handle($attempt->id);
    expect($attempt->fresh()->status->value)->toBe('manual_review')
        ->and(FakeMailProvider::$store['sends'] ?? [])->toHaveCount(1);
});

it('rolls back human campaign snapshots when compilation fails after adoption', function () {
    $fixture = humanApprovalFixture();
    $plan = $fixture['plan'];
    $manifest = app(ApprovalManifest::class);
    app(ApproveExecutionPlan::class)->handle($plan, $fixture['owner'], $plan->plan_hash, $manifest->hash($plan), $manifest->actionFingerprints($plan));
    $dispatcher = ExecutionPlanCampaign::getEventDispatcher();
    ExecutionPlanCampaign::setEventDispatcher(clone $dispatcher);
    try {
        ExecutionPlanCampaign::creating(function () {
            throw new RuntimeException('Injected failure after human adoption');
        });
        expect(fn () => app(CompileExecutionPlan::class)->handle($plan->fresh(), $fixture['owner']))
            ->toThrow(RuntimeException::class, 'Injected failure');
    } finally {
        ExecutionPlanCampaign::setEventDispatcher($dispatcher);
    }
    expect($fixture['recipient']->fresh()->governed_subject_snapshot)->toBeNull()
        ->and(DB::table('execution_plan_campaigns')->count())->toBe(0)
        ->and(DB::table('execution_plan_action_compilations')->count())->toBe(0)
        ->and(DB::table('execution_plan_compilations')->where('state', 'succeeded')->count())->toBe(0)
        ->and(DB::table('execution_plan_compilations')->where('state', 'failed')->count())->toBe(1)
        ->and($plan->fresh()->status->value)->toBe('approved')
        ->and(DB::table('send_attempts')->count())->toBe(0);
});

it('requires exact approval and refuses corrupted human compilation replay', function () {
    $fixture = humanApprovalFixture();
    $plan = $fixture['plan'];
    $compiler = app(CompileExecutionPlan::class);
    expect(fn () => $compiler->handle($plan, $fixture['owner']))->toThrow(ValidationException::class);
    expect(DB::table('execution_plan_campaigns')->count())->toBe(0);
    $manifest = app(ApprovalManifest::class);
    app(ApproveExecutionPlan::class)->handle($plan, $fixture['owner'], $plan->plan_hash, $manifest->hash($plan), $manifest->actionFingerprints($plan));
    $compiled = $compiler->handle($plan->fresh(), $fixture['owner']);
    DB::table('execution_plan_action_compilations')->where('execution_plan_compilation_id', $compiled->id)
        ->update(['compiled_input_hash' => str_repeat('0', 64)]);
    expect(fn () => $compiler->handle($plan->fresh(), $fixture['owner']))
        ->toThrow(ValidationException::class, 'lineage or exact message snapshot');
    expect(DB::table('execution_plan_compilations')->count())->toBe(1)
        ->and(DB::table('approval_grants')->count())->toBe(1)
        ->and(DB::table('send_attempts')->count())->toBe(0);
});

it('rejects changed human review inputs without writing replacement context or copy', function (string $change) {
    $fixture = humanApprovalFixture();
    $plan = $fixture['plan'];
    match ($change) {
        'copy' => DB::table('sequence_steps')->where('id', $fixture['step']->id)->update(['body_template' => 'Changed copy']),
        'policy' => DB::table('outreach_policies')->where('workspace_id', $plan->workspace_id)->update(['execution_paused_at' => now()]),
        'scope' => app(RevokeExecutionAuthorityScope::class)->handle($fixture['scope'], $fixture['owner'], 'Stop'),
        'recipient' => DB::table('people')->where('id', $fixture['person']->id)->update(['email_address' => 'changed@example.com']),
        'sender' => DB::table('mailboxes')->where('id', $fixture['mailbox']->id)->update(['sending_paused_at' => now()]),
        'context' => DB::table('campaign_compositions')->where('id', $fixture['composition']->id)->update(['sending_ends_at' => now()->addDay()]),
        'source' => DB::table('person_source_memberships')->where('person_id', $fixture['person']->id)->update(['state' => 'restricted']),
        'suppression' => SuppressionEntry::factory()->create(['workspace_id' => $plan->workspace_id, 'email_normalized' => $fixture['recipient']->email_normalized]),
        'attempt' => SendAttempt::factory()->create(['workspace_id' => $plan->workspace_id, 'mailbox_id' => $fixture['mailbox']->id,
            'campaign_recipient_id' => $fixture['recipient']->id, 'status' => 'unknown']),
    };
    $before = [DB::table('objective_versions')->count(), DB::table('objective_run_arm_drafts')->count()];
    $validation = app(ExactExecutionPlanValidator::class)->handle($plan);
    expect($validation->allowed())->toBeFalse()
        ->and([DB::table('objective_versions')->count(), DB::table('objective_run_arm_drafts')->count()])->toBe($before)
        ->and(DB::table('approval_grants')->count())->toBe(0);
    $manifest = app(ApprovalManifest::class);
    expect(fn () => app(ApproveExecutionPlan::class)->handle(
        $plan, $fixture['owner'], $plan->plan_hash, $manifest->hash($plan), $manifest->actionFingerprints($plan),
    ))->toThrow(ApprovalConflict::class);
    expect(DB::table('approval_grants')->count())->toBe(0);
})->with(['copy', 'policy', 'scope', 'recipient', 'sender', 'context', 'source', 'suppression', 'attempt']);

it('allows human external-link Offers without an AI response route but still rejects unsafe routes', function () {
    $fixture = humanContextFixture();
    $offer = OfferVersion::query()->findOrFail($fixture['version']->offer_version_id);
    $payload = $offer->payload;
    $payload['cta']['response_route'] = '';
    DB::table('offer_versions')->where('id', $offer->id)->update([
        'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        'content_hash' => CanonicalOfferPayload::hash($payload, $offer->status_snapshot),
    ]);
    $builder = app(BuildObjectiveDraftPrompt::class);
    expect($builder->offerContract($offer->fresh())['response_route'])->toBe('');
    $strategy = StrategyVersion::query()->where('workspace_id', $offer->workspace_id)->firstOrFail();
    expect(fn () => $builder->offerContract($offer->fresh(), $strategy))->toThrow(ValidationException::class);
    $payload['cta']['response_route'] = "calendar\nBcc: other@example.com";
    DB::table('offer_versions')->where('id', $offer->id)->update([
        'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        'content_hash' => CanonicalOfferPayload::hash($payload, $offer->status_snapshot),
    ]);
    expect(fn () => $builder->offerContract($offer->fresh()))->toThrow(ValidationException::class);
});

it('freezes independent campaign contexts without altering the evergreen Objective or creating AI work', function () {
    $fixture = humanContextFixture();
    $owner = $fixture['owner'];
    $contexts = [];
    $experimentCount = DB::table('gtm_experiments')->count();
    foreach ([null, 4] as $index => $target) {
        $campaign = app(CreateCampaignService::class)->handle($owner, 'Human campaign '.$index);
        $campaign->draftVersion->activeSteps->sole()->forceFill([
            'subject_template' => 'Hello {{first_name}}', 'body_template' => 'Would a demo help {{company}}?',
            'subject_spintax_enabled' => false, 'body_spintax_enabled' => false,
        ])->save();
        $composition = app(SaveCampaignComposition::class)->handle($campaign, $owner, [
            'objective_goal_version_id' => $fixture['goal']->current_goal_version_id,
            'offer_version_id' => $fixture['version']->offer_version_id,
            'execution_authority_scope_id' => $fixture['scope']->id, 'target_contribution_count' => $target,
            'sending_starts_at' => now()->addDay()->toAtomString(), 'sending_ends_at' => now()->addDays($index + 2)->toAtomString(),
            'expected_revision' => 0, 'operation_id' => (string) Str::uuid(),
        ]);
        $person = Person::query()->where('workspace_id', $owner->currentWorkspace()->id)->orderBy('id')->firstOrFail();
        $mailbox = Mailbox::query()->where('workspace_id', $owner->currentWorkspace()->id)->whereNotNull('selected_sender_identity_key')->firstOrFail();
        CampaignRecipient::factory()->create(['campaign_id' => $campaign->id, 'person_id' => $person->id,
            'mailbox_id' => $mailbox->id,
            'first_name' => 'Alex', 'company' => 'Example',
            'account_id' => $person->account_id, 'email_address' => $person->email_address, 'email_normalized' => $person->email_normalized]);
        $contexts[] = DB::transaction(function () use ($campaign, $composition, $owner, $target) {
            $snapshot = app(FreezeHumanCampaignAudience::class)->handle($campaign, $composition, $owner);
            $service = app(FreezeHumanCampaignContext::class);
            $context = $service->handle($campaign, $composition, $snapshot, $owner);
            expect($service->handle($campaign, $composition, $snapshot, $owner)->id)->toBe($context->id);
            expect($context->target_count)->toBe($target)->and($context->maximum_ai_cost_micros)->toBe(0);
            expect($context->authority_envelope['campaign_composition_id'])->toBe($composition->id);
            $operation = (string) Str::uuid();
            $runs = app(StartHumanCampaignRun::class);
            $run = $runs->handle($campaign, $composition, $context, $owner, $operation);
            expect($runs->handle($campaign, $composition, $context, $owner, $operation)->id)->toBe($run->id);
            expect($run->gtm_experiment_id)->toBeNull()->and($run->ai_budget_envelope_id)->toBeNull();
            expect($run->getAttribute('origin')->value)->toBe('human');
            $drafts = app(FreezeHumanCampaignDraft::class);
            $run->forceFill(['state' => 'paused'])->save();
            expect(fn () => $drafts->handle($campaign, $run, $owner))->toThrow(ValidationException::class, 'planned or reviewable');
            $run->forceFill(['state' => 'planned'])->save();
            $step = $campaign->draftVersion->activeSteps->sole();
            $step->forceFill(['subject_spintax_enabled' => true])->save();
            expect(fn () => $drafts->handle($campaign, $run, $owner))->toThrow(ValidationException::class, 'variations');
            $step->forceFill(['subject_spintax_enabled' => false, 'send_as' => 'reply'])->save();
            expect(fn () => $drafts->handle($campaign, $run, $owner))->toThrow(ValidationException::class, 'new-thread');
            $step->forceFill(['send_as' => 'new_thread'])->save();
            expect(DB::table('objective_run_arm_drafts')->where('objective_run_id', $run->id)->count())->toBe(0);
            $draft = $drafts->handle($campaign, $run, $owner);
            expect($drafts->handle($campaign, $run, $owner)->id)->toBe($draft->id);
            expect($draft->claim_manifest['members'][0]['rendered']['subject'])->toBe('Hello Alex');
            expect($draft->claim_manifest['members'][0]['rendered']['body'])->toBe('Would a demo help Example?');
            expect($draft->gtm_experiment_arm_id)->toBeNull()->and($draft->ai_provider_operation_id)->toBeNull();
            $step->forceFill(['body_template' => 'Changed copy'])->save();
            expect(fn () => $drafts->handle($campaign, $run, $owner))->toThrow(ValidationException::class, 'different frozen copy');
            $step->forceFill(['body_template' => 'Would a demo help {{company}}?'])->save();
            DB::table('objective_run_arm_drafts')->where('id', $draft->id)->update(['output_hash' => str_repeat('0', 64)]);
            expect(fn () => $drafts->handle($campaign, $run, $owner))->toThrow(ValidationException::class, 'different frozen copy');
            DB::table('objective_run_arm_drafts')->where('id', $draft->id)->update(['output_hash' => $draft->output_hash]);
            $plans = app(BuildHumanExecutionPlan::class);
            $recipient = $campaign->recipients()->sole();
            $campaign->mailboxes()->detach($recipient->mailbox_id);
            expect(fn () => $plans->handle($campaign, $run, $owner))->toThrow(ValidationException::class, 'selected, connected');
            expect($run->executionPlans()->count())->toBe(0);
            $campaign->mailboxes()->attach($recipient->mailbox_id, ['position' => 1]);
            $plan = $plans->handle($campaign, $run, $owner);
            expect($plans->handle($campaign, $run, $owner)->id)->toBe($plan->id);
            expect($plan->getAttribute('origin')->value)->toBe('human')->and($plan->gtm_experiment_id)->toBeNull();
            expect($plan->actions)->toHaveCount(1);
            expect($plan->actions->sole()->subject)->toBe('Hello Alex');
            expect($plan->actions->sole()->gtm_experiment_assignment_id)->toBeNull();
            expect($plan->arm_count)->toBe(0)->and($plan->actual_cost_micros)->toBe(0);
            expect($run->fresh()->state->value)->toBe('needs_review');
            $action = $plan->actions->sole();
            DB::table('execution_plan_actions')->where('id', $action->id)->update(['subject' => 'Corrupted']);
            expect(fn () => $plans->handle($campaign, $run, $owner))->toThrow(ValidationException::class, 'persisted action');
            DB::table('execution_plan_actions')->where('id', $action->id)->update(['subject' => $action->subject]);
            expect(fn () => $runs->handle($campaign, $composition, $context, $owner, (string) Str::uuid()))
                ->toThrow(ValidationException::class, 'unfinished preparation');

            return $context;
        });
    }
    expect($contexts[0]->id)->not->toBe($contexts[1]->id);
    expect($contexts[0]->ends_at->equalTo($contexts[1]->ends_at))->toBeFalse();
    expect($fixture['goal']->fresh()->current_version_id)->toBeNull();
    expect($fixture['goal']->fresh()->currentGoalVersion->target_count)->toBeNull();
    expect(ObjectiveVersion::query()->where('objective_id', $fixture['goal']->id)->count())->toBe(2);
    expect(DB::table('ai_provider_operations')->count())->toBe(0);
    expect(DB::table('objective_runs')->count())->toBe(2);
    expect(DB::table('gtm_experiments')->count())->toBe($experimentCount);
    expect(DB::table('objective_run_dispatches')->count())->toBe(0);
    expect(DB::table('ai_budget_envelopes')->count())->toBe(0);
    expect(DB::table('send_attempts')->count())->toBe(0);
    expect(DB::table('execution_plans')->count())->toBe(2);
    expect(DB::table('execution_plan_actions')->count())->toBe(2);
    expect(DB::table('execution_action_events')->count())->toBe(2);
    expect(DB::table('approval_grants')->count())->toBe(0);
    $fixture['goal']->forceFill(['status' => 'paused'])->save();
    expect(fn () => DB::transaction(fn () => app(FreezeHumanCampaignContext::class)->handle($campaign, $composition, $contexts[1]->audienceSnapshot, $owner)))
        ->toThrow(ValidationException::class, 'paused');
    $fixture['goal']->forceFill(['status' => 'draft'])->save();
    app(RevokeExecutionAuthorityScope::class)->handle($fixture['scope'], $owner, 'Stop further preparation');
    expect(fn () => DB::transaction(fn () => app(FreezeHumanCampaignContext::class)->handle($campaign, $composition, $contexts[1]->audienceSnapshot, $owner)))
        ->toThrow(ValidationException::class, 'revoked');
    expect(ObjectiveVersion::query()->where('objective_id', $fixture['goal']->id)->count())->toBe(2);
    $migration = require database_path('migrations/2026_09_05_101800_allow_human_execution_without_experiments.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'cannot invent');
});

it('requires the enclosing compiler transaction', function () {
    DB::partialMock()->shouldReceive('transactionLevel')->once()->andReturn(0);
    // The method fails before consulting any supplied identity or performing writes.
    expect(fn () => app(FreezeHumanCampaignContext::class)->handle(new Campaign, new CampaignComposition, new AudienceSnapshot, new User))
        ->toThrow(LogicException::class);
});

it('prepares human review without research atomically and reuses one exact request', function () {
    $fixture = humanContextFixture();
    $owner = $fixture['owner'];
    $campaign = app(CreateCampaignService::class)->handle($owner, 'Atomic human review');
    $composition = app(SaveCampaignComposition::class)->handle($campaign, $owner, [
        'objective_goal_version_id' => $fixture['goal']->current_goal_version_id,
        'offer_version_id' => $fixture['version']->offer_version_id,
        'execution_authority_scope_id' => $fixture['scope']->id,
        'sending_starts_at' => now()->addDay()->toAtomString(), 'sending_ends_at' => now()->addDays(2)->toAtomString(),
        'expected_revision' => 0, 'operation_id' => (string) Str::uuid(),
    ]);
    $person = Person::query()->where('workspace_id', $owner->currentWorkspace()->id)->firstOrFail();
    $person->update(['account_id' => Account::factory()->create(['workspace_id' => $person->workspace_id])->id]);
    $removed = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id, 'status' => 'removed', 'person_id' => null,
        'email_address' => 'removed@example.com', 'email_normalized' => 'removed@example.com',
    ]);
    $mailbox = Mailbox::query()->where('workspace_id', $owner->currentWorkspace()->id)->whereNotNull('selected_sender_identity_key')->firstOrFail();
    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id, 'person_id' => $person->id, 'account_id' => $person->account_id,
        'email_address' => $person->email_address, 'email_normalized' => $person->email_normalized,
        'mailbox_id' => $mailbox->id, 'first_name' => 'Casey', 'company' => 'Example',
    ]);
    $campaign->draftVersion->activeSteps->sole()->forceFill([
        'subject_template' => 'Hello {{first_name}}', 'body_template' => 'Would a consultation help {{company}}?',
        'subject_spintax_enabled' => false, 'body_spintax_enabled' => false,
    ])->save();
    $service = app(PrepareHumanCampaignReview::class);
    $operation = (string) Str::uuid();
    $snapshotCount = AudienceSnapshot::query()->count();
    expect(fn () => $service->handle($campaign, $owner, 0, $operation))->toThrow(ValidationException::class, 'selections changed');
    $campaign->mailboxes()->detach();
    expect(fn () => $service->handle($campaign, $owner, $composition->revision, $operation))->toThrow(ValidationException::class, 'selected, connected');
    expect(AudienceSnapshot::query()->count())->toBe($snapshotCount);
    expect(DB::table('objective_runs')->count())->toBe(0);
    expect(DB::table('objective_run_arm_drafts')->count())->toBe(0);
    $campaign->mailboxes()->attach($mailbox->id, ['position' => 1]);
    $plan = $service->handle($campaign, $owner, $composition->revision, $operation);
    expect($service->handle($campaign, $owner, $composition->revision, $operation)->id)->toBe($plan->id);
    expect(AudienceSnapshot::query()->count())->toBe($snapshotCount + 1);
    expect($plan->actions->sole()->subject)->toBe('Hello Casey');
    expect($removed->fresh()->status->value)->toBe('removed');
    expect($plan->actions->sole()->selected_claim_ids)->toBe([]);
    $context = ObjectiveVersion::query()->findOrFail($plan->objective_version_id);
    expect($context->required_qualification_outcomes)->toBe([])
        ->and($context->authority_envelope['audience_policy'])->toBe('human_selected_research_optional_v1');
    expect(DB::table('objective_runs')->count())->toBe(1);
    expect(DB::table('execution_plans')->count())->toBe(1);
    expect(DB::table('execution_action_events')->count())->toBe(1);
    expect(DB::table('approval_grants')->count())->toBe(0);
    expect(DB::table('send_attempts')->count())->toBe(0);
    expect(DB::table('ai_provider_operations')->count())->toBe(0);
    $membership = PersonSourceMembership::query()->where('workspace_id', $person->workspace_id)
        ->where('person_id', $person->id)->firstOrFail();
    DB::table('person_source_memberships')->where('id', $membership->id)->update(['state' => 'restricted']);
    expect(fn () => $service->handle($campaign, $owner, $composition->revision, $operation))
        ->toThrow(ValidationException::class, 'reviewed source no longer permits outreach');
    expect(DB::table('execution_plans')->count())->toBe(1)
        ->and(DB::table('approval_grants')->count())->toBe(0);
    DB::table('person_source_memberships')->where('id', $membership->id)->update(['state' => 'active', 'expired_at' => now()->subMinute()]);
    expect(fn () => $service->handle($campaign, $owner, $composition->revision, $operation))
        ->toThrow(ValidationException::class, 'reviewed source no longer permits outreach');
    DB::table('person_source_memberships')->where('id', $membership->id)->update(['expired_at' => $membership->expired_at]);
    DB::table('prospect_sources')->where('id', $membership->prospect_source_id)->update(['status' => 'paused']);
    expect(fn () => $service->handle($campaign, $owner, $composition->revision, $operation))
        ->toThrow(ValidationException::class, 'reviewed source no longer permits outreach');
    DB::table('prospect_sources')->where('id', $membership->prospect_source_id)->update(['status' => 'active']);
    $source = ProspectSource::query()->findOrFail($membership->prospect_source_id);
    $sourceVersion = ProspectSourceVersion::query()->findOrFail($source->current_version_id);
    $replacement = ProspectSourceVersion::factory()->forSource($source)->create([
        'version' => $sourceVersion->version + 1,
    ]);
    DB::table('prospect_sources')->where('id', $source->id)->update(['current_version_id' => $replacement->id]);
    expect(fn () => $service->handle($campaign, $owner, $composition->revision, $operation))
        ->toThrow(ValidationException::class, 'no new source was substituted');
    DB::table('prospect_sources')->where('id', $source->id)->update(['current_version_id' => $sourceVersion->id]);
    $policy = $sourceVersion->policy_snapshot;
    DB::table('prospect_source_versions')->where('id', $sourceVersion->id)->update([
        'policy_snapshot' => json_encode([...$policy, 'contact_eligibility' => 'restricted'], JSON_THROW_ON_ERROR),
    ]);
    expect(fn () => $service->handle($campaign, $owner, $composition->revision, $operation))
        ->toThrow(ValidationException::class, 'reviewed source no longer permits outreach');
    DB::table('prospect_source_versions')->where('id', $sourceVersion->id)->update([
        'policy_snapshot' => json_encode($policy, JSON_THROW_ON_ERROR),
    ]);
    expect($service->handle($campaign, $owner, $composition->revision, $operation)->id)->toBe($plan->id);
    $this->actingAs($owner)->get('/campaigns/'.$campaign->id.'/review')
        ->assertOk()->assertInertia(fn ($page) => $page->component('campaigns/Review')
        ->where('review.id', $plan->id)->where('review.messages.0.subject', 'Hello Casey')->has('review.messages', 1));
    $this->post('/campaigns/'.$campaign->id.'/review', [
        'expected_revision' => $composition->revision, 'operation_id' => $operation,
    ])->assertRedirect('/campaigns/'.$campaign->id.'/review');
    expect(DB::table('execution_plans')->count())->toBe(1);
    $attempt = SendAttempt::factory()->create([
        'workspace_id' => $campaign->workspace_id, 'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $campaign->recipients()->where('status', 'queued')->sole()->id,
        'status' => 'unknown',
    ]);
    expect(fn () => $service->handle($campaign, $owner, $composition->revision, $operation))
        ->toThrow(ValidationException::class, 'already has a send attempt');
    expect($attempt->fresh()->status->value)->toBe('unknown')
        ->and(DB::table('execution_plans')->count())->toBe(1)
        ->and(DB::table('approval_grants')->count())->toBe(0);
    $foreign = User::factory()->create();
    $this->actingAs($foreign)->get('/campaigns/'.$campaign->id.'/review')->assertNotFound();
    $this->post('/campaigns/'.$campaign->id.'/review', [
        'expected_revision' => $composition->revision, 'operation_id' => (string) Str::uuid(),
    ])->assertNotFound();
});
