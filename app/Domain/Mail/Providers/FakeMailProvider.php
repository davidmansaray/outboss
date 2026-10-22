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
use App\Domain\Mail\DTOs\SendReconciliationResult;
use App\Domain\Mail\DTOs\SendResult;
use App\Domain\Mail\DTOs\UnknownSendReference;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Services\MailboxSenderIdentityService;
use App\Models\Mailbox;
use App\Models\MailboxSyncState;
use App\Models\OAuthAttempt;
use App\Models\ProviderConnection;
use Illuminate\Support\Str;
use RuntimeException;

class FakeMailProvider implements MailProvider
{
    /** @var array<string, mixed> */
    public static array $store = [];

    public static int $refreshCalls = 0;

    public static bool $omitRefreshToken = false;

    public static ?string $forcedIdentity = null;

    public static bool $failExchange = false;

    public static bool $timeoutSend = false;

    public static bool $timeoutAfterSend = false;

    public static bool $failRevoke = false;

    public static string $sendResultPhase = 'sent';

    public static ?OAuthCallback $lastCallback = null;

    public function __construct(public readonly MailProviderName $provider) {}

    public static function reset(): void
    {
        self::$store = [];
        self::$refreshCalls = 0;
        self::$omitRefreshToken = false;
        self::$forcedIdentity = null;
        self::$failExchange = false;
        self::$timeoutSend = false;
        self::$timeoutAfterSend = false;
        self::$failRevoke = false;
        self::$sendResultPhase = 'sent';
        self::$lastCallback = null;
    }

    public static function seedMailbox(string $email, MailProviderName $provider = MailProviderName::Gmail, string $providerUserId = 'fake-user'): RemoteMailbox
    {
        $mailbox = new RemoteMailbox(
            providerMailboxId: $providerUserId.'-'.$email,
            emailAddress: $email,
            displayName: $email,
            metadata: ['inbox_id' => 'inbox', 'sent_id' => 'sent'],
            senderIdentities: [new RemoteSenderIdentity(
                key: MailboxSenderIdentityService::keyForEmail($email),
                emailAddress: $email,
                displayName: $email,
                mode: 'primary',
            )],
        );

        self::$store['accounts'][$provider->value][$providerUserId] = [
            'email' => $email,
            'mailboxes' => [$mailbox],
        ];

        return $mailbox;
    }

    public static function seedMessage(string $mailboxKey, RemoteMessage $message): void
    {
        self::$store['messages'][$mailboxKey][$message->remoteMessageId] = $message;
    }

    public function authorizationUrl(OAuthAttempt $attempt): string
    {
        return 'https://fake.oauth.test/'.$this->provider->value.'?state='.$attempt->id;
    }

    public function exchangeAuthorizationCode(OAuthCallback $callback): ConnectedAccount
    {
        self::$lastCallback = $callback;

        if (self::$failExchange) {
            throw new RuntimeException('oauth_exchange_failed');
        }

        $providerUserId = self::$forcedIdentity ?? 'fake-'.$this->provider->value;
        $email = self::$store['accounts'][$this->provider->value][$providerUserId]['email'] ?? $this->provider->value.'@example.test';
        $mailbox = self::$store['accounts'][$this->provider->value][$providerUserId]['mailboxes'][0]
            ?? self::seedMailbox($email, $this->provider, $providerUserId);

        return new ConnectedAccount(
            providerUserId: $providerUserId,
            accessToken: 'fake-access-'.$this->provider->value,
            refreshToken: self::$omitRefreshToken ? null : 'fake-refresh-'.$this->provider->value,
            tokenExpiresAt: now()->addHour(),
            grantedScopes: ['mail.read', 'mail.send'],
            mailboxes: [$mailbox],
            metadata: ['region' => 'us'],
        );
    }

    public function refreshConnection(ProviderConnection $connection): RefreshedTokens
    {
        self::$refreshCalls++;

        return new RefreshedTokens(
            accessToken: 'fake-access-refreshed-'.self::$refreshCalls,
            refreshToken: $connection->refresh_token ? 'fake-refresh-rotated-'.self::$refreshCalls : null,
            tokenExpiresAt: now()->addHour(),
        );
    }

    public function revokeConnection(ProviderConnection $connection): void
    {
        if (self::$failRevoke) {
            throw new RuntimeException('revoke_failed');
        }

        self::$store['revoked'][] = $connection->id;
    }

    public function mailboxes(ProviderConnection $connection): array
    {
        if ($connection->provider === MailProviderName::Stalwart) {
            $payload = $connection->getAttribute('credential_payload');
            $email = is_array($payload)
                ? ($payload['email'] ?? $payload['username'] ?? null)
                : null;
            $email = is_string($email) && filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false
                ? strtolower(trim($email))
                : 'stalwart@example.test';

            // Managed readiness tests need a deterministic Mailbox/get result
            // whose account id is the remote account persisted by the fake
            // management provisioner. Public OAuth fake behavior remains the
            // original provider-user-id-based fixture below.
            return [new RemoteMailbox(
                providerMailboxId: (string) $connection->provider_user_id,
                emailAddress: $email,
                displayName: $email,
                metadata: ['account_id' => (string) $connection->provider_user_id, 'inbox_id' => 'inbox', 'sent_id' => 'sent'],
                senderIdentities: [new RemoteSenderIdentity(
                    key: MailboxSenderIdentityService::keyForEmail($email),
                    emailAddress: $email,
                    displayName: $email,
                    mode: 'primary',
                )],
            )];
        }

        return self::$store['accounts'][$connection->provider->value][$connection->provider_user_id]['mailboxes']
            ?? [self::seedMailbox($connection->provider->value.'@example.test', $connection->provider, $connection->provider_user_id)];
    }

    public function syncFolder(Mailbox $mailbox, MailboxSyncState $state, int $pageSize = 100): RemoteMessagePage
    {
        $messages = array_values(self::$store['messages'][$mailbox->email_address] ?? []);

        return new RemoteMessagePage(
            messages: array_slice($messages, 0, $pageSize),
            cursor: 'cursor-'.$mailbox->id,
            nextState: ['synced' => true],
        );
    }

    public function sendNew(Mailbox $mailbox, OutgoingMessage $message): SendResult
    {
        return $this->recordSend($mailbox, $message, null);
    }

    public function sendReply(Mailbox $mailbox, RemoteMessageReference $replyTo, OutgoingMessage $message): SendResult
    {
        return $this->recordSend($mailbox, $message, $replyTo);
    }

    public function reconcileUnknownSend(Mailbox $mailbox, UnknownSendReference $reference): SendReconciliationResult
    {
        $matches = [];

        foreach (self::$store['sends'] ?? [] as $send) {
            if (! is_array($send) || ($send['mailbox'] ?? null) !== $mailbox->email_address) {
                continue;
            }

            if ($reference->clientMessageMarker !== null
                && ($send['clientMessageMarker'] ?? null) === $reference->clientMessageMarker) {
                $matches[] = $send;

                continue;
            }

            // Older fake fixtures only recorded mailbox + subject. Keep
            // those fixtures compatible while preferring exact markers
            // whenever the provider has one.
            if (($send['clientMessageMarker'] ?? null) === null
                && ($reference->subject === null || ($send['subject'] ?? null) === $reference->subject)) {
                $matches[] = $send;
            }
        }

        // Preserve the fake provider's existing operator-review semantics.
        // It intentionally does not claim that a matching record proves final
        // delivery; Stalwart uses the stronger exact-marker result below.
        if ($matches !== []) {
            return SendReconciliationResult::manualReview(
                code: 'fake_provider_match_requires_review',
                message: 'Ambiguous provider result; confirm before retrying.',
            );
        }

        return SendReconciliationResult::confirmedNotSent();
    }

    private function recordSend(Mailbox $mailbox, OutgoingMessage $message, ?RemoteMessageReference $replyTo): SendResult
    {
        if (self::$timeoutSend) {
            throw new RuntimeException('provider_timeout');
        }

        $remoteId = 'fake-msg-'.Str::uuid();
        $threadId = $replyTo?->remoteThreadId ?? 'fake-thread-'.Str::uuid();
        $internetId = '<'.$remoteId.'@outboss.test>';

        $remote = new RemoteMessage(
            remoteMessageId: $remoteId,
            remoteThreadId: $threadId,
            direction: MessageDirection::Outbound,
            subject: $message->subject,
            bodyText: $message->bodyText,
            bodyHtml: $message->bodyHtml,
            fromEmail: $message->fromEmail,
            fromName: $message->fromName,
            to: $message->to,
            internetMessageId: $internetId,
            inReplyTo: $message->inReplyTo,
            references: $message->references,
            occurredAt: now(),
            metadata: ['client_marker' => $message->clientMessageMarker],
        );

        self::seedMessage($mailbox->email_address, $remote);
        self::$store['sends'][] = [
            'mailbox' => $mailbox->email_address,
            'fromEmail' => $message->fromEmail,
            'fromName' => $message->fromName,
            'subject' => $message->subject,
            'clientMessageMarker' => $message->clientMessageMarker,
            'remoteMessageId' => $remoteId,
            'remoteThreadId' => $threadId,
            'internetMessageId' => $internetId,
            'thread' => $threadId,
            'reply_to' => $replyTo?->remoteMessageId,
            'list_unsubscribe' => $message->listUnsubscribeUrl,
            'list_unsubscribe_post' => $message->oneClickPost,
        ];

        if (self::$timeoutAfterSend) {
            throw new RuntimeException('provider_timeout_after_acceptance');
        }

        return new SendResult(
            remoteMessageId: $remoteId,
            remoteThreadId: $threadId,
            internetMessageId: $internetId,
            phase: self::$sendResultPhase,
        );
    }
}
