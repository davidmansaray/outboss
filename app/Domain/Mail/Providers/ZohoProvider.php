<?php

namespace App\Domain\Mail\Providers;

use App\Domain\Mail\Contracts\MailProvider;
use App\Domain\Mail\DTOs\ConnectedAccount;
use App\Domain\Mail\DTOs\OAuthCallback;
use App\Domain\Mail\DTOs\OutgoingMessage;
use App\Domain\Mail\DTOs\RefreshedTokens;
use App\Domain\Mail\DTOs\RemoteMailbox;
use App\Domain\Mail\DTOs\RemoteMessage;
use App\Domain\Mail\DTOs\RemoteMessagePage;
use App\Domain\Mail\DTOs\RemoteMessageReference;
use App\Domain\Mail\DTOs\RemoteSenderIdentity;
use App\Domain\Mail\DTOs\SendResult;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\SyncScope;
use App\Domain\Mail\Services\MailboxSenderIdentityService;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Models\Mailbox;
use App\Models\MailboxSyncState;
use App\Models\Message;
use App\Models\OAuthAttempt;
use App\Models\ProviderConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class ZohoProvider implements MailProvider
{
    use ProviderUnknownSendReconciliation;

    public function authorizationUrl(OAuthAttempt $attempt): string
    {
        $query = http_build_query([
            'client_id' => config('services.zoho.client_id'),
            'response_type' => 'code',
            'redirect_uri' => config('services.zoho.redirect'),
            'scope' => 'ZohoMail.accounts.READ,ZohoMail.messages.READ,ZohoMail.messages.CREATE,ZohoMail.folders.READ',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $attempt->raw_state ?? $attempt->state_hash,
        ]);

        return 'https://accounts.zoho.com/oauth/v2/auth?'.$query;
    }

    public function exchangeAuthorizationCode(OAuthCallback $callback): ConnectedAccount
    {
        $accountsHost = $this->accountsHostFromCallback($callback);
        $response = Http::asForm()->post($accountsHost.'/oauth/v2/token', [
            'code' => $callback->code,
            'client_id' => config('services.zoho.client_id'),
            'client_secret' => config('services.zoho.client_secret'),
            'redirect_uri' => config('services.zoho.redirect'),
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('zoho_oauth_exchange_failed');
        }

        $mailHost = $this->mailHostFromAccounts($accountsHost);
        $accessToken = (string) $response->json('access_token');
        $account = $this->primaryAccount($this->fetchAccounts($mailHost, $accessToken));
        $accountId = (string) ($account['accountId'] ?? 'zoho-user');
        $folders = $this->discoverFolderIds($mailHost, $accessToken, $accountId);

        return new ConnectedAccount(
            providerUserId: $accountId,
            accessToken: $accessToken,
            refreshToken: $response->json('refresh_token'),
            tokenExpiresAt: now()->addSeconds((int) $response->json('expires_in', 3600)),
            grantedScopes: $this->parseScopes($response->json('scope')),
            mailboxes: [new RemoteMailbox(
                providerMailboxId: $accountId,
                emailAddress: (string) ($account['primaryEmailAddress'] ?? 'zoho@example.test'),
                displayName: isset($account['displayName']) ? (string) $account['displayName'] : null,
                metadata: $folders,
                senderIdentities: $this->senderIdentities($account),
            )],
            metadata: [
                'location' => $this->locationFromAccountsHost($accountsHost, $callback),
                'accounts_server' => $accountsHost,
                'api_domain' => $mailHost,
            ],
        );
    }

    public function refreshConnection(ProviderConnection $connection): RefreshedTokens
    {
        $accounts = $this->allowlistedAccountsHost(data_get($connection->provider_metadata, 'accounts_server', 'https://accounts.zoho.com'));
        $response = Http::asForm()->post($accounts.'/oauth/v2/token', [
            'refresh_token' => $connection->refresh_token,
            'client_id' => config('services.zoho.client_id'),
            'client_secret' => config('services.zoho.client_secret'),
            'grant_type' => 'refresh_token',
        ])->throw();

        return new RefreshedTokens(
            accessToken: $response->json('access_token'),
            refreshToken: $connection->refresh_token,
            tokenExpiresAt: now()->addSeconds((int) $response->json('expires_in', 3600)),
        );
    }

    public function revokeConnection(ProviderConnection $connection): void
    {
        $accounts = $this->allowlistedAccountsHost(data_get($connection->provider_metadata, 'accounts_server', 'https://accounts.zoho.com'));
        Http::asForm()->timeout(5)->post($accounts.'/oauth/v2/token/revoke', [
            'token' => $connection->refresh_token,
        ]);
    }

    public function mailboxes(ProviderConnection $connection): array
    {
        $mailHost = $this->mailHostFromConnection($connection);
        $account = $this->primaryAccount($this->fetchAccounts($mailHost, (string) $connection->access_token));
        $accountId = (string) $account['accountId'];
        $folders = $this->discoverFolderIds($mailHost, (string) $connection->access_token, $accountId);

        return [new RemoteMailbox(
            providerMailboxId: $accountId,
            emailAddress: (string) $account['primaryEmailAddress'],
            displayName: isset($account['displayName']) ? (string) $account['displayName'] : null,
            metadata: $folders,
            senderIdentities: $this->senderIdentities($account),
        )];
    }

    public function syncFolder(Mailbox $mailbox, MailboxSyncState $state, int $pageSize = 100): RemoteMessagePage
    {
        $connection = $this->requireConnection($mailbox);
        $mailHost = $this->mailHostFromConnection($connection);
        $folderId = $this->resolveFolderId($mailbox, $state);
        $scope = $this->syncScope($state);
        $storedScanState = $state->getAttribute('scan_state');
        $scanState = is_array($storedScanState) ? $storedScanState : [];
        $phase = data_get($scanState, 'phase', 'backfill') === 'incremental'
            ? 'incremental'
            : 'backfill';
        $limit = max(1, min($pageSize, 200));
        $start = $phase === 'incremental'
            ? 1
            : max(1, (int) data_get($scanState, 'start', 1));
        $lookbackCutoffMs = data_get($scanState, 'lookback_cutoff_ms');

        if (! is_numeric($lookbackCutoffMs)) {
            $lookbackDays = max(0, (int) config('outboss.sync.zoho.lookback_days', 30));
            $lookbackCutoffMs = now()->subDays($lookbackDays)->getTimestampMs();
        }

        $lookbackCutoffMs = (int) $lookbackCutoffMs;

        /** @var array<string, array<string, mixed>> $keptById */
        $keptById = [];
        $rawRowCount = 0;
        $hasOlderRow = false;

        if ($phase === 'incremental') {
            $overlapPages = max(1, (int) config('outboss.sync.zoho.overlap_pages', 2));

            for ($page = 0; $page < $overlapPages; $page++) {
                $pageStart = 1 + ($page * $limit);
                $rows = $this->fetchMessageRows(
                    $mailHost,
                    (string) $connection->access_token,
                    $mailbox->provider_mailbox_id,
                    $folderId,
                    $pageStart,
                    $limit,
                );
                $rawRowCount += count($rows);
                [$pageKept, $pageHasOlder] = $this->keepRowsWithinLookback($rows, $lookbackCutoffMs);
                $hasOlderRow = $hasOlderRow || $pageHasOlder;

                foreach ($pageKept as $messageId => $row) {
                    $keptById[$messageId] = $row;
                }

                if (count($rows) < $limit) {
                    break;
                }
            }
        } else {
            $rows = $this->fetchMessageRows(
                $mailHost,
                (string) $connection->access_token,
                $mailbox->provider_mailbox_id,
                $folderId,
                $start,
                $limit,
            );
            $rawRowCount = count($rows);
            [$keptById, $hasOlderRow] = $this->keepRowsWithinLookback($rows, $lookbackCutoffMs);
        }

        $keptRows = array_values($keptById);

        // Incremental passes only need to ingest messages we have not stored
        // yet; re-fetching content/headers for known messages turns every
        // sync into hundreds of upstream requests. Messages still missing an
        // internet_message_id (e.g. locally composed campaign sends) are
        // refetched so their headers can be enriched.
        $storedIds = [];

        if ($phase === 'incremental' && $keptRows !== []) {
            $storedIds = Message::query()
                ->where('mailbox_id', $mailbox->id)
                ->whereIn('remote_message_id', array_map(
                    fn (array $row): string => (string) ($row['messageId'] ?? ''),
                    $keptRows,
                ))
                ->whereNotNull('internet_message_id')
                ->pluck('remote_message_id')
                ->all();
        }

        $messages = [];

        foreach ($keptRows as $row) {
            $messageId = isset($row['messageId']) ? (string) $row['messageId'] : '';

            if ($messageId === '') {
                continue;
            }

            if (in_array($messageId, $storedIds, true)) {
                continue;
            }

            $bodyHtml = $this->fetchMessageContent(
                $mailHost,
                (string) $connection->access_token,
                $mailbox->provider_mailbox_id,
                $folderId,
                $messageId,
            );
            $headers = $this->fetchMessageHeaders(
                $mailHost,
                (string) $connection->access_token,
                $mailbox->provider_mailbox_id,
                $folderId,
                $messageId,
            );
            $from = $this->parseAddress(isset($row['fromAddress']) ? (string) $row['fromAddress'] : null);
            $bodyText = $bodyHtml !== null ? $this->plainText($bodyHtml) : '';

            if ($bodyText === '') {
                $bodyText = (string) ($row['summary'] ?? '');
            }

            $messages[] = new RemoteMessage(
                remoteMessageId: $messageId,
                remoteThreadId: isset($row['threadId']) ? (string) $row['threadId'] : null,
                direction: $this->messageDirection($mailbox, $scope, $from['email'] ?? null),
                subject: (string) ($row['subject'] ?? ''),
                bodyText: $bodyText,
                bodyHtml: $bodyHtml,
                fromEmail: $from['email'] ?? null,
                fromName: $from['name'] ?? (isset($row['sender']) ? (string) $row['sender'] : null),
                to: $this->parseAddresses($row['toAddress'] ?? null),
                cc: $this->parseAddresses($row['ccAddress'] ?? null),
                internetMessageId: $this->firstMessageId($headers['message-id'] ?? null),
                inReplyTo: $this->firstMessageId($headers['in-reply-to'] ?? null),
                references: $this->messageIds($headers['references'] ?? null),
                occurredAt: $this->receivedTimeMs($row) !== null
                    ? now()->setTimestamp(intdiv((int) $this->receivedTimeMs($row), 1000))
                    : now(),
                hasAttachments: $this->toBoolean($row['hasAttachment'] ?? false),
                headers: $headers,
                metadata: $row,
            );
        }

        $highWaterTimestamp = null;

        foreach ($messages as $message) {
            $occurredAt = $message->occurredAt?->getTimestamp();

            if ($occurredAt !== null && ($highWaterTimestamp === null || $occurredAt > $highWaterTimestamp)) {
                $highWaterTimestamp = $occurredAt;
            }
        }

        $boundaryIds = [];

        foreach ($messages as $message) {
            if ($highWaterTimestamp !== null && $message->occurredAt?->getTimestamp() === $highWaterTimestamp) {
                $boundaryIds[] = $message->remoteMessageId;
            }
        }

        $hasMore = $phase === 'backfill'
            && $rawRowCount >= $limit
            && ! $hasOlderRow;
        $nextPhase = $hasMore ? 'backfill' : 'incremental';
        $nextState = [
            'phase' => $phase === 'incremental' ? 'incremental' : $nextPhase,
            'start' => $hasMore ? $start + $rawRowCount : 1,
        ];

        $nextState['lookback_cutoff_ms'] = $lookbackCutoffMs;

        if ($phase === 'backfill') {
            $nextState['backfill_progress'] = $this->backfillProgress(
                $rows,
                $lookbackCutoffMs,
                $hasMore,
            );
        }

        if ($boundaryIds !== []) {
            $nextState['high_water_remote_ids'] = $boundaryIds;
        }

        return new RemoteMessagePage(
            messages: $messages,
            highWaterTimestamp: $highWaterTimestamp,
            nextState: $nextState,
            hasMore: $hasMore,
        );
    }

    private function messageDirection(Mailbox $mailbox, SyncScope $scope, ?string $fromEmail): MessageDirection
    {
        if ($scope === SyncScope::Sent) {
            return MessageDirection::Outbound;
        }

        $fromEmail = EmailNormalizer::normalize((string) $fromEmail);

        if ($fromEmail === '') {
            return MessageDirection::Inbound;
        }

        $senderEmails = [EmailNormalizer::normalize($mailbox->email_address)];
        $storedIdentities = $mailbox->getAttribute('sender_identities');

        if (is_array($storedIdentities)) {
            foreach ($storedIdentities as $identity) {
                if (! is_array($identity) || ($identity['active'] ?? true) !== true) {
                    continue;
                }

                $email = $identity['email'] ?? null;

                if (is_string($email)) {
                    $senderEmails[] = EmailNormalizer::normalize($email);
                }
            }
        }

        return in_array($fromEmail, array_unique(array_filter($senderEmails)), true)
            ? MessageDirection::Outbound
            : MessageDirection::Inbound;
    }

    /**
     * @return list<mixed>
     */
    private function fetchMessageRows(
        string $mailHost,
        string $accessToken,
        string $accountId,
        string $folderId,
        int $start,
        int $limit,
    ): array {
        $rows = $this->authorized($accessToken)
            ->get($mailHost.'/api/accounts/'.$accountId.'/messages/view', [
                'folderId' => $folderId,
                'start' => $start,
                'limit' => $limit,
                'sortBy' => 'date',
                // Zoho expects the literal boolean strings in query parameters.
                // Laravel serializes PHP booleans as 1/0, which Zoho rejects.
                'sortorder' => 'false',
                'includeto' => 'true',
            ])
            ->throw()
            ->json('data') ?? [];

        return is_array($rows) ? array_values($rows) : [];
    }

    private function fetchMessageContent(
        string $mailHost,
        string $accessToken,
        string $accountId,
        string $folderId,
        string $messageId,
    ): ?string {
        $response = $this->authorized($accessToken)
            ->retry(2, 250, $this->retryTooManyRequests(...), throw: false)
            ->get(
                $mailHost.'/api/accounts/'.$accountId.'/folders/'.$folderId.'/messages/'.$messageId.'/content',
                ['includeBlockContent' => 'true'],
            );

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();
        $content = $response->json('data.content');

        return is_string($content) && $content !== '' ? $content : null;
    }

    /**
     * @return array<string, string>
     */
    private function fetchMessageHeaders(
        string $mailHost,
        string $accessToken,
        string $accountId,
        string $folderId,
        string $messageId,
    ): array {
        $response = $this->authorized($accessToken)
            ->retry(2, 250, $this->retryTooManyRequests(...), throw: false)
            ->get(
                $mailHost.'/api/accounts/'.$accountId.'/folders/'.$folderId.'/messages/'.$messageId.'/header',
                ['raw' => 'false'],
            );

        if ($response->status() === 404) {
            return [];
        }

        $response->throw();

        return $this->normalizeHeaders($response->json('data.headerContent'));
    }

    /**
     * @param  list<mixed>  $rows
     * @return array{0: array<string, array<string, mixed>>, 1: bool}
     */
    private function keepRowsWithinLookback(array $rows, int $lookbackCutoffMs): array
    {
        $kept = [];
        $hasOlderRow = false;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $messageId = isset($row['messageId']) ? (string) $row['messageId'] : '';

            if ($messageId === '') {
                continue;
            }

            $receivedTimeMs = $this->receivedTimeMs($row);

            if ($receivedTimeMs !== null && $receivedTimeMs < $lookbackCutoffMs) {
                $hasOlderRow = true;

                continue;
            }

            $kept[$messageId] = $row;
        }

        return [$kept, $hasOlderRow];
    }

    /**
     * Fraction of the lookback window the backfill has walked through,
     * derived from the oldest message on the current page.
     *
     * @param  list<mixed>  $rows
     */
    private function backfillProgress(array $rows, int $lookbackCutoffMs, bool $hasMore): int
    {
        if (! $hasMore) {
            return 100;
        }

        $nowMs = now()->getTimestampMs();
        $windowMs = max(1, $nowMs - $lookbackCutoffMs);
        $oldestMs = null;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $receivedTimeMs = $this->receivedTimeMs($row);

            if ($receivedTimeMs !== null && ($oldestMs === null || $receivedTimeMs < $oldestMs)) {
                $oldestMs = $receivedTimeMs;
            }
        }

        if ($oldestMs === null || $oldestMs <= $lookbackCutoffMs) {
            return 1;
        }

        $percent = (int) floor((($nowMs - $oldestMs) / $windowMs) * 100);

        return max(1, min(99, $percent));
    }

    private function retryTooManyRequests(Throwable $exception): bool
    {
        return $exception instanceof RequestException
            && $exception->response->status() === 429;
    }

    private function authorized(string $accessToken): PendingRequest
    {
        return Http::withHeaders(['Authorization' => 'Zoho-oauthtoken '.$accessToken]);
    }

    /**
     * @return array<string, string>
     */
    private function normalizeHeaders(mixed $headers): array
    {
        if (is_string($headers)) {
            $headers = preg_replace("/\r?\n[ \t]+/", ' ', trim($headers)) ?? $headers;
            $lines = preg_split('/\r?\n/', $headers) ?: [];
            $normalized = [];

            foreach ($lines as $line) {
                if (! str_contains($line, ':')) {
                    continue;
                }

                [$name, $value] = explode(':', $line, 2);
                $this->addHeader($normalized, $name, $value);
            }

            return $normalized;
        }

        if (! is_array($headers)) {
            return [];
        }

        $normalized = [];

        foreach ($headers as $name => $values) {
            if (! is_string($name)) {
                continue;
            }

            if (is_array($values)) {
                $parts = array_values(array_filter(
                    array_map(fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '', $values),
                    fn (string $value): bool => $value !== '',
                ));
                $value = implode(', ', $parts);
            } else {
                $value = is_scalar($values) ? trim((string) $values) : '';
            }

            $this->addHeader($normalized, $name, $value);
        }

        return $normalized;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function addHeader(array &$headers, string $name, string $value): void
    {
        $name = strtolower(trim($name));
        $value = trim($value);

        if ($name === '' || $value === '') {
            return;
        }

        $headers[$name] = isset($headers[$name])
            ? $headers[$name].', '.$value
            : $value;
    }

    /**
     * @return array{email: string, name?: string|null}|null
     */
    private function parseAddress(?string $value): ?array
    {
        return $this->parseAddresses($value)[0] ?? null;
    }

    /**
     * @return list<array{email: string, name?: string|null}>
     */
    private function parseAddresses(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($value === '' || strcasecmp($value, 'Not Provided') === 0) {
            return [];
        }

        $parts = preg_split('/,(?=(?:[^\"]*\"[^\"]*\")*[^\"]*$)/', $value) ?: [];
        $addresses = [];

        foreach ($parts as $part) {
            $part = trim($part);
            $name = null;
            $email = $part;

            if (preg_match('/^(.*?)<([^<>]+)>$/', $part, $matches) === 1) {
                $name = trim($matches[1], " \t\n\r\0\x0B\"");
                $email = trim($matches[2]);
            }

            if ($email === '') {
                continue;
            }

            $address = ['email' => $email];

            if ($name !== null && $name !== '') {
                $address['name'] = $name;
            }

            $addresses[] = $address;
        }

        return $addresses;
    }

    private function plainText(string $content): string
    {
        return EmailNormalizer::htmlToText($content);
    }

    /**
     * @return list<string>
     */
    private function messageIds(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        preg_match_all('/<\s*([^<>]+?)\s*>/', $value, $matches);
        $parts = $matches[1];

        if ($parts === []) {
            $parts = preg_split('/[\s,]+/', trim($value)) ?: [];
        }

        $ids = [];

        foreach ($parts as $part) {
            $id = $this->normalizeMessageId($part);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function firstMessageId(mixed $value): ?string
    {
        return $this->messageIds($value)[0] ?? null;
    }

    private function normalizeMessageId(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value, " \t\n\r\0\x0B<>"));

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function receivedTimeMs(array $row): ?int
    {
        $value = $row['receivedTime'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value > 0;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    public function sendNew(Mailbox $mailbox, OutgoingMessage $message): SendResult
    {
        return $this->send($mailbox, $this->sendPayload($message), 'zoho-sent');
    }

    public function sendReply(Mailbox $mailbox, RemoteMessageReference $replyTo, OutgoingMessage $message): SendResult
    {
        $connection = $this->requireConnection($mailbox);
        $mailHost = $this->mailHostFromConnection($connection);
        $response = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken '.$connection->access_token])
            ->post(
                $mailHost.'/api/accounts/'.$mailbox->provider_mailbox_id.'/messages/'.$replyTo->remoteMessageId,
                $this->sendPayload($message, 'reply'),
            );

        $this->assertZohoAccepted($response, 'zoho_reply_failed');

        return new SendResult(
            remoteMessageId: (string) data_get($response->json(), 'data.messageId', 'zoho-reply'),
            remoteThreadId: data_get($response->json(), 'data.threadId', $replyTo->remoteThreadId),
            phase: 'sent',
        );
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function send(Mailbox $mailbox, array $payload, string $fallbackId): SendResult
    {
        $connection = $this->requireConnection($mailbox);
        $mailHost = $this->mailHostFromConnection($connection);
        $response = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken '.$connection->access_token])
            ->post($mailHost.'/api/accounts/'.$mailbox->provider_mailbox_id.'/messages', $payload);

        $this->assertZohoAccepted($response, 'zoho_send_failed');

        return new SendResult(
            remoteMessageId: (string) data_get($response->json(), 'data.messageId', $fallbackId),
            remoteThreadId: data_get($response->json(), 'data.threadId'),
            phase: 'sent',
        );
    }

    /**
     * Campaign and Unibox compose are single-recipient; OutgoingMessage has no cc/bcc.
     * toAddress still joins every $message->to entry if more than one To is present.
     *
     * @return array<string, string>
     */
    private function sendPayload(OutgoingMessage $message, ?string $action = null): array
    {
        $payload = [
            'fromAddress' => $message->fromEmail,
            'toAddress' => implode(',', array_map(fn (array $to): string => $to['email'], $message->to)),
            'subject' => $message->subject,
            'content' => $message->bodyHtml,
            'mailFormat' => 'html',
        ];

        if ($action !== null) {
            $payload['action'] = $action;
        }

        return $payload;
    }

    private function assertZohoAccepted(Response $response, string $prefix): void
    {
        if ($response->successful()) {
            return;
        }

        $description = data_get($response->json(), 'status.description');
        $moreInfo = data_get($response->json(), 'data.moreInfo');
        $detail = trim(implode(' ', array_filter([
            is_string($description) ? $description : null,
            is_string($moreInfo) ? $moreInfo : null,
        ])));

        throw new RuntimeException($detail !== '' ? $prefix.': '.$detail : $prefix);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAccounts(string $mailHost, string $accessToken): array
    {
        $data = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken '.$accessToken])
            ->get($mailHost.'/api/accounts')
            ->throw()
            ->json('data') ?? [];

        if (! is_array($data)) {
            return [];
        }

        /** @var list<array<string, mixed>> $accounts */
        $accounts = [];

        foreach ($data as $row) {
            if (is_array($row)) {
                $accounts[] = $row;
            }
        }

        return $accounts;
    }

    /**
     * @param  list<array<string, mixed>>  $accounts
     * @return array<string, mixed>
     */
    private function primaryAccount(array $accounts): array
    {
        foreach ($accounts as $account) {
            if (($account['type'] ?? null) === 'ZOHO_ACCOUNT') {
                return $account;
            }
        }

        return $accounts[0] ?? [];
    }

    /**
     * Parse only the safe, documented subset of Zoho's sendMailDetails rows.
     * The send API chooses by fromAddress, so the mailbox identity service
     * later collapses duplicate addresses into one effective choice.
     *
     * @param  array<string, mixed>  $account
     * @return list<RemoteSenderIdentity>
     */
    private function senderIdentities(array $account): array
    {
        $hasDetails = array_key_exists('sendMailDetails', $account);
        $details = $account['sendMailDetails'] ?? [];
        $details = is_array($details) ? $details : [];
        $identities = [];

        foreach ($details as $detail) {
            if (! is_array($detail)) {
                continue;
            }

            $email = EmailNormalizer::normalize((string) ($detail['fromAddress'] ?? ''));

            if (! EmailNormalizer::isValid($email)) {
                continue;
            }

            $identities[] = new RemoteSenderIdentity(
                key: MailboxSenderIdentityService::keyForEmail($email),
                emailAddress: $email,
                displayName: $this->safeProviderText($detail['displayName'] ?? null, 120),
                remoteId: $this->safeProviderText($detail['sendMailId'] ?? null, 120),
                mode: $this->safeProviderText($detail['mode'] ?? null, 40),
                active: $this->senderDetailIsActive($detail),
            );
        }

        // Older regional responses may omit sendMailDetails entirely. Keep
        // the account's primary address usable in that case; an explicitly
        // empty/invalid list remains empty so outbound can be blocked safely.
        if (! $hasDetails && EmailNormalizer::isValid((string) ($account['primaryEmailAddress'] ?? ''))) {
            $email = EmailNormalizer::normalize((string) $account['primaryEmailAddress']);
            $identities[] = new RemoteSenderIdentity(
                key: MailboxSenderIdentityService::keyForEmail($email),
                emailAddress: $email,
                displayName: $this->safeProviderText($account['displayName'] ?? null, 120),
                remoteId: $this->safeProviderText($account['accountId'] ?? null, 120),
                mode: 'primary',
                active: true,
            );
        }

        return $identities;
    }

    /**
     * Zoho has returned booleans, numeric flags, and status words across
     * regions. Validation flags are intentionally not used as the active
     * signal: active entries can legitimately have validated=false while
     * Zoho is still allowing them to be selected.
     *
     * @param  array<string, mixed>  $detail
     */
    private function senderDetailIsActive(array $detail): bool
    {
        if (! array_key_exists('status', $detail)) {
            return true;
        }

        $status = $detail['status'];

        if (is_bool($status)) {
            return $status;
        }

        if (is_numeric($status)) {
            return (int) $status > 0;
        }

        $status = strtolower(trim((string) $status));

        return in_array($status, ['1', 'true', 'yes', 'active', 'enabled', 'valid', 'verified'], true);
    }

    private function safeProviderText(mixed $value, int $maxLength): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $value) ?? '';
        $value = trim(EmailNormalizer::stripHeaderInjection($value));

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    /**
     * @return array{inbox_id: string|null, sent_id: string|null}
     */
    private function discoverFolderIds(string $mailHost, string $accessToken, string $accountId): array
    {
        $data = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken '.$accessToken])
            ->get($mailHost.'/api/accounts/'.$accountId.'/folders')
            ->throw()
            ->json('data') ?? [];

        if (! is_array($data)) {
            return ['inbox_id' => null, 'sent_id' => null];
        }

        /** @var list<array<string, mixed>> $folders */
        $folders = [];

        foreach ($data as $row) {
            if (is_array($row)) {
                $folders[] = $row;
            }
        }

        return [
            'inbox_id' => $this->folderId($folders, 'Inbox'),
            'sent_id' => $this->folderId($folders, 'Sent'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $folders
     */
    private function folderId(array $folders, string $type): ?string
    {
        $canonicalPath = '/'.$type;
        $match = null;

        foreach ($folders as $folder) {
            if (($folder['folderType'] ?? null) !== $type) {
                continue;
            }

            if (($folder['path'] ?? null) === $canonicalPath) {
                $match = $folder;
                break;
            }

            $match ??= $folder;
        }

        return isset($match['folderId']) ? (string) $match['folderId'] : null;
    }

    private function resolveFolderId(Mailbox $mailbox, MailboxSyncState $state): string
    {
        if (is_numeric($state->remote_folder_id)) {
            return (string) $state->remote_folder_id;
        }

        $scope = $this->syncScope($state);
        $metaKey = $scope === SyncScope::Sent ? 'sent_id' : 'inbox_id';
        $fromMailbox = data_get($mailbox->provider_metadata, $metaKey);

        if (is_numeric($fromMailbox)) {
            return (string) $fromMailbox;
        }

        $connection = $this->requireConnection($mailbox);
        $discovered = $this->discoverFolderIds(
            $this->mailHostFromConnection($connection),
            (string) $connection->access_token,
            $mailbox->provider_mailbox_id,
        );
        $id = $discovered[$metaKey] ?? null;

        if (! is_numeric($id)) {
            throw new RuntimeException('zoho_folder_unresolved');
        }

        $existingMeta = $mailbox->getAttribute('provider_metadata');
        $mailbox->forceFill([
            'provider_metadata' => array_merge(is_array($existingMeta) ? $existingMeta : [], $discovered),
        ])->save();

        return (string) $id;
    }

    private function accountsHostFromCallback(OAuthCallback $callback): string
    {
        $accountsServer = $callback->extras['accounts_server'] ?? null;

        if (is_string($accountsServer) && $accountsServer !== '') {
            return $this->allowlistedAccountsHost($accountsServer);
        }

        $location = $callback->extras['location'] ?? null;

        return $this->accountsHostFromLocation(is_string($location) ? $location : null);
    }

    private function accountsHostFromLocation(?string $location): string
    {
        $key = strtolower((string) $location);
        $region = config('outboss.zoho_regions.'.$key);

        if (is_array($region) && isset($region['accounts']) && is_string($region['accounts'])) {
            return $this->allowlistedAccountsHost($region['accounts']);
        }

        return $this->allowlistedAccountsHost('https://accounts.zoho.com');
    }

    private function locationFromAccountsHost(string $accountsHost, OAuthCallback $callback): string
    {
        $location = $callback->extras['location'] ?? null;

        if (is_string($location) && $location !== '') {
            return strtolower($location);
        }

        foreach (config('outboss.zoho_regions') as $key => $region) {
            if (is_array($region) && ($region['accounts'] ?? null) === $accountsHost) {
                return (string) $key;
            }
        }

        return 'us';
    }

    private function allowlistedAccountsHost(string $host): string
    {
        $allowed = [];
        $regions = config('outboss.zoho_regions');

        if (is_array($regions)) {
            foreach ($regions as $region) {
                if (is_array($region) && isset($region['accounts']) && is_string($region['accounts'])) {
                    $allowed[] = $region['accounts'];
                }
            }
        }

        $normalized = rtrim($host, '/');

        if (! in_array($normalized, $allowed, true)) {
            throw new RuntimeException('zoho_untrusted_accounts_host');
        }

        return $normalized;
    }

    private function mailHostFromAccounts(string $accountsHost): string
    {
        foreach (config('outboss.zoho_regions') as $region) {
            if (is_array($region) && ($region['accounts'] ?? null) === $accountsHost) {
                return (string) $region['mail'];
            }
        }

        throw new RuntimeException('zoho_untrusted_mail_host');
    }

    private function mailHostFromConnection(ProviderConnection $connection): string
    {
        $host = data_get($connection->provider_metadata, 'api_domain', 'https://mail.zoho.com');

        return is_string($host) && $host !== '' ? rtrim($host, '/') : 'https://mail.zoho.com';
    }

    private function requireConnection(Mailbox $mailbox): ProviderConnection
    {
        $connection = $mailbox->relationLoaded('connection')
            ? $mailbox->getRelation('connection')
            : $mailbox->connection()->first();

        if (! $connection instanceof ProviderConnection) {
            throw new RuntimeException('zoho_mailbox_unconnected');
        }

        return $connection;
    }

    private function syncScope(MailboxSyncState $state): SyncScope
    {
        $scope = $state->getAttribute('scope');

        return $scope instanceof SyncScope
            ? $scope
            : SyncScope::from((string) $scope);
    }

    /**
     * @return list<string>
     */
    private function parseScopes(mixed $scope): array
    {
        if (! is_string($scope) || $scope === '') {
            return [];
        }

        $parts = preg_split('/[\s,]+/', $scope) ?: [];

        return array_values(array_filter($parts, fn (string $part): bool => $part !== ''));
    }
}
