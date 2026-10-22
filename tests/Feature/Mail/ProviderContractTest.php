<?php

use App\Domain\Mail\DTOs\OAuthCallback;
use App\Domain\Mail\DTOs\OutgoingMessage;
use App\Domain\Mail\DTOs\RemoteMessageReference;
use App\Domain\Mail\Enums\AuthScheme;
use App\Domain\Mail\Enums\ConnectionKind;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\SyncScope;
use App\Domain\Mail\Providers\FakeMailProvider;
use App\Domain\Mail\Providers\StalwartProvider;
use App\Models\Mailbox;
use App\Models\MailboxSyncState;
use App\Models\OAuthAttempt;
use App\Models\ProviderConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('satisfies the shared provider contract for every adapter', function (MailProviderName $provider) {
    $adapter = new FakeMailProvider($provider);
    $user = User::factory()->create();
    $attempt = OAuthAttempt::factory()->create(['user_id' => $user->id, 'provider' => $provider]);

    expect($adapter->authorizationUrl($attempt))->toContain($provider->value);

    $account = $adapter->exchangeAuthorizationCode(new OAuthCallback(
        code: 'code',
        state: 'state',
    ));
    expect($account->providerUserId)->not->toBeEmpty()
        ->and($account->mailboxes)->not->toBeEmpty();

    $mailbox = Mailbox::factory()->create();
    $tokens = $adapter->refreshConnection($mailbox->connection);
    expect($tokens->accessToken)->toStartWith('fake-access-refreshed');

    $state = MailboxSyncState::factory()->create(['mailbox_id' => $mailbox->id]);
    $page = $adapter->syncFolder($mailbox, $state);
    expect($page->messages)->toBeArray();

    $sent = $adapter->sendNew($mailbox, new OutgoingMessage(
        subject: 'Hello',
        bodyText: 'Body',
        bodyHtml: '<p>Body</p>',
        to: [['email' => 'maya@stripe.com']],
        fromEmail: $mailbox->email_address,
        clientMessageMarker: 'marker',
        listUnsubscribeUrl: 'https://outboss.test/unsub/aaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        oneClickPost: true,
    ));
    $recorded = FakeMailProvider::$store['sends'][array_key_last(FakeMailProvider::$store['sends'])];
    expect($sent->remoteMessageId)->not->toBeEmpty()
        ->and($recorded['list_unsubscribe'])->toContain('/unsub/')
        ->and($recorded['list_unsubscribe_post'])->toBeTrue();

    $reply = $adapter->sendReply($mailbox, new RemoteMessageReference(
        remoteMessageId: $sent->remoteMessageId,
        remoteThreadId: $sent->remoteThreadId,
        internetMessageId: $sent->internetMessageId,
        subject: 'Hello',
    ), new OutgoingMessage(
        subject: 'Hello',
        bodyText: 'Reply',
        bodyHtml: '<p>Reply</p>',
        to: [['email' => 'maya@stripe.com']],
        fromEmail: $mailbox->email_address,
        clientMessageMarker: 'marker-2',
        inReplyTo: $sent->internetMessageId,
    ));
    expect($reply->remoteThreadId)->toBe($sent->remoteThreadId);
})->with(MailProviderName::cases());

it('satisfies the shared transport contract with the real Stalwart provider', function () {
    // The contract fixture uses an isolated instance key and an in-process
    // HTTP fake; configure the transport origin explicitly so this test does
    // not depend on a developer's environment variables.
    config()->set('outboss.managed_mail.instances.internal.base_url', 'https://jmap.contract.test');
    config()->set('outboss.managed_mail.instances.internal.verify_tls', true);
    config()->set('outboss.managed_mail.instances.internal.timeout_seconds', 15);
    config()->set('outboss.managed_mail.session_cache_seconds', 60);

    $connection = ProviderConnection::factory()->create([
        'provider' => MailProviderName::Stalwart,
        'provider_user_id' => 'contract-account',
        'connection_kind' => ConnectionKind::Managed,
        'auth_scheme' => AuthScheme::AppPassword,
        'credential_payload' => [
            'username' => 'contract@managed.example.com',
            'password' => 'contract-runtime-secret',
            'version' => 1,
        ],
        'access_token' => null,
        'refresh_token' => null,
        'managed_instance_key' => 'internal',
    ]);
    $mailbox = Mailbox::factory()->create([
        'user_id' => $connection->user_id,
        'workspace_id' => $connection->workspace_id,
        'provider_connection_id' => $connection->id,
        'provider_mailbox_id' => 'contract-account',
        'email_address' => 'contract@managed.example.com',
        'provider_metadata' => [
            'account_id' => 'contract-account',
            'inbox_id' => 'contract-inbox',
            'sent_id' => 'contract-sent',
        ],
    ]);
    $state = MailboxSyncState::factory()->create([
        'mailbox_id' => $mailbox->id,
        'scope' => SyncScope::Inbox,
        'remote_folder_id' => 'contract-inbox',
    ]);

    $session = [
        'capabilities' => [
            'urn:ietf:params:jmap:core' => [],
            'urn:ietf:params:jmap:mail' => [],
            'urn:ietf:params:jmap:submission' => [],
        ],
        'apiUrl' => 'https://jmap.contract.test/jmap',
        'primaryAccounts' => ['urn:ietf:params:jmap:mail' => 'contract-account'],
        'accounts' => ['contract-account' => ['name' => 'contract@managed.example.com']],
    ];
    $mailboxList = [
        'list' => [
            ['id' => 'contract-inbox', 'name' => 'Inbox', 'role' => 'inbox', 'mayReadItems' => true],
            ['id' => 'contract-sent', 'name' => 'Sent', 'role' => 'sent', 'mayAddItems' => true],
        ],
        'accountId' => 'contract-account',
        'state' => 'contract-mailbox-state',
    ];

    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($session, $mailboxList) {
        if ($request->method() === 'GET') {
            return Http::response($session);
        }

        $calls = $request->data()['methodCalls'] ?? [];
        $method = $calls[0][0] ?? null;

        $createId = array_key_first($calls[0][1]['create'] ?? []) ?: 'contract-create';

        return match ($method) {
            'Mailbox/get' => Http::response([
                'methodResponses' => [['Mailbox/get', $mailboxList, 'mailboxes']],
            ]),
            'Email/query' => Http::response([
                'methodResponses' => [[
                    'Email/query',
                    [
                        'accountId' => 'contract-account',
                        'ids' => ['contract-message'],
                        'queryState' => 'contract-query-state',
                        'canCalculateChanges' => true,
                        'total' => 1,
                    ],
                    'query',
                ]],
            ]),
            'Email/queryChanges' => Http::response([
                'methodResponses' => [[
                    'Email/queryChanges',
                    [
                        'oldQueryState' => 'contract-query-state',
                        'newQueryState' => 'contract-query-state',
                        'added' => [],
                        'removed' => [],
                    ],
                    'queryChanges',
                ]],
            ]),
            'Email/get' => Http::response([
                'methodResponses' => [[
                    'Email/get',
                    [
                        'accountId' => 'contract-account',
                        'state' => 'contract-email-state',
                        'list' => [[
                            'id' => 'contract-message',
                            'threadId' => 'contract-thread',
                            'mailboxIds' => ['contract-inbox' => true],
                            'receivedAt' => '2026-09-01T12:00:00Z',
                            'messageId' => ['<contract-message@managed.example.com>'],
                            'from' => [['email' => 'reply@example.net', 'name' => 'Reply']],
                            'to' => [['email' => 'contract@managed.example.com']],
                            'subject' => 'Contract message',
                            'textBody' => [['partId' => 'text', 'type' => 'text/plain']],
                            'bodyValues' => ['text' => ['value' => 'Contract body']],
                        ]],
                    ],
                    'get',
                ]],
            ]),
            'Identity/get' => Http::response([
                'methodResponses' => [['Identity/get', [
                    'list' => [['id' => 'contract-identity', 'email' => 'contract@managed.example.com']],
                ], 'identities']],
            ]),
            'Email/set' => Http::response([
                'methodResponses' => [
                    ['Email/set', [
                        'created' => [$createId => [
                            'id' => 'contract-sent-message',
                            'threadId' => 'contract-sent-thread',
                        ]],
                    ], 'email'],
                    ['EmailSubmission/set', [
                        'created' => [$createId => ['id' => 'contract-submission']],
                    ], 'submission'],
                ],
            ]),
            default => Http::response([], 500),
        };
    });

    $provider = new StalwartProvider;
    $remoteMailboxes = $provider->mailboxes($connection);
    $page = $provider->syncFolder($mailbox, $state, 20);
    $sent = $provider->sendNew($mailbox, new OutgoingMessage(
        subject: 'Contract send',
        bodyText: 'Contract body',
        bodyHtml: '<p>Contract body</p>',
        to: [['email' => 'prospect@example.net']],
        fromEmail: $mailbox->email_address,
        clientMessageMarker: 'contract-marker',
    ));

    expect($remoteMailboxes)->toHaveCount(1)
        ->and($remoteMailboxes[0]->emailAddress)->toBe('contract@managed.example.com')
        ->and($page->messages)->toHaveCount(1)
        ->and($page->messages[0]->remoteMessageId)->toBe('contract-message')
        ->and($sent->remoteMessageId)->toBe('contract-sent-message')
        ->and($sent->providerDraftId)->toBe('contract-submission');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader(
        'Authorization',
        'Basic '.base64_encode('contract@managed.example.com:contract-runtime-secret'),
    ));
});
