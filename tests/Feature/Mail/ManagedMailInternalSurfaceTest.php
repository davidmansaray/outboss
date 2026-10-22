<?php

use App\Domain\Mail\Contracts\ManagedMailboxProvisioner;
use App\Domain\Mail\Enums\AuthScheme;
use App\Domain\Mail\Enums\ConnectionKind;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\MailExperimentDomainStrategy;
use App\Domain\Mail\Enums\MailExperimentStatus;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\ManagedMailboxAccountStatus;
use App\Domain\Mail\Enums\ManagedMailDomainKind;
use App\Domain\Mail\Enums\ManagedMailDomainStatus;
use App\Domain\Mail\Providers\FakeManagedMailboxProvisioner;
use App\Domain\Mail\Services\FakeDnsResolver;
use App\Domain\Mail\Services\FakeDomainAuthChecker;
use App\Models\Campaign;
use App\Models\Mailbox;
use App\Models\MailExperiment;
use App\Models\ManagedMailboxAccount;
use App\Models\ManagedMailDomain;
use App\Models\Person;
use App\Models\ProviderConnection;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;

function configureManagedInternalSurface(User $owner): void
{
    config()->set('outboss.managed_mail.default_instance', 'internal');
    config()->set('outboss.managed_mail.instances.internal', [
        'base_url' => 'https://mx.fake-managed.test',
        'admin_api_key' => 'test-only-secret',
        'verify_tls' => true,
        'timeout_seconds' => 15,
    ]);
}

/** @return list<Mailbox> */
function internalSurfaceManagedMailboxSet(MailExperiment $experiment, MailExperimentDomainStrategy $strategy, string $prefix): array
{
    $domainKind = $strategy === MailExperimentDomainStrategy::Subdomain
        ? ManagedMailDomainKind::Subdomain
        : ManagedMailDomainKind::Root;
    $domain = $strategy === MailExperimentDomainStrategy::Subdomain
        ? ManagedMailDomain::factory()->create([
            'workspace_id' => $experiment->workspace_id,
            'created_by_user_id' => $experiment->created_by_user_id,
            'domain' => $prefix.'-'.$experiment->id.'.example.com',
            'organisational_domain' => 'example.com',
            'domain_kind' => $domainKind,
            'provider' => MailProviderName::Stalwart,
            'status' => ManagedMailDomainStatus::Active,
            'verified_at' => now()->subDay(),
            'last_verified_at' => now()->subHour(),
        ])
        : null;

    $mailboxes = [];
    foreach (range(1, 3) as $position) {
        $mailDomain = $domain ?? ManagedMailDomain::factory()->create([
            'workspace_id' => $experiment->workspace_id,
            'created_by_user_id' => $experiment->created_by_user_id,
            'domain' => $prefix.'-'.$position.'-'.$experiment->id.'.example.com',
            'organisational_domain' => 'example.com',
            'domain_kind' => $domainKind,
            'provider' => MailProviderName::Stalwart,
            'status' => ManagedMailDomainStatus::Active,
            'verified_at' => now()->subDay(),
            'last_verified_at' => now()->subHour(),
        ]);
        $connection = ProviderConnection::factory()->create([
            'user_id' => $experiment->created_by_user_id,
            'workspace_id' => $experiment->workspace_id,
            'provider' => MailProviderName::Stalwart,
            'connection_kind' => ConnectionKind::Managed,
            'auth_scheme' => AuthScheme::AppPassword,
            'access_token' => null,
            'refresh_token' => null,
            'credential_payload' => [
                'username' => $prefix.$position.'@'.$mailDomain->domain,
                'password' => 'test-only-password-'.$position,
                'version' => 1,
            ],
            'status' => ConnectionStatus::Connected,
        ]);
        $mailbox = Mailbox::factory()->create([
            'user_id' => $experiment->created_by_user_id,
            'workspace_id' => $experiment->workspace_id,
            'provider_connection_id' => $connection->id,
            'email_address' => $prefix.$position.'@'.$mailDomain->domain,
            'status' => ConnectionStatus::Connected,
            'provider_metadata' => ['managed_mail_domain_id' => $mailDomain->id],
        ]);
        ManagedMailboxAccount::factory()->create([
            'managed_mail_domain_id' => $mailDomain->id,
            'mailbox_id' => $mailbox->id,
            'local_part' => $prefix.$position,
            'status' => ManagedMailboxAccountStatus::Active,
            'provisioned_at' => now()->subDay(),
            'last_verified_at' => now()->subHour(),
        ]);
        $mailboxes[] = $mailbox;
    }

    return $mailboxes;
}

it('keeps internal managed routes unavailable to members but available to verified owners', function () {
    $owner = User::factory()->create();
    configureManagedInternalSurface($owner);
    $workspace = $owner->currentWorkspace();
    $member = User::factory()->create();
    $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);

    $this->actingAs($member)
        ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $workspace->id])
        ->get(route('managed-mail.index'))
        ->assertNotFound();

    $this->actingAs($member)
        ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $workspace->id])
        ->get(route('mail-experiments.index'))
        ->assertNotFound();

    $this->actingAs($member)
        ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $workspace->id])
        ->get('/settings/mailboxes/managed-mail')
        ->assertNotFound();

    $this->actingAs($owner)
        ->get(route('managed-mail.index'))
        ->assertOk();
});

it('redirects the nested mailboxes URL to the managed mail surface for an eligible owner', function () {
    $owner = User::factory()->create();
    configureManagedInternalSurface($owner);

    $this->actingAs($owner)
        ->get('/settings/mailboxes/managed-mail')
        ->assertRedirect(route('managed-mail.index'));
});

it('provisions, verifies, and creates managed mailboxes without exposing credentials', function () {
    Bus::fake();
    FakeManagedMailboxProvisioner::reset();
    $owner = User::factory()->create();
    configureManagedInternalSurface($owner);
    FakeDomainAuthChecker::$results['outreach.example.com'] = [
        'spf' => 'pass',
        'dmarc' => 'pass',
        'dkim' => 'pass',
    ];
    FakeDnsResolver::set('outreach.example.com', ['mx.fake-managed.test']);
    app()->instance(ManagedMailboxProvisioner::class, new FakeManagedMailboxProvisioner);

    $this->actingAs($owner)
        ->post(route('managed-mail.domains.store'), ['domain' => 'Outreach.Example.com'])
        ->assertRedirect(route('managed-mail.index'));
    $domain = ManagedMailDomain::query()->firstOrFail();
    expect($domain->status->value)->toBe('pending_dns');

    $this->actingAs($owner)
        ->post(route('managed-mail.domains.verify', $domain->id))
        ->assertRedirect(route('managed-mail.index'));
    expect($domain->fresh()->status->value)->toBe('active');

    $this->actingAs($owner)
        ->post(route('managed-mail.mailboxes.store', $domain->id), [
            'local_part' => 'sales',
            'display_name' => "Sales\nTeam",
        ])
        ->assertRedirect(route('managed-mail.index'));

    $account = ManagedMailboxAccount::query()->firstOrFail()->fresh(['mailbox.connection']);
    expect($account->email_address)->toBe('sales@outreach.example.com')
        ->and($account->status->value)->toBe('provisioning')
        ->and($account->mailbox?->connection?->toArray())->not->toHaveKey('credential_payload')
        ->and(response()->json(['account' => $account])->getContent())->not->toContain('test-only-secret');
});

it('rotates and suspends managed mailbox credentials through owner-only actions', function () {
    FakeManagedMailboxProvisioner::reset();
    $owner = User::factory()->create();
    configureManagedInternalSurface($owner);
    app()->instance(ManagedMailboxProvisioner::class, new FakeManagedMailboxProvisioner);
    FakeDomainAuthChecker::$results['outreach.example.com'] = [
        'spf' => 'pass',
        'dmarc' => 'pass',
        'dkim' => 'pass',
    ];
    FakeDnsResolver::set('outreach.example.com', ['mx.fake-managed.test']);

    $this->actingAs($owner)->post(route('managed-mail.domains.store'), ['domain' => 'outreach.example.com']);
    $domain = ManagedMailDomain::query()->firstOrFail();
    $this->actingAs($owner)->post(route('managed-mail.domains.verify', $domain->id));
    $this->actingAs($owner)->post(route('managed-mail.mailboxes.store', $domain->id), ['local_part' => 'sales']);
    $account = ManagedMailboxAccount::query()->firstOrFail();
    $account->fresh()->markActive();
    $oldVersion = $account->credential_version;

    $this->actingAs($owner)
        ->post(route('managed-mail.mailboxes.rotate', $account->id))
        ->assertRedirect(route('managed-mail.index'));
    expect($account->fresh()->credential_version)->toBe($oldVersion + 1);

    $this->actingAs($owner)
        ->post(route('managed-mail.mailboxes.suspend', $account->id))
        ->assertRedirect(route('managed-mail.index'));
    expect($account->fresh()->status->value)->toBe('suspended')
        ->and($account->fresh()->mailbox?->sending_paused_reason)->toBe('managed_mailbox_suspended');

    $this->actingAs($owner)
        ->delete(route('managed-mail.mailboxes.destroy', $account->id), ['confirm' => true])
        ->assertRedirect(route('managed-mail.index'));
    expect($account->fresh()->status->value)->toBe('deleted')
        ->and(FakeManagedMailboxProvisioner::$store['accounts'])->toBe([]);
});

it('requires server-side confirmation before deleting a remote managed mailbox', function () {
    FakeManagedMailboxProvisioner::reset();
    $owner = User::factory()->create();
    configureManagedInternalSurface($owner);
    app()->instance(ManagedMailboxProvisioner::class, new FakeManagedMailboxProvisioner);
    FakeDomainAuthChecker::$results['outreach.example.com'] = [
        'spf' => 'pass',
        'dmarc' => 'pass',
        'dkim' => 'pass',
    ];
    FakeDnsResolver::set('outreach.example.com', ['mx.fake-managed.test']);

    $this->actingAs($owner)->post(route('managed-mail.domains.store'), ['domain' => 'outreach.example.com']);
    $domain = ManagedMailDomain::query()->firstOrFail();
    $this->actingAs($owner)->post(route('managed-mail.domains.verify', $domain->id));
    $this->actingAs($owner)->post(route('managed-mail.mailboxes.store', $domain->id), ['local_part' => 'sales']);
    $account = ManagedMailboxAccount::query()->firstOrFail();

    $this->actingAs($owner)
        ->delete(route('managed-mail.mailboxes.destroy', $account->id))
        ->assertSessionHasErrors('confirm');

    expect($account->fresh()->status->value)->not->toBe('deleted')
        ->and(FakeManagedMailboxProvisioner::$store['accounts'])->toHaveCount(1);
});

it('retires an empty managed domain locally and on its owning mail server', function () {
    FakeManagedMailboxProvisioner::reset();
    $owner = User::factory()->create();
    configureManagedInternalSurface($owner);
    app()->instance(ManagedMailboxProvisioner::class, new FakeManagedMailboxProvisioner);

    $this->actingAs($owner)
        ->post(route('managed-mail.domains.store'), ['domain' => 'retire.example.com']);
    $domain = ManagedMailDomain::query()->firstOrFail();

    $this->actingAs($owner)
        ->delete(route('managed-mail.domains.destroy', $domain->id), ['confirm' => true])
        ->assertRedirect(route('managed-mail.index'));

    expect(ManagedMailDomain::query()->whereKey($domain->id)->exists())->toBeFalse()
        ->and(FakeManagedMailboxProvisioner::$store['domains'])->toBe([]);
});

it('refuses to retire a managed domain until every mailbox is deleted', function () {
    FakeManagedMailboxProvisioner::reset();
    $owner = User::factory()->create();
    configureManagedInternalSurface($owner);
    app()->instance(ManagedMailboxProvisioner::class, new FakeManagedMailboxProvisioner);

    $this->actingAs($owner)
        ->post(route('managed-mail.domains.store'), ['domain' => 'in-use.example.com']);
    $domain = ManagedMailDomain::query()->firstOrFail();
    ManagedMailboxAccount::factory()->create([
        'managed_mail_domain_id' => $domain->id,
        'status' => ManagedMailboxAccountStatus::Active,
    ]);

    $this->actingAs($owner)
        ->delete(route('managed-mail.domains.destroy', $domain->id), ['confirm' => true])
        ->assertSessionHas('error', 'Delete every mailbox on this domain before retiring it.');

    expect($domain->fresh())->not->toBeNull()
        ->and(FakeManagedMailboxProvisioner::$store['domains'])->toHaveCount(1);
});

it('returns a complete multiline DNS zone file to the managed mail screen', function () {
    $owner = User::factory()->create();
    configureManagedInternalSurface($owner);
    $zoneFile = implode("\n", array_map(
        fn (int $line): string => "selector{$line}._domainkey.example.com. IN TXT \"v=DKIM1; p={$line}\"",
        range(1, 80),
    ));
    $domain = ManagedMailDomain::factory()->create([
        'workspace_id' => $owner->currentWorkspace()->id,
        'created_by_user_id' => $owner->id,
        'dns_records' => ['zone_file' => $zoneFile],
    ]);

    expect(strlen($zoneFile))->toBeGreaterThan(1000);

    $this->actingAs($owner)
        ->get(route('managed-mail.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('domains.0.id', $domain->id)
            ->where('domains.0.dns_records.zone_file', $zoneFile));
});

it('prepares, balances, and freezes a workspace-scoped experiment', function () {
    $owner = User::factory()->create();
    configureManagedInternalSurface($owner);
    $workspace = $owner->currentWorkspace();
    $people = Person::factory()->count(4)->create(['workspace_id' => $workspace->id]);

    $this->actingAs($owner)
        ->post(route('mail-experiments.store'), [
            'name' => 'Domain strategy pilot',
            'hypothesis' => 'A branded subdomain will perform comparably to separate roots.',
        ])
        ->assertRedirect();
    $experiment = MailExperiment::query()->firstOrFail();
    $cohorts = $experiment->cohorts()->orderBy('key')->get();
    $mailboxesA = internalSurfaceManagedMailboxSet($experiment, MailExperimentDomainStrategy::Subdomain, 'surface-subdomain');
    $mailboxesB = internalSurfaceManagedMailboxSet($experiment, MailExperimentDomainStrategy::SeparateRootDomains, 'surface-root');
    $template = Campaign::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $workspace->id,
    ]);

    $this->actingAs($owner)->post(route('mail-experiments.cohorts.mailboxes', [$experiment->id, $cohorts[0]->id]), ['mailbox_ids' => collect($mailboxesA)->pluck('id')->all()])->assertRedirect();
    $this->actingAs($owner)->post(route('mail-experiments.cohorts.mailboxes', [$experiment->id, $cohorts[1]->id]), ['mailbox_ids' => collect($mailboxesB)->pluck('id')->all()])->assertRedirect();

    $this->actingAs($owner)
        ->post(route('mail-experiments.prepare', $experiment->id), [
            'template_campaign_id' => $template->id,
        ])
        ->assertSessionHasErrors([
            'compliance_review_confirmed',
            'legal_review_confirmed',
        ]);
    expect($experiment->assignments()->count())->toBe(0);

    $this->actingAs($owner)
        ->post(route('mail-experiments.prepare', $experiment->id), [
            'template_campaign_id' => $template->id,
            'compliance_review_confirmed' => true,
            'legal_review_confirmed' => true,
        ])
        ->assertRedirect();

    $approvalEvidence = $experiment->fresh()->configuration['approval_evidence'];
    expect($approvalEvidence['compliance_review_confirmed'])->toBeTrue()
        ->and($approvalEvidence['legal_review_confirmed'])->toBeTrue()
        ->and($approvalEvidence['source'])->toBe('http')
        ->and($approvalEvidence['confirmed_by_user_id'])->toBe($owner->id)
        ->and($experiment->assignments()->whereNull('exclusion_reason')->count())->toBe(4);

    $this->actingAs($owner)
        ->postJson(route('mail-experiments.balance', $experiment->id))
        ->assertOk()
        ->assertJsonPath('balance.assignment_count', 4);

    $this->actingAs($owner)
        ->post(route('mail-experiments.freeze', $experiment->id))
        ->assertRedirect();

    expect($experiment->fresh()->frozen_at)->not->toBeNull()
        ->and($experiment->fresh()->status->value)->toBe('ready')
        ->and($people)->toHaveCount(4);

    $this->actingAs($owner)
        ->post(route('mail-experiments.launch', $experiment->id))
        ->assertRedirect();
    expect($experiment->fresh()->status->value)->toBe('running');

    $this->actingAs($owner)
        ->post(route('mail-experiments.pause', $experiment->id))
        ->assertRedirect();
    expect($experiment->fresh()->status->value)->toBe('paused');

    $this->actingAs($owner)
        ->post(route('mail-experiments.resume', $experiment->id))
        ->assertRedirect();
    expect($experiment->fresh()->status->value)->toBe('running');
});

it('rejects resume outside the paused state and keeps the route owner-gated', function () {
    $owner = User::factory()->create();
    configureManagedInternalSurface($owner);
    $workspace = $owner->currentWorkspace();
    $experiment = MailExperiment::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by_user_id' => $owner->id,
        'status' => MailExperimentStatus::Draft,
    ]);
    $member = User::factory()->create();
    $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);

    $this->actingAs($owner)
        ->post(route('mail-experiments.resume', $experiment->id))
        ->assertRedirect(route('mail-experiments.show', $experiment->id))
        ->assertSessionHas('error', 'Cannot transition mail experiment from draft to running.');
    expect($experiment->fresh()->status->value)->toBe('draft');

    $this->actingAs($member)
        ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $workspace->id])
        ->post(route('mail-experiments.resume', $experiment->id))
        ->assertNotFound();
});

it('requires explicit compliance and legal confirmations for CLI preparation', function () {
    $owner = User::factory()->create();
    configureManagedInternalSurface($owner);
    $workspace = $owner->currentWorkspace();
    Person::factory()->count(2)->create(['workspace_id' => $workspace->id]);
    $experiment = MailExperiment::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by_user_id' => $owner->id,
    ]);
    $experiment->cohorts()->createMany([
        ['key' => 'a', 'label' => 'Subdomain', 'domain_strategy' => MailExperimentDomainStrategy::Subdomain],
        ['key' => 'b', 'label' => 'Separate roots', 'domain_strategy' => MailExperimentDomainStrategy::SeparateRootDomains],
    ]);

    $this->artisan('mail-experiment:prepare', [
        'experiment' => $experiment->id,
        '--workspace' => $workspace->id,
    ])->assertExitCode(2);
    expect($experiment->assignments()->count())->toBe(0);

    $this->artisan('mail-experiment:prepare', [
        'experiment' => $experiment->id,
        '--workspace' => $workspace->id,
        '--confirm-compliance-review' => true,
        '--confirm-legal-review' => true,
        '--approval-reference' => 'LEGAL-42',
    ])->assertSuccessful();

    $approvalEvidence = $experiment->fresh()->configuration['approval_evidence'];
    expect($approvalEvidence['compliance_review_confirmed'])->toBeTrue()
        ->and($approvalEvidence['legal_review_confirmed'])->toBeTrue()
        ->and($approvalEvidence['source'])->toBe('cli')
        ->and($approvalEvidence['reference'])->toBe('LEGAL-42')
        ->and($experiment->assignments()->whereNull('exclusion_reason')->count())->toBe(2);
});

it('does not resolve another workspace object after the capability gate', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    configureManagedInternalSurface($owner);
    $domain = ManagedMailDomain::factory()->create(['created_by_user_id' => $owner->id, 'workspace_id' => $owner->currentWorkspace()->id]);

    $this->actingAs($other)
        ->post(route('managed-mail.domains.verify', $domain->id))
        ->assertNotFound();
});
