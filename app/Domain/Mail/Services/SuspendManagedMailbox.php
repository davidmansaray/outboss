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

/** Suspend a remote managed account and stop its local sending path. */
final class SuspendManagedMailbox
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

        if ($record->remote_account_id === null || $record->mailbox_id === null) {
            throw new ManagedMailProvisioningException('managed_mailbox_not_provisioned');
        }

        if ($this->status($record) === ManagedMailboxAccountStatus::Deleted) {
            throw new ManagedMailProvisioningException('managed_mailbox_deleted');
        }

        try {
            $this->provisioner->suspendMailbox($record);

            return DB::transaction(function () use ($workspace, $actor, $record): ManagedMailboxAccount {
                $locked = $this->queryAccount($workspace, $record->id)->lockForUpdate()->firstOrFail();
                $locked->loadMissing('managedMailDomain', 'mailbox.connection');
                $fromState = $this->status($locked);

                if ($fromState !== ManagedMailboxAccountStatus::Suspended) {
                    $locked->markSuspended(persist: false);
                }
                $locked->forceFill([
                    'last_verified_at' => now(),
                    'last_error_code' => null,
                    'last_error_message' => null,
                ])->save();

                $mailbox = $locked->mailbox;
                if ($mailbox !== null) {
                    $mailbox->forceFill([
                        'status' => ConnectionStatus::NeedsReconnect,
                        'sending_paused_at' => now(),
                        'sending_paused_reason' => 'managed_mailbox_suspended',
                    ])->save();

                    $connection = $mailbox->connection;
                    if ($connection !== null) {
                        $connection->forceFill([
                            'status' => ConnectionStatus::NeedsReconnect,
                            'last_error_code' => 'managed_mailbox_suspended',
                            'last_error_message' => 'Managed mailbox is suspended.',
                        ])->save();
                        if ($fromState !== ManagedMailboxAccountStatus::Suspended) {
                            // This path did not historically lock the
                            // connection row. Use an atomic increment so a
                            // concurrent authority transition cannot lose a
                            // generation advance.
                            ProviderConnection::query()
                                ->whereKey($connection->id)
                                ->where('workspace_id', $workspace->id)
                                ->increment('authorization_version');
                        }
                    }
                }

                $this->events->record(
                    $workspace,
                    'mailbox_suspended',
                    $actor,
                    $locked->managedMailDomain,
                    $locked,
                    fromState: $fromState->value,
                    toState: ManagedMailboxAccountStatus::Suspended->value,
                );

                return $locked;
            }, 3)->fresh(['managedMailDomain', 'mailbox.connection']);
        } catch (ManagedMailProvisioningException|StalwartManagementException $exception) {
            $this->markFailure($workspace, $record, $exception);

            throw $exception;
        } catch (Throwable $exception) {
            $this->markFailure($workspace, $record, $exception);

            throw new ManagedMailProvisioningException('mailbox_suspend_failed', previous: $exception);
        }
    }

    public function suspend(
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

    private function markFailure(Workspace $workspace, ManagedMailboxAccount $account, Throwable $exception): void
    {
        try {
            $locked = $this->queryAccount($workspace, $account->id)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }

            $code = $exception instanceof StalwartManagementException || $exception instanceof ManagedMailProvisioningException
                ? $exception->errorCode
                : 'mailbox_suspend_failed';
            $code = preg_replace('/[^a-z0-9_.-]+/', '_', strtolower(substr($code, 0, 120))) ?: 'mailbox_suspend_failed';
            $locked->forceFill([
                'last_error_code' => $code,
                'last_error_message' => 'Managed mailbox suspension requires retry.',
            ])->save();
        } catch (Throwable) {
            // Preserve the original failure for the operator.
        }
    }
}
