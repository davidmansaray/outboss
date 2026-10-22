<?php

use App\Domain\Mail\DTOs\RemoteSenderIdentity;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\MessageStatus;
use App\Domain\Mail\Providers\FakeMailProvider;
use App\Domain\Mail\Services\ComposeMessageService;
use App\Domain\Mail\Services\MailboxSenderIdentityService;
use App\Domain\Mail\Services\ReplyToThreadService;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\ProviderConnection;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Support\Str;

function senderIdentityMailbox(array $attributes = []): Mailbox
{
    $user = isset($attributes['user_id'])
        ? User::query()->findOrFail($attributes['user_id'])
        : User::factory()->create();
    $connection = ProviderConnection::factory()->create([
        'user_id' => $user->id,
        'provider' => MailProviderName::Zoho,
    ]);

    return Mailbox::factory()->create(array_merge([
        'user_id' => $user->id,
        'provider_connection_id' => $connection->id,
        'email_address' => 'david@outboss.io',
        'display_name' => 'David',
    ], $attributes));
}

/** @return list<RemoteSenderIdentity> */
function senderIdentityChoices(): array
{
    return [
        new RemoteSenderIdentity(
            key: 'ignored',
            emailAddress: 'PARTNERSHIPS@OUTBOSS.IO',
            displayName: "Partnerships\r\nInjected",
            remoteId: 'send-partnerships',
            mode: 'alias',
        ),
        new RemoteSenderIdentity(
            key: 'ignored-again',
            emailAddress: 'david@outboss.io',
            displayName: 'David Mansaray',
            remoteId: 'send-primary',
            mode: 'primary',
        ),
        new RemoteSenderIdentity(
            key: 'duplicate',
            emailAddress: 'partnerships@outboss.io',
            displayName: 'Duplicate display name',
            remoteId: 'send-duplicate',
            mode: 'group',
        ),
        new RemoteSenderIdentity(
            key: 'inactive',
            emailAddress: 'disabled@outboss.io',
            displayName: 'Disabled',
            active: false,
        ),
    ];
}

it('normalizes, bounds, deduplicates, and defaults sender identities to the primary address', function () {
    $mailbox = senderIdentityMailbox();
    $service = app(MailboxSenderIdentityService::class);

    $service->sync($mailbox, senderIdentityChoices(), supported: true);
    $mailbox = $mailbox->fresh();

    expect($mailbox->sender_identities)->toHaveCount(3)
        ->and($mailbox->selected_sender_identity_key)
        ->toBe(MailboxSenderIdentityService::keyForEmail('david@outboss.io'))
        ->and($service->clientOptions($mailbox))->toHaveCount(2)
        ->and($service->effective($mailbox))->toMatchArray([
            'email' => 'david@outboss.io',
            'name' => 'David Mansaray',
        ])
        ->and(json_encode($mailbox->sender_identities))->not->toContain("\r")
        ->and(json_encode($mailbox->sender_identities))->not->toContain("\n");
});

it('retains a valid selected address on reconciliation and chooses a deterministic replacement when removed', function () {
    $mailbox = senderIdentityMailbox();
    $service = app(MailboxSenderIdentityService::class);
    $service->sync($mailbox, senderIdentityChoices(), supported: true);

    $mailbox->forceFill([
        'selected_sender_identity_key' => MailboxSenderIdentityService::keyForEmail('partnerships@outboss.io'),
    ])->save();
    $service->sync($mailbox->fresh(), [
        new RemoteSenderIdentity(
            key: 'new-primary',
            emailAddress: 'david@outboss.io',
            displayName: 'David updated',
        ),
        new RemoteSenderIdentity(
            key: 'new-partnerships',
            emailAddress: 'partnerships@outboss.io',
            displayName: 'Partnerships updated',
        ),
    ], supported: true);

    expect($mailbox->fresh()->selected_sender_identity_key)
        ->toBe(MailboxSenderIdentityService::keyForEmail('partnerships@outboss.io'));

    $service->sync($mailbox->fresh(), [
        new RemoteSenderIdentity(
            key: 'new-primary',
            emailAddress: 'david@outboss.io',
            displayName: 'David updated',
        ),
    ], supported: true);

    expect($mailbox->fresh()->selected_sender_identity_key)
        ->toBe(MailboxSenderIdentityService::keyForEmail('david@outboss.io'));
});

it('accepts only an owned current identity key through the update endpoint', function () {
    $mailbox = senderIdentityMailbox();
    $service = app(MailboxSenderIdentityService::class);
    $service->sync($mailbox, senderIdentityChoices(), supported: true);
    $key = MailboxSenderIdentityService::keyForEmail('partnerships@outboss.io');

    $this->actingAs($mailbox->user)
        ->patch(route('mailboxes.reply-identity.update', $mailbox), ['identity_key' => $key])
        ->assertRedirect();

    expect($mailbox->fresh()->selected_sender_identity_key)->toBe($key);

    $this->actingAs($mailbox->user)
        ->patch(route('mailboxes.reply-identity.update', $mailbox), ['identity_key' => str_repeat('a', 64)])
        ->assertSessionHasErrors('identity_key');

    $intruder = User::factory()->create();
    $this->actingAs($intruder)
        ->patch(route('mailboxes.reply-identity.update', $mailbox), ['identity_key' => $key])
        ->assertForbidden();
});

it('snapshots the selected identity for future compose messages', function () {
    FakeMailProvider::reset();
    $mailbox = senderIdentityMailbox();
    $service = app(MailboxSenderIdentityService::class);
    $service->sync($mailbox, senderIdentityChoices(), supported: true);
    $user = $mailbox->user;

    $first = app(ComposeMessageService::class)->handle(
        $user,
        $mailbox->fresh('connection'),
        'one@example.test',
        'First',
        'Body',
        (string) Str::uuid(),
    );

    expect($first->message->from_email)->toBe('david@outboss.io')
        ->and($first->message->from_name)->toBe('David Mansaray');

    $key = MailboxSenderIdentityService::keyForEmail('partnerships@outboss.io');
    $service->select($mailbox, $key);

    $second = app(ComposeMessageService::class)->handle(
        $user,
        $mailbox->fresh('connection'),
        'two@example.test',
        'Second',
        'Body',
        (string) Str::uuid(),
    );

    expect($first->fresh('message')->message->from_email)->toBe('david@outboss.io')
        ->and($second->message->from_email)->toBe('partnerships@outboss.io')
        ->and($second->message->from_name)->toBe('PartnershipsInjected');

    expect(FakeMailProvider::$store['sends'])->toHaveCount(2)
        ->and(FakeMailProvider::$store['sends'][0]['fromEmail'])->toBe('david@outboss.io')
        ->and(FakeMailProvider::$store['sends'][1]['fromEmail'])->toBe('partnerships@outboss.io');
});

it('uses the mailbox current identity for a reply without rewriting the parent snapshot', function () {
    FakeMailProvider::reset();
    $mailbox = senderIdentityMailbox();
    $service = app(MailboxSenderIdentityService::class);
    $service->sync($mailbox, senderIdentityChoices(), supported: true);
    $thread = Thread::factory()->create([
        'user_id' => $mailbox->user_id,
        'mailbox_id' => $mailbox->id,
        'participant_email' => 'maya@example.test',
        'subject' => 'Historical sender',
    ]);
    $parent = Message::factory()->create([
        'thread_id' => $thread->id,
        'mailbox_id' => $mailbox->id,
        'direction' => MessageDirection::Outbound,
        'status' => MessageStatus::Sent,
        'from_email' => 'david@outboss.io',
        'from_name' => 'David Mansaray',
        'remote_message_id' => 'zoho-parent-1',
        'internet_message_id' => '<zoho-parent-1@example.test>',
        'received_at' => null,
        'sent_at' => now()->subMinute(),
    ]);

    $service->select(
        $mailbox,
        MailboxSenderIdentityService::keyForEmail('partnerships@outboss.io'),
    );

    $attempt = app(ReplyToThreadService::class)->handle(
        $mailbox->user,
        $thread,
        'Following up from the newly selected identity.',
        (string) Str::uuid(),
    );

    expect($mailbox->fresh()->selected_sender_identity_key)
        ->toBe(MailboxSenderIdentityService::keyForEmail('partnerships@outboss.io'))
        ->and($parent->fresh()->from_email)->toBe('david@outboss.io')
        ->and($attempt->message->from_email)->toBe('partnerships@outboss.io')
        ->and($attempt->message->from_name)->toBe('PartnershipsInjected')
        ->and(FakeMailProvider::$store['sends'])->toHaveCount(1)
        ->and(FakeMailProvider::$store['sends'][0]['fromEmail'])->toBe('partnerships@outboss.io');
});

it('returns an actionable compose error without creating a thread when no Zoho identity is active', function () {
    FakeMailProvider::reset();
    $mailbox = senderIdentityMailbox([
        'sender_identities' => [],
        'selected_sender_identity_key' => null,
    ]);
    $expectedError = 'No active sender identity is available. Reconnect this inbox to refresh its From addresses.';

    $this->actingAs($mailbox->user)
        ->from(route('unibox.index'))
        ->post(route('messages.store'), [
            'mailbox_id' => $mailbox->id,
            'to' => 'compose@example.test',
            'subject' => 'Cannot send',
            'body' => 'No sender is available.',
            'client_operation_id' => (string) Str::uuid(),
        ])
        ->assertRedirect(route('unibox.index'))
        ->assertSessionHasErrors(['mailbox_id' => $expectedError]);

    expect(Thread::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0)
        ->and(FakeMailProvider::$store['sends'] ?? [])->toBe([]);
});

it('returns an actionable reply error without creating an outbound message when no Zoho identity is active', function () {
    FakeMailProvider::reset();
    $mailbox = senderIdentityMailbox([
        'sender_identities' => [],
        'selected_sender_identity_key' => null,
    ]);
    $thread = Thread::factory()->create([
        'user_id' => $mailbox->user_id,
        'mailbox_id' => $mailbox->id,
        'participant_email' => 'maya@example.test',
    ]);
    Message::factory()->create([
        'thread_id' => $thread->id,
        'mailbox_id' => $mailbox->id,
        'remote_message_id' => 'zoho-parent-2',
        'internet_message_id' => '<zoho-parent-2@example.test>',
        'received_at' => now(),
    ]);
    $expectedError = 'No active sender identity is available. Reconnect this inbox to refresh its From addresses.';

    $this->actingAs($mailbox->user)
        ->from(route('unibox.index'))
        ->post(route('threads.reply', $thread), [
            'body' => 'Cannot reply without an active sender.',
            'client_operation_id' => (string) Str::uuid(),
        ])
        ->assertRedirect(route('unibox.index'))
        ->assertSessionHasErrors(['reply' => $expectedError]);

    expect(Message::query()->where('direction', MessageDirection::Outbound)->count())->toBe(0)
        ->and(Thread::query()->count())->toBe(1)
        ->and(FakeMailProvider::$store['sends'] ?? [])->toBe([]);
});

it('keeps non-Zoho mailboxes on the existing mailbox address fallback', function () {
    $user = User::factory()->create();
    $connection = ProviderConnection::factory()->create([
        'user_id' => $user->id,
        'provider' => MailProviderName::Gmail,
    ]);
    $mailbox = Mailbox::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $connection->id,
        'email_address' => 'fallback@outboss.io',
        'display_name' => 'Fallback sender',
    ]);

    expect(app(MailboxSenderIdentityService::class)->effective($mailbox))
        ->toMatchArray([
            'email' => 'fallback@outboss.io',
            'name' => 'Fallback sender',
        ]);
});

it('keeps the mailbox page available when Zoho has no active sender identity', function () {
    $mailbox = senderIdentityMailbox([
        'sender_identities' => [],
        'selected_sender_identity_key' => null,
    ]);

    $this->actingAs($mailbox->user)
        ->get(route('mailboxes.index'))
        ->assertInertia(fn ($page) => $page
            ->where('mailboxes.0.reply_identities', [])
            ->where('mailboxes.0.effective_reply_identity', null)
            ->where('mailboxes.0.reply_identity_error', 'No active sender identity is available. Reconnect this inbox to refresh its From addresses.'));
});
