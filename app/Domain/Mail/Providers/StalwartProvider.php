<?php

namespace App\Domain\Mail\Providers;

use App\Domain\Mail\Contracts\MailTransport;
use App\Domain\Mail\DTOs\OutgoingMessage;
use App\Domain\Mail\DTOs\RemoteMailbox;
use App\Domain\Mail\DTOs\RemoteMessage;
use App\Domain\Mail\DTOs\RemoteMessagePage;
use App\Domain\Mail\DTOs\RemoteMessageReference;
use App\Domain\Mail\DTOs\RemoteSenderIdentity;
use App\Domain\Mail\DTOs\SendReconciliationResult;
use App\Domain\Mail\DTOs\SendResult;
use App\Domain\Mail\DTOs\UnknownSendReference;
use App\Domain\Mail\Enums\SyncScope;
use App\Domain\Mail\Services\MailboxSenderIdentityService;
use App\Models\Mailbox;
use App\Models\MailboxSyncState;
use App\Models\ProviderConnection;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use RuntimeException;
use Throwable;

/**
 * Stalwart's account-level JMAP transport.
 *
 * The transport deliberately implements no OAuth or management capability.
 * Managed mailbox credentials are supplied by the provisioning workflow and
 * are used only by StalwartJmapClient for account JMAP requests.
 */
final class StalwartProvider implements MailTransport
{
    public function __construct(
        private readonly StalwartJmapClient $client = new StalwartJmapClient,
        private readonly StalwartMessageMapper $mapper = new StalwartMessageMapper,
    ) {}

    /** @return list<RemoteMailbox> */
    public function mailboxes(ProviderConnection $connection): array
    {
        $context = $this->client->mailboxContext($connection);
        $email = $this->accountEmail($connection);

        if ($email === '') {
            throw new RuntimeException('stalwart_account_email_missing');
        }

        $displayName = $this->accountDisplayName($connection);
        $metadata = [
            'account_id' => $context['account_id'],
            'inbox_id' => $context['inbox_id'],
            'sent_id' => $context['sent_id'],
            'managed_instance_key' => $connection->getAttribute('managed_instance_key'),
        ];

        return [new RemoteMailbox(
            providerMailboxId: $context['account_id'],
            emailAddress: $email,
            displayName: $displayName,
            metadata: $metadata,
            senderIdentities: [new RemoteSenderIdentity(
                key: MailboxSenderIdentityService::keyForEmail($email),
                emailAddress: $email,
                displayName: $displayName,
                remoteId: $this->identityId($connection),
                mode: 'primary',
            )],
        )];
    }

    public function syncFolder(Mailbox $mailbox, MailboxSyncState $state, int $pageSize = 100): RemoteMessagePage
    {
        $connection = $this->connectionFor($mailbox);
        $context = $this->client->mailboxContext($connection);
        $scope = $this->scope($state);
        $folderId = $this->folderId($state, $context, $scope);
        $mailboxAddress = (string) $mailbox->getAttribute('email_address');
        $limit = max(1, min($pageSize, 200));
        $stored = $state->getAttribute('scan_state');
        $scanState = is_array($stored) ? $stored : [];
        $phase = data_get($scanState, 'jmap_phase', 'backfill') === 'incremental'
            ? 'incremental'
            : 'backfill';
        $accountId = $context['account_id'];

        if ($phase === 'incremental' && is_string(data_get($scanState, 'jmap_query_state'))) {
            return $this->incrementalPage(
                $connection,
                $accountId,
                $folderId,
                $scope,
                $mailboxAddress,
                $scanState,
                $limit,
            );
        }

        // A backfill may span several jobs.  Keep reconciling from the state
        // captured by the first query until all query changes are consumed;
        // advancing this state on each page would make an insertion that
        // shifted an offset invisible to the eventual queryChanges call.
        $reconcileQueryState = $this->nullableString(data_get($scanState, 'jmap_reconcile_query_state'));
        $backfillQueryState = $this->nullableString(data_get($scanState, 'jmap_backfill_query_state'));

        if ($reconcileQueryState !== null && $backfillQueryState !== null) {
            return $this->reconcileBackfill(
                $connection,
                $accountId,
                $folderId,
                $scope,
                $mailboxAddress,
                $reconcileQueryState,
                $backfillQueryState,
                $limit,
            );
        }

        $position = max(0, (int) data_get($scanState, 'jmap_position', 0));
        $anchor = $this->nullableString(data_get($scanState, 'jmap_anchor'));

        // States written by versions before the pinned-query state existed
        // cannot be reconciled safely. Restart that backfill once instead of
        // treating a later-page queryState as the initial snapshot.
        if ($position > 0 && $backfillQueryState === null && $anchor === null) {
            $position = 0;
        }

        try {
            $query = $this->client->queryMessages(
                $connection,
                $accountId,
                $folderId,
                $position,
                $limit,
                $anchor,
            );
        } catch (StalwartJmapException $exception) {
            if ($anchor === null || $exception->safeCode !== 'stalwart_jmap_anchor_not_found') {
                throw $exception;
            }

            // RFC 8620 rejects a query when its anchor was deleted. Restart
            // from the beginning; the pinned queryChanges reconciliation and
            // idempotent local upsert make this safe even if the preceding
            // page was already persisted.
            $position = 0;
            $anchor = null;
            $query = $this->client->queryMessages(
                $connection,
                $accountId,
                $folderId,
                $position,
                $limit,
            );
        }

        $ids = $this->stringList($query['ids'] ?? []);
        $rawMessages = $this->client->getMessages($connection, $accountId, $ids);
        $messages = $this->mapMessages($rawMessages, $scope, $mailboxAddress);
        $queryState = $this->nullableString($query['queryState'] ?? null);
        // RFC 8621 calls this capability canCalculateChanges. Keep the
        // older alias for fixture/provider variants seen during rollout.
        $canCalculateUpdates = (bool) data_get($scanState, 'jmap_can_calculate_changes', false)
            || (bool) ($query['canCalculateChanges']
            ?? $query['canCalculateUpdates']
            ?? false);
        $total = is_numeric($query['total'] ?? null) ? (int) $query['total'] : null;
        $responsePosition = is_numeric($query['position'] ?? null)
            ? max(0, (int) $query['position'])
            : $position;
        $hasMore = $total !== null
            ? $responsePosition + count($ids) < $total
            : count($ids) >= $limit;
        $nextPosition = $responsePosition + count($ids);
        $nextAnchor = $ids !== [] ? $ids[array_key_last($ids)] : null;
        $highWater = $this->highWater($messages);
        $initialQueryState = $backfillQueryState ?? $queryState;
        $nextState = [
            'jmap_account_id' => $accountId,
            'jmap_folder_id' => $folderId,
            'jmap_position' => $hasMore ? $nextPosition : 0,
            'jmap_phase' => 'backfill',
            // Keep the public cursor pinned to the first query generation;
            // the current page's queryState must not replace it mid-walk.
            'jmap_query_state' => $initialQueryState,
        ];

        if ($initialQueryState !== null) {
            $nextState['jmap_backfill_query_state'] = $initialQueryState;
        }

        if ($canCalculateUpdates) {
            $nextState['jmap_can_calculate_changes'] = true;
        }

        if ($hasMore && $nextAnchor !== null) {
            // Anchor pagination is defined by RFC 8620 and remains stable if
            // a newer reply is inserted above this page or an older item is
            // removed before it. Position is retained as a diagnostic/fallback
            // value, but is not used while an anchor is present.
            $nextState['jmap_anchor'] = $nextAnchor;
        }

        if (! $hasMore && $canCalculateUpdates && $initialQueryState !== null) {
            $reconciled = $this->reconcileBackfill(
                $connection,
                $accountId,
                $folderId,
                $scope,
                $mailboxAddress,
                $initialQueryState,
                $initialQueryState,
                $limit,
                $messages,
            );

            $messages = $reconciled->messages;
            $highWater = $this->highWater($messages);
            $nextState = $reconciled->nextState;

            return new RemoteMessagePage(
                messages: $messages,
                cursor: $reconciled->cursor,
                highWaterTimestamp: $highWater['timestamp'],
                nextState: $nextState,
                hasMore: $reconciled->hasMore,
            );
        }

        if ($highWater['remote_ids'] !== []) {
            $nextState['high_water_remote_ids'] = $highWater['remote_ids'];
        }

        return new RemoteMessagePage(
            messages: $messages,
            cursor: $initialQueryState,
            highWaterTimestamp: $highWater['timestamp'],
            nextState: $nextState,
            hasMore: $hasMore,
        );
    }

    public function sendNew(Mailbox $mailbox, OutgoingMessage $message): SendResult
    {
        return $this->send($mailbox, $message, null);
    }

    public function sendReply(Mailbox $mailbox, RemoteMessageReference $replyTo, OutgoingMessage $message): SendResult
    {
        return $this->send($mailbox, $message, $replyTo);
    }

    public function reconcileUnknownSend(Mailbox $mailbox, UnknownSendReference $reference): SendReconciliationResult
    {
        if ($reference->submissionState === 'not_created') {
            // Email/set can legitimately leave a copy in Sent even when the
            // paired EmailSubmission/set was rejected.  That copy is not
            // evidence of acceptance and must never be promoted to delivered.
            return SendReconciliationResult::confirmedNotSent([
                'submission_state' => 'not_created',
                'email_created' => true,
            ]);
        }

        try {
            $matches = $this->client->searchSent($mailbox, $reference);
        } catch (Throwable $exception) {
            $code = $exception instanceof StalwartJmapException
                ? $exception->safeCode
                : 'stalwart_reconciliation_unavailable';

            $pending = $this->pendingReconciliation($reference);

            if ($pending !== null) {
                return $pending;
            }

            return SendReconciliationResult::manualReview(
                code: $code,
                message: 'The managed mail provider result could not be reconciled automatically; confirm before retrying.',
            );
        }

        if (count($matches) === 0) {
            if ($reference->submissionState === 'accepted') {
                return SendReconciliationResult::manualReview(
                    code: 'stalwart_submission_accepted_not_visible',
                    message: 'The provider accepted the submission, but its Sent copy is not visible; confirm before retrying.',
                );
            }

            // Stalwart may commit a submission before the message is visible
            // in Sent. A first empty read is therefore not evidence that no
            // message was accepted. Keep the attempt retryable until the
            // configured consistency window has elapsed.
            $pending = $this->pendingReconciliation($reference);

            if ($pending !== null) {
                return $pending;
            }

            // Without a submission timestamp there is no safe way to bound a
            // retry window. This should be unusual (the service normally
            // carries the timestamp from the attempt), but an unbounded
            // unknown send must not be retried forever.
            if ($reference->submittedAt === null) {
                return SendReconciliationResult::manualReview(
                    code: 'stalwart_reconciliation_deadline_unknown',
                    message: 'The managed provider result has no submission timestamp; confirm before retrying.',
                );
            }

            $delay = $this->reconciliationConsistencyDelay();

            return SendReconciliationResult::confirmedNotSent([
                'consistency_delay_seconds' => $delay,
                'consistency_delay_elapsed' => true,
            ]);
        }

        if (count($matches) !== 1) {
            return SendReconciliationResult::manualReview(
                code: 'stalwart_reconciliation_ambiguous',
                message: 'More than one managed message matched the send marker; confirm before retrying.',
                metadata: ['match_count' => count($matches)],
            );
        }

        $raw = $matches[0];
        $remote = $this->mapper->map($raw, SyncScope::Sent, (string) $mailbox->getAttribute('email_address'));

        return SendReconciliationResult::confirmedSent(
            result: new SendResult(
                remoteMessageId: $remote->remoteMessageId,
                remoteThreadId: $remote->remoteThreadId,
                internetMessageId: $remote->internetMessageId,
                providerRequestId: $this->nullableString($raw['requestId'] ?? null),
                phase: 'reconciled_sent',
                metadata: array_filter([
                    'matched_by' => $this->matchedBy($raw, $reference),
                    'submission_state' => $reference->submissionState,
                ], static fn (mixed $value): bool => $value !== null),
            ),
            message: $remote,
        );
    }

    /**
     * Apply one bounded page of JMAP query changes captured during backfill.
     *
     * @param  list<RemoteMessage>  $alreadyFetched
     */
    private function reconcileBackfill(
        ProviderConnection $connection,
        string $accountId,
        string $folderId,
        SyncScope $scope,
        string $mailboxAddress,
        string $sinceQueryState,
        string $backfillQueryState,
        int $limit,
        array $alreadyFetched = [],
    ): RemoteMessagePage {
        $changes = $this->client->queryMessageChanges(
            $connection,
            $accountId,
            $folderId,
            $sinceQueryState,
            $limit,
        );
        $addedIds = $this->addedIds($changes['added'] ?? []);
        $changedMessages = $this->mapMessages(
            $this->client->getMessages($connection, $accountId, $addedIds),
            $scope,
            $mailboxAddress,
        );
        $messages = $this->mergeMessages($alreadyFetched, $changedMessages);
        $newQueryState = $this->nullableString($changes['newQueryState'] ?? null);
        $hasMore = (bool) ($changes['hasMoreChanges'] ?? false);

        if ($hasMore && $newQueryState === null) {
            // Stalwart's paginated extension must advance the state for the
            // next bounded page. RFC 8620 otherwise reports tooManyChanges;
            // retrying the same state would duplicate forever and could hide
            // an incomplete backfill.
            throw new StalwartJmapException(
                'stalwart_reconciliation_incomplete',
                'stalwart_reconciliation_unavailable',
            );
        }

        $nextState = [
            'jmap_account_id' => $accountId,
            'jmap_folder_id' => $folderId,
            'jmap_position' => 0,
            'jmap_phase' => $hasMore ? 'backfill' : 'incremental',
            'jmap_query_state' => $hasMore ? $backfillQueryState : ($newQueryState ?? $sinceQueryState),
        ];

        if ($hasMore) {
            $nextState['jmap_backfill_query_state'] = $backfillQueryState;
            $nextState['jmap_reconcile_query_state'] = $newQueryState;
            $nextState['jmap_can_calculate_changes'] = true;
        }

        $highWater = $this->highWater($messages);

        if ($highWater['remote_ids'] !== []) {
            $nextState['high_water_remote_ids'] = $highWater['remote_ids'];
        }

        return new RemoteMessagePage(
            messages: $messages,
            cursor: $hasMore ? $backfillQueryState : ($newQueryState ?? $sinceQueryState),
            highWaterTimestamp: $highWater['timestamp'],
            nextState: $nextState,
            hasMore: $hasMore,
        );
    }

    private function send(Mailbox $mailbox, OutgoingMessage $message, ?RemoteMessageReference $replyTo): SendResult
    {
        $this->connectionFor($mailbox);
        $submitted = $this->client->submit($mailbox, $message, $replyTo);
        $email = $submitted['email'];
        $submission = $submitted['submission'];
        $remoteMessageId = $this->nullableString($email['id'] ?? null);

        if ($remoteMessageId === null) {
            throw new StalwartJmapException('stalwart_email_id_missing', 'stalwart_submission_failed');
        }

        $messageId = $this->firstString($email['messageId'] ?? null);
        $threadId = $this->nullableString($email['threadId'] ?? null) ?: $replyTo?->remoteThreadId;
        $submissionId = $this->nullableString($submission['id'] ?? null);

        return new SendResult(
            remoteMessageId: $remoteMessageId,
            remoteThreadId: $threadId,
            internetMessageId: $messageId,
            providerDraftId: $submissionId,
            providerRequestId: $this->nullableString($submitted['request_id'] ?? null),
            phase: 'send_submitted',
            metadata: array_filter([
                'submission_id' => $submissionId,
                'submission_state' => 'accepted',
                'client_message_marker' => $this->nullableString($email['clientMarker'] ?? $message->clientMessageMarker),
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /** @param array<string, mixed> $scanState */
    private function incrementalPage(
        ProviderConnection $connection,
        string $accountId,
        string $folderId,
        SyncScope $scope,
        string $mailboxAddress,
        array $scanState,
        int $limit,
    ): RemoteMessagePage {
        $queryState = (string) data_get($scanState, 'jmap_query_state');
        $changes = $this->client->queryMessageChanges($connection, $accountId, $folderId, $queryState, $limit);
        $added = $changes['added'] ?? [];
        $ids = [];

        if (is_array($added)) {
            foreach ($added as $entry) {
                $id = is_string($entry) ? $entry : (is_array($entry) ? ($entry['id'] ?? null) : null);
                if (is_string($id) && $id !== '') {
                    $ids[] = $id;
                }
            }
        }

        $messages = $this->mapMessages(
            $this->client->getMessages($connection, $accountId, array_values(array_unique($ids))),
            $scope,
            $mailboxAddress,
        );
        $newState = $this->nullableString($changes['newQueryState'] ?? null) ?: $queryState;
        $hasMore = (bool) ($changes['hasMoreChanges'] ?? false);
        $highWater = $this->highWater($messages);

        $nextState = [
            'jmap_account_id' => $accountId,
            'jmap_folder_id' => $folderId,
            'jmap_position' => 0,
            'jmap_phase' => 'incremental',
            'jmap_query_state' => $newState,
        ];

        if ($highWater['remote_ids'] !== []) {
            $nextState['high_water_remote_ids'] = $highWater['remote_ids'];
        }

        return new RemoteMessagePage(
            messages: $messages,
            cursor: $newState,
            highWaterTimestamp: $highWater['timestamp'],
            nextState: $nextState,
            hasMore: $hasMore,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $raw
     * @return list<RemoteMessage>
     */
    private function mapMessages(array $raw, SyncScope $scope, string $mailboxAddress): array
    {
        return array_map(
            fn (array $message): RemoteMessage => $this->mapper->map($message, $scope, $mailboxAddress),
            $raw,
        );
    }

    /**
     * @param  list<RemoteMessage>  $messages
     * @return array{timestamp:?int,remote_ids:list<string>}
     */
    private function highWater(array $messages): array
    {
        $timestamp = null;
        $remoteIds = [];

        foreach ($messages as $message) {
            $current = $message->occurredAt?->getTimestamp();

            if ($current === null || ($timestamp !== null && $current < $timestamp)) {
                continue;
            }

            if ($timestamp === null || $current > $timestamp) {
                $timestamp = $current;
                $remoteIds = [];
            }

            $remoteIds[] = $message->remoteMessageId;
        }

        return ['timestamp' => $timestamp, 'remote_ids' => array_values(array_unique($remoteIds))];
    }

    /** @param array<string, mixed> $context */
    private function folderId(MailboxSyncState $state, array $context, SyncScope $scope): string
    {
        $stored = $state->getAttribute('remote_folder_id');
        $folderId = is_scalar($stored) && (string) $stored !== ''
            ? (string) $stored
            : ($scope === SyncScope::Sent ? $context['sent_id'] : $context['inbox_id']);

        if (! is_string($folderId) || $folderId === '') {
            throw new StalwartJmapException(
                $scope === SyncScope::Sent ? 'stalwart_sent_mailbox_missing' : 'stalwart_inbox_mailbox_missing',
                'stalwart_mailbox_discovery_failed',
            );
        }

        return $folderId;
    }

    private function scope(MailboxSyncState $state): SyncScope
    {
        $scope = $state->getAttribute('scope');

        return $scope instanceof SyncScope ? $scope : SyncScope::from((string) $scope);
    }

    private function connectionFor(Mailbox $mailbox): ProviderConnection
    {
        $connection = $mailbox->relationLoaded('connection')
            ? $mailbox->getRelation('connection')
            : $mailbox->connection()->first();

        if (! $connection instanceof ProviderConnection
            || (int) $connection->getAttribute('workspace_id') !== (int) $mailbox->getAttribute('workspace_id')) {
            throw new StalwartJmapException('stalwart_workspace_mismatch', 'workspace_mismatch');
        }

        return $connection;
    }

    private function reconciliationConsistencyDelay(): int
    {
        $configured = config('outboss.managed_mail.reconciliation_consistency_delay_seconds', 60);

        return max(1, min(3600, is_numeric($configured) ? (int) $configured : 60));
    }

    private function reconciliationRetryDelay(): int
    {
        // Reconciliation is normally invoked every five minutes, but the
        // due_at value returned here also protects manual/queue invocations
        // from hot-looping while the provider's Sent index catches up.
        $configured = config('outboss.managed_mail.reconciliation_retry_seconds', 30);

        return max(1, min(300, is_numeric($configured) ? (int) $configured : 30));
    }

    private function pendingReconciliation(UnknownSendReference $reference): ?SendReconciliationResult
    {
        $submittedAt = $reference->submittedAt;

        if (! $submittedAt instanceof DateTimeInterface) {
            return null;
        }

        $delay = $this->reconciliationConsistencyDelay();
        $deadline = CarbonImmutable::instance($submittedAt)->addSeconds($delay);
        $now = CarbonImmutable::now();

        if (! $deadline->isFuture()) {
            return null;
        }

        $retryAt = $now->addSeconds($this->reconciliationRetryDelay());

        if ($retryAt->greaterThan($deadline)) {
            $retryAt = $deadline;
        }

        return SendReconciliationResult::pending(
            code: 'stalwart_reconciliation_pending',
            message: 'The managed provider has not made the submission visible yet; retry reconciliation before the consistency deadline.',
            metadata: [
                'consistency_delay_seconds' => $delay,
                'consistency_deadline' => $deadline->toIso8601String(),
                'retry_at' => $retryAt->toIso8601String(),
            ],
        );
    }

    private function accountEmail(ProviderConnection $connection): string
    {
        $credentials = $this->credentialPayload($connection);
        $email = $credentials['email']
            ?? $credentials['username']
            ?? $credentials['jmap_username']
            ?? $connection->getAttribute('provider_user_id');

        return is_string($email) && filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false
            ? strtolower(trim($email))
            : '';
    }

    private function accountDisplayName(ProviderConnection $connection): ?string
    {
        $credentials = $this->credentialPayload($connection);
        $name = $credentials['display_name'] ?? $credentials['name'] ?? null;

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    private function identityId(ProviderConnection $connection): ?string
    {
        $credentials = $this->credentialPayload($connection);
        $identity = $credentials['identity_id'] ?? null;

        return is_string($identity) && $identity !== '' ? $identity : null;
    }

    /** @return array<string, mixed> */
    private function credentialPayload(ProviderConnection $connection): array
    {
        try {
            $payload = $connection->getAttribute('credential_payload');
            if (is_string($payload)) {
                $payload = json_decode($payload, true);
            }

            return is_array($payload) ? $payload : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && $item !== ''));
    }

    /** @return list<string> */
    private function addedIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $entry) {
            $id = is_string($entry)
                ? $entry
                : (is_array($entry) && is_string($entry['id'] ?? null) ? $entry['id'] : null);

            if ($id !== null && $id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Keep one local message for each remote id when a change also appeared
     * in the final backfill page. UpsertRemoteMessage is idempotent, but
     * avoiding duplicate DTOs prevents duplicate side effects in one job.
     *
     * @param  list<RemoteMessage>  ...$groups
     * @return list<RemoteMessage>
     */
    private function mergeMessages(array ...$groups): array
    {
        $merged = [];
        $seen = [];

        foreach ($groups as $messages) {
            foreach ($messages as $message) {
                if (isset($seen[$message->remoteMessageId])) {
                    continue;
                }

                $seen[$message->remoteMessageId] = true;
                $merged[] = $message;
            }
        }

        return $merged;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function firstString(mixed $value): ?string
    {
        if (is_string($value)) {
            return $this->nullableString($value);
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_string($item) && trim($item) !== '') {
                    return trim($item, " <>\t\r\n");
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $raw */
    private function matchedBy(array $raw, UnknownSendReference $reference): string
    {
        if ($reference->clientMessageMarker !== null && $this->header($raw, 'X-Outboss-Message-Id') !== null) {
            return 'client_message_marker';
        }

        return 'internet_message_id';
    }

    /** @param array<string, mixed> $raw */
    private function header(array $raw, string $name): ?string
    {
        foreach ($raw as $key => $value) {
            if (! str_starts_with(strtolower($key), 'header:')) {
                continue;
            }

            $header = preg_replace('/:as[a-z]+(?::all)?$/i', '', substr($key, 7)) ?: substr($key, 7);
            if (strcasecmp($header, $name) === 0) {
                return is_string($value) ? trim($value) : null;
            }
        }

        return null;
    }
}
