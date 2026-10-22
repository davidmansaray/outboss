<?php

use App\Domain\People\Services\BackfillPeopleFromRecipients;
use App\Models\Account;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\Person;
use App\Models\User;

function seedPersonBackfillRecipient(
    User $user,
    string $email,
    string $name,
    ?int $accountId = null,
    ?string $updatedAt = null,
): CampaignRecipient {
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $mailbox = Mailbox::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $user->currentWorkspace()->id,
    ]);

    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $user->currentWorkspace()->id,
        'mailbox_id' => $mailbox->id,
        'account_id' => $accountId,
        'email_address' => $email,
        'email_normalized' => strtolower($email),
        'full_name' => $name,
        'first_name' => explode(' ', $name)[0],
    ]);

    if ($updatedAt !== null) {
        $recipient->forceFill(['updated_at' => $updatedAt])->saveQuietly();
    }

    return $recipient->fresh();
}

it('creates one person per distinct email and isolates workspaces', function () {
    $alpha = User::factory()->create();
    $beta = User::factory()->create();
    $account = Account::factory()->create([
        'workspace_id' => $alpha->currentWorkspace()->id,
        'name' => 'Acme',
        'domain_normalized' => 'acme.test',
    ]);

    $older = seedPersonBackfillRecipient($alpha, 'maya@acme.test', 'Maya Old', $account->id, '2026-01-01 00:00:00');
    $newer = seedPersonBackfillRecipient($alpha, 'maya@acme.test', 'Maya Chen', $account->id, '2026-08-01 00:00:00');
    seedPersonBackfillRecipient($alpha, 'theo@acme.test', 'Theo Martin', $account->id);
    seedPersonBackfillRecipient($beta, 'maya@acme.test', 'Other Maya');

    (new BackfillPeopleFromRecipients)->handle();

    $alphaPeople = Person::query()->where('workspace_id', $alpha->currentWorkspace()->id)->orderBy('id')->get();
    $betaPeople = Person::query()->where('workspace_id', $beta->currentWorkspace()->id)->get();

    expect($alphaPeople)->toHaveCount(2)
        ->and($alphaPeople->firstWhere('email_normalized', 'maya@acme.test')?->full_name)->toBe('Maya Chen')
        ->and($alphaPeople->firstWhere('email_normalized', 'maya@acme.test')?->account_id)->toBe($account->id)
        ->and($alphaPeople->firstWhere('email_normalized', 'maya@acme.test')?->source)->toBe(Person::SOURCE_CAMPAIGN_BACKFILL)
        ->and($older->fresh()->person_id)->toBe($alphaPeople->firstWhere('email_normalized', 'maya@acme.test')?->id)
        ->and($newer->fresh()->person_id)->toBe($alphaPeople->firstWhere('email_normalized', 'maya@acme.test')?->id)
        ->and($betaPeople)->toHaveCount(1)
        ->and($betaPeople->first()->id)->not->toBe($alphaPeople->firstWhere('email_normalized', 'maya@acme.test')?->id);
});

it('does not create a second person when one already exists', function () {
    $user = User::factory()->create();
    $existing = Person::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'email_address' => 'maya@acme.test',
        'email_normalized' => 'maya@acme.test',
        'full_name' => 'Maya Manual',
        'source' => Person::SOURCE_MANUAL,
    ]);
    $recipient = seedPersonBackfillRecipient($user, 'maya@acme.test', 'Maya Campaign');

    (new BackfillPeopleFromRecipients)->handle();

    expect(Person::query()->where('workspace_id', $user->currentWorkspace()->id)->count())->toBe(1)
        ->and($existing->fresh()->full_name)->toBe('Maya Manual')
        ->and($recipient->fresh()->person_id)->toBe($existing->id);
});

it('is safe to run twice', function () {
    $user = User::factory()->create();
    seedPersonBackfillRecipient($user, 'maya@acme.test', 'Maya Chen');

    $service = new BackfillPeopleFromRecipients;
    $service->handle();
    $service->handle();

    expect(Person::query()->where('workspace_id', $user->currentWorkspace()->id)->count())->toBe(1)
        ->and(CampaignRecipient::query()->where('email_normalized', 'maya@acme.test')->whereNotNull('person_id')->count())->toBe(1);
});
