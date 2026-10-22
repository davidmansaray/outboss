<?php

use App\Domain\Campaigns\DTOs\GovernedCampaignAction;
use App\Domain\Campaigns\Services\AdoptHumanCampaignDraft;
use App\Domain\Campaigns\Services\CreateCampaignService;
use App\Domain\Campaigns\Services\GovernedCampaignCompilationCapability;
use App\Domain\Campaigns\Services\GovernedCampaignInputGuard;
use App\Domain\Campaigns\Services\RenderSequenceMessage;
use App\Domain\Campaigns\Services\SaveCampaignComposition;
use App\Domain\Gtm\Services\CreateOffer;
use App\Domain\Objectives\Enums\ExecutionPlanCompilationState;
use App\Domain\Objectives\Services\SaveObjectiveGoal;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Models\Account;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\ExecutionPlanCompilation;
use App\Models\Person;
use App\Models\SuppressionEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function humanAdoptionFixture(): array
{
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $campaign = app(CreateCampaignService::class)->handle($owner, 'Human draft');
    $goal = app(SaveObjectiveGoal::class)->handle($workspace, $owner, [
        'name' => 'Meetings', 'measurement' => 'meeting_booked', 'target_count' => 10, 'creation_key' => 'adopt-goal',
    ]);
    $offer = app(CreateOffer::class)->handle($workspace, $owner, [
        'name' => 'Call', 'promise' => '', 'target_audience' => '', 'desired_outcome' => '', 'call_to_action' => '', 'notes' => '',
    ]);
    $composition = app(SaveCampaignComposition::class)->handle($campaign, $owner, [
        'objective_goal_version_id' => $goal->current_goal_version_id, 'offer_version_id' => $offer->current_version_id,
        'expected_revision' => 0, 'operation_id' => (string) Str::uuid(),
    ]);
    $account = Account::factory()->create(['workspace_id' => $workspace->id]);
    $person = Person::factory()->create(['workspace_id' => $workspace->id, 'account_id' => $account->id]);
    $recipient = CampaignRecipient::factory()->create(['campaign_id' => $campaign->id, 'person_id' => $person->id, 'account_id' => $account->id]);
    $campaign->mailboxes()->attach($recipient->mailbox_id, ['position' => 1]);
    $step = $campaign->draftVersion->activeSteps->sole();
    $step->forceFill(['subject_template' => 'Hello {{first_name}}', 'body_template' => 'Would a consultation help {{company}}?'])->save();
    $rendered = app(RenderSequenceMessage::class)->handle($step, $recipient);
    $action = new GovernedCampaignAction(
        (string) Str::uuid(), $person->id, $account->id, $recipient->mailbox_id,
        $recipient->email_address, $recipient->email_normalized, $recipient->full_name, $recipient->first_name, $recipient->company,
        $step->subject_template, $step->body_template, $rendered['subject'], $rendered['body'], str_repeat('a', 64),
        CanonicalObjectivePayload::hash(['subject' => $rendered['subject'], 'body_text' => $rendered['body'], 'body_html' => null]),
    );
    // Exercise the callback capability without fabricating persisted AI/plan data.
    $compilation = new ExecutionPlanCompilation;
    $compilation->forceFill(['id' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'state' => ExecutionPlanCompilationState::Started]);

    return compact('owner', 'campaign', 'composition', 'recipient', 'action', 'compilation');
}

function runHumanAdoption(array $fixture): mixed
{
    return DB::transaction(fn () => app(GovernedCampaignInputGuard::class)->runCompilerMutation(
        $fixture['compilation'], fn ($capability) => app(AdoptHumanCampaignDraft::class)->handle(
            $fixture['campaign'], $fixture['composition'], [$fixture['action']], $capability,
        ),
    ));
}

it('preserves the campaign sequence and recipient identities while projecting exact bytes', function () {
    $fixture = humanAdoptionFixture();
    $result = runHumanAdoption($fixture);
    expect($result->campaign->id)->toBe($fixture['campaign']->id);
    expect($result->sequenceVersion->id)->toBe($fixture['campaign']->draft_sequence_version_id);
    expect($result->recipientsByActionId[$fixture['action']->actionId]->id)->toBe($fixture['recipient']->id);
    expect(Campaign::query()->count())->toBe(1);
    expect(CampaignRecipient::query()->count())->toBe(1);
    expect($fixture['recipient']->fresh()->governed_body_text_snapshot)->toBe($fixture['action']->bodyText);
    expect($fixture['campaign']->fresh()->launched_at)->toBeNull();
});

it('rejects changed messages or suppression before writing snapshots', function (string $change) {
    $fixture = humanAdoptionFixture();
    if ($change === 'message') {
        $fixture['campaign']->draftVersion->activeSteps->sole()->update(['body_template' => 'Changed content']);
    } else {
        SuppressionEntry::factory()->create([
            'user_id' => $fixture['owner']->id, 'workspace_id' => $fixture['campaign']->getAttribute('workspace_id'),
            'email_normalized' => $fixture['recipient']->email_normalized,
        ]);
    }
    expect(fn () => runHumanAdoption($fixture))->toThrow(ValidationException::class);
    expect($fixture['recipient']->fresh()->governed_body_text_snapshot)->toBeNull();
})->with(['message', 'suppression']);

it('rejects an unissued compiler capability', function () {
    $fixture = humanAdoptionFixture();
    expect(fn () => DB::transaction(fn () => app(AdoptHumanCampaignDraft::class)->handle(
        $fixture['campaign'], $fixture['composition'], [$fixture['action']],
        new GovernedCampaignCompilationCapability($fixture['campaign']->getAttribute('workspace_id'), 'unissued', 1),
    )))->toThrow(LogicException::class);
});

it('rejects audience additions and superseded compositions without partial adoption', function (string $change) {
    $fixture = humanAdoptionFixture();
    if ($change === 'audience') {
        CampaignRecipient::factory()->create(['campaign_id' => $fixture['campaign']->id]);
    } else {
        app(SaveCampaignComposition::class)->handle($fixture['campaign'], $fixture['owner'], [
            'objective_goal_version_id' => null, 'offer_version_id' => null,
            'expected_revision' => 1, 'operation_id' => (string) Str::uuid(),
        ]);
    }
    expect(fn () => runHumanAdoption($fixture))->toThrow(ValidationException::class);
    expect($fixture['recipient']->fresh()->governed_subject_snapshot)->toBeNull();
})->with(['audience', 'composition']);
