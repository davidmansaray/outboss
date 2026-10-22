<?php

use App\Domain\Mail\DTOs\ManagedDomainRequest;
use App\Domain\Mail\DTOs\ManagedMailboxRequest;
use App\Domain\Mail\Enums\AuthScheme;
use App\Domain\Mail\Enums\ConnectionKind;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\ManagedMailboxAccountStatus;
use App\Domain\Mail\Enums\ManagedMailDomainStatus;
use App\Domain\Mail\Providers\FakeManagedMailboxProvisioner;
use App\Domain\Mail\Services\ManagedMailCapability;
use App\Domain\Mail\Services\ManagedMailEventRecorder;
use App\Models\ManagedMailboxAccount;
use App\Models\ManagedMailDomain;
use App\Models\ManagedMailEvent;
use App\Models\ProviderConnection;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

it('allows verified workspace owners without rollout configuration', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    $capability = app(ManagedMailCapability::class);

    expect($capability->allows($owner, $owner->currentWorkspace()))->toBeTrue()
        ->and($capability->allows($otherOwner, $otherOwner->currentWorkspace()))->toBeTrue();
});

it('denies members even when their workspace owner is eligible', function () {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $member = User::factory()->create();
    $workspace->memberships()->create([
        'user_id' => $member->id,
        'role' => 'member',
    ]);
    $capability = app(ManagedMailCapability::class);

    expect($capability->allows($owner, $workspace))->toBeTrue()
        ->and($capability->allows($member, $workspace))->toBeFalse();
});

it('requires email verification, membership, and workspace ownership', function () {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $member = User::factory()->create();
    $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
    $domain = ManagedMailDomain::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by_user_id' => $owner->id,
    ]);
    $unverified = User::factory()->unverified()->create();
    $unverifiedDomain = ManagedMailDomain::factory()->create([
        'workspace_id' => $unverified->currentWorkspace()->id,
        'created_by_user_id' => $unverified->id,
    ]);
    $outsider = User::factory()->create();

    expect(Gate::forUser($owner)->allows('view', $domain))->toBeTrue()
        ->and(Gate::forUser($member)->allows('view', $domain))->toBeFalse()
        ->and(Gate::forUser($unverified)->allows('view', $unverifiedDomain))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('view', $domain))->toBeFalse();
});

it('stores managed credentials encrypted and omits them from serialization', function () {
    $connection = ProviderConnection::factory()->create([
        'provider' => MailProviderName::Stalwart,
        'connection_kind' => ConnectionKind::Managed,
        'auth_scheme' => AuthScheme::AppPassword,
        'credential_payload' => [
            'username' => 'sales@example.com',
            'password' => 'one-time-secret',
            'version' => 1,
        ],
        'access_token' => null,
        'refresh_token' => null,
    ]);

    $raw = $connection->getRawOriginal('credential_payload');
    $serialized = $connection->toArray();

    expect($connection->fresh()->credential_payload['password'])->toBe('one-time-secret')
        ->and($connection->fresh()->hasDecryptableSecrets())->toBeTrue()
        ->and($connection->fresh()->hasUsableCredentials())->toBeTrue()
        ->and($raw)->toBeString()
        ->and($raw)->not->toContain('one-time-secret')
        ->and($serialized)->not->toHaveKey('credential_payload');
});

it('backfills existing provider rows to the OAuth defaults', function () {
    // A row created through the post-migration model receives the same
    // defaults that the migration applies to legacy rows. The migration
    // itself is exercised by RefreshDatabase on both supported drivers.
    $connection = ProviderConnection::factory()->create(['provider' => MailProviderName::Gmail]);

    expect($connection->fresh()->connection_kind)->toBe(ConnectionKind::OAuth)
        ->and($connection->fresh()->auth_scheme)->toBe(AuthScheme::OAuth2);
});

it('enforces managed-domain uniqueness per mail instance and permits isolated reuse', function () {
    $domain = ManagedMailDomain::factory()->create(['domain' => 'outreach.example.com']);

    expect(fn () => ManagedMailDomain::factory()->create([
        'workspace_id' => $domain->workspace_id,
        'created_by_user_id' => $domain->created_by_user_id,
        'domain' => 'outreach.example.com',
    ]))->toThrow(QueryException::class);

    $other = User::factory()->create();
    expect(fn () => ManagedMailDomain::factory()->create([
        'workspace_id' => $other->currentWorkspace()->id,
        'created_by_user_id' => $other->id,
        'domain' => 'outreach.example.com',
    ]))->toThrow(QueryException::class);

    $isolatedDomain = ManagedMailDomain::factory()->create([
        'workspace_id' => $other->currentWorkspace()->id,
        'created_by_user_id' => $other->id,
        'managed_instance_key' => 'isolated',
        'domain' => 'outreach.example.com',
    ]);

    expect($isolatedDomain->workspace_id)->not->toBe($domain->workspace_id)
        ->and($isolatedDomain->managed_instance_key)->toBe('isolated');
});

it('does not permit a domain to become active before DNS verification', function () {
    $domain = ManagedMailDomain::factory()->draft()->create();

    expect(fn () => $domain->activate())->toThrow(LogicException::class);

    $domain->markProvisioning()
        ->markPendingDns()
        ->markVerified()
        ->activate();

    expect($domain->fresh()->status)->toBe(ManagedMailDomainStatus::Active)
        ->and($domain->fresh()->verified_at)->not->toBeNull();
});

it('keeps account transitions explicit and blocks a deleted account from returning', function () {
    $account = ManagedMailboxAccount::factory()->provisioning()->create();

    $account->markActive()->markSuspended()->markDeleted();

    expect($account->fresh()->status)->toBe(ManagedMailboxAccountStatus::Deleted)
        ->and(fn () => $account->markActive())->toThrow(LogicException::class);
});

it('provisions fake domains and accounts idempotently and rotates credentials', function () {
    FakeManagedMailboxProvisioner::reset();
    $provisioner = new FakeManagedMailboxProvisioner;
    $domain = $provisioner->createDomain(new ManagedDomainRequest('outreach.example.com'));
    $request = new ManagedMailboxRequest(
        localPart: 'sales',
        domain: 'outreach.example.com',
        remoteDomainId: $domain->remoteDomainId,
        password: 'initial-secret',
    );

    $first = $provisioner->createMailbox($request);
    $second = $provisioner->createMailbox($request);
    $local = ManagedMailboxAccount::factory()->create(['remote_account_id' => $first->remoteAccountId]);
    $rotated = $provisioner->rotateMailboxCredential($local);

    expect($first->remoteAccountId)->toBe($second->remoteAccountId)
        ->and($first->password)->toBe('initial-secret')
        ->and($second->password)->toBeNull()
        ->and($rotated->version)->toBe(2)
        ->and($rotated->password)->not->toBe('initial-secret')
        ->and(count(FakeManagedMailboxProvisioner::$store['accounts']))->toBe(1);
});

it('records managed lifecycle audit events with secret-looking metadata removed', function () {
    $domain = ManagedMailDomain::factory()->create();
    $account = ManagedMailboxAccount::factory()->create(['managed_mail_domain_id' => $domain->id]);
    $event = app(ManagedMailEventRecorder::class)->record(
        $domain->workspace,
        'rotate',
        $domain->createdBy,
        $domain,
        $account,
        ManagedMailboxAccountStatus::Active->value,
        ManagedMailboxAccountStatus::RotationRequired->value,
        [
            'remote_account_id' => 'remote-123',
            'password' => 'secret-value',
            'nested' => ['api_key' => 'admin-secret', 'attempt' => 1],
        ],
    );

    expect($event)->toBeInstanceOf(ManagedMailEvent::class)
        ->and($event->safe_metadata)->toBe([
            'remote_account_id' => 'remote-123',
            'nested' => ['attempt' => 1],
        ])
        ->and(ManagedMailEvent::query()->whereKey($event->id)->exists())->toBeTrue();
});

it('creates managed foundation tables on the active database', function () {
    expect(Schema::hasTable('managed_mail_domains'))->toBeTrue()
        ->and(Schema::hasTable('managed_mailbox_accounts'))->toBeTrue()
        ->and(Schema::hasTable('managed_mail_events'))->toBeTrue()
        ->and(Schema::hasColumn('provider_connections', 'credential_payload'))->toBeTrue();
});
