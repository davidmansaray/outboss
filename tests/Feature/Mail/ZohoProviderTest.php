<?php

use App\Domain\Mail\DTOs\OAuthCallback;
use App\Domain\Mail\DTOs\OutgoingMessage;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\SyncScope;
use App\Domain\Mail\Providers\ZohoProvider;
use App\Domain\Mail\Services\SyncMailboxService;
use App\Models\Mailbox;
use App\Models\MailboxSyncState;
use App\Models\OAuthAttempt;
use App\Models\ProviderConnection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.zoho.client_id', 'zoho-client');
    config()->set('services.zoho.client_secret', 'zoho-secret');
    config()->set('services.zoho.redirect', 'https://app.test/oauth/zoho/callback');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function zohoTokenResponse(array $overrides = []): array
{
    return array_merge([
        'access_token' => 'access-eu',
        'refresh_token' => 'refresh-eu',
        'expires_in' => 3600,
        'scope' => 'ZohoMail.accounts.READ,ZohoMail.messages.READ',
        'api_domain' => 'https://www.zohoapis.eu',
    ], $overrides);
}

/**
 * @return array<string, mixed>
 */
function zohoAccountsResponse(): array
{
    return [
        'data' => [
            [
                'type' => 'IMAP_ACCOUNT',
                'accountId' => 'imap-1',
                'primaryEmailAddress' => 'imap@zylker.com',
            ],
            [
                'type' => 'ZOHO_ACCOUNT',
                'accountId' => 'acc-1',
                'primaryEmailAddress' => 'david@zylker.eu',
                'displayName' => 'David',
                'sendMailDetails' => [
                    [
                        'sendMailId' => 'send-primary',
                        'displayName' => "David\nInjected",
                        'fromAddress' => 'david@zylker.eu',
                        'mode' => 'primary',
                        'status' => true,
                        'validated' => false,
                        'validationRequired' => true,
                        'verifyCode' => 'never-store-this',
                        'smtpUserName' => 'secret-user',
                    ],
                    [
                        'sendMailId' => 'send-partnerships',
                        'displayName' => 'Partnerships',
                        'fromAddress' => 'partnerships@zylker.eu',
                        'mode' => 'alias',
                        'status' => 'true',
                        'validated' => false,
                    ],
                    [
                        'sendMailId' => 'send-inactive',
                        'displayName' => 'Inactive',
                        'fromAddress' => 'inactive@zylker.eu',
                        'mode' => 'external',
                        'status' => false,
                    ],
                ],
            ],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function zohoFoldersResponse(): array
{
    return [
        'data' => [
            ['folderType' => 'Inbox', 'path' => '/Inbox', 'folderId' => '9001', 'folderName' => 'Inbox'],
            ['folderType' => 'Inbox', 'path' => '/test', 'folderId' => '9999', 'folderName' => 'test'],
            ['folderType' => 'Sent', 'path' => '/Sent', 'folderId' => '9002', 'folderName' => 'Sent'],
        ],
    ];
}

function fakeZohoDatacenter(string $accountsHost, string $mailHost): void
{
    Http::fake([
        $accountsHost.'/oauth/v2/token' => Http::response(zohoTokenResponse()),
        $mailHost.'/api/accounts' => Http::response(zohoAccountsResponse()),
        $mailHost.'/api/accounts/acc-1/folders' => Http::response(zohoFoldersResponse()),
    ]);
}

/**
 * @param  array<string, mixed>  $scanState
 * @return array{Mailbox, MailboxSyncState}
 */
function zohoProviderTestMailboxAndState(array $scanState = [], string $mailHost = 'https://mail.zoho.eu'): array
{
    $connection = ProviderConnection::factory()->create([
        'provider' => MailProviderName::Zoho,
        'access_token' => 'live-access',
        'refresh_token' => 'live-refresh',
        'provider_metadata' => [
            'location' => 'eu',
            'accounts_server' => 'https://accounts.zoho.eu',
            'api_domain' => $mailHost,
        ],
    ]);
    $mailbox = Mailbox::factory()->create([
        'user_id' => $connection->user_id,
        'provider_connection_id' => $connection->id,
        'provider_mailbox_id' => 'acc-1',
        'email_address' => 'david@zylker.eu',
        'provider_metadata' => ['inbox_id' => '9001', 'sent_id' => '9002'],
    ]);
    $state = MailboxSyncState::factory()->create([
        'mailbox_id' => $mailbox->id,
        'scope' => SyncScope::Inbox,
        'remote_folder_id' => '9001',
        'scan_state' => $scanState,
    ]);

    return [$mailbox, $state];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function zohoProviderTestRow(string $messageId, int $daysAgo = 1, array $overrides = []): array
{
    return array_merge([
        'messageId' => $messageId,
        'threadId' => 'thread-'.$messageId,
        'subject' => 'Subject '.$messageId,
        'summary' => 'Summary '.$messageId,
        'fromAddress' => 'maya@stripe.com',
        'sender' => 'Maya',
        'toAddress' => '&quot;David&quot;&lt;david@zylker.eu&gt;',
        'ccAddress' => 'Not Provided',
        'receivedTime' => now()->subDays($daysAgo)->getTimestampMs(),
        'hasAttachment' => '0',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $headers
 * @return array<string, mixed>
 */
function zohoProviderTestHeaders(array $headers = []): array
{
    return [
        'data' => [
            'headerContent' => array_merge([
                'Message-Id' => ['<MESSAGE@example.test>'],
            ], $headers),
        ],
    ];
}

it('builds an authorization URL with consent, offline access, and mail scopes', function () {
    $attempt = new OAuthAttempt;
    $attempt->setAttribute('raw_state', 'state-token');

    $url = (new ZohoProvider)->authorizationUrl($attempt);

    expect($url)->toStartWith('https://accounts.zoho.com/oauth/v2/auth?')
        ->and($url)->toContain('prompt=consent')
        ->and($url)->toContain('access_type=offline')
        ->and($url)->toContain(rawurlencode('ZohoMail.accounts.READ'))
        ->and($url)->toContain(rawurlencode('ZohoMail.messages.READ'))
        ->and($url)->toContain(rawurlencode('ZohoMail.messages.CREATE'))
        ->and($url)->toContain(rawurlencode('ZohoMail.folders.READ'))
        ->and($url)->toContain(rawurlencode('https://app.test/oauth/zoho/callback'));
});

it('exchanges against the callback accounts-server and derives the mail host', function () {
    Http::preventStrayRequests();
    fakeZohoDatacenter('https://accounts.zoho.eu', 'https://mail.zoho.eu');

    $account = (new ZohoProvider)->exchangeAuthorizationCode(new OAuthCallback(
        code: 'auth-code',
        state: 'state',
        extras: [
            'location' => 'eu',
            'accounts_server' => 'https://accounts.zoho.eu',
        ],
    ));

    expect($account->providerUserId)->toBe('acc-1')
        ->and($account->mailboxes[0]->emailAddress)->toBe('david@zylker.eu')
        ->and($account->metadata['accounts_server'])->toBe('https://accounts.zoho.eu')
        ->and($account->metadata['api_domain'])->toBe('https://mail.zoho.eu')
        ->and($account->metadata['location'])->toBe('eu');

    Http::assertSent(fn ($request) => $request->url() === 'https://accounts.zoho.eu/oauth/v2/token'
        && $request['code'] === 'auth-code');
});

it('rejects a non-allowlisted accounts-server', function () {
    expect(fn () => (new ZohoProvider)->exchangeAuthorizationCode(new OAuthCallback(
        code: 'auth-code',
        state: 'state',
        extras: ['accounts_server' => 'https://evil.example'],
    )))->toThrow(RuntimeException::class, 'zoho_untrusted_accounts_host');
});

it('resolves the India accounts host from location when accounts-server is absent', function () {
    Http::preventStrayRequests();
    fakeZohoDatacenter('https://accounts.zoho.in', 'https://mail.zoho.in');

    $account = (new ZohoProvider)->exchangeAuthorizationCode(new OAuthCallback(
        code: 'auth-code',
        state: 'state',
        extras: ['location' => 'in'],
    ));

    expect($account->metadata['accounts_server'])->toBe('https://accounts.zoho.in')
        ->and($account->metadata['api_domain'])->toBe('https://mail.zoho.in')
        ->and($account->metadata['location'])->toBe('in');

    Http::assertSent(fn ($request) => $request->url() === 'https://accounts.zoho.in/oauth/v2/token');
});

it('resolves the China accounts host from location when accounts-server is absent', function () {
    Http::preventStrayRequests();
    fakeZohoDatacenter('https://accounts.zoho.com.cn', 'https://mail.zoho.com.cn');

    $account = (new ZohoProvider)->exchangeAuthorizationCode(new OAuthCallback(
        code: 'auth-code',
        state: 'state',
        extras: ['location' => 'cn'],
    ));

    expect($account->metadata['accounts_server'])->toBe('https://accounts.zoho.com.cn')
        ->and($account->metadata['api_domain'])->toBe('https://mail.zoho.com.cn')
        ->and($account->metadata['location'])->toBe('cn');

    Http::assertSent(fn ($request) => $request->url() === 'https://accounts.zoho.com.cn/oauth/v2/token');
});

it('selects the ZOHO_ACCOUNT when an IMAP account is listed first', function () {
    Http::preventStrayRequests();
    fakeZohoDatacenter('https://accounts.zoho.com', 'https://mail.zoho.com');

    $account = (new ZohoProvider)->exchangeAuthorizationCode(new OAuthCallback(
        code: 'auth-code',
        state: 'state',
    ));

    expect($account->providerUserId)->toBe('acc-1')
        ->and($account->mailboxes[0]->emailAddress)->toBe('david@zylker.eu');
});

it('maps numeric inbox and sent folder IDs into mailbox metadata', function () {
    Http::preventStrayRequests();
    fakeZohoDatacenter('https://accounts.zoho.com', 'https://mail.zoho.com');

    $account = (new ZohoProvider)->exchangeAuthorizationCode(new OAuthCallback(
        code: 'auth-code',
        state: 'state',
    ));

    expect($account->mailboxes[0]->metadata['inbox_id'])->toBe('9001')
        ->and($account->mailboxes[0]->metadata['sent_id'])->toBe('9002');
});

it('maps only sanitized Zoho sender identity fields and ignores provider secrets', function () {
    Http::preventStrayRequests();
    fakeZohoDatacenter('https://accounts.zoho.com', 'https://mail.zoho.com');

    $account = (new ZohoProvider)->exchangeAuthorizationCode(new OAuthCallback(
        code: 'auth-code',
        state: 'state',
    ));

    $identities = $account->mailboxes[0]->senderIdentities;

    expect($identities)->toHaveCount(3)
        ->and($identities[0]->emailAddress)->toBe('david@zylker.eu')
        ->and($identities[0]->displayName)->toBe('DavidInjected')
        ->and($identities[0]->active)->toBeTrue()
        ->and($identities[1]->emailAddress)->toBe('partnerships@zylker.eu')
        ->and($identities[1]->active)->toBeTrue()
        ->and($identities[2]->active)->toBeFalse()
        ->and(json_encode($identities))->not->toContain('never-store-this')
        ->and(json_encode($identities))->not->toContain('secret-user');
});

it('splits granted scopes on commas or spaces', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://accounts.zoho.com/oauth/v2/token' => Http::response(zohoTokenResponse([
            'scope' => 'ZohoMail.accounts.READ, ZohoMail.messages.READ ZohoMail.messages.CREATE',
        ])),
        'https://mail.zoho.com/api/accounts' => Http::response(zohoAccountsResponse()),
        'https://mail.zoho.com/api/accounts/acc-1/folders' => Http::response(zohoFoldersResponse()),
    ]);

    $account = (new ZohoProvider)->exchangeAuthorizationCode(new OAuthCallback(
        code: 'auth-code',
        state: 'state',
    ));

    expect($account->grantedScopes)->toBe([
        'ZohoMail.accounts.READ',
        'ZohoMail.messages.READ',
        'ZohoMail.messages.CREATE',
    ]);
});

it('sends html mail with joined recipients and the regional Zoho-oauthtoken header', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://mail.zoho.eu/api/accounts/acc-1/messages' => Http::response([
            'status' => ['code' => 200, 'description' => 'success'],
            'data' => ['messageId' => 'sent-1', 'threadId' => 'thread-1'],
        ]),
    ]);

    $connection = ProviderConnection::factory()->create([
        'provider' => MailProviderName::Zoho,
        'access_token' => 'live-access',
        'refresh_token' => 'live-refresh',
        'provider_metadata' => [
            'location' => 'eu',
            'accounts_server' => 'https://accounts.zoho.eu',
            'api_domain' => 'https://mail.zoho.eu',
        ],
    ]);
    $mailbox = Mailbox::factory()->create([
        'user_id' => $connection->user_id,
        'provider_connection_id' => $connection->id,
        'provider_mailbox_id' => 'acc-1',
        'email_address' => 'david@zylker.eu',
    ]);

    $result = (new ZohoProvider)->sendNew($mailbox, new OutgoingMessage(
        subject: 'Hello',
        bodyText: 'Body',
        bodyHtml: '<p>Body</p>',
        to: [['email' => 'maya@stripe.com'], ['email' => 'ops@stripe.com']],
        fromEmail: 'david@zylker.eu',
    ));

    expect($result->remoteMessageId)->toBe('sent-1');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://mail.zoho.eu/api/accounts/acc-1/messages'
            && $request->hasHeader('Authorization', 'Zoho-oauthtoken live-access')
            && $request['mailFormat'] === 'html'
            && $request['toAddress'] === 'maya@stripe.com,ops@stripe.com'
            && $request['content'] === '<p>Body</p>';
    });
});

it('includes Zoho error description and moreInfo when send fails', function () {
    Http::fake([
        'https://mail.zoho.com/api/accounts/*/messages' => Http::response([
            'status' => ['code' => 500, 'description' => 'Internal Error'],
            'data' => ['moreInfo' => 'fromAddress is not confirmed'],
        ], 500),
    ]);

    $mailbox = Mailbox::factory()->create();

    expect(fn () => (new ZohoProvider)->sendNew($mailbox, new OutgoingMessage(
        subject: 'Hello',
        bodyText: 'Body',
        bodyHtml: '<p>Body</p>',
        to: [['email' => 'maya@stripe.com']],
        fromEmail: $mailbox->email_address,
    )))->toThrow(RuntimeException::class, 'zoho_send_failed: Internal Error fromAddress is not confirmed');
});

it('refreshes against the stored accounts host and keeps the existing refresh token', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://accounts.zoho.eu/oauth/v2/token' => Http::response([
            'access_token' => 'new-access',
            'expires_in' => 3600,
        ]),
    ]);

    $connection = ProviderConnection::factory()->create([
        'provider' => MailProviderName::Zoho,
        'access_token' => 'old-access',
        'refresh_token' => 'keep-me',
        'provider_metadata' => [
            'accounts_server' => 'https://accounts.zoho.eu',
            'api_domain' => 'https://mail.zoho.eu',
        ],
    ]);

    $tokens = (new ZohoProvider)->refreshConnection($connection);

    expect($tokens->accessToken)->toBe('new-access')
        ->and($tokens->refreshToken)->toBe('keep-me');

    Http::assertSent(fn ($request) => $request->url() === 'https://accounts.zoho.eu/oauth/v2/token'
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'keep-me');
});

it('returns an empty page without failing when the folder has no messages', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://mail.zoho.eu/api/accounts/acc-1/messages/view*' => Http::response(['data' => []]),
    ]);
    [$mailbox, $state] = zohoProviderTestMailboxAndState();

    $page = (new ZohoProvider)->syncFolder($mailbox, $state);

    expect($page->messages)->toBe([])
        ->and($page->hasMore)->toBeFalse()
        ->and($page->highWaterTimestamp)->toBeNull()
        ->and($page->nextState['phase'])->toBe('incremental')
        ->and($page->nextState['start'])->toBe(1)
        ->and($page->nextState)->not->toHaveKey('high_water_remote_ids');
});

it('maps list rows and fetches html content and reply headers', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://mail.zoho.eu/api/accounts/acc-1/messages/view*' => Http::response([
            'data' => [zohoProviderTestRow('message-1', overrides: [
                'threadId' => 'thread-42',
                'fromAddress' => '&quot;Maya Chen&quot;&lt;maya@stripe.com&gt;',
                'toAddress' => '&quot;David&quot;&lt;david@zylker.eu&gt;, ops@zylker.eu',
                'hasAttachment' => '1',
            ])],
        ]),
        'https://mail.zoho.eu/api/accounts/acc-1/folders/9001/messages/message-1/content*' => Http::response([
            'data' => ['content' => '<div><strong>Full reply body</strong></div>'],
        ]),
        'https://mail.zoho.eu/api/accounts/acc-1/folders/9001/messages/message-1/header*' => Http::response(zohoProviderTestHeaders([
            'Message-Id' => ['<ABC@Example.COM>'],
            'In-Reply-To' => ['<Parent@Example.COM>'],
            'References' => ['<Root@Example.COM> <Parent@Example.COM>'],
            'Auto-Submitted' => ['no'],
        ])),
    ]);
    [$mailbox, $state] = zohoProviderTestMailboxAndState();

    $page = (new ZohoProvider)->syncFolder($mailbox, $state);
    $message = $page->messages[0];

    expect($message->remoteMessageId)->toBe('message-1')
        ->and($message->remoteThreadId)->toBe('thread-42')
        ->and($message->bodyHtml)->toBe('<div><strong>Full reply body</strong></div>')
        ->and($message->bodyText)->toBe('Full reply body')
        ->and($message->fromEmail)->toBe('maya@stripe.com')
        ->and($message->fromName)->toBe('Maya Chen')
        ->and($message->to)->toBe([
            ['email' => 'david@zylker.eu', 'name' => 'David'],
            ['email' => 'ops@zylker.eu'],
        ])
        ->and($message->internetMessageId)->toBe('abc@example.com')
        ->and($message->inReplyTo)->toBe('parent@example.com')
        ->and($message->references)->toBe(['root@example.com', 'parent@example.com'])
        ->and($message->hasAttachments)->toBeTrue()
        ->and($message->headers['auto-submitted'])->toBe('no');
});

it('falls back to list summary when content returns 404', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://mail.zoho.eu/api/accounts/acc-1/messages/view*' => Http::response([
            'data' => [zohoProviderTestRow('missing-content', overrides: ['summary' => 'List summary survives'])],
        ]),
        'https://mail.zoho.eu/api/accounts/acc-1/folders/9001/messages/missing-content/content*' => Http::response([], 404),
        'https://mail.zoho.eu/api/accounts/acc-1/folders/9001/messages/missing-content/header*' => Http::response(zohoProviderTestHeaders([
            'Message-Id' => ['<fallback@example.test>'],
        ])),
    ]);
    [$mailbox, $state] = zohoProviderTestMailboxAndState();

    $message = (new ZohoProvider)->syncFolder($mailbox, $state)->messages[0];

    expect($message->bodyHtml)->toBeNull()
        ->and($message->bodyText)->toBe('List summary survives')
        ->and($message->internetMessageId)->toBe('fallback@example.test');
});

it('retries a rate-limited content request once', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://mail.zoho.eu/api/accounts/acc-1/messages/view*' => Http::response([
            'data' => [zohoProviderTestRow('rate-limited')],
        ]),
        'https://mail.zoho.eu/api/accounts/acc-1/folders/9001/messages/rate-limited/content*' => Http::sequence()
            ->push(['status' => ['code' => 429]], 429)
            ->push(['data' => ['content' => '<p>Recovered body</p>']]),
        'https://mail.zoho.eu/api/accounts/acc-1/folders/9001/messages/rate-limited/header*' => Http::response(zohoProviderTestHeaders()),
    ]);
    [$mailbox, $state] = zohoProviderTestMailboxAndState();

    $message = (new ZohoProvider)->syncFolder($mailbox, $state)->messages[0];

    expect($message->bodyText)->toBe('Recovered body');

    Http::assertSentCount(4);
});

it('stops backfill when a page contains mail older than the lookback window', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://mail.zoho.eu/api/accounts/acc-1/messages/view*' => Http::response([
            'data' => [
                zohoProviderTestRow('recent', 1),
                zohoProviderTestRow('old', 40),
            ],
        ]),
        'https://mail.zoho.eu/api/accounts/acc-1/folders/9001/messages/recent/content*' => Http::response([
            'data' => ['content' => '<p>Recent</p>'],
        ]),
        'https://mail.zoho.eu/api/accounts/acc-1/folders/9001/messages/recent/header*' => Http::response(zohoProviderTestHeaders()),
    ]);
    [$mailbox, $state] = zohoProviderTestMailboxAndState();

    $page = (new ZohoProvider)->syncFolder($mailbox, $state, 2);

    expect(collect($page->messages)->pluck('remoteMessageId')->all())->toBe(['recent'])
        ->and($page->hasMore)->toBeFalse()
        ->and($page->nextState['phase'])->toBe('incremental')
        ->and($page->nextState['start'])->toBe(1);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/messages/old/'));
});

it('continues backfill when the page is full and entirely inside the lookback window', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://mail.zoho.eu/api/accounts/acc-1/messages/view*' => Http::response([
            'data' => [
                zohoProviderTestRow('recent-1', 1),
                zohoProviderTestRow('recent-2', 2),
            ],
        ]),
        'https://mail.zoho.eu/api/accounts/acc-1/folders/9001/messages/*/content*' => Http::response([
            'data' => ['content' => '<p>Recent</p>'],
        ]),
        'https://mail.zoho.eu/api/accounts/acc-1/folders/9001/messages/*/header*' => Http::response(zohoProviderTestHeaders()),
    ]);
    [$mailbox, $state] = zohoProviderTestMailboxAndState();

    $page = (new ZohoProvider)->syncFolder($mailbox, $state, 2);

    expect($page->messages)->toHaveCount(2)
        ->and($page->hasMore)->toBeTrue()
        ->and($page->nextState['phase'])->toBe('backfill')
        ->and($page->nextState['start'])->toBe(3)
        ->and($page->nextState['lookback_cutoff_ms'])->toBeInt();
});

it('polls newest pages during incremental sync instead of advancing start forever', function () {
    config()->set('outboss.sync.zoho.overlap_pages', 2);
    Http::preventStrayRequests();
    $requestedStarts = [];

    Http::fake(function (Request $request) use (&$requestedStarts) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if (str_ends_with($path, '/messages/view')) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $start = (int) ($query['start'] ?? 0);
            $requestedStarts[] = $start;

            return Http::response(['data' => match ($start) {
                1 => [zohoProviderTestRow('newest-1'), zohoProviderTestRow('newest-2')],
                3 => [zohoProviderTestRow('overlap-1', 2), zohoProviderTestRow('old-overlap', 40)],
                default => [],
            }]);
        }

        if (str_ends_with($path, '/content')) {
            return Http::response(['data' => ['content' => '<p>Full body</p>']]);
        }

        if (str_ends_with($path, '/header')) {
            return Http::response(zohoProviderTestHeaders());
        }

        return Http::response([], 500);
    });
    [$mailbox, $state] = zohoProviderTestMailboxAndState([
        'phase' => 'incremental',
        'start' => 500,
    ]);

    $page = (new ZohoProvider)->syncFolder($mailbox, $state, 2);

    expect($requestedStarts)->toBe([1, 3])
        ->and(collect($page->messages)->pluck('remoteMessageId')->all())->toBe(['newest-1', 'newest-2', 'overlap-1'])
        ->and($page->hasMore)->toBeFalse()
        ->and($page->nextState['phase'])->toBe('incremental')
        ->and($page->nextState['start'])->toBe(1)
        ->and($page->nextState['lookback_cutoff_ms'])->toBeInt();

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/messages/old-overlap/'));
});

it('authorizes list and content with Zoho-oauthtoken on the regional mail host', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://mail.zoho.eu/api/accounts/acc-1/messages/view*' => Http::response([
            'data' => [zohoProviderTestRow('regional')],
        ]),
        'https://mail.zoho.eu/api/accounts/acc-1/folders/9001/messages/regional/content*' => Http::response([
            'data' => ['content' => '<p>Regional body</p>'],
        ]),
        'https://mail.zoho.eu/api/accounts/acc-1/folders/9001/messages/regional/header*' => Http::response(zohoProviderTestHeaders()),
    ]);
    [$mailbox, $state] = zohoProviderTestMailboxAndState();

    (new ZohoProvider)->syncFolder($mailbox, $state);

    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), 'https://mail.zoho.eu/api/accounts/acc-1/messages/view')
            && $request->hasHeader('Authorization', 'Zoho-oauthtoken live-access')
            && $query === [
                'folderId' => '9001',
                'start' => '1',
                'limit' => '100',
                'sortBy' => 'date',
                'sortorder' => 'false',
                'includeto' => 'true',
            ];
    });
    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), '/folders/9001/messages/regional/content')
            && $request->hasHeader('Authorization', 'Zoho-oauthtoken live-access');
    });
});

it('seeds inbox and sent sync states from discovered folder IDs', function () {
    $connection = ProviderConnection::factory()->create([
        'provider' => MailProviderName::Zoho,
        'provider_metadata' => [
            'accounts_server' => 'https://accounts.zoho.com',
            'api_domain' => 'https://mail.zoho.com',
        ],
    ]);
    $mailbox = Mailbox::factory()->create([
        'user_id' => $connection->user_id,
        'provider_connection_id' => $connection->id,
        'provider_mailbox_id' => 'acc-1',
        'provider_metadata' => ['inbox_id' => '9001', 'sent_id' => '9002'],
    ]);

    app(SyncMailboxService::class)->handle($mailbox->id);

    expect($mailbox->syncStates()->where('scope', SyncScope::Inbox)->value('remote_folder_id'))->toBe('9001')
        ->and($mailbox->syncStates()->where('scope', SyncScope::Sent)->value('remote_folder_id'))->toBe('9002');
});
