<?php

use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\SyncScope;
use App\Domain\Mail\Providers\GmailProvider;
use App\Domain\Mail\Providers\ProviderRegistry;
use App\Domain\Mail\Services\SyncMailboxService;
use App\Models\Mailbox;
use App\Models\MailboxSyncState;
use App\Models\Message;
use App\Models\ProviderConnection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * @return array<string, mixed>
 */
function gmailProviderTestMessage(string $messageId): array
{
    return [
        'id' => $messageId,
        'threadId' => 'thread-'.$messageId,
        'labelIds' => ['INBOX'],
        'snippet' => 'Body '.$messageId,
        'internalDate' => (string) now()->getTimestampMs(),
        'payload' => [
            'headers' => [
                ['name' => 'Subject', 'value' => 'Subject '.$messageId],
                ['name' => 'From', 'value' => 'Sender <sender@example.test>'],
                ['name' => 'To', 'value' => 'David <david@example.test>'],
                ['name' => 'Message-ID', 'value' => '<'.$messageId.'@example.test>'],
            ],
        ],
    ];
}

it('resumes gmail history pagination and does not apply the zoho page size', function () {
    config()->set('outboss.sync.pages_per_job', 5);
    config()->set('outboss.sync.zoho.page_size', 1);
    app(ProviderRegistry::class)->register(MailProviderName::Gmail, new GmailProvider);

    $connection = ProviderConnection::factory()->create([
        'provider' => MailProviderName::Gmail,
        'access_token' => 'gmail-access',
        'token_expires_at' => now()->addHour(),
        'status' => ConnectionStatus::Connected,
    ]);
    $mailbox = Mailbox::factory()->create([
        'user_id' => $connection->user_id,
        'provider_connection_id' => $connection->id,
        'provider_mailbox_id' => 'david@example.test',
        'email_address' => 'david@example.test',
        'status' => ConnectionStatus::Connected,
    ]);
    MailboxSyncState::factory()->create([
        'mailbox_id' => $mailbox->id,
        'scope' => SyncScope::Inbox,
        'cursor' => '100',
    ]);
    MailboxSyncState::factory()->create([
        'mailbox_id' => $mailbox->id,
        'scope' => SyncScope::Sent,
        'cursor' => '300',
    ]);
    $historyQueries = [];

    Http::preventStrayRequests();
    Http::fake(function (Request $request) use (&$historyQueries) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if (str_ends_with($path, '/history')) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $historyQueries[] = $query;

            return match ([$query['startHistoryId'] ?? null, $query['pageToken'] ?? null]) {
                ['100', null] => Http::response([
                    'history' => [['messages' => [['id' => 'gmail-page-1']]]],
                    'historyId' => '200',
                    'nextPageToken' => 'inbox-page-2',
                ]),
                ['100', 'inbox-page-2'] => Http::response([
                    'history' => [['messages' => [['id' => 'gmail-page-2']]]],
                    'historyId' => '200',
                ]),
                ['300', null] => Http::response([
                    'history' => [],
                    'historyId' => '400',
                ]),
                default => Http::response(['message' => 'Unexpected history query', 'query' => $query], 500),
            };
        }

        if (preg_match('#/messages/([^/]+)$#', $path, $matches) === 1) {
            return Http::response(gmailProviderTestMessage(rawurldecode($matches[1])));
        }

        return Http::response(['message' => 'Unexpected Gmail test request: '.$request->url()], 500);
    });

    app(SyncMailboxService::class)->handle($mailbox->id);

    $inboxState = $mailbox->syncStates()->where('scope', SyncScope::Inbox)->firstOrFail();

    expect(Message::query()->where('mailbox_id', $mailbox->id)->pluck('remote_message_id')->sort()->values()->all())
        ->toBe(['gmail-page-1', 'gmail-page-2'])
        ->and($inboxState->cursor)->toBe('200')
        ->and($inboxState->scan_state)->toBe([
            'gmail_history_start_id' => null,
            'gmail_history_page_token' => null,
        ])
        ->and($historyQueries)->toHaveCount(3)
        ->and($historyQueries[0]['startHistoryId'])->toBe('100')
        ->and($historyQueries[0]['maxResults'])->toBe('100')
        ->and($historyQueries[0])->not->toHaveKey('pageToken')
        ->and($historyQueries[1]['startHistoryId'])->toBe('100')
        ->and($historyQueries[1]['maxResults'])->toBe('100')
        ->and($historyQueries[1]['pageToken'])->toBe('inbox-page-2')
        ->and($historyQueries[2]['startHistoryId'])->toBe('300')
        ->and($historyQueries[2]['maxResults'])->toBe('100');
});
