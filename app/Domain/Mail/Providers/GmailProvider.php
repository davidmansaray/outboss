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
use App\Domain\Mail\DTOs\SendResult;
use App\Domain\Mail\Enums\MessageDirection;
use App\Models\Mailbox;
use App\Models\MailboxSyncState;
use App\Models\OAuthAttempt;
use App\Models\ProviderConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mime\Email;

class GmailProvider implements MailProvider
{
    use ProviderUnknownSendReconciliation;

    public function authorizationUrl(OAuthAttempt $attempt): string
    {
        $query = http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect'),
            'response_type' => 'code',
            'scope' => 'openid email profile https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/gmail.send',
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'state' => $attempt->raw_state ?? $attempt->state_hash,
            'code_challenge' => $this->codeChallenge($attempt->pkce_verifier),
            'code_challenge_method' => 'S256',
        ]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.$query;
    }

    public function exchangeAuthorizationCode(OAuthCallback $callback): ConnectedAccount
    {
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $callback->code,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.redirect'),
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('gmail_oauth_exchange_failed');
        }

        $profile = Http::withToken($response->json('access_token'))
            ->get('https://gmail.googleapis.com/gmail/v1/users/me/profile')
            ->throw()
            ->json();

        return new ConnectedAccount(
            providerUserId: (string) $profile['emailAddress'],
            accessToken: $response->json('access_token'),
            refreshToken: $response->json('refresh_token'),
            tokenExpiresAt: now()->addSeconds((int) $response->json('expires_in', 3600)),
            grantedScopes: explode(' ', (string) $response->json('scope', '')),
            mailboxes: [new RemoteMailbox(
                providerMailboxId: (string) $profile['emailAddress'],
                emailAddress: (string) $profile['emailAddress'],
                metadata: ['history_id' => $profile['historyId'] ?? null],
            )],
        );
    }

    public function refreshConnection(ProviderConnection $connection): RefreshedTokens
    {
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $connection->refresh_token,
            'grant_type' => 'refresh_token',
        ])->throw();

        return new RefreshedTokens(
            accessToken: $response->json('access_token'),
            refreshToken: $response->json('refresh_token') ?: $connection->refresh_token,
            tokenExpiresAt: now()->addSeconds((int) $response->json('expires_in', 3600)),
        );
    }

    public function revokeConnection(ProviderConnection $connection): void
    {
        if ($connection->refresh_token) {
            Http::asForm()->timeout(5)->post('https://oauth2.googleapis.com/revoke', [
                'token' => $connection->refresh_token,
            ]);
        }
    }

    public function mailboxes(ProviderConnection $connection): array
    {
        $profile = Http::withToken($connection->access_token)
            ->get('https://gmail.googleapis.com/gmail/v1/users/me/profile')
            ->throw()
            ->json();

        return [new RemoteMailbox(
            providerMailboxId: (string) $profile['emailAddress'],
            emailAddress: (string) $profile['emailAddress'],
            metadata: ['history_id' => $profile['historyId'] ?? null],
        )];
    }

    public function syncFolder(Mailbox $mailbox, MailboxSyncState $state, int $pageSize = 100): RemoteMessagePage
    {
        $token = $mailbox->connection->access_token;

        if ($state->cursor) {
            $scanState = $state->getAttribute('scan_state');
            $scanState = is_array($scanState) ? $scanState : [];
            $historyStartId = data_get($scanState, 'gmail_history_start_id');
            $historyStartId = is_scalar($historyStartId) && (string) $historyStartId !== ''
                ? (string) $historyStartId
                : $state->cursor;
            $pageToken = data_get($scanState, 'gmail_history_page_token');
            $pageToken = is_scalar($pageToken) && (string) $pageToken !== ''
                ? (string) $pageToken
                : null;
            $query = [
                'startHistoryId' => $historyStartId,
                'maxResults' => $pageSize,
            ];

            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }

            $history = Http::withToken($token)
                ->get('https://gmail.googleapis.com/gmail/v1/users/me/history', $query);

            if ($history->status() === 404) {
                return $this->fullSync($token, $pageSize, true);
            }

            $history->throw();
            $ids = collect($history->json('history', []))
                ->flatMap(fn (array $row) => collect($row['messages'] ?? [])->pluck('id'))
                ->unique()
                ->values();
            $nextPageToken = $history->json('nextPageToken');
            $hasMore = is_scalar($nextPageToken) && (string) $nextPageToken !== '';

            return new RemoteMessagePage(
                messages: $ids->map(fn (string $id) => $this->fetchMessage($token, $id))->all(),
                cursor: $hasMore ? $state->cursor : ($history->json('historyId') ?? $state->cursor),
                nextState: [
                    'gmail_history_start_id' => $hasMore ? $historyStartId : null,
                    'gmail_history_page_token' => $hasMore ? (string) $nextPageToken : null,
                ],
                hasMore: $hasMore,
            );
        }

        return $this->fullSync($token, $pageSize, false);
    }

    public function sendNew(Mailbox $mailbox, OutgoingMessage $message): SendResult
    {
        return $this->sendRaw($mailbox, $message, null);
    }

    public function sendReply(Mailbox $mailbox, RemoteMessageReference $replyTo, OutgoingMessage $message): SendResult
    {
        return $this->sendRaw($mailbox, $message, $replyTo);
    }

    private function sendRaw(Mailbox $mailbox, OutgoingMessage $message, ?RemoteMessageReference $replyTo): SendResult
    {
        $email = (new Email)
            ->from($message->fromEmail)
            ->to(...array_map(fn (array $to) => $to['email'], $message->to))
            ->subject($message->subject)
            ->text($message->bodyText)
            ->html($message->bodyHtml);

        if ($message->clientMessageMarker) {
            $email->getHeaders()->addIdHeader('Message-ID', $message->clientMessageMarker.'@outboss.local');
        }

        if ($message->inReplyTo) {
            $email->getHeaders()->addIdHeader('In-Reply-To', trim($message->inReplyTo, '<>'));
        }

        if ($message->listUnsubscribeUrl) {
            $email->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$message->listUnsubscribeUrl.'>');

            if ($message->oneClickPost) {
                $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            }
        }

        $raw = rtrim(strtr(base64_encode($email->toString()), '+/', '-_'), '=');
        $payload = ['raw' => $raw];

        if ($replyTo?->remoteThreadId) {
            $payload['threadId'] = $replyTo->remoteThreadId;
        }

        $response = Http::withToken($mailbox->connection->access_token)
            ->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', $payload)
            ->throw()
            ->json();

        return new SendResult(
            remoteMessageId: (string) $response['id'],
            remoteThreadId: $response['threadId'] ?? $replyTo?->remoteThreadId,
            internetMessageId: $response['internetMessageId'] ?? null,
            phase: 'sent',
        );
    }

    private function fullSync(string $token, int $pageSize, bool $resync): RemoteMessagePage
    {
        $list = Http::withToken($token)
            ->get('https://gmail.googleapis.com/gmail/v1/users/me/messages', [
                'maxResults' => min($pageSize, 200),
                'q' => 'newer_than:30d',
            ])
            ->throw()
            ->json();

        $messages = collect($list['messages'] ?? [])
            ->pluck('id')
            ->map(fn (string $id) => $this->fetchMessage($token, $id))
            ->all();

        $profile = Http::withToken($token)
            ->get('https://gmail.googleapis.com/gmail/v1/users/me/profile')
            ->throw()
            ->json();

        return new RemoteMessagePage(
            messages: $messages,
            cursor: $profile['historyId'] ?? null,
            nextState: ['resync' => $resync],
        );
    }

    private function fetchMessage(string $token, string $id): RemoteMessage
    {
        $payload = Http::withToken($token)
            ->get("https://gmail.googleapis.com/gmail/v1/users/me/messages/{$id}", [
                'format' => 'full',
            ])
            ->throw()
            ->json();

        $headers = collect($payload['payload']['headers'] ?? [])
            ->mapWithKeys(fn (array $header) => [strtolower($header['name']) => $header['value']]);

        $labelIds = $payload['labelIds'] ?? [];
        $direction = in_array('SENT', $labelIds, true)
            ? MessageDirection::Outbound
            : MessageDirection::Inbound;

        return new RemoteMessage(
            remoteMessageId: (string) $payload['id'],
            remoteThreadId: $payload['threadId'] ?? null,
            direction: $direction,
            subject: (string) $headers->get('subject', ''),
            bodyText: $payload['snippet'] ?? '',
            bodyHtml: null,
            fromEmail: $this->parseAddress($headers->get('from')),
            fromName: null,
            to: [['email' => (string) $this->parseAddress($headers->get('to'))]],
            internetMessageId: $headers->get('message-id'),
            inReplyTo: $headers->get('in-reply-to'),
            occurredAt: isset($payload['internalDate']) ? now()->setTimestamp(((int) $payload['internalDate']) / 1000) : now(),
            headers: $headers->all(),
            metadata: ['labelIds' => $labelIds],
        );
    }

    private function parseAddress(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        if (preg_match('/<([^>]+)>/', $value, $matches)) {
            return $matches[1];
        }

        return trim($value);
    }

    private function codeChallenge(?string $verifier): string
    {
        $verifier ??= Str::random(64);

        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
