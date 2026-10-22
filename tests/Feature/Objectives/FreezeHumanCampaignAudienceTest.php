<?php

use App\Domain\Campaigns\Services\CreateCampaignService;
use App\Domain\Campaigns\Services\SaveCampaignComposition;
use App\Domain\Objectives\Services\FreezeHumanCampaignAudience;
use App\Domain\Objectives\Services\SaveObjectiveGoal;
use App\Models\Account;
use App\Models\AudienceSnapshot;
use App\Models\CampaignRecipient;
use App\Models\Person;
use App\Models\Segment;
use App\Models\SendAttempt;
use App\Models\SuppressionEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ObjectiveScenarioFixtures.php';

function humanAudienceFixture(): array
{
    $fixture = s3IncrementThreeReadyFixture(2);
    $owner = $fixture['owner'];
    $campaign = app(CreateCampaignService::class)->handle($owner, 'Selected prospects');
    $goal = app(SaveObjectiveGoal::class)->handle($owner->currentWorkspace(), $owner, [
        'name' => 'Meetings', 'measurement' => 'meeting_booked', 'target_count' => 10, 'creation_key' => 'human-audience',
    ]);
    $composition = app(SaveCampaignComposition::class)->handle($campaign, $owner, [
        'objective_goal_version_id' => $goal->current_goal_version_id,
        'offer_version_id' => $fixture['version']->offer_version_id,
        'expected_revision' => 0, 'operation_id' => (string) Str::uuid(),
    ]);
    $person = Person::query()->where('workspace_id', $campaign->getAttribute('workspace_id'))->orderBy('id')->firstOrFail();
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id, 'person_id' => $person->id, 'account_id' => $person->account_id,
        'email_address' => $person->email_address, 'email_normalized' => $person->email_normalized,
    ]);

    return compact('owner', 'campaign', 'goal', 'composition', 'person', 'recipient');
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-07 10:00:00 UTC'));
});

it('freezes only selected qualified prospects and retains their source and evidence lineage', function () {
    $fixture = humanAudienceFixture();
    $snapshot = app(FreezeHumanCampaignAudience::class)->handle($fixture['campaign'], $fixture['composition'], $fixture['owner']);
    $included = $snapshot->members->filter(fn ($member) => $member->result->value === 'included');
    expect($snapshot->objective_id)->toBe($fixture['goal']->id)
        ->and($snapshot->included_people_count)->toBe(1)
        ->and($included->sole()->person_id)->toBe($fixture['person']->id)
        ->and($included->sole()->sources)->not->toBeEmpty()
        ->and($included->sole()->evidence_manifest)->not->toBeEmpty()
        ->and(CampaignRecipient::query()->count())->toBe(1);
    expect(DB::table('ai_provider_operations')->count())->toBe(0)
        ->and(DB::table('send_attempts')->count())->toBe(0);
});

it('does not prepare a queued recipient with an existing delivery attempt', function (string $status) {
    $fixture = humanAudienceFixture();
    $before = AudienceSnapshot::query()->count();
    $attempt = SendAttempt::factory()->create([
        'workspace_id' => $fixture['campaign']->workspace_id,
        'campaign_recipient_id' => $fixture['recipient']->id, 'status' => $status,
    ]);
    expect(fn () => app(FreezeHumanCampaignAudience::class)->handle($fixture['campaign'], $fixture['composition'], $fixture['owner']))
        ->toThrow(ValidationException::class, 'already has a send attempt');
    expect(AudienceSnapshot::query()->count())->toBe($before)
        ->and($attempt->fresh()->status->value)->toBe($status)
        ->and($fixture['recipient']->fresh()->status->value)->toBe('queued');
})->with(['unknown', 'sending', 'provider_accepted', 'sent']);

it('rejects workspace members without owner authority but does not require a safe research reason', function () {
    $fixture = humanAudienceFixture();
    $member = User::factory()->create();
    $workspace = $fixture['owner']->currentWorkspace();
    $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
    $this->actingAs($member)->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $workspace->id]);
    $service = app(FreezeHumanCampaignAudience::class);
    expect(fn () => $service->handle($fixture['campaign'], $fixture['composition'], $member))->toThrow(HttpException::class);
    DB::table('reason_to_contact_versions')->where('workspace_id', $workspace->id)
        ->where('account_id', $fixture['person']->account_id)->update(['safe_for_message' => false]);
    $snapshot = $service->handle($fixture['campaign'], $fixture['composition'], $fixture['owner']);
    expect($snapshot->included_people_count)->toBe(1);
});

it('accepts a manually selected prospect without qualification or research evidence', function () {
    $fixture = humanAudienceFixture();
    $account = Account::factory()->create(['workspace_id' => $fixture['person']->workspace_id]);
    $fixture['person']->update(['account_id' => $account->id]);
    $fixture['recipient']->update(['account_id' => $account->id]);
    $snapshot = app(FreezeHumanCampaignAudience::class)->handle($fixture['campaign'], $fixture['composition'], $fixture['owner']);
    $member = $snapshot->members->firstWhere('person_id', $fixture['person']->id);
    expect($snapshot->included_people_count)->toBe(1)
        ->and($member->qualification_decision_id)->toBeNull()
        ->and($member->reason_to_contact_version_id)->toBeNull()
        ->and($member->evidence_manifest)->toBe([])
        ->and($member->sources)->not->toBeEmpty();
});

it('still rejects selected prospects with no permitted source membership', function () {
    $fixture = humanAudienceFixture();
    $fixture['person']->sourceMemberships()->update(['state' => 'restricted']);
    $before = AudienceSnapshot::query()->count();
    expect(fn () => app(FreezeHumanCampaignAudience::class)->handle($fixture['campaign'], $fixture['composition'], $fixture['owner']))
        ->toThrow(ValidationException::class, 'source-rights');
    expect(AudienceSnapshot::query()->count())->toBe($before);
});

it('explains which legacy prospect has no source record without dropping it', function () {
    $fixture = humanAudienceFixture();
    $fixture['person']->sourceMemberships()->update(['state' => 'removed']);
    $before = AudienceSnapshot::query()->count();
    expect(fn () => app(FreezeHumanCampaignAudience::class)->handle($fixture['campaign'], $fixture['composition'], $fixture['owner']))
        ->toThrow(ValidationException::class, $fixture['person']->email_address.': no eligible source record');
    expect(AudienceSnapshot::query()->count())->toBe($before)
        ->and(CampaignRecipient::query()->count())->toBe(1);
});

it('honors historical unsubscribe and bounce without a suppression entry', function (string $status) {
    $fixture = humanAudienceFixture();
    $previous = app(CreateCampaignService::class)->handle($fixture['owner'], 'Historical outreach');
    CampaignRecipient::factory()->create([
        'campaign_id' => $previous->id, 'person_id' => $fixture['person']->id,
        'email_address' => $fixture['person']->email_address, 'email_normalized' => $fixture['person']->email_normalized,
        'status' => $status,
    ]);
    expect(SuppressionEntry::query()->count())->toBe(0);
    $before = AudienceSnapshot::query()->count();
    expect(fn () => app(FreezeHumanCampaignAudience::class)->handle($fixture['campaign'], $fixture['composition'], $fixture['owner']))
        ->toThrow(ValidationException::class, 'unsubscribe/bounce history');
    expect(AudienceSnapshot::query()->count())->toBe($before);
})->with(['unsubscribed', 'bounced']);

it('does not treat another workspace history or ordinary campaign removal as an unsubscribe', function () {
    $fixture = humanAudienceFixture();
    $foreignOwner = User::factory()->create();
    $foreignCampaign = app(CreateCampaignService::class)->handle($foreignOwner, 'Foreign history');
    CampaignRecipient::factory()->create([
        'campaign_id' => $foreignCampaign->id, 'email_address' => $fixture['person']->email_address,
        'email_normalized' => $fixture['person']->email_normalized, 'status' => 'unsubscribed',
    ]);
    $previous = app(CreateCampaignService::class)->handle($fixture['owner'], 'Removed selection');
    CampaignRecipient::factory()->create([
        'campaign_id' => $previous->id, 'person_id' => $fixture['person']->id,
        'email_address' => $fixture['person']->email_address, 'email_normalized' => $fixture['person']->email_normalized,
        'status' => 'removed',
    ]);
    $snapshot = app(FreezeHumanCampaignAudience::class)->handle($fixture['campaign'], $fixture['composition'], $fixture['owner']);
    expect($snapshot->included_people_count)->toBe(1);
});

it('rolls back the freeze rather than silently removing a suppressed selection', function () {
    $fixture = humanAudienceFixture();
    SuppressionEntry::factory()->create([
        'workspace_id' => $fixture['person']->workspace_id, 'email_normalized' => $fixture['person']->email_normalized,
    ]);
    $snapshots = AudienceSnapshot::query()->count();
    $segments = Segment::query()->count();
    expect(fn () => app(FreezeHumanCampaignAudience::class)->handle($fixture['campaign'], $fixture['composition'], $fixture['owner']))
        ->toThrow(ValidationException::class, 'Every selected prospect');
    expect(AudienceSnapshot::query()->count())->toBe($snapshots)
        ->and(Segment::query()->count())->toBe($segments)
        ->and(CampaignRecipient::query()->count())->toBe(1);
});

it('denies foreign actors and stale composition before freezing anything', function () {
    $fixture = humanAudienceFixture();
    $service = app(FreezeHumanCampaignAudience::class);
    expect(fn () => $service->handle($fixture['campaign'], $fixture['composition'], User::factory()->create()))
        ->toThrow(HttpException::class);
    app(SaveCampaignComposition::class)->handle($fixture['campaign'], $fixture['owner'], [
        'objective_goal_version_id' => null, 'offer_version_id' => null,
        'expected_revision' => 1, 'operation_id' => (string) Str::uuid(),
    ]);
    $before = AudienceSnapshot::query()->count();
    expect(fn () => $service->handle($fixture['campaign'], $fixture['composition'], $fixture['owner']))
        ->toThrow(ValidationException::class, 'current complete composition');
    expect(AudienceSnapshot::query()->count())->toBe($before);
});

it('does not hide removed recipients with unresolved sending history', function (string $history) {
    $fixture = humanAudienceFixture();
    $removed = CampaignRecipient::factory()->create([
        'campaign_id' => $fixture['campaign']->id, 'status' => 'removed',
        'email_address' => 'removed@example.com', 'email_normalized' => 'removed@example.com',
        ...match ($history) {
            'sent' => ['first_sent_at' => now()],
            'lease' => ['send_lease_token' => (string) Str::uuid()],
            default => [],
        },
    ]);
    if ($history === 'attempt') {
        SendAttempt::factory()->create(['campaign_recipient_id' => $removed->id]);
    }
    expect(fn () => app(FreezeHumanCampaignAudience::class)->handle($fixture['campaign'], $fixture['composition'], $fixture['owner']))
        ->toThrow(ValidationException::class, 'queued prospects');
})->with(['sent', 'lease', 'attempt']);
