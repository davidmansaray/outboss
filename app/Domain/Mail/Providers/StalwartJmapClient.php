<?php

namespace App\Domain\Mail\Providers;

use App\Domain\Mail\DTOs\OutgoingMessage;
use App\Domain\Mail\DTOs\RemoteMessageReference;
use App\Domain\Mail\DTOs\UnknownSendReference;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\ManagedMailboxAccountStatus;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Domain\Mail\Support\MessageIdNormalizer;
use App\Models\Mailbox;
use App\Models\ManagedMailboxAccount;
use App\Models\ProviderConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Small, deliberately scoped client for Stalwart's account JMAP API.
 *
 * The management API is intentionally not exposed here.  This client only
 * accepts a ProviderConnection and therefore can authenticate with the
 * mailbox account/app-password credential, never the operator admin key.
 */
class StalwartJmapClient
{
    private const CORE = 'urn:ietf:params:jmap:core';

    private const MAIL = 'urn:ietf:params:jmap:mail';

    private const SUBMISSION = 'urn:ietf:params:jmap:submission';

    private const RECONCILIATION_PAGE_SIZE = 50;

    private const MAX_RECONCILIATION_PAGES = 100;

    /** @return array<string, mixed> */
    public function session(ProviderConnection $connection): array
    {
        $key = $this->sessionCacheKey($connection);
        $ttl = max(1, min(300, $this->integerConfig([
            'stalwart.session_cache_seconds',
            'services.stalwart.session_cache_seconds',
            'outboss.managed_mail.session_cache_seconds',
        ], 60)));

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        [$username, $password] = $this->credentials($connection);
        $response = $this->request(
            connection: $connection,
            method: 'GET',
            url: $this->baseUrl($connection).'/.well-known/jmap',
            username: $username,
            password: $password,
            retryable: true,
        );
        $payload = $this->json($response, 'stalwart_session_invalid');

        if (! isset($payload['apiUrl']) || ! is_string($payload['apiUrl']) || trim($payload['apiUrl']) === '') {
            throw new StalwartJmapException('stalwart_session_missing_api_url', 'stalwart_session_invalid');
        }

        Cache::put($key, $payload, now()->addSeconds($ttl));

        return $payload;
    }

    public function invalidateSession(ProviderConnection $connection): void
    {
        Cache::forget($this->sessionCacheKey($connection));
    }

    /**
     * Execute one or more JMAP method calls and return method response tuples.
     *
     * @param  list<array{0:string,1:array<string, mixed>,2:string}>  $calls
     * @param  list<string>|null  $using
     * @return list<array<int, mixed>>
     */
    public function call(
        ProviderConnection $connection,
        array $calls,
        ?array $using = null,
        bool $throwMethodErrors = true,
    ): array {
        $session = $this->session($connection);
        [$username, $password] = $this->credentials($connection);
        $using ??= [self::CORE, self::MAIL, self::SUBMISSION];

        $response = $this->request(
            connection: $connection,
            method: 'POST',
            url: $this->advertisedUrl($session['apiUrl'] ?? null, $connection),
            username: $username,
            password: $password,
            body: [
                'using' => array_values(array_unique($using)),
                'methodCalls' => $calls,
            ],
            retryable: false,
        );
        $payload = $this->json($response, 'stalwart_jmap_invalid');
        $responses = $payload['methodResponses'] ?? null;

        if (! is_array($responses)) {
            throw new StalwartJmapException('stalwart_jmap_missing_responses', 'stalwart_jmap_invalid');
        }

        $normalisedResponses = [];

        foreach ($responses as $methodResponse) {
            if (! isset($methodResponse[0])) {
                continue;
            }

            $normalisedResponses[] = $methodResponse;

            $type = (string) $methodResponse[0];

            if ($type === 'error' && $throwMethodErrors) {
                $this->throwMethodError(
                    is_array($methodResponse[1] ?? null) ? $methodResponse[1] : [],
                    $connection,
                );
            }
        }

        return $normalisedResponses;
    }

    /** @return list<array<string, mixed>> */
    public function discoverMailboxes(ProviderConnection $connection): array
    {
        $session = $this->session($connection);
        $accountId = $this->accountId($session, $connection);
        $responses = $this->call($connection, [[
            'Mailbox/get',
            [
                'accountId' => $accountId,
                'properties' => [
                    'id',
                    'name',
                    'role',
                    'parentId',
                    'sortOrder',
                    'mayReadItems',
                    'mayAddItems',
                ],
            ],
            'mailboxes',
        ]], [self::CORE, self::MAIL]);
        $payload = $this->responsePayload($responses, 'Mailbox/get');
        $list = $payload['list'] ?? [];

        if (! is_array($list)) {
            return [];
        }

        $mailboxes = [];

        foreach ($list as $row) {
            if (! is_array($row) || ! is_string($row['id'] ?? null)) {
                continue;
            }

            $mailboxes[] = [
                ...$row,
                'accountId' => $accountId,
            ];
        }

        return $mailboxes;
    }

    /**
     * @return array{account_id:string,inbox_id:?string,sent_id:?string,mailboxes:list<array<string,mixed>>,session:array<string,mixed>}
     */
    public function mailboxContext(ProviderConnection $connection): array
    {
        $session = $this->session($connection);
        $accountId = $this->accountId($session, $connection);
        $mailboxes = $this->discoverMailboxes($connection);
        $inboxId = null;
        $sentId = null;

        foreach ($mailboxes as $mailbox) {
            $role = strtolower((string) ($mailbox['role'] ?? ''));
            $name = strtolower(trim((string) ($mailbox['name'] ?? '')));

            if ($role === 'inbox' || ($inboxId === null && $name === 'inbox')) {
                $inboxId = (string) $mailbox['id'];
            }

            if ($role === 'sent' || ($sentId === null && in_array($name, ['sent', 'sent items', 'sent mail'], true))) {
                $sentId = (string) $mailbox['id'];
            }
        }

        return [
            'account_id' => $accountId,
            'inbox_id' => $inboxId,
            'sent_id' => $sentId,
            'mailboxes' => $mailboxes,
            'session' => $session,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function queryMessages(
        ProviderConnection $connection,
        string $accountId,
        string $folderId,
        int $position = 0,
        int $limit = 100,
        ?string $anchor = null,
    ): array {
        $arguments = [
            'accountId' => $accountId,
            'filter' => ['inMailbox' => $folderId],
            'sort' => [
                ['property' => 'receivedAt', 'isAscending' => false],
            ],
        ];

        if ($anchor !== null && trim($anchor) !== '') {
            // RFC 8620 requires an anchor to be resolved against the current
            // result set, so inserts/deletes before the page cannot shift the
            // next page as they can with a position offset.
            $arguments['anchor'] = trim($anchor);
            $arguments['anchorOffset'] = 1;
        } else {
            $arguments['position'] = max(0, $position);
        }

        $arguments['limit'] = max(1, min($limit, 200));
        $arguments['calculateTotal'] = false;

        $responses = $this->call($connection, [[
            'Email/query',
            $arguments,
            'query',
        ]], [self::CORE, self::MAIL]);

        return $this->responsePayload($responses, 'Email/query');
    }

    /** @return array<string, mixed> */
    public function queryMessageChanges(
        ProviderConnection $connection,
        string $accountId,
        string $folderId,
        string $sinceQueryState,
        int $maxChanges = 100,
        bool $includeSort = true,
    ): array {
        $arguments = [
            'accountId' => $accountId,
            'filter' => ['inMailbox' => $folderId],
        ];

        if ($includeSort) {
            $arguments['sort'] = [
                ['property' => 'receivedAt', 'isAscending' => false],
            ];
        }

        $arguments['sinceQueryState'] = $sinceQueryState;
        $arguments['maxChanges'] = max(1, min($maxChanges, 200));

        $responses = $this->call($connection, [[
            'Email/queryChanges',
            $arguments,
            'queryChanges',
        ]], [self::CORE, self::MAIL]);

        return $this->responsePayload($responses, 'Email/queryChanges');
    }

    /**
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    public function getMessages(ProviderConnection $connection, string $accountId, array $ids): array
    {
        $ids = array_values(array_filter($ids, static fn (string $id): bool => $id !== ''));

        if ($ids === []) {
            return [];
        }

        $responses = $this->call($connection, [[
            'Email/get',
            [
                'accountId' => $accountId,
                'ids' => $ids,
                'properties' => $this->messageProperties(),
                'bodyProperties' => [
                    'partId',
                    'blobId',
                    'size',
                    'type',
                    'charset',
                    'disposition',
                    'name',
                    'subParts',
                ],
                'fetchTextBodyValues' => true,
                'fetchHTMLBodyValues' => true,
                'maxBodyValueBytes' => 100000,
            ],
            'get',
        ]], [self::CORE, self::MAIL]);
        $payload = $this->responsePayload($responses, 'Email/get');
        $list = $payload['list'] ?? [];

        if (! is_array($list)) {
            return [];
        }

        /** @var list<array<string, mixed>> $messages */
        $messages = array_values(array_filter($list, static fn (mixed $row): bool => is_array($row)));

        return $messages;
    }

    /**
     * @return array{email:array<string,mixed>,submission:array<string,mixed>,request_id:?string}
     */
    public function submit(
        Mailbox $mailbox,
        OutgoingMessage $message,
        ?RemoteMessageReference $replyTo = null,
    ): array {
        $connection = $this->connectionFor($mailbox);
        $context = $this->mailboxContext($connection);
        $accountId = $context['account_id'];
        $marker = $message->clientMessageMarker ?: (string) Str::uuid();
        $internetMessageId = '<'.trim($marker, '<>').'@outboss.local>';
        $createId = 'outboss_'.substr(hash('sha256', $marker), 0, 24);
        $email = $this->emailCreatePayload($message, $context['sent_id'], $internetMessageId, $replyTo, $marker);
        $submission = [
            'emailId' => '#'.$createId,
            'envelope' => [
                'mailFrom' => ['email' => $message->fromEmail, 'parameters' => null],
                'rcptTo' => array_map(
                    static fn (array $to): array => ['email' => $to['email'], 'parameters' => null],
                    $message->to,
                ),
            ],
        ];

        // Stalwart 0.16.15 requires an Identity id on every
        // EmailSubmission/set. The identity id is server-assigned and is not
        // the same as the account id, so discover the sender identity using
        // the mailbox credential instead of guessing or trusting user input.
        $submission['identityId'] = $this->identityId($connection, $accountId, $message->fromEmail);

        // A JMAP request may return a successful Email/set response followed
        // by an EmailSubmission/set `notCreated` response.  Keep the method
        // tuples intact here so the created Sent copy cannot be mistaken for
        // provider acceptance of the submission.
        $responses = $this->call(
            $connection,
            [
                [
                    'Email/set',
                    [
                        'accountId' => $accountId,
                        'create' => [$createId => $email],
                    ],
                    'email',
                ],
                [
                    'EmailSubmission/set',
                    [
                        'accountId' => $accountId,
                        'create' => [$createId => $submission],
                    ],
                    'submission',
                ],
            ],
            throwMethodErrors: false,
        );
        $emailResponse = $this->responseForCallId($responses, 'email');

        if ($emailResponse === null) {
            throw new StalwartJmapException('stalwart_email_submission_missing_email', 'stalwart_submission_failed');
        }

        if (($emailResponse[0] ?? null) === 'error') {
            throw $this->methodErrorException(
                is_array($emailResponse[1] ?? null) ? $emailResponse[1] : [],
                $connection,
            );
        }

        $emailResult = is_array($emailResponse[1] ?? null) ? $emailResponse[1] : [];
        $submissionResponse = $this->responseForCallId($responses, 'submission');
        $submissionResult = $submissionResponse !== null
            && ($submissionResponse[0] ?? null) === 'EmailSubmission/set'
            && is_array($submissionResponse[1] ?? null)
            ? $submissionResponse[1]
            : [];
        $createdEmail = data_get($emailResult, 'created.'.$createId);
        $createdSubmission = data_get($submissionResult, 'created.'.$createId);

        if (! is_array($createdEmail) || ! is_string($createdEmail['id'] ?? null)) {
            throw new StalwartJmapException('stalwart_email_submission_missing_email', 'stalwart_submission_failed');
        }

        if (! is_array($createdSubmission) || ! is_string($createdSubmission['id'] ?? null)) {
            $submissionError = null;

            if ($submissionResponse !== null && ($submissionResponse[0] ?? null) === 'error') {
                $submissionError = $this->methodErrorException(
                    is_array($submissionResponse[1] ?? null) ? $submissionResponse[1] : [],
                    $connection,
                );
            }

            throw new StalwartJmapException(
                'stalwart_email_created_not_submitted',
                'stalwart_submission_not_created',
                $submissionError?->httpStatus,
                $submissionError !== null ? $submissionError->retryable : false,
                [
                    'submission_state' => 'not_created',
                    'email_id' => (string) $createdEmail['id'],
                    'client_message_marker' => $marker,
                    'internet_message_id' => $internetMessageId,
                    'submission_error_code' => $submissionError !== null
                        ? $submissionError->safeCode
                        : (is_array(data_get($submissionResult, 'notCreated.'.$createId))
                            ? $this->safeSubmissionFailureCode(data_get($submissionResult, 'notCreated.'.$createId))
                            : 'stalwart_submission_not_created'),
                ],
            );
        }

        return [
            'email' => [
                ...$createdEmail,
                'id' => (string) $createdEmail['id'],
                'threadId' => isset($createdEmail['threadId']) ? (string) $createdEmail['threadId'] : null,
                'messageId' => [$internetMessageId],
                'clientMarker' => $marker,
            ],
            'submission' => [
                ...$createdSubmission,
                'submission_state' => 'accepted',
            ],
            'request_id' => null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchSent(
        Mailbox $mailbox,
        UnknownSendReference $reference,
    ): array {
        $connection = $this->connectionFor($mailbox);
        $context = $this->mailboxContext($connection);
        $sentId = $context['sent_id'];

        if (! is_string($sentId) || $sentId === '') {
            throw new StalwartJmapException('stalwart_sent_mailbox_missing', 'stalwart_reconciliation_unavailable');
        }

        $filters = [];

        if ($reference->clientMessageMarker !== null && trim($reference->clientMessageMarker) !== '') {
            $filters[] = ['header' => ['X-Outboss-Message-Id', trim($reference->clientMessageMarker)]];
        }

        if ($reference->internetMessageId !== null && trim($reference->internetMessageId) !== '') {
            $filters[] = ['header' => ['Message-ID', trim($reference->internetMessageId, '<>')]];
        }

        // Stalwart does not guarantee that arbitrary header filters are
        // indexed immediately after submission. Keep the targeted lookups
        // first, then walk the Sent result set and match the marker and
        // Message-ID locally. This keeps reconciliation reliable across v0.16
        // installations without trusting one arbitrary provider page.
        $filters[] = [];

        $seenIds = [];

        foreach ($filters as $filter) {
            if ($filter === []) {
                return $this->searchSentPages(
                    $connection,
                    $context['account_id'],
                    $sentId,
                    $reference,
                );
            }

            $queryFilter = ['inMailbox' => $sentId, ...$filter];
            try {
                // Keep reconciliation queries deliberately small and omit a
                // sort. Stalwart 0.16 installations can reject provider
                // specific/header queries with unsupportedSort even though a
                // bounded mailbox query is supported. A failed targeted
                // query must fall through to the bounded local-match query;
                // it must not turn an otherwise recoverable sent message
                // into an unavailable reconciliation result.
                $responses = $this->call($connection, [[
                    'Email/query',
                    [
                        'accountId' => $context['account_id'],
                        'filter' => $queryFilter,
                        'limit' => 10,
                        'calculateTotal' => false,
                    ],
                    'reconcile',
                ]], [self::CORE, self::MAIL]);
            } catch (StalwartJmapException $exception) {
                if ($this->isUnsupportedReconciliationQuery($exception)) {
                    continue;
                }

                throw $exception;
            }
            $payload = $this->responsePayload($responses, 'Email/query');
            $ids = $payload['ids'] ?? [];
            $ids = is_array($ids)
                ? array_values(array_filter($ids, static fn (mixed $id): bool => is_string($id) && $id !== ''))
                : [];
            $ids = array_values(array_filter($ids, function (string $id) use (&$seenIds): bool {
                if (isset($seenIds[$id])) {
                    return false;
                }

                $seenIds[$id] = true;

                return true;
            }));

            if ($ids === []) {
                continue;
            }

            $messages = $this->getMessages(
                $connection,
                $context['account_id'],
                $ids,
            );

            $exact = array_values(array_filter(
                $messages,
                fn (array $message): bool => $this->matchesReference($message, $reference),
            ));

            if ($exact !== []) {
                return $this->requireSubmissionAcceptance(
                    $connection,
                    $context['account_id'],
                    $exact,
                    $reference,
                );
            }
        }

        return [];
    }

    /**
     * Walk every page of the unfiltered Sent query before deciding that a
     * marker is absent. A page of exactly the request limit is never treated
     * as the end of the result set, even when the provider omits `total`.
     * Providers that fail to make progress or never expose an exhaustive
     * result are reported as incomplete so the caller can require review.
     *
     * @return list<array<string, mixed>>
     */
    private function searchSentPages(
        ProviderConnection $connection,
        string $accountId,
        string $sentId,
        UnknownSendReference $reference,
    ): array {
        $anchor = null;
        $initialQueryState = null;
        $seenIds = [];

        for ($page = 0; $page < self::MAX_RECONCILIATION_PAGES; $page++) {
            $arguments = [
                'accountId' => $accountId,
                'filter' => ['inMailbox' => $sentId],
                'limit' => self::RECONCILIATION_PAGE_SIZE,
                'calculateTotal' => false,
            ];

            if ($anchor === null) {
                // The first page starts at the beginning of the query. Every
                // later page is resolved from the previous page's immutable
                // id; live offsets are unsafe when Sent mutates between calls.
                $arguments['position'] = 0;
            } else {
                $arguments['anchor'] = $anchor;
                $arguments['anchorOffset'] = 1;
            }

            try {
                $responses = $this->call($connection, [[
                    'Email/query',
                    $arguments,
                    'reconcile',
                ]], [self::CORE, self::MAIL]);
            } catch (StalwartJmapException $exception) {
                if ($this->isUnsupportedReconciliationQuery($exception)) {
                    return $this->scanSentWithoutQuery(
                        $connection,
                        $accountId,
                        $sentId,
                        $reference,
                    );
                }

                throw $exception;
            }

            $payload = $this->responsePayload($responses, 'Email/query');
            $rawIds = $payload['ids'] ?? [];

            if (! is_array($rawIds)) {
                throw $this->incompleteReconciliation();
            }

            $ids = [];

            foreach ($rawIds as $rawId) {
                if (! is_string($rawId) || trim($rawId) === '') {
                    // A malformed page cannot establish either presence or
                    // absence. Do not silently drop an item and then call a
                    // shortened page exhaustive.
                    throw $this->incompleteReconciliation();
                }

                $ids[] = $rawId;
            }

            if (count(array_unique($ids)) !== count($ids)) {
                throw $this->incompleteReconciliation();
            }

            $queryState = $payload['queryState'] ?? null;
            $queryState = is_string($queryState) && trim($queryState) !== ''
                ? trim($queryState)
                : null;

            $queryStateChanged = $page > 0
                && ($initialQueryState === null || $queryState === null || $queryState !== $initialQueryState);

            if ($page === 0) {
                $initialQueryState = $queryState;
            }

            if ((array_key_exists('position', $payload) && ! is_numeric($payload['position']))
                || (array_key_exists('total', $payload)
                    && $payload['total'] !== null
                    && ! is_numeric($payload['total']))) {
                throw $this->incompleteReconciliation();
            }

            $responsePosition = is_numeric($payload['position'] ?? null)
                ? max(0, (int) $payload['position'])
                : ($page === 0 ? 0 : count($seenIds));
            $total = is_numeric($payload['total'] ?? null)
                ? max(0, (int) $payload['total'])
                : null;

            if ($total !== null && $responsePosition + count($ids) > $total) {
                throw $this->incompleteReconciliation();
            }

            if ($ids === []) {
                // An empty first page without a queryState is not a pinned
                // snapshot. Treat it as inconclusive instead of allowing a
                // live, mutable query to establish exhaustive absence.
                if ($page === 0 && $initialQueryState === null) {
                    throw $this->incompleteReconciliation();
                }

                if ($total !== null && $responsePosition < $total) {
                    throw $this->incompleteReconciliation();
                }

                return [];
            }

            $newIds = array_values(array_filter($ids, static function (string $id) use (&$seenIds): bool {
                if (isset($seenIds[$id])) {
                    return false;
                }

                $seenIds[$id] = true;

                return true;
            }));

            if ($newIds === []) {
                throw $this->incompleteReconciliation();
            }

            $messages = $this->getMessages($connection, $accountId, $newIds);
            $exact = array_values(array_filter(
                $messages,
                fn (array $message): bool => $this->matchesReference($message, $reference),
            ));

            if ($exact !== []) {
                return $this->requireSubmissionAcceptance(
                    $connection,
                    $accountId,
                    $exact,
                    $reference,
                );
            }

            if ($queryStateChanged) {
                // An anchor protects the page boundary from offset shifts,
                // but an insertion before the first anchor can still be
                // omitted. A query-state change (or a provider that omits it)
                // therefore cannot prove exhaustive absence; require a
                // retry/manual review instead of declaring the send not
                // accepted.
                throw $this->incompleteReconciliation();
            }

            $fetchedIds = [];

            foreach ($messages as $message) {
                $messageId = $message['id'] ?? null;

                if (is_string($messageId) && $messageId !== '') {
                    $fetchedIds[$messageId] = true;
                }
            }

            if (count(array_diff($newIds, array_keys($fetchedIds))) > 0) {
                throw $this->incompleteReconciliation();
            }

            $hasMore = $total !== null
                ? $responsePosition + count($ids) < $total
                : count($ids) >= self::RECONCILIATION_PAGE_SIZE;

            if (! $hasMore) {
                return [];
            }

            $anchor = $ids[array_key_last($ids)];
        }

        throw $this->incompleteReconciliation();
    }

    /**
     * Stalwart 0.16 can reject every Email/query against a freshly-created
     * account with unsupportedSort, including a query with no explicit sort.
     * Email/get remains available, so request only lightweight fields and
     * match the stable Outboss marker locally. Email/get has no portable
     * position cursor for an unfiltered request, so a non-empty response
     * without a match is inconclusive rather than proof of absence.
     *
     * @return list<array<string, mixed>>
     */
    private function scanSentWithoutQuery(
        ProviderConnection $connection,
        string $accountId,
        string $sentId,
        UnknownSendReference $reference,
    ): array {
        $responses = $this->call($connection, [[
            'Email/get',
            [
                'accountId' => $accountId,
                'properties' => [
                    'id',
                    'mailboxIds',
                    'receivedAt',
                    'sentAt',
                    'messageId',
                    'header:X-Outboss-Message-Id',
                    'header:Message-ID:asMessageIds',
                ],
            ],
            'reconcile-get',
        ]], [self::CORE, self::MAIL]);
        $payload = $this->responsePayload($responses, 'Email/get');
        $list = $payload['list'] ?? [];

        if (! is_array($list)) {
            throw new StalwartJmapException('stalwart_reconciliation_unavailable', 'stalwart_reconciliation_unavailable');
        }

        $rows = array_values(array_filter($list, static function (mixed $row) use ($sentId): bool {
            if (! is_array($row) || ! is_string($row['id'] ?? null)) {
                return false;
            }

            $mailboxIds = $row['mailboxIds'] ?? [];

            return is_array($mailboxIds) && ($mailboxIds[$sentId] ?? false) === true;
        }));
        $matchedIds = array_values(array_map(
            static fn (array $row): string => (string) $row['id'],
            array_filter($rows, fn (array $row): bool => $this->matchesReference($row, $reference)),
        ));

        if ($matchedIds !== []) {
            $messages = $this->getMessages($connection, $accountId, $matchedIds);
            $exact = array_values(array_filter(
                $messages,
                fn (array $message): bool => $this->matchesReference($message, $reference),
            ));

            if ($exact === []) {
                throw new StalwartJmapException(
                    'stalwart_reconciliation_incomplete',
                    'stalwart_reconciliation_incomplete',
                );
            }

            return $this->requireSubmissionAcceptance(
                $connection,
                $accountId,
                $exact,
                $reference,
            );
        }

        if ($list !== []) {
            throw new StalwartJmapException('stalwart_reconciliation_incomplete', 'stalwart_reconciliation_incomplete');
        }

        return [];
    }

    /**
     * When a send response was lost, a Sent copy is only delivery evidence if
     * the provider also exposes a created EmailSubmission for that Email.
     * Older callers without a submission state retain their existing adapter
     * behaviour; Stalwart unknown sends use the strict path.
     *
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    private function requireSubmissionAcceptance(
        ProviderConnection $connection,
        string $accountId,
        array $messages,
        UnknownSendReference $reference,
    ): array {
        if ($reference->submissionState !== 'unknown') {
            return $messages;
        }

        $accepted = [];

        foreach ($messages as $message) {
            $emailId = $message['id'] ?? null;

            if (! is_string($emailId) || trim($emailId) === '') {
                throw $this->incompleteReconciliation();
            }

            if ($this->hasAcceptedSubmission($connection, $accountId, $emailId)) {
                $accepted[] = $message;
            }
        }

        if ($accepted === []) {
            throw $this->incompleteReconciliation();
        }

        return $accepted;
    }

    private function hasAcceptedSubmission(
        ProviderConnection $connection,
        string $accountId,
        string $emailId,
    ): bool {
        $responses = $this->call($connection, [[
            'EmailSubmission/query',
            [
                'accountId' => $accountId,
                'filter' => ['emailIds' => [$emailId]],
                'limit' => 10,
                'calculateTotal' => false,
            ],
            'submission-query',
        ]], [self::CORE, self::SUBMISSION]);
        $payload = $this->responsePayload($responses, 'EmailSubmission/query');
        $rawIds = $payload['ids'] ?? null;

        if (! is_array($rawIds)) {
            throw $this->incompleteReconciliation();
        }

        $ids = [];

        foreach ($rawIds as $rawId) {
            if (! is_string($rawId) || trim($rawId) === '') {
                throw $this->incompleteReconciliation();
            }

            $ids[] = $rawId;
        }

        if ($ids === []) {
            return false;
        }

        $responses = $this->call($connection, [[
            'EmailSubmission/get',
            [
                'accountId' => $accountId,
                'ids' => array_values(array_unique($ids)),
                'properties' => [
                    'id',
                    'emailId',
                    'sendAt',
                    'sentAt',
                    'undoStatus',
                    'deliveredBy',
                ],
            ],
            'submission-get',
        ]], [self::CORE, self::SUBMISSION]);
        $payload = $this->responsePayload($responses, 'EmailSubmission/get');
        $list = $payload['list'] ?? null;

        if (! is_array($list)) {
            throw $this->incompleteReconciliation();
        }

        $found = false;

        foreach ($list as $submission) {
            if (! is_array($submission)) {
                throw $this->incompleteReconciliation();
            }

            if (($submission['emailId'] ?? null) === $emailId) {
                $found = true;
                break;
            }
        }

        // A query id that cannot be fetched is not proof of non-acceptance;
        // retention/race behaviour must remain manual rather than retrying a
        // potentially accepted message.
        if (! $found && count($list) < count(array_unique($ids))) {
            throw $this->incompleteReconciliation();
        }

        return $found;
    }

    private function incompleteReconciliation(): StalwartJmapException
    {
        return new StalwartJmapException(
            'stalwart_reconciliation_incomplete',
            'stalwart_reconciliation_incomplete',
        );
    }

    private function isUnsupportedReconciliationQuery(StalwartJmapException $exception): bool
    {
        return in_array($exception->safeCode, [
            'stalwart_jmap_error_unsupportedsort',
            'stalwart_jmap_error_unsupportedfilter',
            'stalwart_invalid_request',
        ], true);
    }

    /** @return array<string, mixed> */
    public function connectionCredentials(ProviderConnection $connection): array
    {
        [$username, $password] = $this->credentials($connection);

        return ['username' => $username, 'password' => $password];
    }

    /** @return array<string, mixed> */
    private function emailCreatePayload(
        OutgoingMessage $message,
        ?string $sentId,
        string $internetMessageId,
        ?RemoteMessageReference $replyTo,
        string $marker,
    ): array {
        $payload = [
            'from' => [$this->address($message->fromEmail, $message->fromName)],
            'to' => array_map(fn (array $to): array => $this->address($to['email'], $to['name'] ?? null), $message->to),
            'subject' => EmailNormalizer::stripHeaderInjection($message->subject),
            'keywords' => ['$seen' => true],
            'bodyValues' => [
                'text' => ['value' => $message->bodyText],
                'html' => ['value' => $message->bodyHtml],
            ],
            'textBody' => [['partId' => 'text', 'type' => 'text/plain']],
            'htmlBody' => [['partId' => 'html', 'type' => 'text/html']],
            'header:X-Outboss-Message-Id' => $marker,
            'header:Message-ID' => $internetMessageId,
        ];

        if ($sentId !== null && $sentId !== '') {
            $payload['mailboxIds'] = [$sentId => true];
        }

        $inReplyTo = $message->inReplyTo ?: $replyTo?->internetMessageId;

        if ($inReplyTo !== null && trim($inReplyTo) !== '') {
            $payload['header:In-Reply-To'] = '<'.trim($inReplyTo, '<>').'>';
        }

        $references = $message->references;

        if ($replyTo?->internetMessageId !== null && ! in_array($replyTo->internetMessageId, $references, true)) {
            $references[] = $replyTo->internetMessageId;
        }

        $references = MessageIdNormalizer::normalizeList($references);

        if ($references !== []) {
            $payload['header:References'] = implode(' ', array_map(static fn (string $id): string => '<'.trim($id, '<>').'>', $references));
        }

        if ($message->listUnsubscribeUrl !== null && trim($message->listUnsubscribeUrl) !== '') {
            $payload['header:List-Unsubscribe'] = '<'.EmailNormalizer::stripHeaderInjection($message->listUnsubscribeUrl).'>';

            if ($message->oneClickPost) {
                $payload['header:List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
            }
        }

        return $payload;
    }

    /** @return array{email:string,name:?string} */
    private function address(string $email, ?string $name): array
    {
        return [
            'email' => EmailNormalizer::stripHeaderInjection($email),
            'name' => $name === null ? null : EmailNormalizer::stripHeaderInjection($name),
        ];
    }

    private function identityId(ProviderConnection $connection, string $accountId, string $fromEmail): string
    {
        $responses = $this->call($connection, [[
            'Identity/get',
            [
                'accountId' => $accountId,
                'ids' => null,
                'properties' => ['id', 'email'],
            ],
            'identities',
        ]], [self::CORE, self::SUBMISSION]);
        $payload = $this->responsePayload($responses, 'Identity/get');
        $identities = $payload['list'] ?? [];
        $fromEmail = strtolower(trim($fromEmail));

        if (is_array($identities)) {
            foreach ($identities as $identity) {
                if (! is_array($identity)
                    || ! is_string($identity['id'] ?? null)
                    || $identity['id'] === ''
                    || ! is_string($identity['email'] ?? null)
                    || strtolower(trim($identity['email'])) !== $fromEmail) {
                    continue;
                }

                return $identity['id'];
            }
        }

        throw new StalwartJmapException('stalwart_sender_identity_missing', 'stalwart_submission_failed');
    }

    /** @return list<string> */
    private function messageProperties(): array
    {
        return [
            'id',
            'blobId',
            'threadId',
            'mailboxIds',
            'keywords',
            'size',
            'receivedAt',
            'messageId',
            'inReplyTo',
            'references',
            'sender',
            'from',
            'to',
            'cc',
            'bcc',
            'replyTo',
            'subject',
            'sentAt',
            'hasAttachment',
            'preview',
            'bodyValues',
            'textBody',
            'htmlBody',
            'attachments',
            'header:X-Outboss-Message-Id',
            'header:Message-ID:asMessageIds',
            'header:In-Reply-To:asMessageIds',
            'header:References:asMessageIds',
            'header:List-Unsubscribe:asText',
            'header:List-Unsubscribe-Post:asText',
            'header:Auto-Submitted:asText',
            'header:X-Autoreply:asText',
            'header:X-Autorespond:asText',
        ];
    }

    /** @param array<string, mixed> $message */
    private function matchesReference(array $message, UnknownSendReference $reference): bool
    {
        $marker = $this->headerValue($message, 'X-Outboss-Message-Id');
        $messageIds = $message['messageId'] ?? $message['header:Message-ID'] ?? [];
        $messageIds = is_array($messageIds) ? $messageIds : [$messageIds];
        $messageIds = MessageIdNormalizer::normalizeList(array_values(array_filter($messageIds, 'is_string')));

        if ($reference->clientMessageMarker !== null && $marker !== null
            && hash_equals(strtolower(trim($reference->clientMessageMarker)), strtolower(trim($marker)))) {
            return true;
        }

        $internet = MessageIdNormalizer::normalize($reference->internetMessageId);

        return $internet !== null && in_array($internet, $messageIds, true);
    }

    /** @param array<string, mixed> $message */
    private function headerValue(array $message, string $name): ?string
    {
        foreach ([
            'header:'.$name,
            'header:'.$name.':asText',
            'header:'.$name.':asText:all',
        ] as $key) {
            $value = $message[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }

            if (is_array($value)) {
                foreach (array_reverse($value) as $item) {
                    if (is_string($item) && trim($item) !== '') {
                        return trim($item);
                    }
                }
            }
        }

        foreach (($message['headers'] ?? []) as $header) {
            if (is_array($header) && strcasecmp((string) ($header['name'] ?? ''), $name) === 0) {
                return is_string($header['value'] ?? null) ? trim($header['value']) : null;
            }
        }

        return null;
    }

    private function connectionFor(Mailbox $mailbox): ProviderConnection
    {
        $connection = $mailbox->connection;

        if (! $connection instanceof ProviderConnection
            || (int) $connection->getAttribute('workspace_id') !== (int) $mailbox->getAttribute('workspace_id')) {
            throw new StalwartJmapException('stalwart_workspace_mismatch', 'workspace_mismatch');
        }

        return $connection;
    }

    /**
     * @param  list<array<int, mixed>>  $responses
     * @return array<string, mixed>
     */
    private function responsePayload(array $responses, string $method): array
    {
        foreach ($responses as $response) {
            if (($response[0] ?? null) === $method && is_array($response[1] ?? null)) {
                /** @var array<string, mixed> $payload */
                $payload = $response[1];

                return $payload;
            }
        }

        throw new StalwartJmapException('stalwart_missing_method_response', 'stalwart_jmap_invalid');
    }

    /**
     * @param  list<array<int, mixed>>  $responses
     * @return array<int, mixed>|null
     */
    private function responseForCallId(array $responses, string $callId): ?array
    {
        foreach ($responses as $response) {
            if (($response[2] ?? null) === $callId) {
                return $response;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $failure */
    private function safeSubmissionFailureCode(array $failure): string
    {
        $type = strtolower((string) ($failure['type'] ?? ''));

        return match ($type) {
            'invalidarguments', 'invalidproperties' => 'stalwart_invalid_request',
            'forbidden', 'forbiddentosend', 'unauthorized' => 'credential_rotation_required',
            'limit' => 'stalwart_rate_limited',
            default => 'stalwart_submission_not_created',
        };
    }

    /** @param array<string, mixed> $error */
    private function throwMethodError(array $error, ProviderConnection $connection): never
    {
        throw $this->methodErrorException($error, $connection);
    }

    /** @param array<string, mixed> $error */
    private function methodErrorException(array $error, ProviderConnection $connection): StalwartJmapException
    {
        $type = strtolower((string) ($error['type'] ?? 'serverFail'));

        $safeCode = match ($type) {
            'invalidarguments', 'invalidproperties' => 'stalwart_invalid_request',
            'accountnotfound', 'notfound' => 'stalwart_account_not_found',
            'anchornotfound' => 'stalwart_jmap_anchor_not_found',
            'forbidden', 'forbiddentosend', 'unauthorized' => 'credential_rotation_required',
            'limit' => 'stalwart_rate_limited',
            'unsupportedsort' => 'stalwart_jmap_error_unsupportedsort',
            'unsupportedfilter' => 'stalwart_jmap_error_unsupportedfilter',
            default => 'stalwart_jmap_error',
        };

        if ($safeCode === 'credential_rotation_required') {
            $this->markCredentialFailure($connection);
        }

        return new StalwartJmapException($safeCode, $safeCode, null, $safeCode === 'stalwart_rate_limited');
    }

    /** @param array<string, mixed>|null $body */
    private function request(
        ProviderConnection $connection,
        string $method,
        string $url,
        string $username,
        string $password,
        ?array $body = null,
        bool $retryable = false,
    ): Response {
        // Validate the complete request origin before constructing an
        // authenticated request. Session documents are provider-controlled,
        // so checking only the hostname would allow an HTTPS downgrade,
        // non-default port, or userinfo-bearing URL to receive credentials.
        $this->assertSameOrigin($url, $connection);

        try {
            $pending = Http::withBasicAuth($username, $password)
                ->acceptJson()
                ->timeout($this->timeout($connection))
                ->connectTimeout(min(10, $this->timeout($connection)))
                ->withOptions(['verify' => $this->verifyTls($connection)])
                ->withHeaders([
                    'X-Outboss-Correlation-Id' => (string) Str::uuid(),
                ]);

            $response = $method === 'GET'
                ? $pending->get($url)
                : $pending->post($url, $body ?? []);
        } catch (Throwable $exception) {
            throw new StalwartJmapException('stalwart_transport_unavailable', 'stalwart_transport_unavailable', null, true);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            $this->markCredentialFailure($connection);

            throw new StalwartJmapException('credential_rotation_required', 'credential_rotation_required', $response->status());
        }

        if (! $response->successful()) {
            $status = $response->status();
            $safeCode = $status === 429
                ? 'stalwart_rate_limited'
                : ($status >= 500 ? 'stalwart_provider_unavailable' : 'stalwart_request_failed');

            throw new StalwartJmapException($safeCode, $safeCode, $status, $status === 429 || $status >= 500);
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function json(Response $response, string $code): array
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new StalwartJmapException($code, $code);
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /** @return array{0:string,1:string} */
    private function credentials(ProviderConnection $connection): array
    {
        try {
            $payload = $this->credentialPayload($connection);
            $username = $payload['username']
                ?? $payload['jmap_username']
                ?? $payload['email']
                ?? $connection->getAttribute('provider_user_id');
            $password = $payload['password']
                ?? $payload['app_password']
                ?? $payload['secret']
                ?? $connection->getAttribute('access_token');
        } catch (Throwable) {
            $this->markCredentialFailure($connection);

            throw new StalwartJmapException('credential_rotation_required', 'credential_rotation_required');
        }

        if (! is_string($username) || trim($username) === '' || ! is_string($password) || $password === '') {
            $this->markCredentialFailure($connection);

            throw new StalwartJmapException('credential_rotation_required', 'credential_rotation_required');
        }

        return [trim($username), $password];
    }

    /** @return array<string, mixed> */
    private function credentialPayload(ProviderConnection $connection): array
    {
        $payload = $connection->getAttribute('credential_payload');

        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        return is_array($payload) ? $payload : [];
    }

    private function baseUrl(ProviderConnection $connection): string
    {
        $instance = $this->instanceKey($connection);
        $paths = [
            'stalwart.instances.'.$instance.'.base_url',
            'services.stalwart.instances.'.$instance.'.base_url',
            'outboss.managed_mail.instances.'.$instance.'.base_url',
            'stalwart.'.$instance.'.base_url',
            'services.stalwart.'.$instance.'.base_url',
        ];

        if ($instance === 'internal') {
            $paths[] = 'stalwart.internal.base_url';
            $paths[] = 'services.stalwart.internal.base_url';
        }

        $base = null;

        foreach ($paths as $path) {
            $candidate = config($path);

            if (is_string($candidate) && trim($candidate) !== '') {
                $base = trim($candidate);

                break;
            }
        }

        if ($base === null) {
            $candidate = config('stalwart.base_url') ?? config('services.stalwart.base_url');
            $base = is_string($candidate) ? trim($candidate) : '';
        }

        if ($base === '') {
            throw new StalwartJmapException('stalwart_configuration_missing', 'stalwart_configuration_missing');
        }

        $parts = parse_url($base);

        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;

        if (! is_array($parts) || ! is_string($scheme) || ! in_array(strtolower($scheme), ['https', 'http'], true)
            || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new StalwartJmapException('stalwart_configuration_invalid', 'stalwart_configuration_invalid');
        }

        if (app()->environment('production') && strtolower($scheme) !== 'https') {
            throw new StalwartJmapException('stalwart_tls_required', 'stalwart_configuration_invalid');
        }

        return rtrim($base, '/');
    }

    private function advertisedUrl(mixed $url, ProviderConnection $connection): string
    {
        if (! is_string($url) || trim($url) === '') {
            throw new StalwartJmapException('stalwart_session_missing_api_url', 'stalwart_session_invalid');
        }

        $url = trim($url);

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $this->baseUrl($connection).'/'.ltrim($url, '/');
        }

        if (str_starts_with($url, '//')) {
            throw new StalwartJmapException('stalwart_session_invalid_api_url', 'stalwart_session_invalid');
        }

        $this->assertSameOrigin($url, $connection);

        return $url;
    }

    /**
     * Assert that a provider-advertised URL stays on the configured origin.
     *
     * The comparison deliberately uses the effective port (80/443 when the
     * URL omits one) and a normalized hostname. Userinfo is never accepted;
     * otherwise a URL such as https://user@host could be treated as a safe
     * host by one parser and leak the Basic credential to another authority.
     */
    private function assertSameOrigin(string $url, ProviderConnection $connection): void
    {
        $candidate = parse_url($url);
        $configured = parse_url($this->baseUrl($connection));

        if (! is_array($candidate) || ! is_array($configured)
            || ! isset($candidate['scheme'], $candidate['host'])
            || isset($candidate['user']) || isset($candidate['pass'])
            || isset($candidate['fragment'])) {
            throw new StalwartJmapException('stalwart_session_invalid_api_url', 'stalwart_session_invalid');
        }

        $candidateScheme = strtolower((string) $candidate['scheme']);
        $configuredScheme = strtolower((string) ($configured['scheme'] ?? ''));
        $normalisedCandidateHost = $this->normaliseHost($candidate['host']);
        $normalisedConfiguredHost = $this->normaliseHost($configured['host'] ?? null);
        if (! in_array($candidateScheme, ['http', 'https'], true)
            || $candidateScheme !== $configuredScheme
            || $normalisedCandidateHost === null
            || $normalisedConfiguredHost === null
            || $normalisedCandidateHost !== $normalisedConfiguredHost
            || $this->effectivePort($candidate) !== $this->effectivePort($configured)) {
            throw new StalwartJmapException('stalwart_session_invalid_api_url', 'stalwart_session_invalid');
        }
    }

    /** @param array<string, mixed> $parts */
    private function effectivePort(array $parts): int
    {
        $port = $parts['port'] ?? null;
        if (is_int($port) || (is_string($port) && ctype_digit($port))) {
            return (int) $port;
        }

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https' ? 443 : 80;
    }

    private function normaliseHost(mixed $host): ?string
    {
        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        $host = strtolower(trim($host));
        $host = trim($host, '[]');
        $host = rtrim($host, '.');

        if ($host === '') {
            return null;
        }

        if (function_exists('idn_to_ascii') && ! filter_var($host, FILTER_VALIDATE_IP)) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT);
            if (is_string($ascii) && $ascii !== '') {
                $host = strtolower(rtrim($ascii, '.'));
            }
        }

        return $host;
    }

    private function instanceKey(ProviderConnection $connection): string
    {
        $instance = $connection->getAttribute('managed_instance_key');

        if ($instance === null) {
            try {
                $instance = data_get($connection->getAttribute('provider_metadata'), 'managed_instance_key');
            } catch (Throwable) {
                $instance = null;
            }
        }

        $instance ??= config('outboss.managed_mail.default_instance')
            ?? config('stalwart.default_instance')
            ?? config('services.stalwart.default_instance')
            ?? 'internal';
        $instance = $instance instanceof \BackedEnum ? $instance->value : $instance;
        $instance = is_string($instance) && trim($instance) !== '' ? trim($instance) : 'internal';

        if (preg_match('/^[A-Za-z0-9_-]+$/', $instance) !== 1) {
            throw new StalwartJmapException('stalwart_configuration_invalid', 'stalwart_configuration_invalid');
        }

        return $instance;
    }

    private function sessionCacheKey(ProviderConnection $connection): string
    {
        $version = $connection->getAttribute('credential_version');
        if ($version === null) {
            try {
                $version = data_get($this->credentialPayload($connection), 'version');
            } catch (Throwable) {
                $version = 'unknown';
            }
        }
        $version ??= $connection->getAttribute('token_version') ?? 0;
        $version = is_scalar($version) ? (string) $version : '0';
        $identity = $connection->getKey() ?: $connection->getAttribute('provider_user_id');

        return 'stalwart:jmap:session:'.hash('sha256', (string) $identity.'|'.$version.'|'.$this->instanceKey($connection));
    }

    private function timeout(ProviderConnection $connection): int
    {
        $instance = $this->instanceKey($connection);
        $timeout = config('stalwart.instances.'.$instance.'.timeout_seconds')
            ?? config('services.stalwart.instances.'.$instance.'.timeout_seconds')
            ?? config('outboss.managed_mail.instances.'.$instance.'.timeout_seconds')
            ?? config('stalwart.timeout_seconds')
            ?? config('services.stalwart.timeout_seconds')
            ?? config('outboss.managed_mail.timeout_seconds', 15);

        return max(1, min(120, is_numeric($timeout) ? (int) $timeout : 15));
    }

    private function verifyTls(ProviderConnection $connection): bool
    {
        // The connection's instance key selects a configuration-only host;
        // never accept a URL or TLS policy from mailbox credentials.
        $instance = $this->instanceKey($connection);
        $verify = config('stalwart.instances.'.$instance.'.verify_tls')
            ?? config('services.stalwart.instances.'.$instance.'.verify_tls')
            ?? config('outboss.managed_mail.instances.'.$instance.'.verify_tls')
            ?? config('stalwart.verify_tls')
            ?? config('services.stalwart.verify_tls')
            ?? config('outboss.managed_mail.verify_tls', true);

        return app()->environment('production') ? true : filter_var($verify, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
    }

    /** @param list<string> $paths */
    private function integerConfig(array $paths, int $default): int
    {
        foreach ($paths as $path) {
            $value = config($path);

            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return $default;
    }

    /** @param array<string, mixed> $session */
    private function accountId(array $session, ProviderConnection $connection): string
    {
        $primary = $session['primaryAccounts']['urn:ietf:params:jmap:mail'] ?? null;

        if (is_string($primary) && $primary !== '') {
            return $primary;
        }

        $accounts = $session['accounts'] ?? [];

        if (is_array($accounts)) {
            foreach (array_keys($accounts) as $key) {
                if (is_string($key) && $key !== '') {
                    return $key;
                }
            }
        }

        $fallback = $connection->getAttribute('provider_user_id');

        if (is_string($fallback) && trim($fallback) !== '') {
            return trim($fallback);
        }

        throw new StalwartJmapException('stalwart_account_missing', 'stalwart_session_invalid');
    }

    private function markCredentialFailure(ProviderConnection $connection): void
    {
        try {
            $this->invalidateSession($connection);
            $connection->forceFill([
                'status' => ConnectionStatus::NeedsReconnect,
                'last_error_code' => 'credential_rotation_required',
                'last_error_message' => 'Managed mailbox credentials were rejected.',
            ])->saveQuietly();
            $mailboxIds = $connection->mailboxes()->pluck('id')->all();
            $connection->mailboxes()->update(['status' => ConnectionStatus::NeedsReconnect]);

            if ($mailboxIds !== []) {
                $account = ManagedMailboxAccount::query()
                    ->whereIn('mailbox_id', $mailboxIds)
                    ->where('status', ManagedMailboxAccountStatus::Active->value)
                    ->first();

                if ($account !== null) {
                    $account->markRotationRequired();
                }
            }
        } catch (Throwable) {
            // Do not mask the redacted provider error if local state cannot be
            // updated while handling a remote authentication failure.
        }
    }
}
