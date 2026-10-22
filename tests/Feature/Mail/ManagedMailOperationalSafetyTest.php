<?php

use App\Domain\Mail\Contracts\ManagedMailboxProvisioner;
use App\Domain\Mail\DTOs\RotatedCredential;
use App\Domain\Mail\Enums\AuthScheme;
use App\Domain\Mail\Enums\ConnectionKind;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\ManagedMailboxAccountStatus;
use App\Domain\Mail\Exceptions\ManagedMailProvisioningException;
use App\Domain\Mail\Providers\FakeManagedMailboxProvisioner;
use App\Domain\Mail\Services\DisconnectMailboxService;
use App\Domain\Mail\Services\ManagedMailCapability;
use App\Domain\Mail\Services\ManagedMailEventRecorder;
use App\Domain\Mail\Services\RotateManagedMailboxCredential;
use App\Models\Mailbox;
use App\Models\ManagedMailboxAccount;
use App\Models\ManagedMailDomain;
use App\Models\ProviderConnection;
use App\Models\User;
use Illuminate\Database\QueryException;
use Mockery;

function operationalManagedOwner(): User
{
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();

    config()->set('outboss.managed_mail.default_instance', 'internal');
    config()->set('outboss.managed_mail.instances.internal', [
        'base_url' => 'https://mx-api.example.test',
        'admin_api_key' => 'test-only-secret',
        'verify_tls' => true,
        'timeout_seconds' => 15,
    ]);
    config()->set('outboss.managed_mail.instances.isolated', [
        'base_url' => 'https://isolated-mx-api.example.test',
        'admin_api_key' => 'test-only-secret',
        'verify_tls' => true,
        'timeout_seconds' => 15,
    ]);

    return $owner;
}

it('scopes managed provider identity by instance and prevents credential overwrite', function () {
    $owner = operationalManagedOwner();
    $workspace = $owner->currentWorkspace();

    $internal = ProviderConnection::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $workspace->id,
        'provider' => MailProviderName::Stalwart,
        'provider_user_id' => 'remote-account-1',
        'connection_kind' => ConnectionKind::Managed,
        'auth_scheme' => AuthScheme::AppPassword,
        'managed_instance_key' => 'internal',
        'access_token' => null,
        'refresh_token' => null,
        'credential_payload' => [
            'username' => 'internal@example.com',
            'password' => 'internal-password',
            'version' => 1,
        ],
    ]);
    $isolated = ProviderConnection::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $workspace->id,
        'provider' => MailProviderName::Stalwart,
        'provider_user_id' => 'remote-account-1',
        'connection_kind' => ConnectionKind::Managed,
        'auth_scheme' => AuthScheme::AppPassword,
        'managed_instance_key' => 'isolated',
        'access_token' => null,
        'refresh_token' => null,
        'credential_payload' => [
            'username' => 'isolated@example.com',
            'password' => 'isolated-password',
            'version' => 1,
        ],
    ]);

    expect($isolated->id)->not->toBe($internal->id)
        ->and($internal->fresh()->credential_payload['password'])->toBe('internal-password')
        ->and($isolated->fresh()->credential_payload['password'])->toBe('isolated-password')
        ->and(fn () => ProviderConnection::factory()->create([
            'user_id' => $owner->id,
            'workspace_id' => $workspace->id,
            'provider' => MailProviderName::Stalwart,
            'provider_user_id' => 'remote-account-1',
            'connection_kind' => ConnectionKind::Managed,
            'auth_scheme' => AuthScheme::AppPassword,
            'managed_instance_key' => 'internal',
            'access_token' => null,
            'refresh_token' => null,
            'credential_payload' => [
                'username' => 'duplicate@example.com',
                'password' => 'duplicate-password',
                'version' => 1,
            ],
        ]))->toThrow(QueryException::class);
});

it('keeps OAuth NULL instance identities unique on both database drivers', function () {
    $owner = operationalManagedOwner();
    $workspace = $owner->currentWorkspace();
    $attributes = [
        'user_id' => $owner->id,
        'workspace_id' => $workspace->id,
        'provider' => MailProviderName::Gmail,
        'provider_user_id' => 'oauth-user-1',
        'connection_kind' => ConnectionKind::OAuth,
        'auth_scheme' => AuthScheme::OAuth2,
        'managed_instance_key' => null,
    ];

    ProviderConnection::factory()->create($attributes);

    expect(fn () => ProviderConnection::factory()->create($attributes))
        ->toThrow(QueryException::class);
});

it('retains the newer local credential when a stale rotation response arrives', function () {
    $owner = operationalManagedOwner();
    $workspace = $owner->currentWorkspace();
    [$account, $connection] = operationalManagedMailbox($owner);

    $provisioner = Mockery::mock(ManagedMailboxProvisioner::class);
    $provisioner->shouldReceive('rotateMailboxCredential')
        ->twice()
        ->andReturn(
            new RotatedCredential('sales@example.com', 'newer-password', 3),
            new RotatedCredential('sales@example.com', 'older-password', 2),
        );

    $service = new RotateManagedMailboxCredential(
        $provisioner,
        new ManagedMailCapability,
        new ManagedMailEventRecorder,
    );

    $service->handle($workspace, $owner, $account);
    $updated = $connection->fresh();

    expect($updated->credential_payload['password'])->toBe('newer-password')
        ->and($updated->credential_payload['version'])->toBe(3);

    try {
        $service->handle($workspace, $owner, $account->fresh());
        expect(false)->toBeTrue();
    } catch (ManagedMailProvisioningException $exception) {
        expect($exception->errorCode)->toBe('stale_rotated_credential');
    }

    $final = $connection->fresh();
    expect($final->credential_payload['password'])->toBe('newer-password')
        ->and($final->credential_payload['version'])->toBe(3)
        ->and($account->fresh()->status)->toBe(ManagedMailboxAccountStatus::RotationRequired);
});

it('refuses generic disconnect before mutating a managed mailbox', function () {
    $owner = operationalManagedOwner();
    [$account, $connection] = operationalManagedMailbox($owner);
    $mailbox = $account->fresh('mailbox')->mailbox;
    $remoteAccountsBeforeDisconnect = FakeManagedMailboxProvisioner::$store['accounts'];

    expect($mailbox)->toBeInstanceOf(Mailbox::class);
    try {
        app(DisconnectMailboxService::class)->handle($mailbox);
        expect(false)->toBeTrue();
    } catch (ManagedMailProvisioningException $exception) {
        expect($exception->errorCode)->toBe('managed_mailbox_explicit_delete_required');
    }

    expect($mailbox->fresh()->status)->toBe(ConnectionStatus::Connected)
        ->and($connection->fresh()->status)->toBe(ConnectionStatus::Connected)
        ->and($connection->fresh()->credential_payload['password'])->toBe('initial-password')
        ->and($account->fresh()->status)->toBe(ManagedMailboxAccountStatus::Active)
        ->and(FakeManagedMailboxProvisioner::$store['accounts'])->toBe($remoteAccountsBeforeDisconnect);
});

it('returns an actionable response when generic managed disconnect is requested', function () {
    $owner = operationalManagedOwner();
    [$account] = operationalManagedMailbox($owner);
    $mailbox = $account->fresh('mailbox')->mailbox;

    $this->actingAs($owner)
        ->from(route('mailboxes.index'))
        ->delete(route('mailboxes.destroy', $mailbox))
        ->assertRedirect(route('mailboxes.index'))
        ->assertSessionHas('error', 'Delete this managed mailbox from Managed Mail before disconnecting it.');
});

function operationalManagedMailbox(User $owner): array
{
    $workspace = $owner->currentWorkspace();
    $domain = ManagedMailDomain::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by_user_id' => $owner->id,
        'managed_instance_key' => 'internal',
        'domain' => 'example.com',
        'organisational_domain' => 'example.com',
    ]);
    $connection = ProviderConnection::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $workspace->id,
        'provider' => MailProviderName::Stalwart,
        'provider_user_id' => 'remote-account-1',
        'connection_kind' => ConnectionKind::Managed,
        'auth_scheme' => AuthScheme::AppPassword,
        'managed_instance_key' => 'internal',
        'access_token' => null,
        'refresh_token' => null,
        'credential_payload' => [
            'username' => 'sales@example.com',
            'password' => 'initial-password',
            'version' => 1,
        ],
        'status' => ConnectionStatus::Connected,
    ]);
    $mailbox = Mailbox::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $workspace->id,
        'provider_connection_id' => $connection->id,
        'provider_mailbox_id' => 'remote-account-1',
        'email_address' => 'sales@example.com',
        'status' => ConnectionStatus::Connected,
    ]);
    $account = ManagedMailboxAccount::factory()->create([
        'managed_mail_domain_id' => $domain->id,
        'mailbox_id' => $mailbox->id,
        'remote_account_id' => 'remote-account-1',
        'local_part' => 'sales',
        'status' => ManagedMailboxAccountStatus::Active,
        'credential_version' => 1,
    ]);

    return [$account, $connection];
}
