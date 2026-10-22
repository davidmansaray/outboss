<?php

use App\Domain\People\Services\DeleteAccount;
use App\Models\Account;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\Person;
use App\Models\ProspectSource;
use App\Models\User;
use App\Models\WorkspaceMembership;
use League\Csv\Reader;

it('creates an account with a normalised domain', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('accounts.store'), [
            'name' => 'Example',
            'website' => 'https://www.Example.COM/about',
            'notes' => 'A note',
            'tags' => ['saas', 'saas', 'gtm'],
        ])
        ->assertRedirect(route('prospects.index', ['view' => 'accounts']));

    $account = Account::query()->where('workspace_id', $user->currentWorkspace()->id)->first();

    expect($account)->not->toBeNull()
        ->and($account->domain_normalized)->toBe('example.com')
        ->and($account->website)->toBe('https://www.Example.COM/about')
        ->and($account->tags)->toBe(['saas', 'gtm'])
        ->and($account->source)->toBe(Account::SOURCE_MANUAL)
        ->and($account->created_by_user_id)->toBe($user->id);
});

it('rejects a duplicate domain in the same workspace and names the existing account', function () {
    $user = User::factory()->create();
    Account::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'name' => 'Acme',
        'domain_normalized' => 'acme.com',
    ]);

    $this->actingAs($user)
        ->from(route('prospects.index'))
        ->post(route('accounts.store'), [
            'name' => 'Acme Two',
            'website' => 'https://acme.com',
        ])
        ->assertRedirect(route('prospects.index'))
        ->assertSessionHasErrors([
            'website' => 'Another account, ‘Acme’, already uses acme.com.',
        ]);

    expect(Account::query()->where('workspace_id', $user->currentWorkspace()->id)->count())->toBe(1);
});

it('allows the same domain in a different workspace', function () {
    $alpha = User::factory()->create();
    $beta = User::factory()->create();
    Account::factory()->create([
        'workspace_id' => $alpha->currentWorkspace()->id,
        'domain_normalized' => 'acme.com',
        'website' => 'https://acme.com',
    ]);

    $this->actingAs($beta)
        ->post(route('accounts.store'), [
            'name' => 'Acme',
            'website' => 'https://acme.com',
        ])
        ->assertRedirect(route('prospects.index', ['view' => 'accounts']));

    expect(Account::query()->where('domain_normalized', 'acme.com')->count())->toBe(2);
});

it('warns on a name-only clash and creates after confirmation', function () {
    $user = User::factory()->create();
    Account::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'name' => 'Acme',
        'website' => null,
        'domain_normalized' => null,
    ]);

    $this->actingAs($user)
        ->from(route('prospects.index'))
        ->post(route('accounts.store'), [
            'name' => 'acme',
        ])
        ->assertRedirect(route('prospects.index'))
        ->assertSessionHasErrors(['name', 'confirmed_duplicate']);

    $this->actingAs($user)
        ->post(route('accounts.store'), [
            'name' => 'acme',
            'confirmed_duplicate' => true,
        ])
        ->assertRedirect(route('prospects.index', ['view' => 'accounts']));

    expect(Account::query()->where('workspace_id', $user->currentWorkspace()->id)->count())->toBe(2);
});

it('does not rewrite recipient company snapshots when an account is renamed', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'name' => 'Acme',
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
        'account_id' => $account->id,
        'company' => 'Acme',
    ]);

    $this->actingAs($user)
        ->patch(route('accounts.update', $account), [
            'name' => 'Acme Ltd',
            'website' => $account->website,
        ])
        ->assertRedirect(route('prospects.index', ['view' => 'accounts']));

    expect($account->fresh()->name)->toBe('Acme Ltd')
        ->and($recipient->fresh()->company)->toBe('Acme')
        ->and($recipient->fresh()->account_id)->toBe($account->id);
});

it('deletes an account without touching recipient history', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
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
        'account_id' => $account->id,
        'company' => 'Acme',
        'email_address' => 'maya@acme.test',
        'email_normalized' => 'maya@acme.test',
    ]);

    $this->actingAs($user)
        ->delete(route('accounts.destroy', $account))
        ->assertRedirect(route('prospects.index', ['view' => 'accounts']))
        ->assertSessionHas('success', 'Account deleted; 1 people kept their campaign history.');

    expect(Account::query()->whereKey($account->id)->exists())->toBeFalse()
        ->and($recipient->fresh()->account_id)->toBeNull()
        ->and($recipient->fresh()->company)->toBe('Acme')
        ->and($recipient->fresh()->email_address)->toBe('maya@acme.test');
});

it('lets a workspace member delete an Account when no Objective run or plan is affected', function () {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $member = User::factory()->create();
    WorkspaceMembership::query()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $member->id,
        'role' => 'member',
    ]);
    $account = Account::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($member)
        ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $workspace->id])
        ->delete(route('accounts.destroy', $account))
        ->assertRedirect(route('prospects.index', ['view' => 'accounts']));

    expect(Account::query()->whereKey($account->id)->exists())->toBeFalse()
        ->and(app(DeleteAccount::class)->handle($account, $member))->toBe(0);
});

it('exports only the current workspace accounts as csv', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $account = Account::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'name' => 'Northstar',
        'website' => 'https://northstar.studio',
        'domain_normalized' => 'northstar.studio',
        'notes' => 'Design',
        'tags' => ['design', 'saas'],
        'source' => Account::SOURCE_MANUAL,
    ]);
    Person::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'account_id' => $account->id,
        'email_address' => 'maya@northstar.studio',
        'email_normalized' => 'maya@northstar.studio',
    ]);
    Account::factory()->create([
        'workspace_id' => $other->currentWorkspace()->id,
        'name' => 'Secret Co',
        'domain_normalized' => 'secret.test',
    ]);

    $response = $this->actingAs($user)->get(route('accounts.export'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toStartWith('text/csv');

    $csv = $response->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    expect($lines[0])->toBe('name,website,domain,notes,tags,source,source_count,source_ids,source_names,latest_observed_at,source_omission_reason,people_count,created_at,updated_at')
        ->and($csv)->toContain('Northstar')
        ->and($csv)->toContain('northstar.studio')
        ->and($csv)->toContain('design|saas')
        ->and($csv)->toContain(',1,')
        ->and($csv)->not->toContain('Secret Co');
});

it('exports policy-safe provenance for accounts created through the application', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('accounts.store'), [
            'name' => 'Provenance Co',
            'website' => 'https://provenance.test',
        ])
        ->assertRedirect(route('prospects.index', ['view' => 'accounts']));

    $source = ProspectSource::query()
        ->where('workspace_id', $user->currentWorkspace()->id)
        ->where('system_key', 'manual')
        ->firstOrFail();
    $response = $this->actingAs($user)->get(route('accounts.export'));
    $csv = Reader::createFromString($response->streamedContent());
    $csv->setHeaderOffset(0);
    $record = iterator_to_array($csv->getRecords(), false)[0];

    $response->assertHeader('Cache-Control', 'no-store, private');
    $this->assertDatabaseHas('workspace_data_export_events', [
        'workspace_id' => $user->currentWorkspace()->id,
        'user_id' => $user->id,
        'export_type' => 'accounts',
    ]);
    expect($record['source_count'])->toBe('1')
        ->and($record['source_ids'])->toBe((string) $source->id)
        ->and($record['source_names'])->toBe('Manual')
        ->and($record['latest_observed_at'])->not->toBe('')
        ->and($record['source_omission_reason'])->toBe('');
});
