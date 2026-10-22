<?php

use App\Domain\Mail\DTOs\OutgoingMessage;
use App\Domain\Mail\DTOs\RemoteMessageReference;
use App\Domain\Mail\DTOs\UnknownSendReference;
use App\Domain\Mail\Enums\AuthScheme;
use App\Domain\Mail\Enums\ConnectionKind;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\SendAttemptKind;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Enums\SyncScope;
use App\Domain\Mail\Providers\ProviderRegistry;
use App\Domain\Mail\Providers\StalwartJmapException;
use App\Domain\Mail\Providers\StalwartProvider;
use App\Domain\Mail\Services\EnsureConnectionUsable;
use App\Domain\Mail\Services\ReconcileUnknownSend;
use App\Models\Mailbox;
use App\Models\MailboxSyncState;
use App\Models\Message;
use App\Models\ProviderConnection;
use App\Models\SendAttempt;
use App\Models\Thread;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function stalwartRuntimeResponse(string $method, array $payload, string $callId = 'outboss-1'): array
{
    return ['methodResponses' => [[$method, $payload, $callId]]];
}

function stalwartRuntimeConnection(): ProviderConnection
{
    return ProviderConnection::factory()->create([
        'provider' => MailProviderName::Stalwart,
        'provider_user_id' => 'alice@managed.example.test',
        'connection_kind' => ConnectionKind::Managed,
        'auth_scheme' => AuthScheme::AppPassword,
        'credential_payload' => [
            'username' => 'alice@managed.example.test',
            'password' => 'runtime-app-password',
            'version' => 1,
        ],
        'managed_instance_key' => 'internal',
    ]);
}

function stalwartSessionResponse(): array
{
    return [
        'capabilities' => [
            'urn:ietf:params:jmap:core' => [],
            'urn:ietf:params:jmap:mail' => [],
            'urn:ietf:params:jmap:submission' => [],
        ],
        'apiUrl' => 'https://jmap.example.test/jmap',
        'primaryAccounts' => ['urn:ietf:params:jmap:mail' => 'account-1'],
        'accounts' => ['account-1' => ['name' => 'alice@managed.example.test']],
    ];
}

function stalwartMailboxList(): array
{
    return [
        'list' => [
            ['id' => 'mb-inbox', 'name' => 'Inbox', 'role' => 'inbox', 'mayReadItems' => true],
            ['id' => 'mb-sent', 'name' => 'Sent', 'role' => 'sent', 'mayAddItems' => true],
        ],
        'accountId' => 'account-1',
        'state' => 'mailbox-state-1',
    ];
}

function stalwartRuntimeMailbox(): Mailbox
{
    $connection = stalwartRuntimeConnection();

    return Mailbox::factory()->create([
        'user_id' => $connection->user_id,
        'workspace_id' => $connection->workspace_id,
        'provider_connection_id' => $connection->id,
        'provider_mailbox_id' => 'account-1',
        'email_address' => 'alice@managed.example.test',
        'provider_metadata' => [
            'account_id' => 'account-1',
            'inbox_id' => 'mb-inbox',
            'sent_id' => 'mb-sent',
        ],
    ]);
}

beforeEach(function () {
    config()->set('outboss.managed_mail.instances.internal.base_url', 'https://jmap.example.test');
    config()->set('outboss.managed_mail.instances.internal.verify_tls', true);
    config()->set('outboss.managed_mail.instances.internal.timeout_seconds', 15);
    config()->set('outboss.managed_mail.session_cache_seconds', 60);
});

it('discovers Stalwart folders and maps JMAP inbox messages', function () {
    $mailbox = stalwartRuntimeMailbox();
    $state = MailboxSyncState::factory()->create([
        'mailbox_id' => $mailbox->id,
        'scope' => SyncScope::Inbox,
        'remote_folder_id' => 'mb-inbox',
    ]);

    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            return Http::response(stalwartSessionResponse());
        }

        $calls = $request->data()['methodCalls'] ?? [];
        $method = $calls[0][0] ?? null;

        return match ($method) {
            'Mailbox/get' => Http::response(stalwartRuntimeResponse('Mailbox/get', stalwartMailboxList(), 'mailboxes')),
            'Email/query' => Http::response(stalwartRuntimeResponse('Email/query', [
                'accountId' => 'account-1',
                'ids' => ['message-1'],
                'queryState' => 'query-state-1',
                'canCalculateChanges' => true,
                'total' => 1,
            ], 'query')),
            'Email/queryChanges' => Http::response(stalwartRuntimeResponse('Email/queryChanges', [
                'oldQueryState' => 'query-state-1',
                'newQueryState' => 'query-state-1',
                'added' => [],
                'removed' => [],
            ], 'queryChanges')),
            'Email/get' => Http::response(stalwartRuntimeResponse('Email/get', [
                'accountId' => 'account-1',
                'state' => 'email-state-1',
                'list' => [[
                    'id' => 'message-1',
                    'threadId' => 'thread-1',
                    'mailboxIds' => ['mb-inbox' => true],
                    'receivedAt' => '2026-08-31T13:14:15Z',
                    'messageId' => ['<reply-1@example.net>'],
                    'from' => [['email' => 'reply@example.net', 'name' => 'Reply Person']],
                    'to' => [['email' => 'alice@managed.example.test', 'name' => 'Alice']],
                    'subject' => 'A reply',
                    'header:Auto-Submitted:asText' => 'auto-replied',
                    'textBody' => [['partId' => '1', 'type' => 'text/plain']],
                    'bodyValues' => ['1' => ['value' => 'Hello from the managed inbox']],
                    'hasAttachment' => false,
                ]],
            ], 'get')),
            default => Http::response([], 500),
        };
    });

    $page = (new StalwartProvider)->syncFolder($mailbox, $state, 20);

    expect($page->messages)->toHaveCount(1)
        ->and($page->messages[0]->remoteMessageId)->toBe('message-1')
        ->and($page->messages[0]->direction->value)->toBe('inbound')
        ->and($page->messages[0]->bodyText)->toBe('Hello from the managed inbox')
        ->and($page->messages[0]->headers['auto-submitted'])->toBe('auto-replied')
        ->and($page->messages[0]->internetMessageId)->toBe('reply-1@example.net')
        ->and($page->highWaterTimestamp)->toBe(strtotime('2026-08-31T13:14:15Z'))
        ->and($page->nextState['jmap_query_state'])->toBe('query-state-1')
        ->and($page->hasMore)->toBeFalse();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://jmap.example.test/.well-known/jmap'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('alice@managed.example.test:runtime-app-password')));

    Http::assertSent(function (Request $request): bool {
        $calls = $request->data()['methodCalls'] ?? [];

        if (($calls[0][0] ?? null) === 'Email/get') {
            $properties = $calls[0][1]['properties'] ?? [];

            expect($properties)->toContain(
                'header:Auto-Submitted:asText',
                'header:X-Autoreply:asText',
                'header:X-Autorespond:asText',
            );
        }

        return ($calls[0][0] ?? null) !== 'Email/query'
            || ($calls[0][1]['sort'] ?? null) === [[
                'property' => 'receivedAt',
                'isAscending' => false,
            ]];
    });
});

it('pins the first backfill query and reconciles a reply inserted during pagination', function () {
    $mailbox = stalwartRuntimeMailbox();
    $state = MailboxSyncState::factory()->create([
        'mailbox_id' => $mailbox->id,
        'scope' => SyncScope::Inbox,
        'remote_folder_id' => 'mb-inbox',
    ]);
    $queryCount = 0;
    $queryChangesRequest = null;
    $rawMessages = [];

    foreach (['old-1', 'old-2', 'old-3', 'old-4', 'inserted-reply'] as $index => $id) {
        $rawMessages[$id] = [
            'id' => $id,
            'threadId' => 'thread-'.$id,
            'receivedAt' => $id === 'inserted-reply'
                ? '2026-09-01T12:00:00Z'
                : '2026-08-31T'.str_pad((string) (13 - $index), 2, '0', STR_PAD_LEFT).':00:00Z',
            'messageId' => ['<'.$id.'@example.net>'],
            'from' => [['email' => 'reply@example.net']],
            'to' => [['email' => 'alice@managed.example.test']],
            'subject' => $id === 'inserted-reply' ? 'Inserted reply' : 'Older message',
            'bodyValues' => ['1' => ['value' => $id.' body']],
            'textBody' => [['partId' => '1', 'type' => 'text/plain']],
        ];
    }

    Http::preventStrayRequests();
    Http::fake(function (Request $request) use (&$queryCount, &$queryChangesRequest, $rawMessages) {
        if ($request->method() === 'GET') {
            return Http::response(stalwartSessionResponse());
        }

        $calls = $request->data()['methodCalls'] ?? [];
        $method = $calls[0][0] ?? null;

        if ($method === 'Mailbox/get') {
            return Http::response(stalwartRuntimeResponse('Mailbox/get', stalwartMailboxList(), 'mailboxes'));
        }

        if ($method === 'Email/query') {
            $queryCount++;
            $arguments = $calls[0][1] ?? [];

            expect($arguments['filter'] ?? null)->toBe(['inMailbox' => 'mb-inbox'])
                ->and($arguments['sort'] ?? null)->toBe([[
                    'property' => 'receivedAt',
                    'isAscending' => false,
                ]]);

            if ($queryCount === 1) {
                expect($arguments['position'] ?? null)->toBe(0)
                    ->and($arguments)->not->toHaveKey('anchor');

                return Http::response(stalwartRuntimeResponse('Email/query', [
                    'accountId' => 'account-1',
                    'ids' => ['old-1', 'old-2'],
                    'queryState' => 'backfill-query-state',
                    'canCalculateChanges' => true,
                    'total' => 4,
                    'position' => 0,
                ], 'query'));
            }

            expect($arguments['anchor'] ?? null)->toBe('old-2')
                ->and($arguments['anchorOffset'] ?? null)->toBe(1)
                ->and($arguments)->not->toHaveKey('position');

            // The newly-arrived reply is above the first page. The anchor
            // keeps the second page on old-3/old-4, and queryChanges imports
            // the insertion after the backfill has reached the end.
            return Http::response(stalwartRuntimeResponse('Email/query', [
                'accountId' => 'account-1',
                'ids' => ['old-3', 'old-4'],
                'queryState' => 'later-query-state',
                'canCalculateChanges' => true,
                'total' => 4,
                'position' => 2,
            ], 'query'));
        }

        if ($method === 'Email/queryChanges') {
            $queryChangesRequest = $request->data();

            return Http::response(stalwartRuntimeResponse('Email/queryChanges', [
                'oldQueryState' => 'backfill-query-state',
                'newQueryState' => 'current-query-state',
                'added' => [['id' => 'inserted-reply', 'index' => 0]],
                'removed' => [],
            ], 'queryChanges'));
        }

        if ($method === 'Email/get') {
            $ids = $calls[0][1]['ids'] ?? [];
            $list = [];

            foreach ($ids as $id) {
                if (is_string($id) && isset($rawMessages[$id])) {
                    $list[] = $rawMessages[$id];
                }
            }

            return Http::response(stalwartRuntimeResponse('Email/get', [
                'accountId' => 'account-1',
                'list' => $list,
            ], 'get'));
        }

        return Http::response([], 500);
    });

    $provider = new StalwartProvider;
    $firstPage = $provider->syncFolder($mailbox, $state, 2);

    expect($firstPage->messages)->toHaveCount(2)
        ->and($firstPage->hasMore)->toBeTrue()
        ->and($firstPage->cursor)->toBe('backfill-query-state')
        ->and($firstPage->nextState['jmap_backfill_query_state'])->toBe('backfill-query-state')
        ->and($firstPage->nextState['jmap_query_state'])->toBe('backfill-query-state')
        ->and($firstPage->nextState['jmap_anchor'])->toBe('old-2');

    $state->forceFill([
        'cursor' => $firstPage->cursor,
        'scan_state' => $firstPage->nextState,
    ])->save();
    $state->refresh();

    $secondPage = $provider->syncFolder($mailbox, $state, 2);

    expect($secondPage->messages)->toHaveCount(3)
        ->and(collect($secondPage->messages)->pluck('remoteMessageId')->all())
        ->toEqual(['old-3', 'old-4', 'inserted-reply'])
        ->and($secondPage->hasMore)->toBeFalse()
        ->and($secondPage->cursor)->toBe('current-query-state')
        ->and($secondPage->nextState['jmap_phase'])->toBe('incremental')
        ->and($secondPage->nextState['jmap_query_state'])->toBe('current-query-state')
        ->and($secondPage->nextState)->not->toHaveKey('jmap_backfill_query_state')
        ->and($queryChangesRequest['methodCalls'][0][1]['sinceQueryState'] ?? null)
        ->toBe('backfill-query-state');
});

it('submits a new message and reply with stable marker and threading headers', function () {
    $mailbox = stalwartRuntimeMailbox();
    $calls = [];

    Http::preventStrayRequests();
    Http::fake(function (Request $request) use (&$calls) {
        if ($request->method() === 'GET') {
            return Http::response(stalwartSessionResponse());
        }

        $methodCalls = $request->data()['methodCalls'] ?? [];
        $calls[] = $methodCalls;
        $method = $methodCalls[0][0] ?? null;

        return match ($method) {
            'Mailbox/get' => Http::response(stalwartRuntimeResponse('Mailbox/get', stalwartMailboxList(), 'mailboxes')),
            'Identity/get' => Http::response(stalwartRuntimeResponse('Identity/get', [
                'list' => [['id' => 'identity-1', 'email' => 'alice@managed.example.test']],
            ], 'identities')),
            'Email/set' => Http::response([
                'methodResponses' => [
                    ['Email/set', [
                        'created' => [array_key_first($methodCalls[0][1]['create']) => ['id' => 'message-sent', 'threadId' => 'thread-sent']],
                    ], 'email'],
                    ['EmailSubmission/set', [
                        'created' => [array_key_first($methodCalls[1][1]['create']) => ['id' => 'submission-1']],
                    ], 'submission'],
                ],
            ]),
            default => Http::response([], 500),
        };
    });

    $provider = new StalwartProvider;
    $sent = $provider->sendNew($mailbox, new OutgoingMessage(
        subject: 'New message',
        bodyText: 'Body',
        bodyHtml: '<p>Body</p>',
        to: [['email' => 'prospect@example.net', 'name' => 'Prospect']],
        fromEmail: 'alice@managed.example.test',
        clientMessageMarker: 'marker-new',
    ));

    $reply = $provider->sendReply($mailbox, new RemoteMessageReference(
        remoteMessageId: 'message-1',
        remoteThreadId: 'thread-1',
        internetMessageId: 'reply-1@example.net',
    ), new OutgoingMessage(
        subject: 'Re: New message',
        bodyText: 'Reply body',
        bodyHtml: '<p>Reply body</p>',
        to: [['email' => 'prospect@example.net']],
        fromEmail: 'alice@managed.example.test',
        clientMessageMarker: 'marker-reply',
        inReplyTo: 'reply-1@example.net',
        references: ['reply-1@example.net'],
    ));

    expect($sent->remoteMessageId)->toBe('message-sent')
        ->and($sent->remoteThreadId)->toBe('thread-sent')
        ->and($sent->providerDraftId)->toBe('submission-1')
        ->and($reply->remoteMessageId)->toBe('message-sent')
        ->and($calls)->toHaveCount(6);

    $emailCreate = collect($calls)
        ->flatMap(fn (array $batch): array => $batch)
        ->filter(fn (array $call): bool => ($call[0] ?? null) === 'Email/set')
        ->last();
    $email = $emailCreate[1]['create'][array_key_first($emailCreate[1]['create'])];

    expect($email['header:X-Outboss-Message-Id'])->toBe('marker-reply')
        ->and($email['header:In-Reply-To'])->toBe('<reply-1@example.net>')
        ->and($email['header:References'])->toBe('<reply-1@example.net>');
});

it('preserves EmailSubmission rejection when Email/set created a Sent copy', function () {
    $mailbox = stalwartRuntimeMailbox();
    $marker = 'marker-email-created-only';

    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            return Http::response(stalwartSessionResponse());
        }

        $calls = $request->data()['methodCalls'] ?? [];
        $method = $calls[0][0] ?? null;

        return match ($method) {
            'Mailbox/get' => Http::response(stalwartRuntimeResponse('Mailbox/get', stalwartMailboxList(), 'mailboxes')),
            'Identity/get' => Http::response(stalwartRuntimeResponse('Identity/get', [
                'list' => [['id' => 'identity-1', 'email' => 'alice@managed.example.test']],
            ], 'identities')),
            'Email/set' => Http::response([
                'methodResponses' => [
                    ['Email/set', [
                        'created' => [array_key_first($calls[0][1]['create']) => [
                            'id' => 'message-created-only',
                            'threadId' => 'thread-created-only',
                        ]],
                    ], 'email'],
                    ['EmailSubmission/set', [
                        'notCreated' => [array_key_first($calls[1][1]['create']) => [
                            'type' => 'forbiddenToSend',
                        ]],
                    ], 'submission'],
                ],
            ]),
            default => Http::response([], 500),
        };
    });

    $exception = null;

    try {
        (new StalwartProvider)->sendNew($mailbox, new OutgoingMessage(
            subject: 'Created only',
            bodyText: 'Body',
            bodyHtml: '<p>Body</p>',
            to: [['email' => 'prospect@example.net']],
            fromEmail: 'alice@managed.example.test',
            clientMessageMarker: $marker,
        ));
    } catch (StalwartJmapException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(StalwartJmapException::class)
        ->and($exception?->safeCode)->toBe('stalwart_submission_not_created')
        ->and($exception?->metadata['submission_state'])->toBe('not_created')
        ->and($exception?->metadata['email_id'])->toBe('message-created-only')
        ->and($exception?->metadata['client_message_marker'])->toBe($marker);

    // The explicit non-acceptance evidence wins over a Sent copy. No Sent
    // query is needed, and the result is safe to retry as a new submission.
    $result = (new StalwartProvider)->reconcileUnknownSend($mailbox, new UnknownSendReference(
        clientMessageMarker: $marker,
        internetMessageId: $marker.'@outboss.local',
        submissionState: 'not_created',
    ));

    expect($result->isConfirmedNotSent())->toBeTrue()
        ->and($result->isConfirmedSent())->toBeFalse();
});

it('uses JMAP queryChanges after the initial folder backfill', function () {
    $mailbox = stalwartRuntimeMailbox();
    $state = MailboxSyncState::factory()->create([
        'mailbox_id' => $mailbox->id,
        'scope' => SyncScope::Inbox,
        'remote_folder_id' => 'mb-inbox',
        'scan_state' => [
            'jmap_phase' => 'incremental',
            'jmap_query_state' => 'query-state-1',
        ],
    ]);

    $queryChangesRequest = null;

    Http::preventStrayRequests();
    Http::fake(function (Request $request) use (&$queryChangesRequest) {
        if ($request->method() === 'GET') {
            return Http::response(stalwartSessionResponse());
        }

        $calls = $request->data()['methodCalls'] ?? [];
        $method = $calls[0][0] ?? null;

        if ($method === 'Email/queryChanges') {
            $queryChangesRequest = $request->data();
        }

        return match ($method) {
            'Mailbox/get' => Http::response(stalwartRuntimeResponse('Mailbox/get', stalwartMailboxList(), 'mailboxes')),
            'Email/queryChanges' => Http::response(stalwartRuntimeResponse('Email/queryChanges', [
                'oldQueryState' => 'query-state-1',
                'newQueryState' => 'query-state-2',
                'added' => [['id' => 'message-2', 'index' => 0]],
                'removed' => [],
                'hasMoreChanges' => false,
            ], 'queryChanges')),
            'Email/get' => Http::response(stalwartRuntimeResponse('Email/get', [
                'list' => [[
                    'id' => 'message-2',
                    'threadId' => 'thread-2',
                    'receivedAt' => '2026-09-01T12:00:00Z',
                    'messageId' => ['<reply-2@example.net>'],
                    'from' => [['email' => 'reply@example.net']],
                    'to' => [['email' => 'alice@managed.example.test']],
                    'subject' => 'New reply',
                    'bodyValues' => ['1' => ['value' => 'Incremental body']],
                    'textBody' => [['partId' => '1', 'type' => 'text/plain']],
                ]],
            ], 'get')),
            default => Http::response([], 500),
        };
    });

    $page = (new StalwartProvider)->syncFolder($mailbox, $state, 20);

    expect($page->messages)->toHaveCount(1)
        ->and($page->messages[0]->remoteMessageId)->toBe('message-2')
        ->and($page->cursor)->toBe('query-state-2')
        ->and($page->hasMore)->toBeFalse();

    expect($queryChangesRequest)->toBe([
        'using' => [
            'urn:ietf:params:jmap:core',
            'urn:ietf:params:jmap:mail',
        ],
        'methodCalls' => [[
            'Email/queryChanges',
            [
                'accountId' => 'account-1',
                'filter' => ['inMailbox' => 'mb-inbox'],
                'sort' => [[
                    'property' => 'receivedAt',
                    'isAscending' => false,
                ]],
                'sinceQueryState' => 'query-state-1',
                'maxChanges' => 20,
            ],
            'queryChanges',
        ]],
    ]);
});

it('reconciles one exact Sent match and never treats an unavailable search as sent', function () {
    $mailbox = stalwartRuntimeMailbox();
    $reference = new UnknownSendReference(
        clientMessageMarker: 'marker-unknown',
        internetMessageId: 'unknown@example.test',
        subject: 'Unknown send',
    );

    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            return Http::response(stalwartSessionResponse());
        }

        $calls = $request->data()['methodCalls'] ?? [];
        $method = $calls[0][0] ?? null;

        return match ($method) {
            'Mailbox/get' => Http::response(stalwartRuntimeResponse('Mailbox/get', stalwartMailboxList(), 'mailboxes')),
            'Email/query' => Http::response(stalwartRuntimeResponse('Email/query', [
                'ids' => ['message-sent'],
                'queryState' => 'sent-query-state',
            ], 'reconcile')),
            'Email/get' => Http::response(stalwartRuntimeResponse('Email/get', [
                'list' => [[
                    'id' => 'message-sent',
                    'threadId' => 'thread-sent',
                    'mailboxIds' => ['mb-sent' => true],
                    'sentAt' => '2026-09-01T10:00:00Z',
                    'messageId' => ['<unknown@example.test>'],
                    'from' => [['email' => 'alice@managed.example.test']],
                    'to' => [['email' => 'prospect@example.net']],
                    'subject' => 'Unknown send',
                    'bodyValues' => ['1' => ['value' => 'Sent body']],
                    'textBody' => [['partId' => '1', 'type' => 'text/plain']],
                    'header:X-Outboss-Message-Id:asText' => 'marker-unknown',
                ]],
            ], 'get')),
            default => Http::response([], 500),
        };
    });

    $result = (new StalwartProvider)->reconcileUnknownSend($mailbox, $reference);

    expect($result->isConfirmedSent())->toBeTrue()
        ->and($result->sendResult?->remoteMessageId)->toBe('message-sent')
        ->and($result->sendResult?->metadata['matched_by'])->toBe('client_message_marker')
        ->and($result->message?->direction->value)->toBe('outbound');
});

it('does not promote a Sent marker without EmailSubmission acceptance evidence', function () {
    $mailbox = stalwartRuntimeMailbox();
    $reference = new UnknownSendReference(
        clientMessageMarker: 'marker-without-submission',
        internetMessageId: 'without-submission@example.test',
        submissionState: 'unknown',
    );

    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            return Http::response(stalwartSessionResponse());
        }

        $calls = $request->data()['methodCalls'] ?? [];
        $method = $calls[0][0] ?? null;

        return match ($method) {
            'Mailbox/get' => Http::response(stalwartRuntimeResponse('Mailbox/get', stalwartMailboxList(), 'mailboxes')),
            'Email/query' => Http::response(stalwartRuntimeResponse('Email/query', [
                'ids' => ['message-without-submission'],
                'queryState' => 'sent-query-state',
            ], 'reconcile')),
            'Email/get' => Http::response(stalwartRuntimeResponse('Email/get', [
                'list' => [[
                    'id' => 'message-without-submission',
                    'threadId' => 'thread-without-submission',
                    'mailboxIds' => ['mb-sent' => true],
                    'sentAt' => '2026-09-01T10:00:00Z',
                    'messageId' => ['<without-submission@example.test>'],
                    'header:X-Outboss-Message-Id:asText' => 'marker-without-submission',
                ]],
            ], 'get')),
            'EmailSubmission/query' => Http::response(stalwartRuntimeResponse('EmailSubmission/query', [
                'ids' => [],
                'queryState' => 'submission-query-state',
            ], 'submission-query')),
            default => Http::response([], 500),
        };
    });

    $result = (new StalwartProvider)->reconcileUnknownSend($mailbox, $reference);

    expect($result->requiresManualReview())->toBeTrue()
        ->and($result->safeErrorCode)->toBe('stalwart_reconciliation_incomplete')
        ->and($result->isConfirmedSent())->toBeFalse();
});

it('falls back to a bounded Email get scan when Stalwart rejects Sent queries', function () {
    $mailbox = stalwartRuntimeMailbox();
    $reference = new UnknownSendReference(
        clientMessageMarker: 'marker-fallback',
        internetMessageId: 'fallback@example.test',
        subject: 'Fallback send',
    );

    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            return Http::response(stalwartSessionResponse());
        }

        $calls = $request->data()['methodCalls'] ?? [];
        $method = $calls[0][0] ?? null;

        if ($method === 'Mailbox/get') {
            return Http::response(stalwartRuntimeResponse('Mailbox/get', stalwartMailboxList(), 'mailboxes'));
        }

        if ($method === 'Email/query') {
            return Http::response(stalwartRuntimeResponse('error', [
                'type' => 'unsupportedSort',
                'description' => 'The requested query is not supported.',
            ], 'reconcile'));
        }

        if ($method === 'Email/get') {
            if (! array_key_exists('ids', $calls[0][1] ?? [])) {
                return Http::response(stalwartRuntimeResponse('Email/get', [
                    'list' => [[
                        'id' => 'message-fallback',
                        'mailboxIds' => ['mb-sent' => true],
                        'messageId' => ['<fallback@example.test>'],
                        'header:X-Outboss-Message-Id' => 'marker-fallback',
                    ]],
                ], 'reconcile-get'));
            }

            return Http::response(stalwartRuntimeResponse('Email/get', [
                'list' => [[
                    'id' => 'message-fallback',
                    'threadId' => 'thread-fallback',
                    'mailboxIds' => ['mb-sent' => true],
                    'sentAt' => '2026-09-01T10:00:00Z',
                    'messageId' => ['<fallback@example.test>'],
                    'from' => [['email' => 'alice@managed.example.test']],
                    'to' => [['email' => 'prospect@example.net']],
                    'subject' => 'Fallback send',
                    'bodyValues' => ['1' => ['value' => 'Sent body']],
                    'textBody' => [['partId' => '1', 'type' => 'text/plain']],
                    'header:X-Outboss-Message-Id:asText' => 'marker-fallback',
                ]],
            ], 'get'));
        }

        return Http::response([], 500);
    });

    $result = (new StalwartProvider)->reconcileUnknownSend($mailbox, $reference);

    expect($result->isConfirmedSent())->toBeTrue()
        ->and($result->sendResult?->remoteMessageId)->toBe('message-fallback');

    Http::assertSentCount(7);
});

it('continues an unfiltered Sent query after a full page before confirming a match', function () {
    $mailbox = stalwartRuntimeMailbox();
    $reference = new UnknownSendReference(
        clientMessageMarker: 'marker-on-second-page',
        internetMessageId: 'second-page@example.test',
        subject: 'Second-page send',
    );
    $oldIds = array_map(static fn (int $number): string => 'old-'.$number, range(1, 50));
    $queryPages = [];

    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($oldIds, &$queryPages) {
        if ($request->method() === 'GET') {
            return Http::response(stalwartSessionResponse());
        }

        $calls = $request->data()['methodCalls'] ?? [];
        $method = $calls[0][0] ?? null;

        if ($method === 'Mailbox/get') {
            return Http::response(stalwartRuntimeResponse('Mailbox/get', stalwartMailboxList(), 'mailboxes'));
        }

        if ($method === 'Email/query') {
            $arguments = $calls[0][1] ?? [];
            $filter = $arguments['filter'] ?? [];

            if (isset($filter['header'])) {
                return Http::response(stalwartRuntimeResponse('Email/query', [
                    'ids' => [],
                    'queryState' => 'targeted-empty',
                ], 'reconcile'));
            }

            $queryPages[] = $arguments;
            $position = (int) ($arguments['position'] ?? -1);

            return Http::response(stalwartRuntimeResponse('Email/query', [
                'ids' => $position === 0 ? $oldIds : ['target-second-page'],
                'position' => $position === 0 ? 0 : 50,
                'queryState' => 'sent-page-'.$position,
            ], 'reconcile'));
        }

        if ($method === 'Email/get') {
            $ids = $calls[0][1]['ids'] ?? [];
            $list = [];

            foreach (is_array($ids) ? $ids : [] as $id) {
                if (! is_string($id)) {
                    continue;
                }

                $list[] = $id === 'target-second-page'
                    ? [
                        'id' => $id,
                        'threadId' => 'thread-second-page',
                        'mailboxIds' => ['mb-sent' => true],
                        'sentAt' => '2026-09-01T10:00:00Z',
                        'messageId' => ['<second-page@example.test>'],
                        'from' => [['email' => 'alice@managed.example.test']],
                        'to' => [['email' => 'prospect@example.net']],
                        'subject' => 'Second-page send',
                        'bodyValues' => ['1' => ['value' => 'Sent body']],
                        'textBody' => [['partId' => '1', 'type' => 'text/plain']],
                        'header:X-Outboss-Message-Id:asText' => 'marker-on-second-page',
                    ]
                    : [
                        'id' => $id,
                        'mailboxIds' => ['mb-sent' => true],
                        'messageId' => ['<'.$id.'@example.test>'],
                        'header:X-Outboss-Message-Id:asText' => 'not-the-marker',
                    ];
            }

            return Http::response(stalwartRuntimeResponse('Email/get', [
                'list' => $list,
            ], 'get'));
        }

        return Http::response([], 500);
    });

    $result = (new StalwartProvider)->reconcileUnknownSend($mailbox, $reference);

    expect($result->isConfirmedSent())->toBeTrue()
        ->and($result->sendResult?->remoteMessageId)->toBe('target-second-page')
        ->and($queryPages[0]['position'] ?? null)->toBe(0)
        ->and($queryPages[1]['anchor'] ?? null)->toBe('old-50')
        ->and($queryPages[1]['anchorOffset'] ?? null)->toBe(1)
        ->and($queryPages[1])->not->toHaveKey('position');
});

it('returns inconclusive when Sent changes between anchored absence pages', function () {
    $mailbox = stalwartRuntimeMailbox();
    $reference = new UnknownSendReference(
        clientMessageMarker: 'marker-deleted-inserted',
        internetMessageId: 'deleted-inserted@example.test',
        submittedAt: now()->subMinutes(10),
    );
    $firstPageIds = array_map(static fn (int $number): string => 'old-'.$number, range(1, 50));
    $secondPageIds = array_map(static fn (int $number): string => 'old-'.$number, range(51, 100));
    $queryPages = [];

    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($firstPageIds, $secondPageIds, &$queryPages) {
        if ($request->method() === 'GET') {
            return Http::response(stalwartSessionResponse());
        }

        $calls = $request->data()['methodCalls'] ?? [];
        $method = $calls[0][0] ?? null;

        if ($method === 'Mailbox/get') {
            return Http::response(stalwartRuntimeResponse('Mailbox/get', stalwartMailboxList(), 'mailboxes'));
        }

        if ($method === 'Email/query') {
            $arguments = $calls[0][1] ?? [];

            if (isset(($arguments['filter'] ?? [])['header'])) {
                return Http::response(stalwartRuntimeResponse('Email/query', [
                    'ids' => [],
                    'queryState' => 'targeted-empty',
                ], 'reconcile'));
            }

            $queryPages[] = $arguments;
            $hasAnchor = isset($arguments['anchor']);
            $ids = $hasAnchor ? $secondPageIds : $firstPageIds;

            return Http::response(stalwartRuntimeResponse('Email/query', [
                'ids' => $ids,
                'position' => $hasAnchor ? 49 : 0,
                // A deletion plus insertion between pages advances the
                // provider query state. The first anchor remains valid, but
                // exhaustive absence is no longer provable from the walk.
                'queryState' => $hasAnchor ? 'sent-query-state-2' : 'sent-query-state-1',
                'total' => 100,
            ], 'reconcile'));
        }

        if ($method === 'Email/get') {
            $ids = $calls[0][1]['ids'] ?? [];
            $list = [];

            foreach (is_array($ids) ? $ids : [] as $id) {
                if (is_string($id)) {
                    $list[] = [
                        'id' => $id,
                        'mailboxIds' => ['mb-sent' => true],
                        'messageId' => ['<'.$id.'@example.test>'],
                        'header:X-Outboss-Message-Id:asText' => 'other-'.$id,
                    ];
                }
            }

            return Http::response(stalwartRuntimeResponse('Email/get', ['list' => $list], 'get'));
        }

        return Http::response([], 500);
    });

    $result = (new StalwartProvider)->reconcileUnknownSend($mailbox, $reference);

    expect($result->requiresManualReview())->toBeTrue()
        ->and($result->safeErrorCode)->toBe('stalwart_reconciliation_incomplete')
        ->and($result->isConfirmedNotSent())->toBeFalse()
        ->and($result->isConfirmedSent())->toBeFalse()
        ->and($queryPages[0]['position'] ?? null)->toBe(0)
        ->and($queryPages[1]['anchor'] ?? null)->toBe('old-50')
        ->and($queryPages[1])->not->toHaveKey('position');
});

it('returns an incomplete reconciliation result for an unfiltered non-exhaustive fallback page', function () {
    $mailbox = stalwartRuntimeMailbox();
    $submittedAt = now()->subMinutes(10);
    $reference = new UnknownSendReference(
        clientMessageMarker: 'marker-not-in-page',
        internetMessageId: 'not-in-page@example.test',
        submittedAt: $submittedAt,
    );
    $rows = array_map(static fn (int $number): array => [
        'id' => 'fallback-'.$number,
        'mailboxIds' => ['mb-sent' => true],
        'messageId' => ['<fallback-'.$number.'@example.test>'],
        'header:X-Outboss-Message-Id' => 'other-marker-'.$number,
    ], range(1, 50));

    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($rows) {
        if ($request->method() === 'GET') {
            return Http::response(stalwartSessionResponse());
        }

        $calls = $request->data()['methodCalls'] ?? [];
        $method = $calls[0][0] ?? null;

        if ($method === 'Mailbox/get') {
            return Http::response(stalwartRuntimeResponse('Mailbox/get', stalwartMailboxList(), 'mailboxes'));
        }

        if ($method === 'Email/query') {
            return Http::response(stalwartRuntimeResponse('error', [
                'type' => 'unsupportedSort',
                'description' => 'The requested query is not supported.',
            ], 'reconcile'));
        }

        if ($method === 'Email/get' && ! array_key_exists('ids', $calls[0][1] ?? [])) {
            return Http::response(stalwartRuntimeResponse('Email/get', [
                'list' => $rows,
            ], 'reconcile-get'));
        }

        return Http::response([], 500);
    });

    $result = (new StalwartProvider)->reconcileUnknownSend($mailbox, $reference);

    expect($result->requiresManualReview())->toBeTrue()
        ->and($result->safeErrorCode)->toBe('stalwart_reconciliation_incomplete')
        ->and($result->isConfirmedNotSent())->toBeFalse();
});

it('keeps an empty immediate Sent search pending through the consistency window', function () {
    config()->set('outboss.managed_mail.reconciliation_consistency_delay_seconds', 300);
    $mailbox = stalwartRuntimeMailbox();
    $reference = new UnknownSendReference(
        clientMessageMarker: 'marker-pending',
        internetMessageId: 'pending@example.test',
        subject: 'Pending send',
        submittedAt: now(),
    );

    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            return Http::response(stalwartSessionResponse());
        }

        $calls = $request->data()['methodCalls'] ?? [];
        $method = $calls[0][0] ?? null;

        return match ($method) {
            'Mailbox/get' => Http::response(stalwartRuntimeResponse('Mailbox/get', stalwartMailboxList(), 'mailboxes')),
            'Email/query' => Http::response(stalwartRuntimeResponse('Email/query', [
                'ids' => [],
                'queryState' => 'empty-sent-query',
            ], 'reconcile')),
            default => Http::response([], 500),
        };
    });

    $result = (new StalwartProvider)->reconcileUnknownSend($mailbox, $reference);

    expect($result->isPending())->toBeTrue()
        ->and($result->requiresManualReview())->toBeFalse()
        ->and($result->safeErrorCode)->toBe('stalwart_reconciliation_pending')
        ->and($result->isConfirmedNotSent())->toBeFalse()
        ->and($result->metadata['consistency_delay_seconds'])->toBe(300)
        ->and($result->metadata['retry_at'])->not->toBeNull()
        ->and($result->metadata['consistency_deadline'])->not->toBeNull();

    Http::assertSent(function (Request $request): bool {
        if ($request->method() !== 'POST' || ! is_array($calls = $request->data()['methodCalls'] ?? null)) {
            return true;
        }

        return ($calls[0][0] ?? null) !== 'Email/query'
            || ! array_key_exists('sort', $calls[0][1] ?? []);
    });
});

it('keeps a pending unknown send due for retry and defers it after the deadline', function () {
    config()->set('outboss.managed_mail.reconciliation_consistency_delay_seconds', 300);
    config()->set('outboss.managed_mail.reconciliation_retry_seconds', 30);

    $mailbox = stalwartRuntimeMailbox();
    $submittedAt = now();
    $thread = Thread::factory()->create([
        'user_id' => $mailbox->user_id,
        'workspace_id' => $mailbox->workspace_id,
        'mailbox_id' => $mailbox->id,
    ]);
    $message = Message::factory()->outbound()->create([
        'workspace_id' => $mailbox->workspace_id,
        'thread_id' => $thread->id,
        'mailbox_id' => $mailbox->id,
        'subject' => 'Pending send',
        'client_message_marker' => 'marker-pending-service',
    ]);
    $attempt = SendAttempt::factory()->create([
        'user_id' => $mailbox->user_id,
        'workspace_id' => $mailbox->workspace_id,
        'mailbox_id' => $mailbox->id,
        'message_id' => $message->id,
        'kind' => SendAttemptKind::Campaign,
        'status' => SendAttemptStatus::Unknown,
        'provider_accepted_at' => null,
        'lease_acquired_at' => $submittedAt,
        'due_at' => null,
    ]);
    $attempt->forceFill([
        'provider_accepted_at' => null,
        'lease_acquired_at' => $submittedAt,
    ])->save();

    $connection = $mailbox->connection;
    $connectionUsable = Mockery::mock(EnsureConnectionUsable::class);
    $connectionUsable->shouldReceive('handle')->andReturn($connection);
    app()->instance(EnsureConnectionUsable::class, $connectionUsable);
    app()->instance(ProviderRegistry::class, new ProviderRegistry([
        MailProviderName::Stalwart->value => new StalwartProvider,
    ]));

    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            return Http::response(stalwartSessionResponse());
        }

        $calls = $request->data()['methodCalls'] ?? [];
        $method = $calls[0][0] ?? null;

        return match ($method) {
            'Mailbox/get' => Http::response(stalwartRuntimeResponse('Mailbox/get', stalwartMailboxList(), 'mailboxes')),
            'Email/query' => Http::response(stalwartRuntimeResponse('Email/query', [
                'ids' => [],
                'queryState' => 'empty-sent-query',
            ], 'reconcile')),
            default => Http::response([], 500),
        };
    });

    expect(app(ReconcileUnknownSend::class)->handle($attempt->id))->toBe(1);

    $pending = $attempt->fresh();
    expect($pending->status)->toBe(SendAttemptStatus::Unknown)
        ->and($pending->manual_review_cycle)->toBe(0)
        ->and($pending->error_code)->toBe('stalwart_reconciliation_pending')
        ->and($pending->due_at)->not->toBeNull()
        ->and($pending->due_at->isFuture())->toBeTrue()
        ->and($pending->due_at->lessThanOrEqualTo($submittedAt->copy()->addSeconds(300)))->toBeTrue();

    // The due-time gate makes an immediate scheduler/manual invocation a
    // no-op, so the provider is not queried repeatedly in a hot loop.
    expect(app(ReconcileUnknownSend::class)->handle($attempt->id))->toBe(0)
        ->and($attempt->fresh()->status)->toBe(SendAttemptStatus::Unknown);

    $this->travelTo($submittedAt->copy()->addSeconds(31));
    expect(app(ReconcileUnknownSend::class)->handle($attempt->id))->toBe(1)
        ->and($attempt->fresh()->status)->toBe(SendAttemptStatus::Unknown);

    $this->travelTo($submittedAt->copy()->addSeconds(301));
    expect(app(ReconcileUnknownSend::class)->handle($attempt->id))->toBe(1)
        ->and($attempt->fresh()->status)->toBe(SendAttemptStatus::Deferred)
        ->and($attempt->fresh()->deferred_reason)->toBe('reconciled_not_sent')
        ->and($attempt->fresh()->due_at)->toBeNull();
});
