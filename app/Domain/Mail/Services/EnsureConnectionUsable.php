<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\DTOs\RemoteMailbox;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\ManagedMailboxAccountStatus;
use App\Domain\Mail\Providers\ProviderRegistry;
use App\Models\Mailbox;
use App\Models\ManagedMailboxAccount;
use App\Models\ProviderConnection;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Ensures a provider connection can be used by a runtime mail operation.
 *
 * OAuth connections retain the previous refresh-lock/version algorithm.
 * Managed JMAP connections have no refresh endpoint: their encrypted
 * username/app-password payload and instance configuration are validated and
 * the runtime transport performs authentication on its next request.
 */
class EnsureConnectionUsable
{
    public function __construct(
        private ProviderRegistry $registry,
    ) {}

    public function handle(ProviderConnection $connection): ProviderConnection
    {
        $status = $connection->getAttribute('status');
        $status = $status instanceof \BackedEnum ? $status->value : $status;

        if ($this->isManaged($connection)) {
            if ($status !== ConnectionStatus::Connected->value) {
                throw new RuntimeException('managed_connection_unusable');
            }

            return $this->validateManaged($connection);
        }

        if ($status !== ConnectionStatus::Connected->value) {
            return $connection;
        }

        return $this->refreshOAuth($connection);
    }

    /**
     * Authenticate a newly provisioned managed account and atomically make
     * its local account, connection, and mailbox usable. This is intentionally
     * separate from handle(): runtime workers may only use an already-active
     * account and must never promote a partially provisioned row implicitly.
     */
    public function verifyAndActivateManaged(
        ProviderConnection $connection,
        ?User $actor = null,
    ): ProviderConnection {
        if (! $this->isManaged($connection)) {
            throw new RuntimeException('managed_connection_required');
        }

        $context = $this->managedContext($connection);
        $account = $context['account'];
        $mailbox = $context['mailbox'];

        if (! $account instanceof ManagedMailboxAccount || ! $mailbox instanceof Mailbox) {
            throw new RuntimeException('managed_mailbox_missing');
        }

        $accountStatus = $this->accountStatus($account);
        if ($accountStatus === ManagedMailboxAccountStatus::Active) {
            return $this->handle($connection);
        }

        if ($accountStatus !== ManagedMailboxAccountStatus::Provisioning) {
            throw new RuntimeException('managed_mailbox_not_pending');
        }

        $this->assertManagedWorkspace($connection, $account);
        $this->assertManagedCredentials($connection);

        try {
            // StalwartProvider::mailboxes performs authenticated /.well-known
            // discovery followed by Mailbox/get. Do not activate based on
            // management-account creation or local credentials alone.
            $remoteMailboxes = $this->registry->transport($connection->provider)->mailboxes($connection);
        } catch (Throwable $exception) {
            $this->markUnavailable($connection, 'managed_mailbox_discovery_failed');

            throw new RuntimeException('managed_mailbox_not_ready', 0, $exception);
        }

        $remote = collect($remoteMailboxes)->first(function (RemoteMailbox $candidate) use ($account, $mailbox): bool {
            $remoteId = $candidate->providerMailboxId;
            $remoteEmail = strtolower(trim($candidate->emailAddress));
            $localEmail = strtolower(trim((string) $mailbox->getAttribute('email_address')));

            return $remoteId !== ''
                && $remoteId === (string) $account->remote_account_id
                && ($remoteEmail === '' || $remoteEmail === $localEmail);
        });

        if (! $remote instanceof RemoteMailbox) {
            $this->markUnavailable($connection, 'managed_mailbox_discovery_mismatch');

            throw new RuntimeException('managed_mailbox_not_ready');
        }

        $remoteMetadata = $remote->metadata;

        return DB::transaction(function () use ($connection, $account, $mailbox, $remoteMetadata, $actor): ProviderConnection {
            $lockedConnection = ProviderConnection::query()->whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $lockedMailbox = Mailbox::query()
                ->whereKey($mailbox->id)
                ->where('provider_connection_id', $lockedConnection->id)
                ->where('workspace_id', $lockedConnection->workspace_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedAccount = ManagedMailboxAccount::query()
                ->whereKey($account->id)
                ->where('mailbox_id', $lockedMailbox->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedAccount->loadMissing('managedMailDomain');
            $domain = $lockedAccount->managedMailDomain;

            if ($domain === null
                || (int) $domain->workspace_id !== (int) $lockedConnection->workspace_id
                || ! $domain->isActive()
                || $this->accountStatus($lockedAccount) !== ManagedMailboxAccountStatus::Provisioning) {
                throw new RuntimeException('managed_mailbox_not_ready');
            }

            $fromState = $this->accountStatus($lockedAccount)->value;
            $safeMetadata = array_filter([
                'account_id' => $remoteMetadata['account_id'] ?? null,
                'inbox_id' => $remoteMetadata['inbox_id'] ?? null,
                'sent_id' => $remoteMetadata['sent_id'] ?? null,
                'managed_instance_key' => $lockedConnection->managed_instance_key,
            ], static fn (mixed $value): bool => is_scalar($value) && (string) $value !== '');

            $lockedConnection->forceFill([
                'status' => ConnectionStatus::Connected,
                'last_error_code' => null,
                'last_error_message' => null,
            ])->advanceAuthorizationVersion()->save();
            $lockedMailbox->forceFill([
                'status' => ConnectionStatus::Connected,
                'provider_metadata' => [
                    ...(is_array($lockedMailbox->getAttribute('provider_metadata'))
                        ? $lockedMailbox->getAttribute('provider_metadata')
                        : []),
                    ...$safeMetadata,
                ],
                'last_sync_error' => null,
            ])->save();
            $lockedAccount->forceFill([
                'last_verified_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
            ])->markActive(persist: false)->save();

            app(ManagedMailEventRecorder::class)->record(
                $domain->workspace,
                'mailbox_activated',
                $actor ?? $domain->createdBy,
                $domain,
                $lockedAccount,
                fromState: $fromState,
                toState: ManagedMailboxAccountStatus::Active->value,
                metadata: ['mailbox_id' => $lockedMailbox->id],
            );

            return $lockedConnection->fresh();
        }, 3);
    }

    /**
     * Promote only the initial provisioning state. A disconnected or
     * credential-failed active account must go through its normal repair flow.
     */
    public function activatePendingManaged(ProviderConnection $connection): bool
    {
        if (! $this->isManaged($connection)) {
            return false;
        }

        $context = $this->managedContext($connection);
        $account = $context['account'];

        if (! $account instanceof ManagedMailboxAccount
            || $this->accountStatus($account) !== ManagedMailboxAccountStatus::Provisioning) {
            return false;
        }

        $this->verifyAndActivateManaged($connection);

        return true;
    }

    private function validateManaged(ProviderConnection $connection): ProviderConnection
    {
        try {
            $context = $this->managedContext($connection);
            $account = $context['account'];
            $mailbox = $context['mailbox'];

            if (! $account instanceof ManagedMailboxAccount || ! $mailbox instanceof Mailbox) {
                throw new RuntimeException('managed_mailbox_missing');
            }

            $this->assertManagedWorkspace($connection, $account);

            if ($this->accountStatus($account) !== ManagedMailboxAccountStatus::Active
                || ! $account->isActive()
                || $mailbox->getAttribute('status') !== ConnectionStatus::Connected) {
                throw new RuntimeException('managed_mailbox_not_active');
            }

            // Reading the cast is intentional: it proves the encrypted
            // payload can be decrypted without ever putting its value in an
            // exception or log message.
            $payload = $connection->getAttribute('credential_payload');
            $payload = is_array($payload)
                ? $payload
                : (is_string($payload) ? json_decode($payload, true) : null);

            if (! is_array($payload)) {
                // During the migration window, a managed connection may use
                // the legacy encrypted access_token field as its password.
                $payload = [
                    'username' => $connection->getAttribute('provider_user_id'),
                    'password' => $connection->getAttribute('access_token'),
                ];
            }

            $username = $payload['username']
                ?? $payload['jmap_username']
                ?? $payload['email']
                ?? $connection->getAttribute('provider_user_id');
            $password = $payload['password']
                ?? $payload['app_password']
                ?? $payload['secret']
                ?? $connection->getAttribute('access_token');

            if (! is_string($username) || trim($username) === ''
                || ! is_string($password) || $password === '') {
                throw new RuntimeException('managed_credentials_missing');
            }

            $instanceKey = $connection->getAttribute('managed_instance_key')
                ?? data_get($connection->getAttribute('provider_metadata'), 'managed_instance_key');
            $instanceKey = $this->backedValue($instanceKey) ?? 'internal';

            if (! is_string($instanceKey) || trim($instanceKey) === '') {
                throw new RuntimeException('managed_instance_missing');
            }

            // The runtime Stalwart client is the source of truth for the
            // full instance configuration.  This check only rejects an
            // explicitly disabled/malformed key and leaves URL resolution to
            // the transport so configuration remains centralised.
            if (str_contains($instanceKey, '/') || str_contains($instanceKey, '\\')) {
                throw new RuntimeException('managed_instance_invalid');
            }

            return $connection;
        } catch (Throwable $exception) {
            $code = match ($exception->getMessage()) {
                'managed_mailbox_missing' => 'managed_mailbox_missing',
                'managed_mailbox_not_active' => 'managed_mailbox_not_active',
                'managed_mail_workspace_mismatch' => 'managed_mail_workspace_mismatch',
                default => 'credential_rotation_required',
            };
            $this->markUnavailable($connection, $code);

            throw new RuntimeException('managed_connection_unusable', 0, $exception);
        }
    }

    /** @return array{account:?ManagedMailboxAccount,mailbox:?Mailbox} */
    private function managedContext(ProviderConnection $connection): array
    {
        $mailbox = Mailbox::query()
            ->where('provider_connection_id', $connection->id)
            ->where('workspace_id', $connection->workspace_id)
            ->first();
        $account = $mailbox === null
            ? null
            : ManagedMailboxAccount::query()
                ->where('mailbox_id', $mailbox->id)
                ->with('managedMailDomain')
                ->first();

        return ['account' => $account, 'mailbox' => $mailbox];
    }

    private function assertManagedWorkspace(
        ProviderConnection $connection,
        ManagedMailboxAccount $account,
    ): void {
        if ($account->workspaceId() !== (int) $connection->workspace_id) {
            throw new RuntimeException('managed_mail_workspace_mismatch');
        }
    }

    private function assertManagedCredentials(ProviderConnection $connection): void
    {
        if (! $connection->hasUsableCredentials()) {
            $this->markUnavailable($connection, 'credential_rotation_required');

            throw new RuntimeException('managed_credentials_missing');
        }
    }

    private function accountStatus(ManagedMailboxAccount $account): ManagedMailboxAccountStatus
    {
        $status = $account->getAttribute('status');

        return $status instanceof ManagedMailboxAccountStatus
            ? $status
            : ManagedMailboxAccountStatus::from((string) $status);
    }

    private function refreshOAuth(ProviderConnection $connection): ProviderConnection
    {
        $expiresAt = $connection->getAttribute('token_expires_at');

        if ($expiresAt instanceof DateTimeInterface && $expiresAt > now()->addMinutes(2)) {
            return $connection;
        }

        $lock = Cache::lock('provider-token:'.$connection->id, 30);

        try {
            $lock->block(20);
            $connection->refresh();

            $expiresAt = $connection->getAttribute('token_expires_at');

            if ($expiresAt instanceof DateTimeInterface && $expiresAt > now()->addMinutes(2)) {
                return $connection;
            }

            $tokens = $this->registry->oauth($connection->provider)->refreshConnection($connection);
            $version = $connection->token_version;

            $connection->forceFill([
                'access_token' => $tokens->accessToken,
                'refresh_token' => $tokens->refreshToken ?? $connection->refresh_token,
                'token_expires_at' => $tokens->tokenExpiresAt,
                'token_version' => $version + 1,
                'status' => ConnectionStatus::Connected,
                'last_error_code' => null,
                'last_error_message' => null,
            ]);

            $updated = ProviderConnection::query()
                ->whereKey($connection->id)
                ->where('token_version', $version)
                ->update([
                    'access_token' => $connection->getAttributes()['access_token'],
                    'refresh_token' => $connection->getAttributes()['refresh_token'],
                    'token_expires_at' => $connection->getAttributes()['token_expires_at'],
                    'token_version' => $connection->token_version,
                    'status' => ConnectionStatus::Connected->value,
                    'last_error_code' => null,
                    'last_error_message' => null,
                ]);

            if ($updated === 0) {
                return $connection->fresh();
            }

            $connection->syncOriginal();

            return $connection;
        } catch (Throwable $exception) {
            $this->markUnavailable($connection, 'token_refresh_failed');

            throw $exception;
        } finally {
            optional($lock)->release();
        }
    }

    private function markUnavailable(ProviderConnection $connection, string $code): void
    {
        $connection->forceFill([
            'status' => ConnectionStatus::NeedsReconnect,
            'last_error_code' => $code,
            'last_error_message' => $this->safeMessage($code),
        ])->saveQuietly();

        $connection->mailboxes()->update(['status' => ConnectionStatus::NeedsReconnect]);
    }

    private function isManaged(ProviderConnection $connection): bool
    {
        $kind = $this->backedValue($connection->getAttribute('connection_kind'));
        $provider = $this->backedValue($connection->getAttribute('provider'));

        return $kind === 'managed' || $provider === MailProviderName::Stalwart->value;
    }

    private function backedValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }

    private function safeMessage(string $code): string
    {
        return match ($code) {
            'credential_rotation_required' => 'Managed mailbox credentials require rotation.',
            'managed_mailbox_discovery_failed' => 'Managed mailbox authentication is not ready yet.',
            'managed_mailbox_discovery_mismatch' => 'Managed mailbox discovery did not match the provisioned account.',
            'managed_mailbox_not_active' => 'Managed mailbox is not active.',
            'managed_mailbox_missing' => 'Managed mailbox is not linked to its provider connection.',
            'token_refresh_failed' => 'The provider connection needs to be reconnected.',
            default => 'The provider connection is not usable.',
        };
    }
}
