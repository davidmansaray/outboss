<?php

use App\Domain\Api\TokenScope;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Enums\ThreadLocalState;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\SendAttempt;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Support\Str;

function apiLinkedThread(User $user, Mailbox $mailbox, ThreadLocalState $state = ThreadLocalState::NeedsAction): Thread
{
    $thread = Thread::factory()->create([
        'user_id' => $user->id,
        'mailbox_id' => $mailbox->id,
        'local_state' => $state,
    ]);

    Message::factory()->create([
        'thread_id' => $thread->id,
        'mailbox_id' => $mailbox->id,
        'direction' => MessageDirection::Inbound,
    ]);

    return $thread;
}

it('rejects thread list requests without a token', function () {
    $this->getJson('/api/v1/threads')
        ->assertUnauthorized()
        ->assertJsonPath('error.type', 'unauthenticated');
});

it('rejects thread list requests when the token lacks inbox:read', function () {
    $user = User::factory()->create();

    $this->withToken(apiToken($user, [TokenScope::CampaignsRead->value]))
        ->getJson('/api/v1/threads')
        ->assertForbidden()
        ->assertJsonPath('error.type', 'insufficient_scope');
});

it('lists and shows threads with inbox:read', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $thread = apiLinkedThread($user, $mailbox);
    $thread->forceFill(['is_unread' => true])->save();
    $token = apiToken($user, [TokenScope::InboxRead->value]);

    $this->withToken($token)
        ->getJson('/api/v1/threads?folder=needs&per_page=10')
        ->assertOk()
        ->assertJsonPath('data.0.id', $thread->id)
        ->assertJsonStructure(['data', 'meta']);

    $this->withToken($token)
        ->getJson('/api/v1/threads/'.$thread->id)
        ->assertOk()
        ->assertJsonPath('data.id', $thread->id)
        ->assertJsonStructure(['data' => ['messages']]);

    expect($thread->fresh()->is_unread)->toBeTrue();
});

it('marks a thread done and classifies it with inbox:send', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $thread = apiLinkedThread($user, $mailbox);
    $token = apiToken($user, [TokenScope::InboxSend->value]);

    $this->withToken($token)
        ->postJson('/api/v1/threads/'.$thread->id.'/done')
        ->assertOk()
        ->assertJsonPath('data.state', 'done');

    $open = apiLinkedThread($user, $mailbox);
    $this->withToken($token)
        ->postJson('/api/v1/threads/'.$open->id.'/classification', ['action' => 'not_now'])
        ->assertOk()
        ->assertJsonPath('data.action', 'not_now');
});

it('rejects thread state changes when the token only has inbox:read', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $thread = apiLinkedThread($user, $mailbox);

    $this->withToken(apiToken($user, [TokenScope::InboxRead->value]))
        ->postJson('/api/v1/threads/'.$thread->id.'/done')
        ->assertForbidden()
        ->assertJsonPath('error.type', 'insufficient_scope');
});

it('replies to a thread and composes a message', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $thread = apiLinkedThread($user, $mailbox);
    $token = apiToken($user, [TokenScope::InboxSend->value]);

    $this->withToken($token)
        ->postJson('/api/v1/threads/'.$thread->id.'/reply', [
            'body' => 'Thanks for the note.',
            'client_operation_id' => (string) Str::uuid(),
        ])
        ->assertOk()
        ->assertJsonPath('sent', true);

    $this->withToken($token)
        ->postJson('/api/v1/messages', [
            'mailbox_id' => $mailbox->id,
            'to' => 'maya@stripe.com',
            'subject' => 'Hello',
            'body' => 'Quick note',
            'client_operation_id' => (string) Str::uuid(),
        ])
        ->assertCreated()
        ->assertJsonPath('sent', true);
});

it('returns a validation error when composing without a body', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);

    $this->withToken(apiToken($user, [TokenScope::InboxSend->value]))
        ->postJson('/api/v1/messages', [
            'mailbox_id' => $mailbox->id,
            'to' => 'maya@stripe.com',
            'subject' => 'Hello',
            'client_operation_id' => (string) Str::uuid(),
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.type', 'validation_error');
});

it('rejects reusing a client operation ID for a different message', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $token = apiToken($user, [TokenScope::InboxSend->value]);
    $operationId = (string) Str::uuid();

    $this->withToken($token)
        ->postJson('/api/v1/messages', [
            'mailbox_id' => $mailbox->id,
            'to' => 'maya@stripe.com',
            'subject' => 'Hello',
            'body' => 'Original body',
            'client_operation_id' => $operationId,
        ])
        ->assertCreated();

    $this->withToken($token)
        ->postJson('/api/v1/messages', [
            'mailbox_id' => $mailbox->id,
            'to' => 'maya@stripe.com',
            'subject' => 'Hello',
            'body' => 'Different body',
            'client_operation_id' => $operationId,
        ])
        ->assertConflict()
        ->assertJsonPath('error.type', 'client_operation_conflict');
});

it('does not report an unconfirmed compose attempt as sent', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $token = apiToken($user, [TokenScope::InboxSend->value]);
    $operationId = (string) Str::uuid();
    $payload = [
        'mailbox_id' => $mailbox->id,
        'to' => 'maya@stripe.com',
        'subject' => 'Hello',
        'body' => 'Original body',
        'client_operation_id' => $operationId,
    ];

    $this->withToken($token)->postJson('/api/v1/messages', $payload)->assertCreated();

    SendAttempt::query()->where('client_operation_id', $operationId)->firstOrFail()
        ->forceFill(['status' => SendAttemptStatus::Sending])->save();

    $this->withToken($token)
        ->postJson('/api/v1/messages', $payload)
        ->assertConflict()
        ->assertJsonPath('error.type', 'send_not_confirmed')
        ->assertJsonMissing(['sent' => true]);
});

it('validates mailbox IDs only within the token workspace', function () {
    $user = User::factory()->create();
    $otherMailbox = Mailbox::factory()->create();
    $token = apiToken($user, [TokenScope::InboxSend->value]);
    $payload = [
        'to' => 'maya@stripe.com',
        'subject' => 'Hello',
        'body' => 'Original body',
        'client_operation_id' => (string) Str::uuid(),
    ];

    $this->withToken($token)
        ->postJson('/api/v1/messages', ['mailbox_id' => $otherMailbox->id, ...$payload])
        ->assertNotFound()
        ->assertJsonPath('error.type', 'not_found');
    $this->withToken($token)
        ->postJson('/api/v1/messages', ['mailbox_id' => PHP_INT_MAX, ...$payload])
        ->assertNotFound()
        ->assertJsonPath('error.type', 'not_found');
});

it('hides another workspace thread', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $owner->id]);
    $thread = apiLinkedThread($owner, $mailbox);

    $this->withToken(apiToken($intruder, [TokenScope::InboxRead->value]))
        ->getJson('/api/v1/threads/'.$thread->id)
        ->assertForbidden();
});
