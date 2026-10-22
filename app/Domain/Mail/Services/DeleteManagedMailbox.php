<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\Contracts\ManagedMailboxProvisioner;
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

/** Explicitly delete a managed remote account and its local send identity. */
final class DeleteManagedMailbox
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
        $record = $this->resolveAccount($workspace, $account);

        if ($this->status($record) === ManagedMailboxAccountStatus::Deleted) {
            return $record;
        }

        if ($record->remote_account_id === null) {
            throw new ManagedMailProvisioningException('managed_mailbox_not_provisioned');
        }

        try {
            $this->provisioner->deleteMailbox($record);

            return DB::transaction(function () use ($workspace, $actor, $record): ManagedMailboxAccount {
                $locked = $this->queryAccount($workspace, $record->id)->lockForUpdate()->firstOrFail();
                $locked->loadMissing('managedMailDomain', 'mailbox.connection');
                $fromState = $this->status($locked);
                $mailbox = $locked->mailbox;
                $connection = $mailbox?->connection;

                $locked->markDeleted(persist: false)->save();

                if ($mailbox !== null) {
                    $mailbox->forceFill([
                        'status' => ConnectionStatus::Disconnected,
                        'provider_metadata' => null,
                        'last_sync_error' => null,
                        'sync_requested_at' => null,
                        'sending_paused_at' => null,
                        'sending_paused_reason' => null,
                    ])->save();
                }

                if ($connection !== null
                    && (int) $connection->workspace_id === (int) $workspace->id
                    && $connection->mailboxes()->where('status', '!=', ConnectionStatus::Disconnected)->count() === 0) {
                    $connection->forceFill([
                        'status' => ConnectionStatus::Disconnected,
                        'access_token' => null,
                        'refresh_token' => null,
                        'credential_payload' => null,
                        'managed_instance_key' => null,
                        'provider_metadata' => null,
                    ])->save();
                    ProviderConnection::query()
                        ->whereKey($connection->id)
                        ->where('workspace_id', $workspace->id)
                        ->increment('authorization_version');
                }

                $this->events->record(
                    $workspace,
                    'mailbox_deleted',
                    $actor,
                    $locked->managedMailDomain,
                    $locked,
                    fromState: $fromState->value,
                    toState: ManagedMailboxAccountStatus::Deleted->value,
                );

                return $locked;
            }, 3)->fresh(['managedMailDomain', 'mailbox.connection']);
        } catch (ManagedMailProvisioningException|StalwartManagementException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ManagedMailProvisioningException('mailbox_delete_failed', previous: $exception);
        }
    }

    public function delete(
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

    private function status(ManagedMailboxAccount $account): ManagedMailboxAccountStatus
    {
        $status = $account->getAttribute('status');

        return $status instanceof ManagedMailboxAccountStatus
            ? $status
            : ManagedMailboxAccountStatus::from((string) $status);
    }
}
