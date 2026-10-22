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
use App\Domain\Mail\Support\EmailNormalizer;
use App\Models\Mailbox;
use App\Models\MailboxSyncState;
use App\Models\OAuthAttempt;
use App\Models\ProviderConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MicrosoftProvider implements MailProvider
{
    use ProviderUnknownSendReconciliation;

    public function authorizationUrl(OAuthAttempt $attempt): string
    {
        $query = http_build_query([
            'client_id' => config('services.microsoft.client_id'),
            'response_type' => 'code',
            'redirect_uri' => config('services.microsoft.redirect'),
            'response_mode' => 'query',
            'scope' => 'openid profile email offline_access User.Read Mail.ReadWrite Mail.Send',
            'state' => $attempt->raw_state ?? $attempt->state_hash,
        ]);

        return 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?'.$query;
    }

    public function exchangeAuthorizationCode(OAuthCallback $callback): ConnectedAccount
    {
        $response = Http::asForm()->post('https://login.microsoftonline.com/common/oauth2/v2.0/token', [
            'client_id' => config('services.microsoft.client_id'),
            'client_secret' => config('services.microsoft.client_secret'),
            'code' => $callback->code,
            'redirect_uri' => config('services.microsoft.redirect'),
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('microsoft_oauth_exchange_failed');
        }

        $me = $this->graph($response->json('access_token'))->get('/me')->throw()->json();

        return new ConnectedAccount(
            providerUserId: (string) ($me['id'] ?? $me['userPrincipalName']),
            accessToken: $response->json('access_token'),
            refreshToken: $response->json('refresh_token'),
            tokenExpiresAt: now()->addSeconds((int) $response->json('expires_in', 3600)),
            grantedScopes: explode(' ', (string) $response->json('scope', '')),
            mailboxes: [new RemoteMailbox(
                providerMailboxId: (string) ($me['id'] ?? $me['mail'] ?? $me['userPrincipalName']),
                emailAddress: (string) ($me['mail'] ?? $me['userPrincipalName']),
                displayName: $me['displayName'] ?? null,
            )],
        );
    }

    public function refreshConnection(ProviderConnection $connection): RefreshedTokens
    {
        $response = Http::asForm()->post('https://login.microsoftonline.com/common/oauth2/v2.0/token', [
            'client_id' => config('services.microsoft.client_id'),
            'client_secret' => config('services.microsoft.client_secret'),
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
        // Microsoft delegated tokens are invalidated by password/consent change; no universal revoke endpoint.
    }

    public function mailboxes(ProviderConnection $connection): array
    {
        $me = $this->graph($connection->access_token)->get('/me')->throw()->json();

        return [new RemoteMailbox(
            providerMailboxId: (string) $me['id'],
            emailAddress: (string) ($me['mail'] ?? $me['userPrincipalName']),
            displayName: $me['displayName'] ?? null,
        )];
    }

    public function syncFolder(Mailbox $mailbox, MailboxSyncState $state, int $pageSize = 100): RemoteMessagePage
    {
        $url = $state->cursor ?: $this->deltaUrl($state);
        $response = $this->graph($mailbox->connection->access_token)
            ->withHeaders(['Prefer' => 'IdType="ImmutableId"'])
            ->get($url, $state->cursor ? [] : ['$top' => $pageSize])
            ->throw()
            ->json();

        $messages = collect($response['value'] ?? [])
            ->map(fn (array $item) => $this->mapMessage($item, $state->scope->value))
            ->all();

        return new RemoteMessagePage(
            messages: $messages,
            cursor: $response['@odata.deltaLink'] ?? $response['@odata.nextLink'] ?? $state->cursor,
            hasMore: isset($response['@odata.nextLink']),
        );
    }

    public function sendNew(Mailbox $mailbox, OutgoingMessage $message): SendResult
    {
        $draft = $this->graph($mailbox->connection->access_token)
            ->withHeaders(['Prefer' => 'IdType="ImmutableId"'])
            ->post('/me/messages', $this->draftPayload($message))
            ->throw()
            ->json();

        $this->graph($mailbox->connection->access_token)
            ->post('/me/messages/'.$draft['id'].'/send')
            ->throw();

        return new SendResult(
            remoteMessageId: (string) $draft['id'],
            remoteThreadId: $draft['conversationId'] ?? null,
            internetMessageId: $draft['internetMessageId'] ?? null,
            providerDraftId: $draft['id'],
            phase: 'send_submitted',
        );
    }

    public function sendReply(Mailbox $mailbox, RemoteMessageReference $replyTo, OutgoingMessage $message): SendResult
    {
        $draft = $this->graph($mailbox->connection->access_token)
            ->withHeaders(['Prefer' => 'IdType="ImmutableId"'])
            ->post('/me/messages/'.$replyTo->remoteMessageId.'/createReply')
            ->throw()
            ->json();

        $this->graph($mailbox->connection->access_token)
            ->withHeaders(['Prefer' => 'IdType="ImmutableId"'])
            ->patch('/me/messages/'.$draft['id'], [
                'body' => ['contentType' => 'HTML', 'content' => $message->bodyHtml],
                'toRecipients' => array_map(fn (array $to) => [
                    'emailAddress' => ['address' => $to['email']],
                ], $message->to),
            ])
            ->throw();

        $this->graph($mailbox->connection->access_token)
            ->post('/me/messages/'.$draft['id'].'/send')
            ->throw();

        return new SendResult(
            remoteMessageId: (string) $draft['id'],
            remoteThreadId: $draft['conversationId'] ?? $replyTo->remoteThreadId,
            internetMessageId: $draft['internetMessageId'] ?? null,
            providerDraftId: $draft['id'],
            phase: 'send_submitted',
        );
    }

    private function graph(string $token): PendingRequest
    {
        return Http::baseUrl('https://graph.microsoft.com/v1.0')->withToken($token);
    }

    private function deltaUrl(MailboxSyncState $state): string
    {
        $folder = $state->scope->value === 'sent' ? 'sentitems' : 'inbox';

        return "/me/mailFolders/{$folder}/messages/delta";
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function mapMessage(array $item, string $scope): RemoteMessage
    {
        return new RemoteMessage(
            remoteMessageId: (string) $item['id'],
            remoteThreadId: $item['conversationId'] ?? null,
            direction: $scope === 'sent' ? MessageDirection::Outbound : MessageDirection::Inbound,
            subject: (string) ($item['subject'] ?? ''),
            bodyText: EmailNormalizer::htmlToText((string) data_get($item, 'body.content', '')),
            bodyHtml: data_get($item, 'body.content'),
            fromEmail: data_get($item, 'from.emailAddress.address'),
            fromName: data_get($item, 'from.emailAddress.name'),
            to: collect($item['toRecipients'] ?? [])->map(fn (array $to) => [
                'email' => data_get($to, 'emailAddress.address'),
            ])->all(),
            internetMessageId: $item['internetMessageId'] ?? null,
            occurredAt: isset($item['receivedDateTime']) ? now()->parse($item['receivedDateTime']) : now(),
            deleted: ($item['@removed']['reason'] ?? null) === 'deleted',
            metadata: ['immutable' => true],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function draftPayload(OutgoingMessage $message): array
    {
        return [
            'subject' => $message->subject,
            'body' => ['contentType' => 'HTML', 'content' => $message->bodyHtml],
            'toRecipients' => array_map(fn (array $to) => [
                'emailAddress' => ['address' => $to['email']],
            ], $message->to),
            'internetMessageHeaders' => $message->clientMessageMarker ? [[
                'name' => 'X-Outboss-Message-Id',
                'value' => $message->clientMessageMarker,
            ]] : [],
        ];
    }
}
