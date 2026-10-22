<?php

use App\Domain\Campaigns\DTOs\EnrollmentCandidate;
use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Services\EnrollPeopleInCampaign;
use App\Domain\Campaigns\Services\LaunchCampaignService;
use App\Domain\Campaigns\Services\ScheduleDueCampaignSends;
use App\Models\Account;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\Person;
use App\Models\SuppressionEntry;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

function attachMailbox(User $user, Campaign $campaign, int $position = 1): Mailbox
{
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign->mailboxes()->attach($mailbox->id, ['position' => $position]);

    return $mailbox;
}

it('lists enrollable people with suppression and already-enrolled reasons', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $account = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Stripe',
    ]);
    $eligible = Person::factory()->forAccount($account)->create([
        'full_name' => 'Maya Chen',
        'email_address' => 'maya@stripe.com',
        'email_normalized' => 'maya@stripe.com',
    ]);
    $suppressed = Person::factory()->create([
        'workspace_id' => $workspace->id,
        'full_name' => 'Gone Person',
        'email_address' => 'gone@acme.test',
        'email_normalized' => 'gone@acme.test',
    ]);
    $enrolled = Person::factory()->create([
        'workspace_id' => $workspace->id,
        'full_name' => 'Already In',
        'email_address' => 'in@acme.test',
        'email_normalized' => 'in@acme.test',
    ]);
    $removed = Person::factory()->create([
        'workspace_id' => $workspace->id,
        'full_name' => 'Removed Person',
        'email_address' => 'removed@acme.test',
        'email_normalized' => 'removed@acme.test',
    ]);
    SuppressionEntry::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'email_address' => 'gone@acme.test',
        'email_normalized' => 'gone@acme.test',
    ]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $mailbox = attachMailbox($user, $campaign);
    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $workspace->id,
        'mailbox_id' => $mailbox->id,
        'person_id' => $enrolled->id,
        'email_address' => $enrolled->email_address,
        'email_normalized' => $enrolled->email_normalized,
    ]);
    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $workspace->id,
        'mailbox_id' => $mailbox->id,
        'person_id' => $removed->id,
        'email_address' => $removed->email_address,
        'email_normalized' => $removed->email_normalized,
        'status' => RecipientStatus::Removed,
    ]);

    $this->actingAs($user)
        ->getJson(route('campaigns.enrollable-people', $campaign))
        ->assertOk()
        ->assertJsonPath('meta.total', 4)
        ->assertJsonFragment([
            'id' => $eligible->id,
            'name' => 'Maya Chen',
            'email' => 'maya@stripe.com',
            'account' => 'Stripe',
            'eligibility' => 'Eligible',
            'enrollable' => true,
            'reason' => null,
        ])
        ->assertJsonFragment([
            'id' => $suppressed->id,
            'enrollable' => false,
            'reason' => 'Suppressed',
            'eligibility' => 'Suppressed',
        ])
        ->assertJsonFragment([
            'id' => $enrolled->id,
            'enrollable' => false,
            'reason' => 'Already in campaign',
        ])
        ->assertJsonFragment([
            'id' => $removed->id,
            'enrollable' => false,
            'reason' => 'Already in campaign',
        ]);

    $this->actingAs($user)
        ->getJson(route('campaigns.enrollable-people', $campaign).'?query=stripe')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $eligible->id);

    $this->actingAs($user)
        ->getJson(route('campaigns.enrollable-people', $campaign).'?query=maya@stripe.com')
        ->assertOk()
        ->assertJsonPath('data.0.id', $eligible->id);

    $this->actingAs($user)
        ->getJson(route('campaigns.enrollable-people', $campaign).'?only_eligible=1')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $eligible->id);

    $other = User::factory()->create();
    $this->actingAs($other)
        ->getJson(route('campaigns.enrollable-people', $campaign))
        ->assertForbidden();
});

it('enrols eligible registry people with snapshot values and mailbox rotation', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $account = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Stripe',
    ]);
    $first = Person::factory()->forAccount($account)->create([
        'full_name' => 'Maya Chen',
        'first_name' => 'Maya',
        'email_address' => 'maya@stripe.com',
        'email_normalized' => 'maya@stripe.com',
    ]);
    $second = Person::factory()->create([
        'workspace_id' => $workspace->id,
        'full_name' => 'Aisha Rahman',
        'first_name' => 'Aisha',
        'email_address' => 'aisha@acme.test',
        'email_normalized' => 'aisha@acme.test',
        'account_id' => null,
    ]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $firstMailbox = attachMailbox($user, $campaign, 1);
    $secondMailbox = attachMailbox($user, $campaign, 2);

    $this->actingAs($user)
        ->post(route('campaigns.enrollments.store', $campaign), [
            'person_ids' => [$first->id, $second->id],
        ])
        ->assertRedirect()
        ->assertSessionHas('enrollment', fn (array $enrollment): bool => $enrollment['enrolled'] === 2
            && $enrollment['skipped'] === []);

    $recipients = $campaign->recipients()->orderBy('id')->get();

    expect($recipients)->toHaveCount(2)
        ->and($recipients[0]->person_id)->toBe($first->id)
        ->and($recipients[0]->account_id)->toBe($account->id)
        ->and($recipients[0]->full_name)->toBe('Maya Chen')
        ->and($recipients[0]->first_name)->toBe('Maya')
        ->and($recipients[0]->company)->toBe('Stripe')
        ->and($recipients[0]->status)->toBe(RecipientStatus::Queued)
        ->and($recipients[0]->mailbox_id)->toBe($firstMailbox->id)
        ->and($recipients[1]->person_id)->toBe($second->id)
        ->and($recipients[1]->account_id)->toBeNull()
        ->and($recipients[1]->company)->toBeNull()
        ->and($recipients[1]->mailbox_id)->toBe($secondMailbox->id);
});

it('activates enrolments on an active campaign and queues them on a completed campaign', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $activePerson = Person::factory()->create(['workspace_id' => $workspace->id]);
    $completedPerson = Person::factory()->create(['workspace_id' => $workspace->id]);
    $active = Campaign::factory()->create([
        'user_id' => $user->id,
        'status' => CampaignStatus::Active,
    ]);
    attachMailbox($user, $active);
    $completed = Campaign::factory()->create([
        'user_id' => $user->id,
        'status' => CampaignStatus::Completed,
        'completed_at' => now(),
    ]);
    attachMailbox($user, $completed);

    $this->actingAs($user)
        ->post(route('campaigns.enrollments.store', $active), [
            'person_ids' => [$activePerson->id],
        ])
        ->assertRedirect();

    $activeRecipient = $active->recipients()->first();
    expect($activeRecipient->status)->toBe(RecipientStatus::Active)
        ->and($activeRecipient->next_send_at)->not->toBeNull();

    $this->actingAs($user)
        ->post(route('campaigns.enrollments.store', $completed), [
            'person_ids' => [$completedPerson->id],
        ])
        ->assertRedirect()
        ->assertSessionHas('enrollment.notice');

    expect($completed->fresh()->status)->toBe(CampaignStatus::Completed)
        ->and($completed->fresh()->completed_at)->not->toBeNull()
        ->and($completed->recipients()->first()->status)->toBe(RecipientStatus::Queued)
        ->and($completed->recipients()->first()->next_send_at)->toBeNull();
});

it('skips suppressed, already enrolled, and foreign people without failing the batch', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $eligible = Person::factory()->create([
        'workspace_id' => $workspace->id,
        'full_name' => 'Ready Person',
    ]);
    $suppressed = Person::factory()->create([
        'workspace_id' => $workspace->id,
        'full_name' => 'Suppressed Person',
        'email_address' => 'gone@acme.test',
        'email_normalized' => 'gone@acme.test',
    ]);
    $already = Person::factory()->create([
        'workspace_id' => $workspace->id,
        'full_name' => 'Already Person',
        'email_address' => 'in@acme.test',
        'email_normalized' => 'in@acme.test',
    ]);
    $foreign = Person::factory()->create([
        'workspace_id' => $other->currentWorkspace()->id,
        'full_name' => 'Foreign Person',
    ]);
    SuppressionEntry::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'email_address' => 'gone@acme.test',
        'email_normalized' => 'gone@acme.test',
    ]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $mailbox = attachMailbox($user, $campaign);
    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $workspace->id,
        'mailbox_id' => $mailbox->id,
        'person_id' => $already->id,
        'email_address' => $already->email_address,
        'email_normalized' => $already->email_normalized,
    ]);

    $this->actingAs($user)
        ->post(route('campaigns.enrollments.store', $campaign), [
            'person_ids' => [$eligible->id, $suppressed->id, $already->id, $foreign->id],
        ])
        ->assertRedirect()
        ->assertSessionHas('enrollment', function (array $enrollment) use ($suppressed, $already, $foreign): bool {
            $reasons = collect($enrollment['skipped'])->keyBy('person_id');

            return $enrollment['enrolled'] === 1
                && $reasons[$suppressed->id]['reason'] === 'Suppressed'
                && $reasons[$already->id]['reason'] === 'Already in campaign'
                && $reasons[$foreign->id]['reason'] === 'Not found'
                && $reasons[$foreign->id]['name'] === 'Unknown';
        });

    expect($campaign->recipients()->where('person_id', $eligible->id)->exists())->toBeTrue();
});

it('allows draft audience setup before choosing a mailbox and assigns it when sending is configured', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
    ]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->from(route('campaigns.show', $campaign))
        ->post(route('campaigns.enrollments.store', $campaign), [
            'person_ids' => [$person->id],
        ])
        ->assertRedirect(route('campaigns.show', $campaign))
        ->assertSessionHasNoErrors();

    $recipient = $campaign->recipients()->sole();
    expect($recipient->mailbox_id)->toBeNull()->and($recipient->status)->toBe(RecipientStatus::Queued);
    expect($recipient->next_send_at)->toBeNull();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $this->patch(route('campaigns.update', $campaign), ['mailbox_ids' => [$mailbox->id]])->assertSessionHasNoErrors();
    expect($recipient->fresh()->mailbox_id)->toBe($mailbox->id);
    expect($recipient->fresh()->next_send_at)->toBeNull();
    expect(DB::table('send_attempts')->count())->toBe(0);
});

it('still rejects unassigned enrollment into an active campaign', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create(['workspace_id' => $user->currentWorkspace()->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id, 'status' => CampaignStatus::Active]);
    $this->actingAs($user)->post(route('campaigns.enrollments.store', $campaign), ['person_ids' => [$person->id]])
        ->assertSessionHasErrors('person_ids');
    expect($campaign->recipients()->count())->toBe(0);
});

it('skips a second enrolment of the same person in one campaign', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'full_name' => 'Maya Chen',
        'email_address' => 'maya@acme.test',
        'email_normalized' => 'maya@acme.test',
    ]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    attachMailbox($user, $campaign);

    $this->actingAs($user)
        ->post(route('campaigns.enrollments.store', $campaign), [
            'person_ids' => [$person->id],
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('campaigns.enrollments.store', $campaign), [
            'person_ids' => [$person->id],
        ])
        ->assertRedirect()
        ->assertSessionHas('enrollment', fn (array $enrollment): bool => $enrollment['enrolled'] === 0
            && $enrollment['skipped'][0]['reason'] === 'Already in campaign');

    expect($campaign->recipients()->count())->toBe(1);

    $other = Campaign::factory()->create(['user_id' => $user->id]);
    attachMailbox($user, $other);

    $this->actingAs($user)
        ->post(route('campaigns.enrollments.store', $other), [
            'person_ids' => [$person->id],
        ])
        ->assertRedirect()
        ->assertSessionHas('enrollment.enrolled', 1);
});

it('skips a duplicate candidate inside a single enrolment call', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'full_name' => 'Maya Chen',
        'first_name' => 'Maya',
        'email_address' => 'maya@acme.test',
        'email_normalized' => 'maya@acme.test',
    ]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    attachMailbox($user, $campaign);
    $candidate = new EnrollmentCandidate(
        $person->id,
        null,
        $person->email_address,
        $person->email_normalized,
        $person->full_name,
        $person->first_name,
        null,
    );

    $result = DB::transaction(function () use ($campaign, $candidate) {
        $locked = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();

        return app(EnrollPeopleInCampaign::class)->handle($locked, [$candidate, $candidate]);
    });

    expect($result->enrolled)->toBe(1)
        ->and($result->skipped)->toHaveCount(1)
        ->and($result->skipped[0]->reason)->toBe('Already in campaign')
        ->and($campaign->recipients()->count())->toBe(1);
});

it('does not schedule a send after an enrolled person is suppressed', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $person = Person::factory()->create([
        'workspace_id' => $workspace->id,
        'email_address' => 'maya@stripe.com',
        'email_normalized' => 'maya@stripe.com',
        'first_name' => 'Maya',
    ]);
    $campaign = Campaign::factory()->create([
        'user_id' => $user->id,
        'business_days_only' => false,
    ]);
    attachMailbox($user, $campaign);

    $this->actingAs($user)
        ->post(route('campaigns.enrollments.store', $campaign), [
            'person_ids' => [$person->id],
        ])
        ->assertRedirect();

    app(LaunchCampaignService::class)->handle($campaign->fresh(), $user);

    SuppressionEntry::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'email_address' => $person->email_address,
        'email_normalized' => $person->email_normalized,
    ]);

    $this->travelTo(now()->setTime(10, 0));
    Config::set('outboss.campaign_sending_enabled', true);
    $created = app(ScheduleDueCampaignSends::class)->handle();

    expect($created)->toBe(0)
        ->and($campaign->recipients()->first()->person_id)->toBe($person->id);
});
