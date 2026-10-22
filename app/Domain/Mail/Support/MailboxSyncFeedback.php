<?php

namespace App\Domain\Mail\Support;

use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\SyncScope;
use App\Domain\Mail\Enums\SyncStatus;
use App\Models\Mailbox;
use App\Models\MailboxSyncState;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class MailboxSyncFeedback
{
    public const STALE_REQUEST_SECONDS = 1080;

    public const STALE_SYNC_SECONDS = 600;

    /**
     * @return array{
     *     phase: 'idle'|'queued'|'syncing'|'failed',
     *     percent: int|null,
     *     phase_label: string,
     *     detail: string,
     *     button_label: string,
     *     last_sync_label: string,
     *     scope_label: string,
     *     active_scope: 'inbox'|'sent'|null
     * }
     */
    public static function for(Mailbox $mailbox): array
    {
        /** @var Collection<int, MailboxSyncState> $states */
        $states = $mailbox->relationLoaded('syncStates')
            ? $mailbox->syncStates
            : $mailbox->syncStates()->get();

        if ($mailbox->getAttribute('status') === ConnectionStatus::NeedsReconnect) {
            return [
                'phase' => $mailbox->last_sync_error ? 'failed' : 'idle',
                'percent' => null,
                'phase_label' => $mailbox->last_sync_error ? 'Sync failed' : 'Sync paused',
                'detail' => 'Sync paused',
                'button_label' => 'Reconnect',
                'last_sync_label' => self::lastSyncedLabel($mailbox),
                'scope_label' => 'Sync paused',
                'active_scope' => null,
            ];
        }

        $inbox = $states->first(fn (MailboxSyncState $state): bool => $state->getAttribute('scope') === SyncScope::Inbox);
        $sent = $states->first(fn (MailboxSyncState $state): bool => $state->getAttribute('scope') === SyncScope::Sent);
        $active = ($inbox?->getAttribute('status') === SyncStatus::Syncing)
            ? $inbox
            : (($sent?->getAttribute('status') === SyncStatus::Syncing) ? $sent : null);

        if ($active !== null) {
            if (self::isStale($active)) {
                return [
                    'phase' => 'failed',
                    'percent' => null,
                    'phase_label' => 'Sync failed',
                    'detail' => $mailbox->last_sync_error ?: 'Sync interrupted',
                    'button_label' => 'Sync',
                    'last_sync_label' => 'Sync failed',
                    'scope_label' => 'Inbox + Sent',
                    'active_scope' => null,
                ];
            }

            return self::syncing($active);
        }

        $requestedAt = $mailbox->getAttribute('sync_requested_at');
        $requestedAt = $requestedAt instanceof CarbonInterface ? $requestedAt : null;
        $lastSyncedAt = $mailbox->getAttribute('last_synced_at');
        $lastSyncedAt = $lastSyncedAt instanceof CarbonInterface ? $lastSyncedAt : null;
        $pending = $requestedAt !== null
            && ($lastSyncedAt === null || $requestedAt->gt($lastSyncedAt));

        if ($pending) {
            $failedFromThisRequest = $states->contains(function (MailboxSyncState $state) use ($requestedAt): bool {
                $startedAt = $state->getAttribute('last_started_at');

                return $state->getAttribute('status') === SyncStatus::Failed
                    && $startedAt instanceof CarbonInterface
                    && $startedAt->gte($requestedAt);
            });

            if ($failedFromThisRequest) {
                return self::failed($mailbox);
            }

            if ($requestedAt->lte(now()->subSeconds(self::STALE_REQUEST_SECONDS))) {
                return [
                    'phase' => 'failed',
                    'percent' => null,
                    'phase_label' => 'Sync failed',
                    'detail' => $mailbox->last_sync_error ?: 'Sync timed out',
                    'button_label' => 'Sync',
                    'last_sync_label' => 'Sync failed',
                    'scope_label' => 'Inbox + Sent',
                    'active_scope' => null,
                ];
            }

            return [
                'phase' => 'queued',
                'percent' => null,
                'phase_label' => 'Sync queued',
                'detail' => 'Waiting for the background worker to start',
                'button_label' => 'Queued',
                'last_sync_label' => 'Sync queued',
                'scope_label' => 'Inbox + Sent',
                'active_scope' => null,
            ];
        }

        $anyFailed = $states->contains(
            fn (MailboxSyncState $state): bool => $state->getAttribute('status') === SyncStatus::Failed,
        );

        if ($anyFailed && filled($mailbox->last_sync_error)) {
            return self::failed($mailbox);
        }

        return [
            'phase' => 'idle',
            'percent' => null,
            'phase_label' => $lastSyncedAt ? 'Up to date' : 'Never synced',
            'detail' => 'Inbox + Sent',
            'button_label' => 'Sync',
            'last_sync_label' => self::lastSyncedLabel($mailbox),
            'scope_label' => 'Inbox + Sent',
            'active_scope' => null,
        ];
    }

    private static function isStale(MailboxSyncState $state): bool
    {
        $startedAt = $state->getAttribute('last_started_at');

        return $startedAt instanceof CarbonInterface
            && $startedAt->lte(now()->subSeconds(self::STALE_SYNC_SECONDS));
    }

    /**
     * @return array{
     *     phase: 'syncing',
     *     percent: int|null,
     *     phase_label: string,
     *     detail: string,
     *     button_label: string,
     *     last_sync_label: string,
     *     scope_label: string,
     *     active_scope: 'inbox'|'sent'
     * }
     */
    private static function syncing(MailboxSyncState $state): array
    {
        $isInbox = $state->getAttribute('scope') === SyncScope::Inbox;
        $isBackfill = data_get($state->scan_state, 'phase') === 'backfill';
        $lookbackDays = max(1, (int) config('outboss.sync.zoho.lookback_days', 30));
        // Real progress while backfilling (position within the lookback window),
        // indeterminate once the scope switches to incremental checks.
        $progress = data_get($state->scan_state, 'backfill_progress');
        $percent = $isBackfill
            ? max(1, min(99, is_numeric($progress) ? (int) $progress : 1))
            : null;

        if ($isInbox) {
            $phaseLabel = $isBackfill ? 'Importing inbox' : 'Checking for new mail';
            $detail = $isBackfill ? "Last {$lookbackDays} days · Inbox" : 'Inbox';
            $scopeLabel = 'Inbox';
            $activeScope = 'inbox';
        } else {
            $phaseLabel = $isBackfill ? 'Importing sent mail' : 'Checking sent mail';
            $detail = $isBackfill ? "Last {$lookbackDays} days · Sent" : 'Sent';
            $scopeLabel = 'Sent';
            $activeScope = 'sent';
        }

        return [
            'phase' => 'syncing',
            'percent' => $percent,
            'phase_label' => $phaseLabel,
            'detail' => $detail,
            'button_label' => $percent === null ? 'Syncing' : 'Syncing · '.$percent.'%',
            'last_sync_label' => 'Syncing now',
            'scope_label' => $scopeLabel,
            'active_scope' => $activeScope,
        ];
    }

    /**
     * @return array{
     *     phase: 'failed',
     *     percent: null,
     *     phase_label: string,
     *     detail: string,
     *     button_label: string,
     *     last_sync_label: string,
     *     scope_label: string,
     *     active_scope: null
     * }
     */
    private static function failed(Mailbox $mailbox): array
    {
        return [
            'phase' => 'failed',
            'percent' => null,
            'phase_label' => 'Sync failed',
            'detail' => $mailbox->last_sync_error ?: 'Sync failed',
            'button_label' => 'Sync',
            'last_sync_label' => 'Sync failed',
            'scope_label' => 'Inbox + Sent',
            'active_scope' => null,
        ];
    }

    private static function lastSyncedLabel(Mailbox $mailbox): string
    {
        $lastSyncedAt = $mailbox->getAttribute('last_synced_at');

        if (! $lastSyncedAt instanceof CarbonInterface) {
            return 'Never synced';
        }

        return 'Last synced '.$lastSyncedAt->diffForHumans();
    }
}
