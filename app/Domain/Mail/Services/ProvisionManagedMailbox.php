<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\Contracts\ManagedMailboxProvisioner;
use App\Domain\Mail\DTOs\ManagedMailboxRequest;
use App\Domain\Mail\DTOs\ProvisionedMailbox;
use App\Domain\Mail\DTOs\RemoteSenderIdentity;
use App\Domain\Mail\Enums\AuthScheme;
use App\Domain\Mail\Enums\ConnectionKind;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\ManagedMailboxAccountStatus;
use App\Domain\Mail\Enums\SyncScope;
use App\Domain\Mail\Enums\SyncStatus;
use App\Domain\Mail\Exceptions\ManagedMailProvisioningException;
use App\Domain\Mail\Exceptions\StalwartManagementException;
use App\Domain\Mail\Support\ManagedDomainNormalizer;
use App\Jobs\Mail\InitialSyncMailbox;
use App\Models\Mailbox;
use App\Models\MailboxSyncState;
use App\Models\ManagedMailboxAccount;
use App\Models\ManagedMailDomain;
use App\Models\ProviderConnection;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Orchestrate a managed mailbox without exposing the provider client to a
 * controller. Remote account creation happens before local mailbox records,
 * while the durable account row remains available for reconciliation if a
 * local transaction fails.
 */
final class ProvisionManagedMailbox
{
    public function __construct(
        private readonly ManagedMailboxProvisioner $provisioner,
        private readonly ManagedMailCapability $capability,
        private readonly ManagedMailEventRecorder $events,
        private readonly MailboxSenderIdentityService $senderIdentities,
        private readonly ?EnsureConnectionUsable $connections = null,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function handle(
        Workspace $workspace,
        User $actor,
        ManagedMailDomain|int $domain,
        string $localPart,
        ?string $displayName = null,
        array $metadata = [],
    ): ManagedMailboxAccount {
        $this->authorize($workspace, $actor);

        $domainRecord = $this->resolveDomain($workspace, $domain);
        if (! $domainRecord->isVerified() || ! $domainRecord->isActive()) {
            throw ValidationException::withMessages([
                'domain' => 'Verify and activate the managed domain before creating a mailbox.',
            ]);
        }

        $normalizedLocalPart = ManagedDomainNormalizer::normalizeLocalPart($localPart);
        if ($normalizedLocalPart === null) {
            throw ValidationException::withMessages([
                'local_part' => 'Enter a valid managed mailbox name.',
            ]);
        }

        $displayName = $this->displayName($displayName);
        $correlationId = $this->correlationId($metadata);

        // The database reservation below is intentionally not the lock. It
        // must be released before calling the remote provider, otherwise a
        // slow provider request would hold an open database transaction. The
        // provider operation, recovery/rotation, and local credential write
        // are instead serialized by an owner-scoped database cache lock.
        // Force the database store even when the application's default cache
        // is array/file: those drivers do not coordinate separate workers.
        $lockStore = Cache::store('database')->getStore();
        if (! $lockStore instanceof LockProvider) {
            throw new ManagedMailProvisioningException(
                'mailbox_provisioning_lock_unavailable',
                'Managed mailbox provisioning locking is not configured.',
            );
        }

        $provisioningLock = $lockStore->lock(
            $this->provisioningLockKey($workspace, $domainRecord, $normalizedLocalPart),
            $this->provisioningLockLeaseSeconds($domainRecord),
        );

        try {
            $provisioningLock->block($this->provisioningLockWaitSeconds());
        } catch (LockTimeoutException $exception) {
            throw new ManagedMailProvisioningException(
                'mailbox_provisioning_in_progress',
                'Another managed mailbox request is already provisioning this address.',
                previous: $exception,
            );
        }

        try {
            $reserved = DB::transaction(function () use (
                $workspace,
                $domainRecord,
                $normalizedLocalPart,
                $displayName,
            ): ManagedMailboxAccount {
                $lockedDomain = ManagedMailDomain::query()
                    ->whereKey($domainRecord->id)
                    ->where('workspace_id', $workspace->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $lockedDomain->isVerified() || ! $lockedDomain->isActive()) {
                    throw ValidationException::withMessages([
                        'domain' => 'Verify and activate the managed domain before creating a mailbox.',
                    ]);
                }

                $account = ManagedMailboxAccount::query()
                    ->where('managed_mail_domain_id', $lockedDomain->id)
                    ->where('local_part', $normalizedLocalPart)
                    ->lockForUpdate()
                    ->first();

                if ($account === null) {
                    return ManagedMailboxAccount::query()->create([
                        'managed_mail_domain_id' => $lockedDomain->id,
                        'local_part' => $normalizedLocalPart,
                        'display_name' => $displayName,
                        'credential_version' => 1,
                        'status' => ManagedMailboxAccountStatus::Provisioning,
                    ]);
                }

                $status = $this->accountStatus($account);
                if ($status === ManagedMailboxAccountStatus::Deleted) {
                    throw ValidationException::withMessages([
                        'local_part' => 'This managed mailbox was deleted and cannot be recreated with the same local part.',
                    ]);
                }

                // A completed local retry is idempotent and must not rotate or
                // overwrite credentials merely because the create form was sent
                // twice.
                if ($account->mailbox_id !== null && in_array($status, [
                    ManagedMailboxAccountStatus::Provisioning,
                    ManagedMailboxAccountStatus::Active,
                    ManagedMailboxAccountStatus::Suspended,
                    ManagedMailboxAccountStatus::RotationRequired,
                ], true)) {
                    return $account;
                }

                if ($status !== ManagedMailboxAccountStatus::Provisioning) {
                    $account->transitionTo(ManagedMailboxAccountStatus::Provisioning, persist: false);
                }

                $account->forceFill([
                    'display_name' => $displayName ?? $account->display_name,
                    'last_error_code' => null,
                    'last_error_message' => null,
                ])->save();

                return $account;
            }, 3);

            if ($reserved->mailbox_id !== null) {
                return $reserved->fresh(['managedMailDomain', 'mailbox']);
            }

            $remoteAccountId = null;

            try {
                if (trim((string) $domainRecord->remote_domain_id) === '') {
                    throw new ManagedMailProvisioningException('domain_missing_remote_id');
                }

                $initialPassword = Str::random(64);
                $remote = $this->provisioner->createMailbox(new ManagedMailboxRequest(
                    localPart: $normalizedLocalPart,
                    domain: $domainRecord->domain,
                    remoteDomainId: (string) $domainRecord->remote_domain_id,
                    displayName: $displayName,
                    password: $initialPassword,
                    managedInstanceKey: $domainRecord->managed_instance_key,
                    workspaceId: $workspace->id,
                    metadata: ['correlation_id' => $correlationId],
                ));
                $remoteAccountId = trim($remote->remoteAccountId);

                if ($remoteAccountId === '') {
                    throw new ManagedMailProvisioningException('mailbox_missing_remote_id');
                }

                // Persist the remote identity before a possible credential
                // rotation. This covers a retry after a local write failure
                // which has no one-time password in its result. The database
                // lock remains held until the credential and mailbox rows are
                // complete, so another request cannot observe this half-state.
                if ($reserved->remote_account_id !== $remoteAccountId) {
                    $reserved->forceFill(['remote_account_id' => $remoteAccountId])->save();
                }

                $credentials = $this->credentialsFor($reserved, $remote, $initialPassword);
                $account = $this->persistLocalMailbox(
                    $workspace,
                    $actor,
                    $domainRecord,
                    $reserved,
                    $remote,
                    $remoteAccountId,
                    $credentials,
                    $displayName,
                );

                InitialSyncMailbox::dispatch((int) $account->mailbox_id);

                return $account->fresh(['managedMailDomain', 'mailbox', 'mailbox.connection']);
            } catch (Throwable $exception) {
                $this->markFailed($reserved, $remoteAccountId, $exception);

                throw $this->safeException($exception, 'mailbox_provision_failed');
            }
        } finally {
            // Release is owner-scoped. If the process dies before this block,
            // the database lock lease expires and a retry can recover it.
            try {
                $provisioningLock->release();
            } catch (Throwable) {
                // Expiration is the safe fallback; never hide the provider or
                // local error because a best-effort release failed.
            }
        }
    }

    /**
     * Alias for callers that prefer an explicit provisioning verb.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function provision(
        Workspace $workspace,
        User $actor,
        ManagedMailDomain|int $domain,
        string $localPart,
        ?string $displayName = null,
        array $metadata = [],
    ): ManagedMailboxAccount {
        return $this->handle($workspace, $actor, $domain, $localPart, $displayName, $metadata);
    }

    /**
     * Mark a mailbox ready after a caller has completed JMAP discovery/read.
     * The service does not claim that remote account creation alone verifies
     * credentials; this explicit method is the local readiness transition.
     */
    public function activate(
        Workspace $workspace,
        User $actor,
        ManagedMailboxAccount $account,
    ): ManagedMailboxAccount {
        $this->authorize($workspace, $actor);
        $account->loadMissing('managedMailDomain');
        $domain = $account->managedMailDomain;

        if ($domain === null || (int) $domain->workspace_id !== (int) $workspace->id) {
            throw new ManagedMailProvisioningException('managed_mail_workspace_mismatch');
        }

        if (! $domain->isVerified() || ! $domain->isActive() || $account->mailbox_id === null) {
            throw ValidationException::withMessages([
                'mailbox' => 'Complete domain verification and mailbox discovery before activation.',
            ]);
        }

        try {
            // Discovery is the only authority that can promote a newly
            // created account. The verifier performs authenticated JMAP
            // session discovery + Mailbox/get, then locks and updates all
            // three local readiness records in one transaction.
            $connections = $this->connections ?? app(EnsureConnectionUsable::class);
            $mailbox = $account->mailbox()->first();
            $connection = $mailbox?->connection()->first();

            if ($connection === null) {
                throw new ManagedMailProvisioningException('managed_mail_connection_missing');
            }

            $connections->verifyAndActivateManaged($connection, $actor);
        } catch (ManagedMailProvisioningException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ManagedMailProvisioningException('managed_mailbox_not_ready', previous: $exception);
        }

        return $account->fresh(['managedMailDomain', 'mailbox', 'mailbox.connection']);
    }

    private function authorize(Workspace $workspace, User $actor): void
    {
        if (! $this->capability->allows($actor, $workspace)) {
            throw new ManagedMailProvisioningException(
                'managed_mail_forbidden',
                'Managed mail is not available for this workspace.',
            );
        }
    }

    private function resolveDomain(Workspace $workspace, ManagedMailDomain|int $domain): ManagedMailDomain
    {
        $record = $domain instanceof ManagedMailDomain
            ? $domain
            : ManagedMailDomain::query()->whereKey($domain)->first();

        if ($record === null || (int) $record->workspace_id !== (int) $workspace->id) {
            throw new ManagedMailProvisioningException('managed_mail_workspace_mismatch');
        }

        return $record->fresh();
    }

    private function displayName(?string $displayName): ?string
    {
        $displayName = trim(str_replace(["\r", "\n"], ' ', (string) $displayName));

        return $displayName === '' ? null : substr($displayName, 0, 255);
    }

    /** @param array<string, mixed> $metadata */
    private function correlationId(array $metadata): string
    {
        $value = $metadata['correlation_id'] ?? null;

        return is_string($value) && preg_match('/\A[a-zA-Z0-9_.:-]{1,120}\z/', $value) === 1
            ? $value
            : (string) Str::uuid();
    }

    private function provisioningLockKey(
        Workspace $workspace,
        ManagedMailDomain $domain,
        string $localPart,
    ): string {
        $identity = implode('|', [
            (string) $workspace->id,
            strtolower(trim((string) $domain->managed_instance_key)),
            strtolower(trim((string) $domain->domain)),
            strtolower(trim($localPart)),
        ]);

        return 'outboss:managed-mail:provision:'.hash('sha256', $identity);
    }

    private function provisioningLockWaitSeconds(): int
    {
        return max(0, (int) config('outboss.managed_mail.provisioning_lock_wait_seconds', 30));
    }

    private function provisioningLockLeaseSeconds(?ManagedMailDomain $domain = null): int
    {
        $lease = max(60, (int) config('outboss.managed_mail.provisioning_lock_lease_seconds', 300));
        $instanceKey = trim((string) $domain?->managed_instance_key);
        $providerTimeout = max(1, (int) ($instanceKey !== ''
            ? config(
                'outboss.managed_mail.instances.'.$instanceKey.'.timeout_seconds',
                config('outboss.managed_mail.timeout_seconds', 15),
            )
            : config('outboss.managed_mail.timeout_seconds', 15)));

        // A create may perform several bounded provider requests (query,
        // write, and inspect). Keep the lease longer than that worst-case
        // sequence even when an operator sets a very small timeout.
        return max($lease, ($providerTimeout * 6) + 30);
    }

    private function accountStatus(ManagedMailboxAccount $account): ManagedMailboxAccountStatus
    {
        $status = $account->getAttribute('status');

        return $status instanceof ManagedMailboxAccountStatus
            ? $status
            : ManagedMailboxAccountStatus::from((string) $status);
    }

    /**
     * Use the one-time create credential where available. A local retry may
     * receive an idempotent remote response without a password, so rotate the
     * existing remote account once to recover a missing local credential.
     *
     * @return array{username: string, password: string, version: int}
     */
    private function credentialsFor(
        ManagedMailboxAccount $account,
        ProvisionedMailbox $remote,
        string $initialPassword,
    ): array {
        $account->loadMissing('managedMailDomain');
        $domain = $account->managedMailDomain;
        if ($domain === null || trim((string) $domain->managed_instance_key) === '') {
            throw new ManagedMailProvisioningException('managed_mail_instance_missing');
        }

        $managedInstanceKey = trim((string) $domain->managed_instance_key);
        $username = trim((string) ($remote->username ?: $remote->emailAddress));
        $password = $remote->password;
        $version = $this->credentialVersion($remote, $account);

        if ($username === '') {
            throw new ManagedMailProvisioningException('mailbox_missing_username');
        }

        if ($password !== null && $password !== '') {
            return ['username' => $username, 'password' => $password, 'version' => $version];
        }

        $connection = ProviderConnection::query()
            ->where('workspace_id', $account->workspaceId())
            ->where('provider', MailProviderName::Stalwart)
            ->where('managed_instance_key', $managedInstanceKey)
            ->where('provider_user_id', $remote->remoteAccountId)
            ->first();

        if ($connection !== null && $connection->hasUsableCredentials()) {
            $payload = $connection->getAttribute('credential_payload');
            if (is_array($payload)
                && is_string($payload['username'] ?? null)
                && is_string($payload['password'] ?? null)
                && $payload['username'] !== ''
                && $payload['password'] !== '') {
                return [
                    'username' => $payload['username'],
                    'password' => $payload['password'],
                    'version' => (int) ($payload['version'] ?? $version),
                ];
            }
        }

        $rotated = $this->provisioner->rotateMailboxCredential($account);
        if ($rotated->password === '') {
            throw new ManagedMailProvisioningException('mailbox_missing_rotated_credential');
        }

        return [
            'username' => trim($rotated->username) !== '' ? trim($rotated->username) : $username,
            'password' => $rotated->password,
            'version' => is_int($rotated->version) || is_string($rotated->version)
                ? (int) $rotated->version
                : $version + 1,
        ];
    }

    private function credentialVersion(ProvisionedMailbox $remote, ManagedMailboxAccount $account): int
    {
        $version = $remote->metadata['credential_version'] ?? null;

        return is_int($version) || (is_string($version) && is_numeric($version))
            ? max(1, (int) $version)
            : max(1, (int) $account->credential_version);
    }

    /**
     * @param  array{username: string, password: string, version: int}  $credentials
     */
    private function persistLocalMailbox(
        Workspace $workspace,
        User $actor,
        ManagedMailDomain $domain,
        ManagedMailboxAccount $reserved,
        ProvisionedMailbox $remote,
        string $remoteAccountId,
        array $credentials,
        ?string $displayName,
    ): ManagedMailboxAccount {
        $expectedEmail = strtolower($reserved->local_part.'@'.$domain->domain);
        $remoteEmail = strtolower(trim($remote->emailAddress));
        if ($remoteEmail !== '' && $remoteEmail !== $expectedEmail) {
            throw new ManagedMailProvisioningException('mailbox_email_mismatch');
        }

        return DB::transaction(function () use (
            $workspace,
            $actor,
            $domain,
            $reserved,
            $remoteAccountId,
            $credentials,
            $displayName,
            $expectedEmail,
            $remote,
        ): ManagedMailboxAccount {
            $connection = ProviderConnection::query()->updateOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'provider' => MailProviderName::Stalwart,
                    'managed_instance_key' => $domain->managed_instance_key,
                    'provider_user_id' => $remoteAccountId,
                ],
                [
                    'user_id' => $actor->id,
                    'connection_kind' => ConnectionKind::Managed,
                    'auth_scheme' => AuthScheme::AppPassword,
                    'access_token' => null,
                    'refresh_token' => null,
                    'credential_payload' => [
                        'username' => $credentials['username'],
                        'password' => $credentials['password'],
                        'version' => $credentials['version'],
                    ],
                    'managed_instance_key' => $domain->managed_instance_key,
                    'token_expires_at' => null,
                    'granted_scopes' => null,
                    'provider_metadata' => [
                        'managed_mail_domain_id' => $domain->id,
                        'remote_account_id' => $remoteAccountId,
                    ],
                    // A managed account is not runtime-usable until its
                    // encrypted credential authenticates and JMAP Mailbox/get
                    // returns the expected account. Activation flips this
                    // status atomically with the managed account row.
                    'status' => ConnectionStatus::NeedsReconnect,
                    'last_error_code' => null,
                    'last_error_message' => null,
                ],
            );

            $mailbox = Mailbox::query()->updateOrCreate(
                [
                    'provider_connection_id' => $connection->id,
                    'provider_mailbox_id' => $remoteAccountId,
                ],
                [
                    'user_id' => $actor->id,
                    'workspace_id' => $workspace->id,
                    'email_address' => $expectedEmail,
                    'display_name' => $displayName,
                    'status' => ConnectionStatus::NeedsReconnect,
                    'provider_metadata' => [
                        ...$this->safeMetadata($remote->metadata),
                        'account_id' => $remoteAccountId,
                        'managed_mail_domain_id' => $domain->id,
                    ],
                    'last_sync_error' => null,
                ],
            );

            foreach ([SyncScope::Inbox, SyncScope::Sent] as $scope) {
                $folderId = $scope === SyncScope::Inbox
                    ? data_get($remote->metadata, 'inbox_id')
                    : data_get($remote->metadata, 'sent_id');
                $folderId = is_scalar($folderId) ? (string) $folderId : null;

                $state = MailboxSyncState::query()->firstOrCreate(
                    [
                        'mailbox_id' => $mailbox->id,
                        'scope' => $scope,
                    ],
                    [
                        'status' => SyncStatus::Idle,
                        'remote_folder_id' => $folderId,
                    ],
                );

                if ($folderId !== null && $state->remote_folder_id !== $folderId) {
                    $state->forceFill(['remote_folder_id' => $folderId])->save();
                }
            }

            $this->senderIdentities->sync($mailbox, [
                new RemoteSenderIdentity(
                    key: MailboxSenderIdentityService::keyForEmail($expectedEmail),
                    emailAddress: $expectedEmail,
                    displayName: $displayName,
                ),
            ], supported: true);

            $account = ManagedMailboxAccount::query()->whereKey($reserved->id)->lockForUpdate()->firstOrFail();
            $fromState = $this->accountStatus($account)->value;
            $account->forceFill([
                'remote_account_id' => $remoteAccountId,
                'mailbox_id' => $mailbox->id,
                'credential_version' => $credentials['version'],
                'provisioned_at' => now(),
                'last_verified_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();

            $this->events->record(
                $workspace,
                'mailbox_provisioned',
                $actor,
                $domain,
                $account,
                fromState: $fromState,
                toState: ManagedMailboxAccountStatus::Provisioning->value,
                metadata: [
                    'remote_account_id' => $remoteAccountId,
                    'mailbox_id' => $mailbox->id,
                ],
            );

            return $account;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, bool|float|int|string|null|array<string, mixed>>
     */
    private function safeMetadata(array $values): array
    {
        $safe = [];
        foreach ($values as $key => $value) {
            $key = (string) $key;
            if ($key === '' || preg_match('/(?:pass(word)?|secret|token|credential|api[_-]?key|authorization|body|payload)/i', $key) === 1) {
                continue;
            }

            if (is_array($value)) {
                $safe[$key] = $this->safeMetadata($value);
            } elseif (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    private function markFailed(ManagedMailboxAccount $account, ?string $remoteAccountId, Throwable $exception): void
    {
        $code = $this->errorCode($exception, 'mailbox_provision_failed');
        $message = $this->errorMessage($exception, 'Managed mailbox provisioning failed. Retry or reconcile the managed mailbox.');

        try {
            $locked = ManagedMailboxAccount::query()->whereKey($account->id)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }

            $fromState = $this->accountStatus($locked)->value;
            if ($remoteAccountId !== null && $locked->remote_account_id === null) {
                $locked->remote_account_id = $remoteAccountId;
            }
            if ($this->accountStatus($locked) !== ManagedMailboxAccountStatus::Failed) {
                $locked->transitionTo(ManagedMailboxAccountStatus::Failed, persist: false);
            }
            $locked->forceFill([
                'last_error_code' => $code,
                'last_error_message' => $message,
            ])->save();

            $locked->loadMissing('managedMailDomain');
            if ($locked->managedMailDomain !== null) {
                $this->events->record(
                    $locked->managedMailDomain->workspace,
                    'mailbox_provision_failed',
                    $locked->managedMailDomain->createdBy,
                    $locked->managedMailDomain,
                    $locked,
                    fromState: $fromState,
                    toState: ManagedMailboxAccountStatus::Failed->value,
                    metadata: ['error_code' => $code],
                );
            }
        } catch (Throwable) {
            // Preserve the original error. The account's reconciliation path
            // can repair a failed write on the next operator retry.
        }
    }

    private function errorCode(Throwable $exception, string $fallback): string
    {
        $code = $exception instanceof StalwartManagementException || $exception instanceof ManagedMailProvisioningException
            ? $exception->errorCode
            : $fallback;
        $code = strtolower(substr($code, 0, 120));

        return preg_replace('/[^a-z0-9_.-]+/', '_', $code) ?: $fallback;
    }

    private function errorMessage(Throwable $exception, string $fallback): string
    {
        if (! ($exception instanceof StalwartManagementException || $exception instanceof ManagedMailProvisioningException)) {
            return $fallback;
        }

        $message = trim(str_replace(["\r", "\n"], ' ', $exception->getMessage()));

        return $message === '' ? $fallback : substr($message, 0, 500);
    }

    private function safeException(Throwable $exception, string $fallback): Throwable
    {
        if ($exception instanceof StalwartManagementException || $exception instanceof ManagedMailProvisioningException) {
            return $exception;
        }

        return new ManagedMailProvisioningException($fallback, previous: $exception);
    }
}
