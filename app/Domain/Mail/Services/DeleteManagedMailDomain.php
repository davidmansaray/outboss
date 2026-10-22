<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\Contracts\ManagedMailboxProvisioner;
use App\Domain\Mail\Enums\ManagedMailboxAccountStatus;
use App\Domain\Mail\Exceptions\ManagedMailProvisioningException;
use App\Domain\Mail\Exceptions\StalwartManagementException;
use App\Models\ManagedMailDomain;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Permanently retire an empty managed domain locally and on its mail server. */
final class DeleteManagedMailDomain
{
    public function __construct(
        private readonly ManagedMailboxProvisioner $provisioner,
        private readonly ManagedMailCapability $capability,
        private readonly ManagedMailEventRecorder $events,
    ) {}

    public function handle(Workspace $workspace, User $actor, ManagedMailDomain|int $domain): void
    {
        if (! $this->capability->allows($actor, $workspace)) {
            throw new ManagedMailProvisioningException('managed_mail_forbidden');
        }

        $record = ManagedMailDomain::query()
            ->whereKey($domain instanceof ManagedMailDomain ? $domain->id : $domain)
            ->where('workspace_id', $workspace->id)
            ->with('mailboxAccounts')
            ->first();

        if ($record === null) {
            throw new ManagedMailProvisioningException('managed_mail_workspace_mismatch');
        }

        if ($record->mailboxAccounts->contains(
            function ($account): bool {
                $status = $account->getAttribute('status');

                return ($status instanceof ManagedMailboxAccountStatus ? $status->value : $status)
                    !== ManagedMailboxAccountStatus::Deleted->value;
            },
        )) {
            throw new ManagedMailProvisioningException(
                'managed_domain_not_empty',
                'Delete every mailbox on this domain before retiring it.',
            );
        }

        try {
            if (is_string($record->remote_domain_id) && $record->remote_domain_id !== '') {
                $this->provisioner->deleteDomain($record);
            }

            DB::transaction(function () use ($workspace, $actor, $record): void {
                $locked = ManagedMailDomain::query()
                    ->whereKey($record->id)
                    ->where('workspace_id', $workspace->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $status = $locked->getAttribute('status');
                $this->events->record(
                    $workspace,
                    'domain_deleted',
                    $actor,
                    $locked,
                    fromState: $status instanceof \BackedEnum ? (string) $status->value : (string) $status,
                    toState: 'deleted',
                    metadata: ['domain' => $locked->domain],
                );

                $locked->mailboxAccounts()->delete();
                $locked->delete();
            }, 3);
        } catch (ManagedMailProvisioningException|StalwartManagementException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ManagedMailProvisioningException('domain_delete_failed', previous: $exception);
        }
    }
}
