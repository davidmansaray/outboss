<?php

use App\Domain\Mail\Enums\SendAttemptKind;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Providers\FakeMailProvider;
use App\Domain\Mail\Services\ComposeMessageService;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\MessageEvent;
use App\Models\SendAttempt;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

it('returns the same gif for valid and invalid tracking tokens and records the first open', function () {
    $recipient = CampaignRecipient::factory()->create();
    $message = Message::factory()->outbound()->create([
        'campaign_recipient_id' => $recipient->id,
        'mailbox_id' => $recipient->mailbox_id,
        'tracking_token' => (string) Str::uuid(),
    ]);
    SendAttempt::factory()->create([
        'message_id' => $message->id,
        'mailbox_id' => $message->mailbox_id,
        'campaign_recipient_id' => $recipient->id,
        'kind' => SendAttemptKind::Campaign,
    ]);

    $first = $this->get('/t/'.$message->tracking_token.'.gif');
    $missing = $this->get('/t/'.Str::uuid().'.gif');

    expect($first->status())->toBe(200)
        ->and($missing->status())->toBe(200)
        ->and($first->headers->get('content-type'))->toBe('image/gif')
        ->and($first->getContent())->toBe($missing->getContent())
        ->and($recipient->fresh()->first_opened_at)->not->toBeNull()
        ->and(MessageEvent::query()->where('message_id', $message->id)->count())->toBe(1);

    $this->get('/t/'.$message->tracking_token.'.gif');
    expect(MessageEvent::query()->where('message_id', $message->id)->count())->toBe(1);
});

it('sends an ad-hoc compose from the selected mailbox and is idempotent', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $operation = (string) Str::uuid();

    $this->actingAs($user)
        ->post(route('messages.store'), [
            'mailbox_id' => $mailbox->id,
            'to' => 'maya@stripe.com',
            'subject' => 'Hello',
            'body' => 'Quick note',
            'client_operation_id' => $operation,
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('messages.store'), [
            'mailbox_id' => $mailbox->id,
            'to' => 'maya@stripe.com',
            'subject' => 'Hello',
            'body' => 'Quick note',
            'client_operation_id' => $operation,
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('messages.store'), [
            'mailbox_id' => $mailbox->id,
            'to' => 'maya@stripe.com',
            'subject' => 'Hello',
            'body' => 'A different note',
            'client_operation_id' => $operation,
        ])
        ->assertSessionHasErrors('client_operation_id');

    expect(SendAttempt::query()->where('kind', SendAttemptKind::Compose)->count())->toBe(1);
});

it('rejects an invalid operation id before touching the database', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);

    expect(fn () => app(ComposeMessageService::class)->handle(
        $user,
        $mailbox,
        'maya@example.test',
        'Subject',
        'Body',
        str_repeat('x', 64),
    ))->toThrow(ValidationException::class);

    expect(SendAttempt::query()->count())->toBe(0);
});

it('reports provider submission separately from confirmed delivery', function () {
    FakeMailProvider::$sendResultPhase = 'send_submitted';
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);

    $attempt = app(ComposeMessageService::class)->handle(
        $user,
        $mailbox,
        'maya@example.test',
        'Subject',
        'Body',
        (string) Str::uuid(),
    );

    expect($attempt->status)->toBe(SendAttemptStatus::ProviderAccepted)
        ->and($attempt->provider_phase)->toBe('send_submitted')
        ->and($attempt->provider_accepted_at)->not->toBeNull()
        ->and($attempt->sent_at)->toBeNull()
        ->and($attempt->message->status->value)->toBe('provider_accepted')
        ->and($attempt->message->sent_at)->toBeNull();
});

it('does not retry an ambiguous provider failure with the same compose operation', function () {
    FakeMailProvider::reset();
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $operation = (string) Str::uuid();
    $payload = [
        'mailbox_id' => $mailbox->id,
        'to' => 'maya@stripe.com',
        'subject' => 'Hello',
        'body' => 'Quick note',
        'client_operation_id' => $operation,
    ];

    FakeMailProvider::$timeoutAfterSend = true;
    $this->actingAs($user)->post(route('messages.store'), $payload)->assertServerError();

    $attempt = SendAttempt::query()->where('client_operation_id', $operation)->firstOrFail();
    expect($attempt->status)->toBe(SendAttemptStatus::Unknown);

    FakeMailProvider::$timeoutAfterSend = false;
    $this->actingAs($user)
        ->post(route('messages.store'), $payload)
        ->assertSessionHasErrors('client_operation_id');

    expect($attempt->fresh()->status)->toBe(SendAttemptStatus::Unknown)
        ->and(SendAttempt::query()->where('client_operation_id', $operation)->count())->toBe(1)
        ->and(FakeMailProvider::$store['sends'])->toHaveCount(1);
});
