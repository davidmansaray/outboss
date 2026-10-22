<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\Contracts\ManagedMailboxProvisioner;
use App\Domain\Mail\DTOs\RotatedCredential;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\ManagedMailboxAccountStatus;
use App\Domain\Mail\Exceptions\ManagedMailProvisioningException;
use App\Domain\Mail\Exceptions\StalwartManagementException;
use App\Models\ManagedMailboxAccount;
use App\Models\ProviderConnection;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Rotate a managed app password and atomically replace its local secret. */
final class RotateManagedMailboxCredential
{
    public function __construct(
        private readonly ManagedMailboxProvisioner $provisioner,
        private readonly ManagedMailCapability $capability,
        private readonly ManagedMailEventRecorder $events,
    ) {}

    public function handle(
        Workspace $workspace,
        User $actor,
        ManagedMailboxAccount|int $account,
    ): ManagedMailboxAccount {
        $this->authorize($workspace, $actor);
        $reserved = $this->resolveAccount($workspace, $account);

        try {
            $updated = DB::transaction(function () use ($workspace, $actor, $reserved): ManagedMailboxAccount {
                // Lock the connection before the account to match the runtime
                // activation and disconnect paths. On SQLite lockForUpdate is
                // a no-op, so the touch below also acquires its write lock
                // before the remote request starts.
                $connectionId = $reserved->mailbox?->provider_connection_id;
                if ($connectionId === null) {
                    throw new ManagedMailProvisioningException('managed_mail_connection_missing');
                }

                $connection = ProviderConnection::query()
                    ->whereKey($connectionId)
                    ->where('workspace_id', $workspace->id)
                    ->lockForUpdate()
                    ->first();
                if ($connection === null) {
                    throw new ManagedMailProvisioningException('managed_mail_connection_missing');
                }

                $locked = $this->queryAccount($workspace, $reserved->id)
                    ->lockForUpdate()
                    ->first();
                if ($locked === null) {
                    throw new ManagedMailProvisioningException('managed_mail_workspace_mismatch');
                }

                $locked->loadMissing('managedMailDomain', 'mailbox');
                $mailbox = $locked->mailbox;

                if ($mailbox === null
                    || (int) $mailbox->getAttribute('workspace_id') !== (int) $workspace->id
                    || (int) $mailbox->provider_connection_id !== (int) $connection->id
                    || $locked->remote_account_id === null) {
                    throw new ManagedMailProvisioningException('managed_mail_connection_missing');
                }

                $fromState = $this->status($locked);
                if ($fromState === ManagedMailboxAccountStatus::Deleted) {
                    throw new ManagedMailProvisioningException('managed_mailbox_deleted');
                }

                // Keep an explicit recoverable state while the remote
                // credential is being rotated. The row lock is held across
                // this provider call, which serializes remote rotations and
                // prevents an older response from winning the local write.
                if ($fromState === ManagedMailboxAccountStatus::Active) {
                    $locked->markRotationRequired(persist: false);
                }
                $locked->forceFill(['updated_at' => now()])->save();

                $rotated = $this->provisioner->rotateMailboxCredential($locked);
                $credential = $this->credential($rotated, $locked);

                $domainInstanceKey = trim((string) $locked->managedMailDomain?->managed_instance_key);
                if ($domainInstanceKey === '') {
                    throw new ManagedMailProvisioningException('managed_mail_instance_missing');
                }
                $connectionInstanceKey = trim((string) $connection->managed_instance_key);
                if ($connectionInstanceKey !== '' && $connectionInstanceKey !== $domainInstanceKey) {
                    throw new ManagedMailProvisioningException('managed_mail_instance_mismatch');
                }

                $connection->forceFill([
                    'credential_payload' => [
                        'username' => $credential['username'],
                        'password' => $credential['password'],
                        'version' => $credential['version'],
                    ],
                    'connection_kind' => 'managed',
                    'auth_scheme' => 'app_password',
                    'managed_instance_key' => $domainInstanceKey,
                    'status' => ConnectionStatus::Connected,
                    'last_error_code' => null,
                    'last_error_message' => null,
                ])->advanceAuthorizationVersion()->save();

                // Authentication failures put both the connection and its
                // mailbox into needs_reconnect. A successful credential
                // rotation must restore the complete runtime state, not just
                // the account and connection rows.
                $mailbox->forceFill([
                    'status' => ConnectionStatus::Connected,
                    'last_sync_error' => null,
                ])->save();

                $locked->forceFill([
                    'credential_version' => $credential['version'],
                    'last_verified_at' => now(),
                    'last_error_code' => null,
                    'last_error_message' => null,
                ]);

                $lockedStatus = $this->status($locked);
                if ($lockedStatus === ManagedMailboxAccountStatus::RotationRequired) {
                    $locked->markActive(persist: false);
                }
                $locked->save();

                $this->events->record(
                    $workspace,
                    'credential_rotated',
                    $actor,
                    $locked->managedMailDomain,
                    $locked,
                    fromState: $fromState->value,
                    toState: $this->status($locked)->value,
                    metadata: ['credential_version' => $credential['version']],
                );

                return $locked;
            }, 3);

            return $updated->fresh(['managedMailDomain', 'mailbox.connection']);
        } catch (ManagedMailProvisioningException|StalwartManagementException $exception) {
            $this->markFailure($workspace, $reserved, $exception);

            throw $exception;
        } catch (Throwable $exception) {
            $this->markFailure($workspace, $reserved, $exception);

            throw new ManagedMailProvisioningException('mailbox_credential_rotation_failed', previous: $exception);
        }
    }

    public function rotate(
        Workspace $workspace,
        User $actor,
        ManagedMailboxAccount|int $account,
    ): ManagedMailboxAccount {
        return $this->handle($workspace, $actor, $account);
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

    private function resolveAccount(Workspace $workspace, ManagedMailboxAccount|int $account): ManagedMailboxAccount
    {
        $record = $account instanceof ManagedMailboxAccount
            ? $account
            : $this->queryAccount($workspace, $account)->first();

        if ($record === null) {
            throw new ManagedMailProvisioningException('managed_mail_workspace_mismatch');
        }

        $record->loadMissing('managedMailDomain', 'mailbox.connection');
        if ($record->managedMailDomain === null
            || (int) $record->managedMailDomain->workspace_id !== (int) $workspace->id) {
            throw new ManagedMailProvisioningException('managed_mail_workspace_mismatch');
        }

        return $record;
    }

    /** @return Builder<ManagedMailboxAccount> */
    private function queryAccount(Workspace $workspace, int $accountId): Builder
    {
        return ManagedMailboxAccount::query()
            ->whereKey($accountId)
            ->whereHas('managedMailDomain', fn ($query) => $query->where('workspace_id', $workspace->id));
    }

    /** @return array{username: string, password: string, version: int} */
    private function credential(RotatedCredential $rotated, ManagedMailboxAccount $account): array
    {
        $username = trim($rotated->username);
        $password = $rotated->password;
        if ($username === '' || $password === '') {
            throw new ManagedMailProvisioningException('mailbox_missing_rotated_credential');
        }

        $version = $rotated->version;
        $currentVersion = (int) $account->credential_version;
        $version = is_int($version) || (is_string($version) && is_numeric($version))
            ? (int) $version
            : ($currentVersion + 1);

        // A provider response with an explicit old version cannot be relabeled
        // as a newer local credential: its password belongs to the old remote
        // rotation and would leave local JMAP authentication out of sync.
        if ($version <= $currentVersion) {
            throw new ManagedMailProvisioningException('stale_rotated_credential');
        }

        return [
            'username' => $username,
            'password' => $password,
            'version' => $version,
        ];
    }

    private function status(ManagedMailboxAccount $account): ManagedMailboxAccountStatus
    {
        $status = $account->getAttribute('status');

        return $status instanceof ManagedMailboxAccountStatus
            ? $status
            : ManagedMailboxAccountStatus::from((string) $status);
    }

    private function markFailure(Workspace $workspace, ManagedMailboxAccount $account, Throwable $exception): void
    {
        try {
            DB::transaction(function () use ($workspace, $account, $exception): void {
                $locked = $this->queryAccount($workspace, $account->id)->lockForUpdate()->first();
                if ($locked === null) {
                    return;
                }

                $code = $exception instanceof StalwartManagementException || $exception instanceof ManagedMailProvisioningException
                    ? $exception->errorCode
                    : 'mailbox_credential_rotation_failed';
                $code = preg_replace('/[^a-z0-9_.-]+/', '_', strtolower(substr($code, 0, 120))) ?: 'mailbox_credential_rotation_failed';
                $status = $this->status($locked);
                if (in_array($status, [
                    ManagedMailboxAccountStatus::Active,
                    ManagedMailboxAccountStatus::Suspended,
                ], true)) {
                    $locked->markRotationRequired(persist: false);
                }
                $locked->forceFill([
                    'last_error_code' => $code,
                    'last_error_message' => 'Managed mailbox credentials require rotation retry.',
                ])->save();

                $locked->loadMissing('mailbox');
                $mailbox = $locked->mailbox;
                $connection = $mailbox === null
                    ? null
                    : ProviderConnection::query()
                        ->whereKey($mailbox->provider_connection_id)
                        ->where('workspace_id', $workspace->id)
                        ->lockForUpdate()
                        ->first();
                if ($mailbox !== null && $connection !== null) {
                    $connection->forceFill([
                        'status' => ConnectionStatus::NeedsReconnect,
                        'last_error_code' => 'credential_rotation_required',
                        'last_error_message' => 'Managed mailbox credentials require rotation.',
                    ])->save();
                    $mailbox->forceFill(['status' => ConnectionStatus::NeedsReconnect])->save();
                }
            }, 3);
        } catch (Throwable) {
            // Preserve the original provider error; a later operator retry
            // can reconcile a row when the local failure write was transient.
        }
    }
}
