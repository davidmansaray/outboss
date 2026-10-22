<?php

namespace App\Domain\Mail\Services;

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\ManagedMailboxAccountStatus;
use App\Domain\Mail\Exceptions\ManagedMailProvisioningException;
use App\Domain\Mail\Providers\ProviderRegistry;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\ManagedMailboxAccount;
use App\Models\ProviderConnection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class DisconnectMailboxService
{
    public function __construct(private ProviderRegistry $registry) {}

    public function handle(Mailbox $mailbox): void
    {
        $workspaceId = (int) $mailbox->getAttribute('workspace_id');
        $connection = $mailbox->connection()->first();

        if ($workspaceId <= 0
            || ! $connection
            || (int) $connection->getAttribute('workspace_id') !== $workspaceId) {
            throw new RuntimeException('workspace_mismatch');
        }

        $pendingRevoke = null;

        DB::transaction(function () use ($mailbox, $workspaceId, &$pendingRevoke): void {
            $connection = $mailbox->connection()
                ->where('workspace_id', $workspaceId)
                ->lockForUpdate()
                ->firstOrFail();
            $managedAccount = ManagedMailboxAccount::query()
                ->where('mailbox_id', $mailbox->id)
                ->lockForUpdate()
                ->first();

            if ($this->requiresManagedDeletion($connection, $managedAccount)) {
                throw new ManagedMailProvisioningException(
                    'managed_mailbox_explicit_delete_required',
                    'Delete this managed mailbox from Managed Mail before disconnecting it.',
                );
            }

            CampaignRecipient::query()
                ->where('mailbox_id', $mailbox->id)
                ->where('workspace_id', $mailbox->getAttribute('workspace_id'))
                ->whereNotIn('status', [
                    RecipientStatus::Removed->value,
                    RecipientStatus::Completed->value,
                    RecipientStatus::Replied->value,
                    RecipientStatus::Interested->value,
                    RecipientStatus::NotNow->value,
                    RecipientStatus::WrongPerson->value,
                    RecipientStatus::Bounced->value,
                    RecipientStatus::Unsubscribed->value,
                ])
                ->each(function (CampaignRecipient $recipient): void {
                    $remaining = $recipient->next_send_at
                        ? max(0, now()->diffInSeconds($recipient->next_send_at, false))
                        : $recipient->remaining_delay_seconds;

                    $recipient->forceFill([
                        'blocked_from_status' => $recipient->status,
                        'blocked_at' => now(),
                        'remaining_delay_seconds' => $remaining,
                        'status' => RecipientStatus::BlockedMailbox,
                    ])->save();
                });

            $remaining = $connection->mailboxes()
                ->whereKeyNot($mailbox->id)
                ->where('workspace_id', $workspaceId)
                ->where('status', '!=', ConnectionStatus::Disconnected)
                ->count();

            if ($remaining === 0) {
                $pendingRevoke = clone $connection;
                $clearedCredentials = [
                    'status' => ConnectionStatus::Disconnected,
                    'access_token' => null,
                    'refresh_token' => null,
                    'provider_metadata' => null,
                ];

                $connectionKind = $connection->getAttribute('connection_kind');
                $connectionKind = $connectionKind instanceof \BackedEnum
                    ? $connectionKind->value
                    : $connectionKind;
                $provider = $connection->getAttribute('provider');
                $provider = $provider instanceof \BackedEnum ? $provider->value : $provider;

                if ($connectionKind === 'managed' || $provider === MailProviderName::Stalwart->value) {
                    $clearedCredentials['credential_payload'] = null;
                    $clearedCredentials['managed_instance_key'] = null;
                }

                $connectionStatus = $connection->getAttribute('status');
                $connectionStatus = $connectionStatus instanceof \BackedEnum
                    ? $connectionStatus->value
                    : $connectionStatus;
                $connection->forceFill($clearedCredentials);
                if ($connectionStatus !== ConnectionStatus::Disconnected->value) {
                    $connection->advanceAuthorizationVersion();
                }
                $connection->save();
            }

            $mailbox->forceFill([
                'status' => ConnectionStatus::Disconnected,
                'provider_metadata' => null,
                'last_sync_error' => null,
                'sync_requested_at' => null,
            ])->save();
        });

        if ($pendingRevoke instanceof ProviderConnection) {
            $this->revokeQuietly($pendingRevoke);
        }
    }

    private function revokeQuietly(ProviderConnection $connection): void
    {
        try {
            if (! $this->registry->supportsOAuth($connection->provider)) {
                return;
            }

            $this->registry->oauth($connection->provider)->revokeConnection($connection);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function requiresManagedDeletion(
        ProviderConnection $connection,
        ?ManagedMailboxAccount $account,
    ): bool {
        $provider = $connection->getAttribute('provider');
        $provider = $provider instanceof \BackedEnum ? $provider->value : $provider;

        if (! $connection->isManaged() && $provider !== MailProviderName::Stalwart->value) {
            return false;
        }

        $status = $account?->getAttribute('status');
        $status = $status instanceof \BackedEnum ? $status->value : $status;

        return $status !== ManagedMailboxAccountStatus::Deleted->value;
    }
}
