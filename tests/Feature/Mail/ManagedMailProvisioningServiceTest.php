<?php

use App\Domain\Mail\Enums\AuthScheme;
use App\Domain\Mail\Enums\ConnectionKind;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\ManagedMailboxAccountStatus;
use App\Domain\Mail\Enums\ManagedMailDomainStatus;
use App\Domain\Mail\Exceptions\ManagedMailProvisioningException;
use App\Domain\Mail\Providers\FakeManagedMailboxProvisioner;
use App\Domain\Mail\Services\DeleteManagedMailbox;
use App\Domain\Mail\Services\MailboxSenderIdentityService;
use App\Domain\Mail\Services\ManagedMailCapability;
use App\Domain\Mail\Services\ManagedMailEventRecorder;
use App\Domain\Mail\Services\ProvisionManagedDomain;
use App\Domain\Mail\Services\ProvisionManagedMailbox;
use App\Jobs\Mail\InitialSyncMailbox;
use App\Models\Mailbox;
use App\Models\MailboxSyncState;
use App\Models\ManagedMailboxAccount;
use App\Models\ManagedMailDomain;
use App\Models\ManagedMailEvent;
use App\Models\ProviderConnection;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;

function managedProvisioningOwner(): User
{
    $owner = User::factory()->create();

    config()->set('outboss.managed_mail.default_instance', 'internal');
    config()->set('outboss.managed_mail.instances.internal', [
        'base_url' => 'https://mx-api.example.test',
        'admin_api_key' => 'not-used-by-fake',
        'verify_tls' => true,
        'timeout_seconds' => 15,
    ]);

    return $owner;
}

function managedDomainProvisioner(): ProvisionManagedDomain
{
    return new ProvisionManagedDomain(
        new FakeManagedMailboxProvisioner,
        new ManagedMailCapability,
        new ManagedMailEventRecorder,
    );
}

function managedMailboxProvisioner(): ProvisionManagedMailbox
{
    return new ProvisionManagedMailbox(
        new FakeManagedMailboxProvisioner,
        new ManagedMailCapability,
        new ManagedMailEventRecorder,
        new MailboxSenderIdentityService,
    );
}

it('provisions a managed domain into pending DNS exactly once', function () {
    FakeManagedMailboxProvisioner::reset();
    $owner = managedProvisioningOwner();
    $workspace = $owner->currentWorkspace();
    $service = managedDomainProvisioner();

    $first = $service->handle($workspace, $owner, ' HTTPS://Outreach.Example.com/path ');
    $second = $service->provision($workspace, $owner, 'outreach.example.com');

    expect($first->id)->toBe($second->id)
        ->and($second->status)->toBe(ManagedMailDomainStatus::PendingDns)
        ->and($second->remote_domain_id)->not->toBeNull()
        ->and($second->dns_records)->toHaveKey('mx')
        ->and(FakeManagedMailboxProvisioner::$store['domains'])->toHaveCount(1)
        ->and(ManagedMailEvent::query()->where('event', 'domain_provisioned')->count())->toBe(1);
});

it('rejects domain claims from another workspace before any remote call', function () {
    FakeManagedMailboxProvisioner::reset();
    $owner = managedProvisioningOwner();
    $workspace = $owner->currentWorkspace();
    $existing = ManagedMailDomain::factory()->create([
        'domain' => 'shared.example.com',
        'workspace_id' => $workspace->id,
        'created_by_user_id' => $owner->id,
    ]);
    $other = User::factory()->create();
    $service = managedDomainProvisioner();

    expect(fn () => $service->handle($other->currentWorkspace(), $other, $existing->domain))
        ->toThrow(ValidationException::class)
        ->and(FakeManagedMailboxProvisioner::$store['domains'])->toBe([]);
});

it('enforces domain ownership per managed instance while permitting isolated instances', function () {
    FakeManagedMailboxProvisioner::reset();
    $owner = managedProvisioningOwner();
    $domain = managedDomainProvisioner()->handle(
        $owner->currentWorkspace(),
        $owner,
        'shared.example.com',
        'internal',
    );
    $other = User::factory()->create();
    $third = User::factory()->create();
    config()->set('outboss.managed_mail.instances.isolated', [
        'base_url' => 'https://isolated-mx.example.test',
        'admin_api_key' => 'not-used-by-fake',
    ]);

    $isolated = managedDomainProvisioner()->handle(
        $other->currentWorkspace(),
        $other,
        $domain->domain,
        'isolated',
    );

    expect($isolated->managed_instance_key)->toBe('isolated')
        ->and($isolated->workspace_id)->toBe($other->currentWorkspace()->id)
        ->and(FakeManagedMailboxProvisioner::$store['domains'])->toHaveCount(2)
        ->and(fn () => ManagedMailDomain::factory()->create([
            'workspace_id' => $third->currentWorkspace()->id,
            'created_by_user_id' => $third->id,
            'managed_instance_key' => 'internal',
            'domain' => $domain->domain,
        ]))->toThrow(QueryException::class);
});

it('creates one managed mailbox, encrypted connection, sync states, and initial sync job', function () {
    FakeManagedMailboxProvisioner::reset();
    Bus::fake();
    $owner = managedProvisioningOwner();
    $workspace = $owner->currentWorkspace();
    $domain = managedDomainProvisioner()->handle($workspace, $owner, 'outreach.example.com');
    $domain->markVerified()->activate();

    $account = managedMailboxProvisioner()->handle(
        $workspace,
        $owner,
        $domain->fresh(),
        'Sales.Team',
        "Sales\nTeam",
    );
    $connection = ProviderConnection::query()->whereKey($account->mailbox->provider_connection_id)->firstOrFail();
    $mailbox = Mailbox::query()->whereKey($account->mailbox_id)->firstOrFail();

    expect($account->status)->toBe(ManagedMailboxAccountStatus::Provisioning)
        ->and($account->remote_account_id)->not->toBeNull()
        ->and($mailbox->email_address)->toBe('sales.team@outreach.example.com')
        ->and($connection->connection_kind)->toBe(ConnectionKind::Managed)
        ->and($connection->auth_scheme)->toBe(AuthScheme::AppPassword)
        ->and($connection->hasUsableCredentials())->toBeTrue()
        ->and($connection->toArray())->not->toHaveKey('credential_payload')
        ->and(MailboxSyncState::query()->where('mailbox_id', $mailbox->id)->count())->toBe(2)
        ->and($mailbox->sender_identities)->toHaveCount(1)
        ->and($mailbox->display_name)->toBe('Sales Team');

    Bus::assertDispatched(InitialSyncMailbox::class, fn (InitialSyncMailbox $job): bool => $job->mailboxId === $mailbox->id);
});

it('replays a completed mailbox provisioning request without rotating its credential', function () {
    FakeManagedMailboxProvisioner::reset();
    Bus::fake();
    $owner = managedProvisioningOwner();
    $workspace = $owner->currentWorkspace();
    $domain = managedDomainProvisioner()->handle($workspace, $owner, 'outreach.example.com');
    $domain->markVerified()->activate();
    $service = managedMailboxProvisioner();

    $first = $service->handle($workspace, $owner, $domain, 'sales');
    $second = $service->handle($workspace, $owner, $domain, 'SALES');

    expect($first->id)->toBe($second->id)
        ->and($first->mailbox_id)->toBe($second->mailbox_id)
        ->and(FakeManagedMailboxProvisioner::$store['accounts'])->toHaveCount(1)
        ->and(ProviderConnection::query()->where('provider_user_id', $first->remote_account_id)->count())->toBe(1)
        ->and(ManagedMailEvent::query()->where('event', 'mailbox_provisioned')->count())->toBe(1);
});

it('serializes a competing request across the remote create and credential persist workflow', function () {
    FakeManagedMailboxProvisioner::reset();
    Bus::fake();
    $owner = managedProvisioningOwner();
    $workspace = $owner->currentWorkspace();
    $domain = managedDomainProvisioner()->handle($workspace, $owner, 'serialized.example.com');
    $domain->markVerified()->activate();
    $service = managedMailboxProvisioner();
    config()->set('outboss.managed_mail.provisioning_lock_wait_seconds', 0);
    $competing = null;

    FakeManagedMailboxProvisioner::$onMailboxCreate = function () use (
        $service,
        $workspace,
        $owner,
        $domain,
        &$competing,
    ): void {
        try {
            $service->handle($workspace, $owner, $domain, 'sales');
        } catch (Throwable $exception) {
            $competing = $exception;
        }
    };

    $account = $service->handle($workspace, $owner, $domain, 'sales');

    expect($competing)
        ->toBeInstanceOf(ManagedMailProvisioningException::class)
        ->and($competing->errorCode)->toBe('mailbox_provisioning_in_progress')
        ->and($account->fresh()->mailbox_id)->not->toBeNull()
        ->and(FakeManagedMailboxProvisioner::$store['accounts'])->toHaveCount(1)
        ->and(ManagedMailEvent::query()->where('event', 'mailbox_provisioned')->count())->toBe(1);
});

it('preserves a failed local account for safe retry and does not expose provider secrets', function () {
    FakeManagedMailboxProvisioner::reset();
    FakeManagedMailboxProvisioner::$failMailboxCreate = true;
    $owner = managedProvisioningOwner();
    $workspace = $owner->currentWorkspace();
    $domain = managedDomainProvisioner()->handle($workspace, $owner, 'outreach.example.com');
    $domain->markVerified()->activate();

    expect(fn () => managedMailboxProvisioner()->handle($workspace, $owner, $domain, 'sales'))
        ->toThrow(RuntimeException::class)
        ->and(ManagedMailboxAccount::query()->firstOrFail()->status)->toBe(ManagedMailboxAccountStatus::Failed)
        ->and(ManagedMailboxAccount::query()->firstOrFail()->last_error_message)->not->toContain('password');
});

it('activates a mailbox only through an explicit readiness transition', function () {
    FakeManagedMailboxProvisioner::reset();
    Bus::fake();
    $owner = managedProvisioningOwner();
    $workspace = $owner->currentWorkspace();
    $domain = managedDomainProvisioner()->handle($workspace, $owner, 'outreach.example.com');
    $domain->markVerified()->activate();
    $service = managedMailboxProvisioner();
    $account = $service->handle($workspace, $owner, $domain, 'sales');

    $active = $service->activate($workspace, $owner, $account);

    expect($active->fresh()->status)->toBe(ManagedMailboxAccountStatus::Active)
        ->and($active->fresh()->last_verified_at)->not->toBeNull()
        ->and(ManagedMailEvent::query()->where('event', 'mailbox_activated')->count())->toBe(1);
});

it('reconciles provisioning and failed accounts and rotates required credentials to healthy state', function () {
    FakeManagedMailboxProvisioner::reset();
    Bus::fake();
    $owner = managedProvisioningOwner();
    $workspace = $owner->currentWorkspace();
    $domain = managedDomainProvisioner()->handle($workspace, $owner, 'reconcile.example.com');
    $domain->markVerified()->activate();
    $service = managedMailboxProvisioner();

    $provisioning = $service->handle($workspace, $owner, $domain, 'provisioning');

    FakeManagedMailboxProvisioner::$failMailboxCreate = true;
    expect(fn () => $service->handle($workspace, $owner, $domain, 'failed'))
        ->toThrow(RuntimeException::class);
    FakeManagedMailboxProvisioner::$failMailboxCreate = false;
    $failed = ManagedMailboxAccount::query()->where('local_part', 'failed')->firstOrFail();

    $rotation = $service->handle($workspace, $owner, $domain, 'rotation');
    $rotation = $service->activate($workspace, $owner, $rotation);
    $originalVersion = $rotation->credential_version;
    $rotation->markRotationRequired();
    $rotation->mailbox->forceFill(['status' => ConnectionStatus::NeedsReconnect])->save();
    $rotation->mailbox->connection->forceFill(['status' => ConnectionStatus::NeedsReconnect])->save();

    $this->artisan('managed-mail:reconcile', [
        '--workspace' => $workspace->id,
        '--account' => implode(',', [$provisioning->id, $failed->id, $rotation->id]),
        '--json' => true,
    ])->expectsOutputToContain('"accounts_repaired": 3')
        ->assertSuccessful();

    foreach ([$provisioning, $failed, $rotation] as $account) {
        $healthy = $account->fresh(['mailbox.connection']);
        expect($healthy->status)->toBe(ManagedMailboxAccountStatus::Active)
            ->and($healthy->last_verified_at)->not->toBeNull()
            ->and($healthy->mailbox?->status)->toBe(ConnectionStatus::Connected)
            ->and($healthy->mailbox?->connection?->status)->toBe(ConnectionStatus::Connected)
            ->and($healthy->mailbox?->connection?->hasUsableCredentials())->toBeTrue();
    }

    expect($rotation->fresh()->credential_version)->toBe($originalVersion + 1);
});

it('does not count a reconcile attempt as repaired when runtime state remains unhealthy', function () {
    FakeManagedMailboxProvisioner::reset();
    Bus::fake();
    $owner = managedProvisioningOwner();
    $workspace = $owner->currentWorkspace();
    $domain = managedDomainProvisioner()->handle($workspace, $owner, 'unhealthy.example.com');
    $domain->markVerified()->activate();
    $service = managedMailboxProvisioner();
    $account = $service->handle($workspace, $owner, $domain, 'sales');
    $account = $service->activate($workspace, $owner, $account);
    $account->mailbox->forceFill(['status' => ConnectionStatus::Disconnected])->save();

    $this->artisan('managed-mail:reconcile', [
        '--workspace' => $workspace->id,
        '--account' => (string) $account->id,
        '--json' => true,
    ])->expectsOutputToContain('"accounts_repaired": 0')
        ->assertFailed();
});

it('finishes local mailbox deletion when the remote account is already absent', function () {
    FakeManagedMailboxProvisioner::reset();
    $owner = managedProvisioningOwner();
    $workspace = $owner->currentWorkspace();
    $domain = managedDomainProvisioner()->handle($workspace, $owner, 'delete-retry.example.com');
    $domain->markVerified()->activate();
    $account = managedMailboxProvisioner()->handle($workspace, $owner, $domain, 'sales');

    FakeManagedMailboxProvisioner::$store['accounts'] = [];

    $deleted = app(DeleteManagedMailbox::class)->handle($workspace, $owner, $account);

    expect($deleted->status)->toBe(ManagedMailboxAccountStatus::Deleted)
        ->and($deleted->mailbox?->status)->toBe(ConnectionStatus::Disconnected)
        ->and($deleted->mailbox?->connection?->status)->toBe(ConnectionStatus::Disconnected)
        ->and(ManagedMailEvent::query()->where('event', 'mailbox_deleted')->count())->toBe(1);
});
