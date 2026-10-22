<?php

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Models\Account;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\Person;
use App\Models\SuppressionEntry;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('opens the independent accounts workspace with only current workspace records', function () {
    $user = User::factory()->create();
    $own = Account::factory()->create(['workspace_id' => $user->currentWorkspace()->id, 'name' => 'Own account']);
    $other = User::factory()->create();
    Account::factory()->create(['workspace_id' => $other->currentWorkspace()->id, 'name' => 'Other account']);
    $this->actingAs($user)->get('/accounts')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('people/Index')
            ->where('initialView', 'accounts')->has('accounts', 1)->where('accounts.0.id', $own->id));
});

it('requires authentication for the accounts workspace', function () {
    $this->get('/accounts')->assertRedirect(route('login'));
});

it('projects campaign recipients into a deduplicated people and accounts registry', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $firstCampaign = Campaign::factory()->create([
        'user_id' => $user->id,
        'name' => 'Founders',
    ]);
    $secondCampaign = Campaign::factory()->create([
        'user_id' => $user->id,
        'name' => 'Growth leaders',
    ]);

    $account = Account::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'name' => 'Northstar Studio',
        'domain_normalized' => 'northstar.studio',
        'website' => 'https://northstar.studio',
    ]);
    $maya = Person::factory()->forAccount($account)->create([
        'full_name' => 'Maya Chen',
        'email_address' => 'maya@northstar.studio',
        'email_normalized' => 'maya@northstar.studio',
    ]);
    $theo = Person::factory()->forAccount($account)->create([
        'full_name' => 'Theo Martin',
        'email_address' => 'theo@northstar.studio',
        'email_normalized' => 'theo@northstar.studio',
    ]);

    foreach ([$firstCampaign, $secondCampaign] as $campaign) {
        CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'mailbox_id' => $mailbox->id,
            'account_id' => $account->id,
            'person_id' => $maya->id,
            'email_address' => 'maya@northstar.studio',
            'email_normalized' => 'maya@northstar.studio',
            'full_name' => 'Maya Chen',
            'company' => 'Northstar Studio',
            'status' => $campaign->is($secondCampaign)
                ? RecipientStatus::Interested
                : RecipientStatus::Replied,
        ]);
    }

    CampaignRecipient::factory()->create([
        'campaign_id' => $firstCampaign->id,
        'mailbox_id' => $mailbox->id,
        'account_id' => $account->id,
        'person_id' => $theo->id,
        'email_address' => 'theo@northstar.studio',
        'email_normalized' => 'theo@northstar.studio',
        'full_name' => 'Theo Martin',
        'company' => 'Northstar Studio',
        'status' => RecipientStatus::Active,
    ]);

    $this->actingAs($user)
        ->get(route('prospects.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('people/Index')
            ->has('people', 2)
            ->has('accounts', 1)
            ->where('people.0.name', 'Maya Chen')
            ->where('people.0.reply', 'Interested')
            ->where('people.0.campaign_label', '2 campaigns')
            ->has('people.0.campaigns', 2)
            ->where('accounts.0.name', 'Northstar Studio')
            ->where('accounts.0.id', $account->id)
            ->where('accounts.0.domain', 'northstar.studio')
            ->where('accounts.0.website', 'https://northstar.studio')
            ->where('accounts.0.people_count', 2)
            ->where('accounts.0.ready_count', 2)
            ->where('accounts.0.readiness', 'Ready')
            ->where('people.0.id', $maya->id)
            ->has('accountOptions', 1)
            ->where('accountOptions.0.id', $account->id)
            ->where('accountOptions.0.name', 'Northstar Studio'));
});

it('shows suppression readiness and excludes another users recipients', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $otherCampaign = Campaign::factory()->create(['user_id' => $otherUser->id]);
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $otherMailbox = Mailbox::factory()->create(['user_id' => $otherUser->id]);

    $account = Account::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'name' => 'Acme',
        'domain_normalized' => 'acme.test',
    ]);
    $blocked = Person::factory()->forAccount($account)->create([
        'full_name' => 'Blocked Person',
        'email_address' => 'blocked@acme.test',
        'email_normalized' => 'blocked@acme.test',
    ]);

    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'account_id' => $account->id,
        'person_id' => $blocked->id,
        'email_address' => 'blocked@acme.test',
        'email_normalized' => 'blocked@acme.test',
        'full_name' => 'Blocked Person',
        'company' => 'Acme',
    ]);
    SuppressionEntry::factory()->create([
        'user_id' => $user->id,
        'email_address' => 'blocked@acme.test',
        'email_normalized' => 'blocked@acme.test',
    ]);
    CampaignRecipient::factory()->create([
        'campaign_id' => $otherCampaign->id,
        'mailbox_id' => $otherMailbox->id,
        'email_address' => 'private@other.test',
        'email_normalized' => 'private@other.test',
        'full_name' => 'Other Tenant',
        'company' => 'Other',
    ]);

    $this->actingAs($user)
        ->get(route('prospects.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('people', 1)
            ->where('people.0.name', 'Blocked Person')
            ->where('people.0.eligibility', 'Suppressed')
            ->has('accounts', 1)
            ->where('accounts.0.readiness', 'Blocked')
            ->where('accounts.0.ready_count', 0));
});

it('requires authentication for the registry', function () {
    $this->get(route('prospects.index'))->assertRedirect(route('login'));
});

it('renders the registry with the Account intelligence entry available by default', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('prospects.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('people/Index')
            ->has('accounts'));
});

it('redirects the legacy people page URL and preserves its query string', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/people?view=accounts&account=42')
        ->assertStatus(301)
        ->assertRedirect(route('prospects.index', [
            'view' => 'accounts',
            'account' => 42,
        ]));
});

it('includes accounts that have never been enrolled in a campaign', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'name' => 'Independent Co',
        'website' => 'https://independent.test',
        'domain_normalized' => 'independent.test',
        'notes' => 'Created by hand',
        'tags' => ['manual'],
        'source' => Account::SOURCE_MANUAL,
    ]);

    $this->actingAs($user)
        ->get(route('prospects.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('people', 0)
            ->has('accounts', 1)
            ->where('accounts.0.id', $account->id)
            ->where('accounts.0.name', 'Independent Co')
            ->where('accounts.0.people_count', 0)
            ->where('accounts.0.ready_count', 0)
            ->where('accounts.0.readiness', 'No people')
            ->where('accounts.0.tags', ['manual'])
            ->where('accounts.0.source', 'manual'));
});

it('preselects a workspace person or account from the query string', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'full_name' => 'Maya Chen',
    ]);
    $account = Account::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'name' => 'Stripe',
    ]);
    $foreign = User::factory()->create();
    $foreignPerson = Person::factory()->create([
        'workspace_id' => $foreign->currentWorkspace()->id,
    ]);

    $this->actingAs($user)
        ->get(route('prospects.index', ['person' => $person->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('preselect.kind', 'person')
            ->where('preselect.id', $person->id));

    $this->actingAs($user)
        ->get(route('prospects.index', ['account' => $account->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('preselect.kind', 'account')
            ->where('preselect.id', $account->id));

    $this->actingAs($user)
        ->get(route('prospects.index', ['person' => $foreignPerson->id]))
        ->assertNotFound();
});

it('shows a never-enrolled person and a suppressed campaign-less person', function () {
    $user = User::factory()->create();
    Person::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'full_name' => null,
        'first_name' => null,
        'email_address' => 'fresh@acme.test',
        'email_normalized' => 'fresh@acme.test',
    ]);
    Person::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'full_name' => 'Quiet Person',
        'email_address' => 'quiet@acme.test',
        'email_normalized' => 'quiet@acme.test',
    ]);
    SuppressionEntry::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $user->currentWorkspace()->id,
        'email_address' => 'quiet@acme.test',
        'email_normalized' => 'quiet@acme.test',
    ]);

    $this->actingAs($user)
        ->get(route('prospects.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('people', 2)
            ->where('people', fn ($people): bool => collect($people)->contains(
                fn (array $person): bool => $person['email'] === 'fresh@acme.test'
                    && $person['reply'] === 'No campaigns'
                    && $person['campaign_label'] === '—',
            ))
            ->where('people', fn ($people): bool => collect($people)->contains(
                fn (array $person): bool => $person['name'] === 'Quiet Person'
                    && $person['eligibility'] === 'Suppressed'
                    && $person['reply'] === 'No campaigns',
            )));
});
