<?php

namespace App\Domain\Mail\Services;

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Mail\DTOs\RemoteMessagePage;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\SyncScope;
use App\Domain\Mail\Enums\SyncStatus;
use App\Domain\Mail\Providers\ProviderRegistry;
use App\Domain\Mail\Support\MessageIdNormalizer;
use App\Models\Mailbox;
use App\Models\MailboxSyncState;
use App\Models\Message;
use App\Models\ProviderConnection;
use Illuminate\Http\Client\RequestException;
use Throwable;

class SyncMailboxService
{
    public function __construct(
        private ProviderRegistry $registry,
        private EnsureConnectionUsable $tokens,
        private UpsertRemoteMessage $upsert,
        private ApplyInboundCampaignEffects $effects,
        private ManagedMailboxOperationalEventRecorder $events,
    ) {}

    public function handle(int $mailboxId): void
    {
        $mailbox = Mailbox::query()->find($mailboxId);

        if (! $mailbox) {
            return;
        }

        $connection = ProviderConnection::query()
            ->whereKey($mailbox->provider_connection_id)
            ->where('workspace_id', $mailbox->getAttribute('workspace_id'))
            ->first();

        if (! $connection) {
            $this->failWorkspaceMismatch($mailbox);

            return;
        }

        try {
            if ($this->tokens->activatePendingManaged($connection)) {
                $mailbox->refresh();
                $connection->refresh();
            }
        } catch (Throwable $exception) {
            $this->failMailbox($mailbox, $exception, reconnect: false);
            $this->events->record(
                $mailbox,
                'mailbox_sync_failed',
                fromState: 'pending_activation',
                toState: 'failed',
                metadata: ['scope' => 'connection'],
            );

            throw $exception;
        }

        if ($mailbox->getAttribute('status') !== ConnectionStatus::Connected) {
            // Initial managed-mail provisioning deliberately stores the local
            // connection/mailbox as unusable. Give that one pending state a
            // chance to authenticate and discover Mailbox/get; all other
            // disconnected states remain blocked.
            $this->clearSyncRequest($mailbox);

            return;
        }

        if (! $connection->hasDecryptableSecrets()) {
            $this->failCredentials($mailbox, $connection);

            return;
        }

        $states = [];

        foreach ([SyncScope::Inbox, SyncScope::Sent] as $scope) {
            $folderId = $scope === SyncScope::Sent
                ? data_get($mailbox->provider_metadata, 'sent_id')
                : data_get($mailbox->provider_metadata, 'inbox_id');
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

            if ($folderId && $state->remote_folder_id !== $folderId) {
                $state->forceFill(['remote_folder_id' => $folderId])->save();
            }

            $states[] = $state;
        }

        try {
            $this->tokens->handle($connection);
        } catch (Throwable $exception) {
            foreach ($states as $state) {
                $this->failState($mailbox, $state, $exception);
            }

            $this->failMailbox($mailbox, $exception, reconnect: true);

            throw $exception;
        }

        $connection->refresh();

        foreach ($states as $state) {
            $this->syncScope($mailbox, $state, $connection);
        }

        $mailbox->forceFill([
            'last_synced_at' => now(),
            'last_sync_error' => null,
            'sync_requested_at' => null,
        ])->save();
    }

    private function syncScope(Mailbox $mailbox, MailboxSyncState $state, ProviderConnection $connection): void
    {
        $wasFailed = $this->enumValue($state->getAttribute('status')) === SyncStatus::Failed->value
            || $state->getAttribute('last_error') !== null;
        $failedSince = $wasFailed ? $state->getAttribute('last_started_at') : null;
        $state->forceFill([
            'status' => SyncStatus::Syncing,
            'last_started_at' => now(),
        ])->save();

        try {
            $provider = $this->registry->transport($connection->provider);
            $isZoho = $connection->getRawOriginal('provider') === MailProviderName::Zoho->value;
            $isSent = $state->getRawOriginal('scope') === SyncScope::Sent->value;
            $allowEmailFallback = ! $isZoho || $isSent;
            $pagesPerJob = max(1, (int) config('outboss.sync.pages_per_job', 5));
            $pages = 0;

            do {
                $page = $isZoho
                    ? $provider->syncFolder(
                        $mailbox,
                        $state,
                        max(1, (int) config('outboss.sync.zoho.page_size', 100)),
                    )
                    : $provider->syncFolder($mailbox, $state);
                $parentInternetMessageIds = [];

                foreach ($page->messages as $remote) {
                    $message = $this->upsert->handle($mailbox, $remote, $allowEmailFallback);

                    if ($isSent
                        && $remote->direction === MessageDirection::Outbound
                        && $message->getAttribute('campaign_recipient_id') !== null) {
                        $storedInternetMessageId = $message->getAttribute('internet_message_id');
                        $internetMessageId = MessageIdNormalizer::normalize(
                            is_string($storedInternetMessageId) ? $storedInternetMessageId : null,
                        );

                        if ($internetMessageId !== null) {
                            $parentInternetMessageIds[$internetMessageId] = $internetMessageId;
                        }
                    }
                }

                if ($parentInternetMessageIds !== []) {
                    $this->reconcileInboundReplies($mailbox, array_values($parentInternetMessageIds));
                }

                $this->persistPage($state, $page);
                $pages++;
            } while ($page->hasMore && $pages < $pagesPerJob);

            if ($isZoho && $isSent && $this->zohoSentBackfillComplete($mailbox)) {
                $this->reconcileDeferredEmailFallback($mailbox);
            }

            $state->forceFill([
                'status' => SyncStatus::Idle,
                'last_completed_at' => now(),
                'last_error' => null,
            ])->save();
            if ($wasFailed) {
                $duration = $failedSince instanceof \DateTimeInterface
                    ? max(0, now()->getTimestamp() - $failedSince->getTimestamp())
                    : null;
                $this->events->record(
                    $mailbox,
                    'mailbox_sync_recovered',
                    fromState: 'failed',
                    toState: 'idle',
                    metadata: [
                        'scope' => $this->enumValue($state->getAttribute('scope')),
                        'duration_seconds' => $duration,
                    ],
                );
            }
        } catch (Throwable $exception) {
            $this->failState($mailbox, $state, $exception);

            $isAuthFailure = $this->isAuthFailure($exception);
            $this->failMailbox($mailbox, $exception, reconnect: $isAuthFailure);

            if ($isAuthFailure) {
                $mailbox->connection->forceFill([
                    'status' => ConnectionStatus::NeedsReconnect,
                    'last_error_code' => 'sync_unauthorized',
                    'last_error_message' => $this->errorMessage($exception),
                ])->save();
            }

            throw $exception;
        }
    }

    private function persistPage(MailboxSyncState $state, RemoteMessagePage $page): void
    {
        $previousTimestamp = $state->high_water_timestamp === null
            ? null
            : (int) $state->high_water_timestamp;
        $pageTimestamp = $page->highWaterTimestamp;
        $previousIds = $this->remoteIds($state->high_water_remote_ids);
        $pageIds = $this->remoteIds($page->nextState['high_water_remote_ids'] ?? null);

        if ($pageTimestamp !== null && ($previousTimestamp === null || $pageTimestamp > $previousTimestamp)) {
            $highWaterTimestamp = $pageTimestamp;
            $highWaterRemoteIds = $pageIds;
        } elseif ($pageTimestamp !== null && $pageTimestamp === $previousTimestamp) {
            $highWaterTimestamp = $previousTimestamp;
            $highWaterRemoteIds = array_values(array_unique([...$previousIds, ...$pageIds]));
        } else {
            $highWaterTimestamp = $previousTimestamp;
            $highWaterRemoteIds = $state->high_water_remote_ids;
        }

        $scanState = $page->nextState;
        unset($scanState['high_water_remote_ids']);

        $state->forceFill([
            'cursor' => $page->cursor,
            'high_water_timestamp' => $highWaterTimestamp,
            'high_water_remote_ids' => $highWaterRemoteIds,
            'scan_state' => $scanState !== [] ? $scanState : $state->scan_state,
        ])->save();

        $state->refresh();
    }

    /**
     * @param  list<string>  $parentInternetMessageIds
     */
    private function reconcileInboundReplies(Mailbox $mailbox, array $parentInternetMessageIds): void
    {
        $query = Message::query()
            ->where('workspace_id', $mailbox->getAttribute('workspace_id'))
            ->where('mailbox_id', $mailbox->id)
            ->where('direction', MessageDirection::Inbound)
            ->where(function ($query) use ($parentInternetMessageIds): void {
                $query->whereIn('in_reply_to', $parentInternetMessageIds);

                foreach ($parentInternetMessageIds as $internetMessageId) {
                    $query->orWhereJsonContains('references', $internetMessageId);
                }
            })
            ->with(['thread', 'mailbox']);

        foreach ($query->get() as $message) {
            $this->effects->handle(
                $message,
                isNewMessage: false,
                allowEmailFallback: false,
            );
        }
    }

    private function reconcileDeferredEmailFallback(Mailbox $mailbox): void
    {
        $limit = max(1, (int) config('outboss.sync.pages_per_job', 5))
            * max(1, (int) config('outboss.sync.zoho.page_size', 100));
        $recipientCutoff = now()->subDays(90);
        $messageCutoff = now()->subDays(max(0, (int) config('outboss.sync.zoho.lookback_days', 30)));

        $messages = Message::query()
            ->where('workspace_id', $mailbox->getAttribute('workspace_id'))
            ->where('mailbox_id', $mailbox->id)
            ->where('direction', MessageDirection::Inbound)
            ->whereNull('campaign_recipient_id')
            ->whereNotNull('from_email')
            ->where('received_at', '>=', $messageCutoff)
            ->whereRaw(
                '(SELECT COUNT(*) FROM campaign_recipients
                    WHERE campaign_recipients.mailbox_id = messages.mailbox_id
                    AND campaign_recipients.workspace_id = messages.workspace_id
                    AND campaign_recipients.email_normalized = LOWER(TRIM(messages.from_email))
                    AND campaign_recipients.status != ?
                    AND campaign_recipients.created_at >= ?) = 1',
                [RecipientStatus::Removed->value, $recipientCutoff],
            )
            ->with(['thread', 'mailbox'])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($messages as $message) {
            $this->effects->handle(
                $message,
                isNewMessage: false,
                allowEmailFallback: true,
            );
        }
    }

    private function zohoSentBackfillComplete(Mailbox $mailbox): bool
    {
        $state = MailboxSyncState::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('scope', SyncScope::Sent)
            ->first();
        $scanState = $state?->getAttribute('scan_state');

        return is_array($scanState) && data_get($scanState, 'phase') === 'incremental';
    }

    private function failState(Mailbox $mailbox, MailboxSyncState $state, Throwable $exception): void
    {
        $fromState = $this->enumValue($state->getAttribute('status'));
        $state->forceFill([
            'status' => SyncStatus::Failed,
            'last_started_at' => $state->last_started_at ?? now(),
            'last_error' => $this->errorMessage($exception),
        ])->save();
        $this->events->record(
            $mailbox,
            'mailbox_sync_failed',
            fromState: $fromState,
            toState: SyncStatus::Failed->value,
            metadata: [
                'scope' => $this->enumValue($state->getAttribute('scope')),
                'failure_type' => class_basename($exception),
            ],
        );
    }

    private function failMailbox(Mailbox $mailbox, Throwable $exception, bool $reconnect): void
    {
        $attributes = [
            'last_sync_error' => $this->errorMessage($exception),
            'sync_requested_at' => null,
        ];

        if ($reconnect) {
            $attributes['status'] = ConnectionStatus::NeedsReconnect;
        }

        $mailbox->forceFill($attributes)->save();
    }

    private function failCredentials(Mailbox $mailbox, ProviderConnection $connection): void
    {
        $message = 'Stored credentials could not be decrypted - please reconnect';

        MailboxSyncState::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('status', SyncStatus::Syncing->value)
            ->get()
            ->each(function (MailboxSyncState $state) use ($mailbox, $message): void {
                $fromState = $this->enumValue($state->getAttribute('status'));
                $state->forceFill([
                    'status' => SyncStatus::Failed,
                    'last_error' => $message,
                ])->save();
                $this->events->record(
                    $mailbox,
                    'mailbox_sync_failed',
                    fromState: $fromState,
                    toState: SyncStatus::Failed->value,
                    metadata: [
                        'scope' => $this->enumValue($state->getAttribute('scope')),
                        'failure_type' => 'credential_decryption',
                    ],
                );
            });

        $connection->forceFill([
            'status' => ConnectionStatus::NeedsReconnect,
            'last_error_code' => 'corrupt_credentials',
            'last_error_message' => $message,
        ])->save();

        $mailbox->forceFill([
            'status' => ConnectionStatus::NeedsReconnect,
            'last_sync_error' => $message,
            'sync_requested_at' => null,
        ])->save();
    }

    private function clearSyncRequest(Mailbox $mailbox): void
    {
        if ($mailbox->sync_requested_at === null) {
            return;
        }

        $mailbox->forceFill(['sync_requested_at' => null])->save();
    }

    private function failWorkspaceMismatch(Mailbox $mailbox): void
    {
        $mailbox->forceFill([
            'last_sync_error' => 'Mailbox and provider connection belong to different workspaces.',
            'sync_requested_at' => null,
        ])->save();
    }

    private function isAuthFailure(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RequestException && in_array($current->response->status(), [401, 403], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function remoteIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (mixed $id): string => (string) $id,
            array_filter($ids, static fn (mixed $id): bool => is_scalar($id)),
        )));
    }

    private function errorMessage(Throwable $exception): string
    {
        return mb_substr($exception->getMessage(), 0, 180);
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) ? $value : null;
    }
}
