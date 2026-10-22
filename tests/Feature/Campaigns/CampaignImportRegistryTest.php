<?php

use App\Domain\Campaigns\Enums\ImportStatus;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Models\Account;
use App\Models\Campaign;
use App\Models\Mailbox;
use App\Models\Person;
use App\Models\RecipientImport;
use App\Models\SuppressionEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

/**
 * @param  list<array{email: string, name?: string, company?: string}>  $rows
 */
function previewAndCommitCampaignImport(User $user, Campaign $campaign, array $rows): RecipientImport
{
    test()->actingAs($user)
        ->post(route('campaigns.imports.preview', $campaign), [
            'source' => 'paste',
            'rows' => $rows,
            'mapping' => ['email' => 'email', 'name' => 'name', 'company' => 'company'],
        ])
        ->assertRedirect();

    $import = $campaign->fresh()->imports()->latest('id')->firstOrFail();

    test()->actingAs($user)
        ->post(route('campaigns.imports.commit', [$campaign, $import]), [
            'expected_mapping_version' => $import->mapping_version,
        ])
        ->assertRedirect();

    return $import->fresh();
}

it('keeps source-producing campaign imports owner-only', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $workspace = Workspace::factory()->create(['type' => 'team']);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'role' => WorkspaceMembership::ROLE_OWNER,
    ]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $member->id,
        'role' => 'member',
    ]);
    $campaign = Campaign::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
    ]);

    $this->actingAs($member)
        ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $workspace->id])
        ->post(route('campaigns.imports.preview', $campaign), [
            'source' => 'paste',
            'rows' => [['email' => 'member@example.test']],
            'mapping' => ['email' => 'email'],
        ])
        ->assertForbidden();

    expect(RecipientImport::query()->where('workspace_id', $workspace->id)->exists())->toBeFalse();
});

function campaignWithMailbox(User $user): Campaign
{
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $campaign->mailboxes()->sync([$mailbox->id => ['position' => 1]]);

    return $campaign->fresh();
}

it('creates people and links recipients when a campaign import is committed', function () {
    $user = User::factory()->create();
    $campaign = campaignWithMailbox($user);

    $import = previewAndCommitCampaignImport($user, $campaign, [
        ['email' => 'maya@stripe.com', 'name' => 'Maya Chen', 'company' => 'Stripe'],
    ]);

    $person = Person::query()->where('email_normalized', 'maya@stripe.com')->first();
    $recipient = $campaign->recipients()->first();
    $account = Account::query()->where('workspace_id', $user->currentWorkspace()->id)->where('name', 'Stripe')->first();

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->imported)->toBe(1)
        ->and($person)->not->toBeNull()
        ->and($person->source)->toBe(Person::SOURCE_IMPORT)
        ->and($person->full_name)->toBe('Maya Chen')
        ->and($account)->not->toBeNull()
        ->and($recipient->person_id)->toBe($person->id)
        ->and($recipient->account_id)->toBe($account->id)
        ->and($recipient->email_address)->toBe('maya@stripe.com')
        ->and($recipient->full_name)->toBe('Maya Chen')
        ->and($recipient->company)->toBe('Stripe');
});

it('uses explicitly mapped CSV columns for campaign variables', function () {
    $user = User::factory()->create();
    $campaign = campaignWithMailbox($user);

    $this->actingAs($user)
        ->post(route('campaigns.imports.preview', $campaign), [
            'source' => 'csv',
            'filename' => 'checkout-watch.csv',
            'rows' => [[
                'Contact email' => 'maya@acme.test',
                'Given name' => 'May',
                'Store' => 'Acme Shop',
            ]],
            'mapping' => [
                'email' => 'Contact email',
                'name' => '',
                'first_name' => 'Given name',
                'company' => 'Store',
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $import = $campaign->imports()->latest('id')->firstOrFail();
    $row = $import->rows()->firstOrFail();

    expect($import->original_filename)->toBe('checkout-watch.csv')
        ->and($row->email_address)->toBe('maya@acme.test')
        ->and($row->first_name)->toBe('May')
        ->and($row->company)->toBe('Acme Shop');
});

it('flashes only aggregate campaign import review data', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->post(route('campaigns.imports.preview', $campaign), [
            'source' => 'csv',
            'rows' => [['email' => 'maya@acme.test']],
            'mapping' => ['email' => 'email'],
        ])
        ->assertRedirect()
        ->assertSessionHas('import', function (array $review): bool {
            return array_keys($review) === [
                'id',
                'mapping_version',
                'ready',
                'invalid',
                'duplicate',
                'suppressed',
            ] && $review['ready'] === 1;
        });
});

it('shows paginated mapped rows only to the campaign import owner', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user)->post(route('campaigns.imports.preview', $campaign), [
        'source' => 'csv', 'rows' => [['email' => 'maya@acme.test', 'name' => 'Maya Chen'], ['email' => 'not-an-email']],
        'mapping' => ['email' => 'email', 'name' => 'name'],
    ])->assertRedirect();
    $import = $campaign->imports()->latest('id')->firstOrFail();
    $url = route('campaigns.imports.rows', [$campaign, $import]);
    $this->getJson($url)->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.first_name', 'Maya');
    $this->getJson($url.'?issue=invalid')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.issue', 'invalid');
    $otherCampaign = Campaign::factory()->create(['user_id' => $user->id]);
    $this->getJson(route('campaigns.imports.rows', [$otherCampaign, $import]))->assertNotFound();
    $this->actingAs(User::factory()->create())->getJson($url)->assertForbidden();
});

it('commits a reviewed draft import before mailbox selection without scheduling a send', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->post(route('campaigns.imports.preview', $campaign), [
        'source' => 'paste',
        'rows' => [['email' => 'maya@acme.test']],
        'mapping' => ['email' => 'email'],
    ])->assertRedirect();

    $import = $campaign->imports()->firstOrFail();

    $this->actingAs($user)
        ->post(route('campaigns.imports.commit', [$campaign, $import]), [
            'expected_mapping_version' => $import->mapping_version,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($import->fresh()->status)->toBe(ImportStatus::Completed)
        ->and($import->rows()->count())->toBe(0)
        ->and($campaign->recipients()->count())->toBe(1);
    expect($campaign->recipients()->sole()->mailbox_id)->toBeNull();
    expect($campaign->recipients()->sole()->next_send_at)->toBeNull();
    $this->post(route('campaigns.imports.commit', [$campaign, $import]), [
        'expected_mapping_version' => $import->mapping_version,
    ])->assertConflict();
    expect($campaign->recipients()->count())->toBe(1);
});

it('rejects campaign imports over the row limit', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->post(route('campaigns.imports.preview', $campaign), [
            'source' => 'csv',
            'rows' => array_fill(0, 10001, ['email' => 'maya@acme.test']),
            'mapping' => ['email' => 'email'],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('rows');

    expect($campaign->imports()->count())->toBe(0);
});

it('rejects campaign imports over the aggregate byte limit', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $rows = array_fill(0, 600, [
        'email' => 'maya@acme.test',
        'company' => str_repeat('a', 9000),
    ]);

    $this->actingAs($user)
        ->post(route('campaigns.imports.preview', $campaign), [
            'source' => 'csv',
            'rows' => $rows,
            'mapping' => ['email' => 'email', 'company' => 'company'],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors([
            'rows' => 'Parsed CSV data must be 5 MB or smaller.',
        ]);

    expect($campaign->imports()->count())->toBe(0);
});

it('keeps the sample campaign import request compatible without a mapping', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->post(route('campaigns.imports.preview', $campaign), [
            'source' => 'sample',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($campaign->imports()->firstOrFail()->total)->toBe(3);
});

it('updates an existing person without blanking notes or moving their account', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $account = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Original',
    ]);
    $person = Person::factory()->create([
        'workspace_id' => $workspace->id,
        'account_id' => $account->id,
        'email_address' => 'maya@acme.test',
        'email_normalized' => 'maya@acme.test',
        'full_name' => 'Maya',
        'notes' => 'Keep me',
        'source' => Person::SOURCE_MANUAL,
    ]);
    $campaign = campaignWithMailbox($user);

    previewAndCommitCampaignImport($user, $campaign, [
        ['email' => 'maya@acme.test', 'name' => 'Maya Chen', 'company' => 'Fresh Co'],
    ]);

    $person->refresh();
    $recipient = $campaign->recipients()->first();

    expect($person->full_name)->toBe('Maya Chen')
        ->and($person->notes)->toBe('Keep me')
        ->and($person->source)->toBe(Person::SOURCE_MANUAL)
        ->and($person->account_id)->toBe($account->id)
        ->and($recipient->person_id)->toBe($person->id)
        ->and($recipient->full_name)->toBe('Maya Chen');
});

it('matches and creates accounts during campaign import', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $existing = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Stripe',
        'domain_normalized' => 'stripe.com',
    ]);
    $campaign = campaignWithMailbox($user);

    previewAndCommitCampaignImport($user, $campaign, [
        ['email' => 'maya@stripe.com', 'name' => 'Maya', 'company' => 'Ignored'],
        ['email' => 'one@fresh.test', 'name' => 'One', 'company' => 'Fresh Co'],
        ['email' => 'two@fresh.test', 'name' => 'Two', 'company' => 'Fresh Co'],
        ['email' => 'free@gmail.com', 'name' => 'Free', 'company' => ''],
    ]);

    expect(Person::query()->where('email_normalized', 'maya@stripe.com')->value('account_id'))->toBe($existing->id)
        ->and(Account::query()->where('workspace_id', $workspace->id)->where('name', 'Fresh Co')->count())->toBe(1)
        ->and(Person::query()->where('email_normalized', 'one@fresh.test')->value('account_id'))
        ->toBe(Person::query()->where('email_normalized', 'two@fresh.test')->value('account_id'))
        ->and(Person::query()->where('email_normalized', 'free@gmail.com')->value('account_id'))->toBeNull()
        ->and(Account::query()->where('domain_normalized', 'gmail.com')->exists())->toBeFalse();
});

it('upserts suppressed rows into the registry without enrolling them', function () {
    $user = User::factory()->create();
    $campaign = campaignWithMailbox($user);
    SuppressionEntry::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $user->currentWorkspace()->id,
        'email_address' => 'gone@acme.test',
        'email_normalized' => 'gone@acme.test',
    ]);

    $import = previewAndCommitCampaignImport($user, $campaign, [
        ['email' => 'ready@acme.test', 'name' => 'Ready', 'company' => 'Acme'],
        ['email' => 'gone@acme.test', 'name' => 'Gone', 'company' => 'Acme'],
    ]);

    expect($import->ready)->toBe(1)
        ->and($import->suppressed)->toBe(1)
        ->and($import->imported)->toBe(1)
        ->and($campaign->recipients()->count())->toBe(1)
        ->and($campaign->recipients()->where('email_normalized', 'gone@acme.test')->exists())->toBeFalse()
        ->and(Person::query()->where('email_normalized', 'gone@acme.test')->exists())->toBeTrue();
});

it('does not create people for invalid or in-file duplicate rows', function () {
    $user = User::factory()->create();
    $campaign = campaignWithMailbox($user);

    previewAndCommitCampaignImport($user, $campaign, [
        ['email' => 'new@acme.test', 'name' => 'New', 'company' => 'Acme'],
        ['email' => 'new@acme.test', 'name' => 'Duplicate', 'company' => 'Acme'],
        ['email' => 'not-an-email', 'name' => 'Bad', 'company' => 'Acme'],
    ]);

    expect(Person::query()->where('workspace_id', $user->currentWorkspace()->id)->count())->toBe(1)
        ->and($campaign->recipients()->count())->toBe(1)
        ->and(Person::query()->where('full_name', 'Duplicate')->exists())->toBeFalse();
});

it('leaves the enrolment snapshot untouched after the person is edited or deleted', function () {
    $user = User::factory()->create();
    $campaign = campaignWithMailbox($user);

    previewAndCommitCampaignImport($user, $campaign, [
        ['email' => 'maya@acme.test', 'name' => 'Maya Chen', 'company' => 'Acme'],
    ]);

    $person = Person::query()->where('email_normalized', 'maya@acme.test')->firstOrFail();
    $recipient = $campaign->recipients()->firstOrFail();

    $this->actingAs($user)
        ->patch(route('people.update', $person), [
            'email' => 'maya@new.test',
            'full_name' => 'Maya Updated',
            'account_id' => null,
        ])
        ->assertRedirect();

    $recipient->refresh();

    expect($recipient->email_address)->toBe('maya@acme.test')
        ->and($recipient->email_normalized)->toBe('maya@acme.test')
        ->and($recipient->full_name)->toBe('Maya Chen')
        ->and($recipient->company)->toBe('Acme')
        ->and($recipient->person_id)->toBe($person->id);

    $this->actingAs($user)
        ->delete(route('people.destroy', $person))
        ->assertRedirect();

    $recipient->refresh();

    expect($recipient->person_id)->toBeNull()
        ->and($recipient->email_address)->toBe('maya@acme.test')
        ->and($recipient->full_name)->toBe('Maya Chen')
        ->and($recipient->status)->toBe(RecipientStatus::Queued);
});

it('does not enrol a row that becomes suppressed between preview and commit', function () {
    $user = User::factory()->create();
    $campaign = campaignWithMailbox($user);

    $this->actingAs($user)
        ->post(route('campaigns.imports.preview', $campaign), [
            'source' => 'paste',
            'rows' => [['email' => 'maya@acme.test', 'name' => 'Maya', 'company' => 'Acme']],
            'mapping' => ['email' => 'email', 'name' => 'name', 'company' => 'company'],
        ])
        ->assertRedirect();

    $import = $campaign->fresh()->imports()->firstOrFail();
    expect($import->ready)->toBe(1);

    SuppressionEntry::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $user->currentWorkspace()->id,
        'email_address' => 'maya@acme.test',
        'email_normalized' => 'maya@acme.test',
    ]);

    $this->actingAs($user)
        ->post(route('campaigns.imports.commit', [$campaign, $import]), [
            'expected_mapping_version' => $import->mapping_version,
        ])
        ->assertRedirect();

    expect($campaign->recipients()->count())->toBe(0)
        ->and(Person::query()->where('email_normalized', 'maya@acme.test')->exists())->toBeTrue();
});

it('never links a campaign import to another workspace person or account', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $foreignAccount = Account::factory()->create([
        'workspace_id' => $other->currentWorkspace()->id,
        'name' => 'Stripe',
        'domain_normalized' => 'stripe.com',
    ]);
    Person::factory()->create([
        'workspace_id' => $other->currentWorkspace()->id,
        'account_id' => $foreignAccount->id,
        'email_address' => 'maya@stripe.com',
        'email_normalized' => 'maya@stripe.com',
    ]);
    $campaign = campaignWithMailbox($user);

    previewAndCommitCampaignImport($user, $campaign, [
        ['email' => 'maya@stripe.com', 'name' => 'Maya', 'company' => 'Stripe'],
    ]);

    $person = Person::query()
        ->where('workspace_id', $user->currentWorkspace()->id)
        ->where('email_normalized', 'maya@stripe.com')
        ->first();
    $recipient = $campaign->recipients()->first();

    expect($person)->not->toBeNull()
        ->and($person->account_id)->not->toBe($foreignAccount->id)
        ->and($recipient->person_id)->toBe($person->id)
        ->and($recipient->account_id)->toBe($person->account_id);
});
