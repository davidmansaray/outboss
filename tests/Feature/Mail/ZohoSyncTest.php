<?php

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\MessageStatus;
use App\Domain\Mail\Enums\SyncScope;
use App\Domain\Mail\Enums\SyncStatus;
use App\Domain\Mail\Enums\ThreadClassification;
use App\Domain\Mail\Enums\ThreadLocalState;
use App\Domain\Mail\Providers\ProviderRegistry;
use App\Domain\Mail\Providers\ZohoProvider;
use App\Domain\Mail\Services\SyncMailboxService;
use App\Jobs\Mail\SyncMailbox;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\MailboxSyncState;
use App\Models\ManagedMailboxAccount;
use App\Models\ManagedMailDomain;
use App\Models\Message;
use App\Models\ProviderConnection;
use App\Models\Thread;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('outboss.sync.pages_per_job', 5);
    config()->set('outboss.sync.zoho.lookback_days', 30);
    config()->set('outboss.sync.zoho.page_size', 100);
    config()->set('outboss.sync.zoho.overlap_pages', 2);

    app(ProviderRegistry::class)->register(MailProviderName::Zoho, new ZohoProvider);
});

function zohoSyncTestMailbox(array $mailboxOverrides = [], array $connectionOverrides = []): Mailbox
{
    $connection = ProviderConnection::factory()->create(array_merge([
        'provider' => MailProviderName::Zoho,
        'provider_user_id' => 'acc-1',
        'access_token' => 'access-eu',
        'refresh_token' => 'refresh-eu',
        'token_expires_at' => now()->addHour(),
        'provider_metadata' => [
            'accounts_server' => 'https://accounts.zoho.eu',
            'api_domain' => 'https://mail.zoho.eu',
        ],
        'status' => ConnectionStatus::Connected,
    ], $connectionOverrides));

    return Mailbox::factory()->create(array_merge([
        'user_id' => $connection->user_id,
        'provider_connection_id' => $connection->id,
        'provider_mailbox_id' => 'acc-1',
        'email_address' => 'david@outboss.test',
        'provider_metadata' => ['inbox_id' => '9001', 'sent_id' => '9002'],
        'status' => ConnectionStatus::Connected,
    ], $mailboxOverrides));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function zohoSyncTestRow(string $messageId, string $threadId, int $daysAgo = 1, array $overrides = []): array
{
    return array_merge([
        'messageId' => $messageId,
        'threadId' => $threadId,
        'subject' => 'Subject '.$messageId,
        'summary' => 'Summary '.$messageId,
        'fromAddress' => 'sender-'.$messageId.'@example.test',
        'toAddress' => 'david@outboss.test',
        'receivedTime' => now()->subDays($daysAgo)->getTimestampMs(),
    ], $overrides);
}

/**
 * @param  callable(string, int, Request): array<string, mixed>  $viewResolver
 * @param  array<string, string>  $contents
 * @param  array<string, array<string, mixed>>  $headers
 */
function zohoSyncTestFakeHttp(callable $viewResolver, array $contents = [], array $headers = []): void
{
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($viewResolver, $contents, $headers) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if (str_ends_with($path, '/messages/view')) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $result = $viewResolver(
                (string) ($query['folderId'] ?? ''),
                (int) ($query['start'] ?? 0),
                $request,
            );
            $status = (int) ($result['__status'] ?? 200);
            unset($result['__status']);

            return Http::response($result, $status);
        }

        if (preg_match('#/messages/([^/]+)/content$#', $path, $matches) === 1) {
            $messageId = rawurldecode($matches[1]);

            return Http::response([
                'data' => [
                    'content' => $contents[$messageId]
                        ?? '<p>Full '.$messageId.'</p><script>alert(1)</script><img src="https://tracker.test/pixel">',
                ],
            ]);
        }

        if (preg_match('#/messages/([^/]+)/header$#', $path, $matches) === 1) {
            $messageId = rawurldecode($matches[1]);

            return Http::response([
                'data' => [
                    'headerContent' => $headers[$messageId] ?? [
                        'Message-Id' => ['<'.$messageId.'@example.test>'],
                        'In-Reply-To' => ['<parent@example.test>'],
                        'References' => ['<root@example.test> <parent@example.test>'],
                    ],
                ],
            ]);
        }

        return Http::response(['message' => 'Unexpected Zoho test request: '.$request->url()], 500);
    });
}

it('upserts zoho inbox and sent messages into unibox threads', function () {
    $mailbox = zohoSyncTestMailbox();

    zohoSyncTestFakeHttp(fn (string $folderId): array => [
        'data' => match ($folderId) {
            '9001' => [zohoSyncTestRow('in-1', 'thread-in', overrides: ['subject' => 'A reply from Maya'])],
            '9002' => [zohoSyncTestRow('sent-1', 'thread-sent', 2, [
                'subject' => 'Our introduction',
                'fromAddress' => 'david@outboss.test',
                'toAddress' => 'maya@example.test',
            ])],
            default => [],
        },
    ]);

    app(SyncMailboxService::class)->handle($mailbox->id);

    $inbound = Message::query()->where('remote_message_id', 'in-1')->firstOrFail();
    $sent = Message::query()->where('remote_message_id', 'sent-1')->firstOrFail();

    expect(Message::query()->where('mailbox_id', $mailbox->id)->count())->toBe(2)
        ->and($inbound->body_text)->toContain('Full in-1')
        ->and($inbound->body_html_sanitized)->toContain('<p>Full in-1</p>')
        ->and($inbound->body_html_sanitized)->not->toContain('<script')
        ->and($inbound->body_html_sanitized)->toContain('<img src="https://tracker.test/pixel"')
        ->and($inbound->thread->remote_thread_id)->toBe('thread-in')
        ->and($inbound->internet_message_id)->toBe('in-1@example.test')
        ->and($sent->thread->remote_thread_id)->toBe('thread-sent')
        ->and($sent->status)->toBe(MessageStatus::Sent);

    $this->actingAs($mailbox->user)
        ->get(route('unibox.index', ['folder' => 'all_open']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('threads.data', 1)
            ->where('threads.data.0.subject', 'A reply from Maya')
            ->where('counts.all_open', 1)
            ->where('counts.sent', 1));

    $this->actingAs($mailbox->user)
        ->get(route('unibox.index', ['folder' => 'sent']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('threads.data', 1)
            ->where('threads.data.0.subject', 'Our introduction')
            ->where('threads.data.0.from', 'maya@example.test')
            ->where('counts.sent', 1));
});

it('does not treat an own-sender message returned by the zoho inbox as a reply', function () {
    $mailbox = zohoSyncTestMailbox();
    $campaign = Campaign::factory()->create(['user_id' => $mailbox->user_id]);
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'email_address' => 'prospect@example.test',
        'email_normalized' => 'prospect@example.test',
        'status' => RecipientStatus::Active,
    ]);
    $thread = Thread::factory()->create([
        'user_id' => $mailbox->user_id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
        'remote_thread_id' => 'campaign-thread',
    ]);
    Message::factory()->outbound()->create([
        'thread_id' => $thread->id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
        'remote_message_id' => 'parent',
        'internet_message_id' => 'parent@example.test',
        'status' => MessageStatus::Sent,
        'sent_at' => now()->subMinute(),
    ]);

    zohoSyncTestFakeHttp(
        fn (string $folderId): array => [
            'data' => $folderId === '9001'
                ? [zohoSyncTestRow('own-reply', 'campaign-thread', overrides: [
                    'subject' => 'Re: introduction',
                    'fromAddress' => $mailbox->email_address,
                    'toAddress' => 'prospect@example.test',
                ])]
                : [],
        ],
        headers: [
            'own-reply' => [
                'Message-Id' => ['<own-reply@example.test>'],
                'In-Reply-To' => ['<parent@example.test>'],
            ],
        ],
    );

    app(SyncMailboxService::class)->handle($mailbox->id);

    $message = Message::query()->where('remote_message_id', 'own-reply')->firstOrFail();

    expect($message->direction)->toBe(MessageDirection::Outbound)
        ->and($message->status)->toBe(MessageStatus::Sent)
        ->and($message->classification)->toBe(ThreadClassification::Unknown)
        ->and($recipient->fresh()->status)->toBe(RecipientStatus::Active)
        ->and($recipient->fresh()->stop_reason)->toBeNull();
});

it('is idempotent when the same zoho message is synced twice', function () {
    $mailbox = zohoSyncTestMailbox();
    zohoSyncTestFakeHttp(fn (string $folderId): array => [
        'data' => $folderId === '9001' ? [zohoSyncTestRow('same-1', 'same-thread')] : [],
    ]);

    app(SyncMailboxService::class)->handle($mailbox->id);
    app(SyncMailboxService::class)->handle($mailbox->id);

    expect(Message::query()
        ->where('mailbox_id', $mailbox->id)
        ->where('remote_message_id', 'same-1')
        ->count())->toBe(1)
        ->and(Thread::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('remote_thread_id', 'same-thread')
            ->count())->toBe(1);
});

it('continues backfill across jobs using the persisted scan start', function () {
    config()->set('outboss.sync.pages_per_job', 1);
    config()->set('outboss.sync.zoho.page_size', 1);
    $mailbox = zohoSyncTestMailbox();
    $inboxStarts = [];

    zohoSyncTestFakeHttp(function (string $folderId, int $start) use (&$inboxStarts): array {
        if ($folderId !== '9001') {
            return ['data' => []];
        }

        $inboxStarts[] = $start;

        return ['data' => [zohoSyncTestRow('page-'.$start, 'cross-page-thread', $start)]];
    });

    app(SyncMailboxService::class)->handle($mailbox->id);

    $stateAfterFirstJob = $mailbox->syncStates()->where('scope', SyncScope::Inbox)->firstOrFail();
    expect($stateAfterFirstJob->scan_state['phase'])->toBe('backfill')
        ->and($stateAfterFirstJob->scan_state['start'])->toBe(2)
        ->and($mailbox->fresh()->last_synced_at)->not->toBeNull();

    app(SyncMailboxService::class)->handle($mailbox->id);

    expect($inboxStarts)->toBe([1, 2])
        ->and(Message::query()->where('mailbox_id', $mailbox->id)->count())->toBe(2)
        ->and($mailbox->syncStates()->where('scope', SyncScope::Inbox)->value('scan_state')['start'])->toBe(3)
        ->and(Thread::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('remote_thread_id', 'cross-page-thread')
            ->firstOrFail()
            ->messages
            ->pluck('remote_message_id')
            ->all())->toBe(['page-2', 'page-1']);
});

it('does not clear high water when a later poll returns no rows', function () {
    $mailbox = zohoSyncTestMailbox();
    $inboxCalls = 0;

    zohoSyncTestFakeHttp(function (string $folderId) use (&$inboxCalls): array {
        if ($folderId !== '9001') {
            return ['data' => []];
        }

        $inboxCalls++;

        return ['data' => $inboxCalls === 1 ? [zohoSyncTestRow('water-1', 'water-thread')] : []];
    });

    app(SyncMailboxService::class)->handle($mailbox->id);
    $before = $mailbox->syncStates()->where('scope', SyncScope::Inbox)->firstOrFail();
    $timestamp = $before->high_water_timestamp;
    $ids = $before->high_water_remote_ids;

    app(SyncMailboxService::class)->handle($mailbox->id);
    $after = $before->fresh();

    expect($timestamp)->not->toBeNull()
        ->and($after->high_water_timestamp)->toBe($timestamp)
        ->and($after->high_water_remote_ids)->toBe($ids);
});

it('sets last_synced_at after a successful job while backfill still has more pages', function () {
    config()->set('outboss.sync.pages_per_job', 1);
    config()->set('outboss.sync.zoho.page_size', 1);
    $mailbox = zohoSyncTestMailbox();
    zohoSyncTestFakeHttp(fn (string $folderId): array => [
        'data' => $folderId === '9001' ? [zohoSyncTestRow('backfill-1', 'backfill-thread')] : [],
    ]);

    app(SyncMailboxService::class)->handle($mailbox->id);

    expect($mailbox->fresh()->last_synced_at)->not->toBeNull()
        ->and($mailbox->syncStates()->where('scope', SyncScope::Inbox)->value('scan_state')['phase'])->toBe('backfill');
});

it('keeps completed page progress when a later page fails', function () {
    config()->set('outboss.sync.pages_per_job', 2);
    config()->set('outboss.sync.zoho.page_size', 1);
    $mailbox = zohoSyncTestMailbox();
    $domain = ManagedMailDomain::factory()->create([
        'workspace_id' => $mailbox->workspace_id,
        'created_by_user_id' => $mailbox->user_id,
    ]);
    $account = ManagedMailboxAccount::factory()->create([
        'managed_mail_domain_id' => $domain->id,
        'mailbox_id' => $mailbox->id,
    ]);

    zohoSyncTestFakeHttp(fn (string $folderId, int $start): array => match ([$folderId, $start]) {
        ['9001', 1] => ['data' => [zohoSyncTestRow('kept-1', 'kept-thread')]],
        ['9001', 2] => ['__status' => 500, 'message' => 'temporary failure'],
        default => ['data' => []],
    });

    expect(fn () => app(SyncMailboxService::class)->handle($mailbox->id))
        ->toThrow(RequestException::class);

    $state = $mailbox->syncStates()->where('scope', SyncScope::Inbox)->firstOrFail();
    expect(Message::query()->where('remote_message_id', 'kept-1')->exists())->toBeTrue()
        ->and($state->scan_state['start'])->toBe(2)
        ->and($state->high_water_timestamp)->not->toBeNull()
        ->and($state->status)->toBe(SyncStatus::Failed)
        ->and($mailbox->fresh()->last_synced_at)->toBeNull()
        ->and($mailbox->fresh()->status)->toBe(ConnectionStatus::Connected)
        ->and($account->events()->where('event', 'mailbox_sync_failed')->count())->toBe(1);
});

it('records recovery when a managed mailbox sync becomes healthy', function () {
    $mailbox = zohoSyncTestMailbox();
    $domain = ManagedMailDomain::factory()->create([
        'workspace_id' => $mailbox->workspace_id,
        'created_by_user_id' => $mailbox->user_id,
    ]);
    $account = ManagedMailboxAccount::factory()->create([
        'managed_mail_domain_id' => $domain->id,
        'mailbox_id' => $mailbox->id,
    ]);
    $state = MailboxSyncState::factory()->create([
        'mailbox_id' => $mailbox->id,
        'scope' => SyncScope::Inbox,
        'status' => SyncStatus::Failed,
        'last_started_at' => now()->subMinutes(3),
        'last_error' => 'temporary failure',
    ]);

    zohoSyncTestFakeHttp(fn (): array => ['data' => []]);
    app(SyncMailboxService::class)->handle($mailbox->id);

    expect($state->fresh()->status)->toBe(SyncStatus::Idle)
        ->and($state->fresh()->last_error)->toBeNull()
        ->and($account->events()->where('event', 'mailbox_sync_recovered')->count())->toBe(1);
});

it('marks the zoho connection and mailbox for reconnect on an unauthorized sync response', function (int $status) {
    $mailbox = zohoSyncTestMailbox();
    zohoSyncTestFakeHttp(fn (): array => [
        '__status' => $status,
        'message' => 'invalid oauth token',
    ]);

    expect(fn () => app(SyncMailboxService::class)->handle($mailbox->id))
        ->toThrow(RequestException::class);

    $mailbox->refresh();
    $mailbox->connection->refresh();
    $state = $mailbox->syncStates()->where('scope', SyncScope::Inbox)->firstOrFail();

    expect($mailbox->status)->toBe(ConnectionStatus::NeedsReconnect)
        ->and($mailbox->last_sync_error)->not->toBeNull()
        ->and($mailbox->connection->status)->toBe(ConnectionStatus::NeedsReconnect)
        ->and($mailbox->connection->last_error_code)->toBe('sync_unauthorized')
        ->and($state->status)->toBe(SyncStatus::Failed)
        ->and($state->last_error)->not->toBeNull();
})->with([401, 403]);

it('records token refresh auth failures on the mailbox and both sync states', function () {
    $mailbox = zohoSyncTestMailbox(connectionOverrides: ['token_expires_at' => now()->subMinute()]);

    Http::preventStrayRequests();
    Http::fake([
        'https://accounts.zoho.eu/oauth/v2/token' => Http::response([
            'error' => 'invalid_code',
        ], 401),
    ]);

    expect(fn () => app(SyncMailboxService::class)->handle($mailbox->id))
        ->toThrow(RequestException::class);

    $mailbox->refresh();
    $mailbox->connection->refresh();

    expect($mailbox->status)->toBe(ConnectionStatus::NeedsReconnect)
        ->and($mailbox->last_sync_error)->not->toBeNull()
        ->and($mailbox->connection->status)->toBe(ConnectionStatus::NeedsReconnect)
        ->and($mailbox->connection->last_error_code)->toBe('token_refresh_failed')
        ->and($mailbox->syncStates()->where('status', SyncStatus::Failed)->count())->toBe(2);
});

it('does not import zoho messages older than thirty days', function () {
    $mailbox = zohoSyncTestMailbox();
    zohoSyncTestFakeHttp(fn (string $folderId): array => [
        'data' => match ($folderId) {
            '9001' => [zohoSyncTestRow('old-inbox', 'old-inbox-thread', 40)],
            '9002' => [zohoSyncTestRow('old-sent', 'old-sent-thread', 40, [
                'fromAddress' => 'david@outboss.test',
                'toAddress' => 'maya@example.test',
            ])],
            default => [],
        },
    ]);

    app(SyncMailboxService::class)->handle($mailbox->id);
    app(SyncMailboxService::class)->handle($mailbox->id);

    expect(Message::query()->where('mailbox_id', $mailbox->id)->count())->toBe(0)
        ->and($mailbox->fresh()->last_synced_at)->not->toBeNull()
        ->and($mailbox->syncStates()->where('scope', SyncScope::Inbox)->value('scan_state')['phase'])->toBe('incremental')
        ->and($mailbox->syncStates()->where('scope', SyncScope::Sent)->value('scan_state')['phase'])->toBe('incremental');
});

it('keeps previously synced bodies and reply headers when a later poll returns 404s', function () {
    $mailbox = zohoSyncTestMailbox();
    zohoSyncTestFakeHttp(fn (string $folderId): array => [
        'data' => $folderId === '9001' ? [zohoSyncTestRow('keep-1', 'keep-thread')] : [],
    ]);

    app(SyncMailboxService::class)->handle($mailbox->id);

    $stored = Message::query()->where('remote_message_id', 'keep-1')->firstOrFail();
    expect($stored->body_html_sanitized)->toContain('<p>Full keep-1</p>')
        ->and($stored->internet_message_id)->toBe('keep-1@example.test')
        ->and($stored->in_reply_to)->toBe('parent@example.test')
        ->and($stored->references)->toBe(['root@example.test', 'parent@example.test']);

    Http::fake(function (Request $request) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if (str_ends_with($path, '/messages/view')) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response([
                'data' => ($query['folderId'] ?? '') === '9001'
                    ? [zohoSyncTestRow('keep-1', 'keep-thread')]
                    : [],
            ]);
        }

        return Http::response([], 404);
    });

    app(SyncMailboxService::class)->handle($mailbox->id);

    $replayed = $stored->fresh();
    expect(Message::query()->where('mailbox_id', $mailbox->id)->count())->toBe(1)
        ->and($replayed->body_html_sanitized)->toContain('<p>Full keep-1</p>')
        ->and($replayed->body_text)->toContain('Full keep-1')
        ->and($replayed->internet_message_id)->toBe('keep-1@example.test')
        ->and($replayed->in_reply_to)->toBe('parent@example.test')
        ->and($replayed->references)->toBe(['root@example.test', 'parent@example.test']);
});

it('reconciles a header-linked reply after its older sent parent is enriched', function () {
    $mailbox = zohoSyncTestMailbox();
    MailboxSyncState::factory()->create([
        'mailbox_id' => $mailbox->id,
        'scope' => SyncScope::Inbox,
        'remote_folder_id' => '9001',
        'scan_state' => ['phase' => 'incremental', 'start' => 1],
    ]);
    MailboxSyncState::factory()->create([
        'mailbox_id' => $mailbox->id,
        'scope' => SyncScope::Sent,
        'remote_folder_id' => '9002',
        'scan_state' => ['phase' => 'incremental', 'start' => 1],
    ]);
    $campaign = Campaign::factory()->create(['user_id' => $mailbox->user_id]);
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'email_address' => 'prospect@example.test',
        'email_normalized' => 'prospect@example.test',
        'status' => RecipientStatus::Active,
    ]);
    $wrongFallbackRecipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'email_address' => 'assistant+alternate@example.test',
        'email_normalized' => 'assistant+alternate@example.test',
        'status' => RecipientStatus::Active,
    ]);
    $parentThread = Thread::factory()->create([
        'user_id' => $mailbox->user_id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
        'remote_thread_id' => 'campaign-parent-thread',
    ]);
    Message::factory()->outbound()->create([
        'thread_id' => $parentThread->id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
        'remote_message_id' => 'parent',
        'internet_message_id' => null,
        'status' => MessageStatus::Sent,
        'sent_at' => now()->subDays(2),
    ]);

    zohoSyncTestFakeHttp(
        fn (string $folderId): array => [
            'data' => match ($folderId) {
                '9001' => [zohoSyncTestRow('reply-before-parent', 'reply-thread', 1, [
                    'subject' => 'Re: introduction',
                    'fromAddress' => 'assistant+alternate@example.test',
                ])],
                '9002' => [zohoSyncTestRow('parent', 'campaign-parent-thread', 2, [
                    'subject' => 'Introduction',
                    'fromAddress' => $mailbox->email_address,
                    'toAddress' => 'prospect@example.test',
                ])],
                default => [],
            },
        ],
        headers: [
            'reply-before-parent' => [
                'Message-Id' => ['<reply-before-parent@example.test>'],
                'References' => ['<root@example.test> <PARENT@example.test>'],
            ],
        ],
    );

    app(SyncMailboxService::class)->handle($mailbox->id);

    $reply = Message::query()->where('remote_message_id', 'reply-before-parent')->firstOrFail();

    expect($reply->campaign_recipient_id)->toBe($recipient->id)
        ->and($reply->classification)->toBe(ThreadClassification::Human)
        ->and($reply->thread->campaign_recipient_id)->toBe($recipient->id)
        ->and($reply->thread->local_state)->toBe(ThreadLocalState::NeedsAction)
        ->and($recipient->fresh()->status)->toBe(RecipientStatus::Replied)
        ->and($recipient->fresh()->stop_reason)->toBe('human_reply')
        ->and($wrongFallbackRecipient->fresh()->status)->toBe(RecipientStatus::Active)
        ->and($wrongFallbackRecipient->fresh()->stop_reason)->toBeNull();
});

it('applies unique-email fallback after zoho sent backfill completes', function () {
    $mailbox = zohoSyncTestMailbox();
    $campaign = Campaign::factory()->create(['user_id' => $mailbox->user_id]);
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'email_address' => 'fallback-only@example.test',
        'email_normalized' => 'fallback-only@example.test',
        'status' => RecipientStatus::Active,
    ]);

    zohoSyncTestFakeHttp(
        fn (string $folderId): array => [
            'data' => $folderId === '9001'
                ? [zohoSyncTestRow('fallback-after-sent', 'fallback-after-sent-thread', 1, [
                    'fromAddress' => 'fallback-only@example.test',
                ])]
                : [],
        ],
        headers: [
            'fallback-after-sent' => [
                'Message-Id' => ['<fallback-after-sent@example.test>'],
                'In-Reply-To' => ['<missing-parent@example.test>'],
            ],
        ],
    );

    app(SyncMailboxService::class)->handle($mailbox->id);

    $message = Message::query()->where('remote_message_id', 'fallback-after-sent')->firstOrFail();

    expect($mailbox->syncStates()->where('scope', SyncScope::Sent)->value('scan_state')['phase'])->toBe('incremental')
        ->and($message->campaign_recipient_id)->toBe($recipient->id)
        ->and($message->classification)->toBe(ThreadClassification::Human)
        ->and($message->thread->local_state)->toBe(ThreadLocalState::NeedsAction)
        ->and($recipient->fresh()->status)->toBe(RecipientStatus::Replied);
});

it('marks the connection needs-reconnect and skips syncing when secrets cannot be decrypted', function () {
    $mailbox = zohoSyncTestMailbox();

    ProviderConnection::query()->whereKey($mailbox->provider_connection_id)->update([
        'access_token' => '1000.corrupt-token',
    ]);

    Http::fake();

    app(SyncMailboxService::class)->handle($mailbox->id);

    $connection = $mailbox->connection->fresh();
    $mailbox = $mailbox->fresh();

    expect($connection->status)->toBe(ConnectionStatus::NeedsReconnect)
        ->and($connection->last_error_code)->toBe('corrupt_credentials')
        ->and($mailbox->status)->toBe(ConnectionStatus::NeedsReconnect)
        ->and($mailbox->last_sync_error)->toContain('reconnect')
        ->and($mailbox->sync_requested_at)->toBeNull();

    Http::assertNothingSent();
});

it('skips content and header fetches for messages already stored on incremental passes', function () {
    $mailbox = zohoSyncTestMailbox();

    zohoSyncTestFakeHttp(fn (string $folderId): array => [
        'data' => $folderId === '9001'
            ? [zohoSyncTestRow('known-1', 'thread-known')]
            : [],
    ]);

    app(SyncMailboxService::class)->handle($mailbox->id);

    expect(Message::query()->where('remote_message_id', 'known-1')->exists())->toBeTrue();

    zohoSyncTestFakeHttp(function (string $folderId, int $start, Request $request): array {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        expect(str_ends_with($path, '/content') || str_ends_with($path, '/header'))
            ->toBeFalse('Incremental pass must not refetch stored messages');

        return ['data' => []];
    });

    app(SyncMailboxService::class)->handle($mailbox->id);

    expect(Message::query()->where('mailbox_id', $mailbox->id)->count())->toBe(1);
});

it('records backfill progress as a percentage of the lookback window', function () {
    config()->set('outboss.sync.pages_per_job', 1);

    $mailbox = zohoSyncTestMailbox();

    zohoSyncTestFakeHttp(function (string $folderId, int $start): array {
        if ($folderId !== '9001' || $start !== 1) {
            return ['data' => []];
        }

        return [
            'data' => array_map(
                fn (int $index): array => zohoSyncTestRow('bulk-'.$index, 'thread-bulk', 15),
                range(1, 100),
            ),
        ];
    });

    app(SyncMailboxService::class)->handle($mailbox->id);

    $scanState = $mailbox->syncStates()->where('scope', SyncScope::Inbox)->value('scan_state');

    expect(data_get($scanState, 'phase'))->toBe('backfill')
        ->and(data_get($scanState, 'backfill_progress'))->toBeBetween(45, 55);
});

it('marks stuck syncing states as failed when the sync job fails', function () {
    $mailbox = zohoSyncTestMailbox();

    MailboxSyncState::factory()->create([
        'mailbox_id' => $mailbox->id,
        'scope' => SyncScope::Inbox,
        'status' => SyncStatus::Syncing,
        'last_started_at' => now()->subMinutes(9),
    ]);

    $mailbox->forceFill([
        'sync_requested_at' => now(),
        'last_sync_error' => null,
    ])->save();

    (new SyncMailbox($mailbox->id))->failed(
        new RuntimeException('App\\Jobs\\Mail\\SyncMailbox has timed out.'),
    );

    $state = $mailbox->syncStates()->where('scope', SyncScope::Inbox)->firstOrFail();
    $mailbox = $mailbox->fresh();

    expect($state->status)->toBe(SyncStatus::Failed)
        ->and($state->last_error)->toBe('App\\Jobs\\Mail\\SyncMailbox has timed out.')
        ->and($mailbox->last_sync_error)->toBe('App\\Jobs\\Mail\\SyncMailbox has timed out.')
        ->and($mailbox->sync_requested_at)->toBeNull();
});
