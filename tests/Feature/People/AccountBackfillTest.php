<?php

use App\Domain\People\Services\BackfillAccountsFromRecipients;
use App\Models\Account;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\User;

function seedRecipient(User $user, string $email, string $company): CampaignRecipient
{
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $mailbox = Mailbox::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $user->currentWorkspace()->id,
    ]);

    return CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $user->currentWorkspace()->id,
        'mailbox_id' => $mailbox->id,
        'email_address' => $email,
        'email_normalized' => strtolower($email),
        'company' => $company,
    ]);
}

it('merges case-insensitive company names and isolates workspaces', function () {
    $alpha = User::factory()->create();
    $beta = User::factory()->create();

    seedRecipient($alpha, 'one@acme.test', 'Acme');
    seedRecipient($alpha, 'two@acme.test', 'acme');
    seedRecipient($beta, 'other@acme.test', 'Acme');

    (new BackfillAccountsFromRecipients)->handle();

    $alphaAccounts = Account::query()->where('workspace_id', $alpha->currentWorkspace()->id)->get();
    $betaAccounts = Account::query()->where('workspace_id', $beta->currentWorkspace()->id)->get();

    expect($alphaAccounts)->toHaveCount(1)
        ->and($alphaAccounts->first()->name)->toBeIn(['Acme', 'acme'])
        ->and($alphaAccounts->first()->source)->toBe(Account::SOURCE_CAMPAIGN_BACKFILL)
        ->and($alphaAccounts->first()->domain_normalized)->toBe('acme.test')
        ->and($betaAccounts)->toHaveCount(1)
        ->and($betaAccounts->first()->id)->not->toBe($alphaAccounts->first()->id)
        ->and(CampaignRecipient::query()->where('workspace_id', $alpha->currentWorkspace()->id)->pluck('account_id')->unique()->all())
        ->toBe([$alphaAccounts->first()->id]);
});

it('sets a domain only when every member shares one corporate email domain', function () {
    $user = User::factory()->create();

    seedRecipient($user, 'a@northstar.studio', 'Northstar');
    seedRecipient($user, 'b@northstar.studio', 'Northstar');
    seedRecipient($user, 'c@one.test', 'Mixed');
    seedRecipient($user, 'd@two.test', 'Mixed');
    seedRecipient($user, 'e@gmail.com', 'Personal');
    seedRecipient($user, 'f@gmail.com', 'Personal');
    seedRecipient($user, 'g@empty.test', '');

    (new BackfillAccountsFromRecipients)->handle();

    $accounts = Account::query()
        ->where('workspace_id', $user->currentWorkspace()->id)
        ->get()
        ->keyBy('name');

    expect($accounts)->toHaveCount(3)
        ->and($accounts['Northstar']->domain_normalized)->toBe('northstar.studio')
        ->and($accounts['Mixed']->domain_normalized)->toBeNull()
        ->and($accounts['Personal']->domain_normalized)->toBeNull()
        ->and(CampaignRecipient::query()->where('email_normalized', 'g@empty.test')->value('account_id'))->toBeNull();
});

it('reuses an existing workspace account when the domain already matches', function () {
    $user = User::factory()->create();
    $existing = Account::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'name' => 'Acme Ltd',
        'domain_normalized' => 'acme.com',
        'source' => Account::SOURCE_MANUAL,
    ]);

    seedRecipient($user, 'maya@acme.com', 'Acme');

    (new BackfillAccountsFromRecipients)->handle();

    expect(Account::query()->where('workspace_id', $user->currentWorkspace()->id)->count())->toBe(1)
        ->and(CampaignRecipient::query()->where('email_normalized', 'maya@acme.com')->value('account_id'))->toBe($existing->id);
});

it('is safe to run twice', function () {
    $user = User::factory()->create();
    seedRecipient($user, 'maya@acme.com', 'Acme');

    $service = new BackfillAccountsFromRecipients;
    $service->handle();
    $service->handle();

    expect(Account::query()->where('workspace_id', $user->currentWorkspace()->id)->count())->toBe(1);
});
