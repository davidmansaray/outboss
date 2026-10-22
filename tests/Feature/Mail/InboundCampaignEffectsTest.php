<?php

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Mail\DTOs\RemoteMessage;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Enums\ThreadClassification;
use App\Domain\Mail\Enums\ThreadLocalState;
use App\Domain\Mail\Services\UpsertRemoteMessage;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\SendAttempt;
use App\Models\Thread;

function campaignEffectsRecipient(Mailbox $mailbox, string $email): CampaignRecipient
{
    $campaign = Campaign::factory()->create(['user_id' => $mailbox->user_id]);

    return CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'email_address' => $email,
        'email_normalized' => strtolower($email),
        'status' => RecipientStatus::Active,
        'next_send_at' => now()->addDay(),
    ]);
}

function campaignEffectsAttempt(CampaignRecipient $recipient): SendAttempt
{
    $thread = Thread::factory()->create([
        'user_id' => $recipient->campaign->user_id,
        'mailbox_id' => $recipient->mailbox_id,
        'campaign_recipient_id' => $recipient->id,
    ]);
    $message = Message::factory()->outbound()->create([
        'thread_id' => $thread->id,
        'mailbox_id' => $recipient->mailbox_id,
        'campaign_recipient_id' => $recipient->id,
    ]);

    return SendAttempt::factory()->create([
        'user_id' => $recipient->campaign->user_id,
        'mailbox_id' => $recipient->mailbox_id,
        'message_id' => $message->id,
        'campaign_recipient_id' => $recipient->id,
        'status' => SendAttemptStatus::Scheduled,
    ]);
}

/**
 * @param  list<string>  $references
 * @param  array<string, string>  $headers
 */
function campaignEffectsRemote(
    string $remoteMessageId,
    string $from,
    ?string $remoteThreadId = null,
    ?string $internetMessageId = null,
    ?string $inReplyTo = null,
    array $references = [],
    string $subject = 'Re: hiring',
    string $body = 'Yes, let us talk.',
    array $headers = [],
    ?DateTimeInterface $occurredAt = null,
    ?string $fromName = 'Sender',
): RemoteMessage {
    return new RemoteMessage(
        remoteMessageId: $remoteMessageId,
        remoteThreadId: $remoteThreadId,
        direction: MessageDirection::Inbound,
        subject: $subject,
        bodyText: $body,
        bodyHtml: '<p>'.e($body).'</p>',
        fromEmail: $from,
        fromName: $fromName,
        internetMessageId: $internetMessageId,
        inReplyTo: $inReplyTo,
        references: $references,
        occurredAt: $occurredAt ?? now(),
        headers: $headers,
    );
}

it('leaves unlinked personal mail unknown and open', function () {
    $mailbox = Mailbox::factory()->create();
    $recipient = campaignEffectsRecipient($mailbox, 'lead@example.com');

    $message = app(UpsertRemoteMessage::class)->handle(
        $mailbox,
        campaignEffectsRemote('personal-1', 'friend@example.com', 'personal-thread'),
    );

    expect($message->classification)->toBe(ThreadClassification::Unknown)
        ->and($message->campaign_recipient_id)->toBeNull()
        ->and($message->thread->classification)->toBe(ThreadClassification::Unknown)
        ->and($message->thread->local_state)->toBe(ThreadLocalState::Open)
        ->and($recipient->fresh()->status)->toBe(RecipientStatus::Active);
});

it('classifies a linked reply as human and stops the recipient idempotently', function () {
    $mailbox = Mailbox::factory()->create();
    $recipient = campaignEffectsRecipient($mailbox, 'maya@stripe.com');
    $attempt = campaignEffectsAttempt($recipient);
    $remote = campaignEffectsRemote('reply-1', 'MAYA@STRIPE.COM', 'reply-thread');

    $message = app(UpsertRemoteMessage::class)->handle($mailbox, $remote);
    app(UpsertRemoteMessage::class)->handle($mailbox, $remote);

    expect($message->classification)->toBe(ThreadClassification::Human)
        ->and($message->campaign_recipient_id)->toBe($recipient->id)
        ->and($message->thread->classification)->toBe(ThreadClassification::Human)
        ->and($message->thread->local_state)->toBe(ThreadLocalState::NeedsAction)
        ->and($recipient->fresh()->status)->toBe(RecipientStatus::Replied)
        ->and($recipient->fresh()->stop_reason)->toBe('human_reply')
        ->and($recipient->fresh()->next_send_at)->toBeNull()
        ->and($attempt->fresh()->status)->toBe(SendAttemptStatus::Cancelled)
        ->and(Message::query()->where('mailbox_id', $mailbox->id)->where('remote_message_id', 'reply-1')->count())->toBe(1);
});

it('preserves a read and done thread when overlap polling replays a terminal reply', function () {
    $mailbox = Mailbox::factory()->create();
    $recipient = campaignEffectsRecipient($mailbox, 'maya@stripe.com');
    $remote = campaignEffectsRemote('done-replay-1', 'maya@stripe.com', 'done-replay-thread');

    $message = app(UpsertRemoteMessage::class)->handle($mailbox, $remote);
    expect($message->thread->is_unread)->toBeTrue();

    $message->thread->forceFill([
        'local_state' => ThreadLocalState::Done,
        'is_unread' => false,
    ])->save();

    $replayed = app(UpsertRemoteMessage::class)->handle($mailbox, $remote);

    expect($recipient->fresh()->status)->toBe(RecipientStatus::Replied)
        ->and($replayed->thread->local_state)->toBe(ThreadLocalState::Done)
        ->and($replayed->thread->is_unread)->toBeFalse()
        ->and($replayed->thread->classification)->toBe(ThreadClassification::Human);
});

it('reopens a done thread for a genuinely new second human reply', function () {
    $mailbox = Mailbox::factory()->create();
    $recipient = campaignEffectsRecipient($mailbox, 'maya@stripe.com');
    $firstAt = now()->startOfSecond();

    $first = app(UpsertRemoteMessage::class)->handle($mailbox, campaignEffectsRemote(
        remoteMessageId: 'first-human-reply',
        from: 'maya@stripe.com',
        remoteThreadId: 'second-reply-thread',
        occurredAt: $firstAt,
    ));
    $first->thread->forceFill([
        'local_state' => ThreadLocalState::Done,
        'is_unread' => false,
    ])->save();

    $second = app(UpsertRemoteMessage::class)->handle($mailbox, campaignEffectsRemote(
        remoteMessageId: 'second-human-reply',
        from: 'maya@stripe.com',
        remoteThreadId: 'second-reply-thread',
        occurredAt: $firstAt->copy()->addMinute(),
    ));

    expect($recipient->fresh()->status)->toBe(RecipientStatus::Replied)
        ->and($second->classification)->toBe(ThreadClassification::Human)
        ->and($second->thread->local_state)->toBe(ThreadLocalState::NeedsAction)
        ->and($second->thread->is_unread)->toBeTrue();
});

it('preserves done and read state when an unlinked bounce is replayed', function () {
    $mailbox = Mailbox::factory()->create();
    $remote = campaignEffectsRemote(
        remoteMessageId: 'unlinked-bounce-replay',
        from: 'mailer-daemon@example.test',
        remoteThreadId: 'unlinked-bounce-thread',
        subject: 'Undeliverable',
        body: 'Diagnostic-Code: smtp; 5.1.1 user unknown',
    );

    $message = app(UpsertRemoteMessage::class)->handle($mailbox, $remote);
    $message->thread->forceFill([
        'local_state' => ThreadLocalState::Done,
        'is_unread' => false,
    ])->save();

    $replayed = app(UpsertRemoteMessage::class)->handle($mailbox, $remote);

    expect($replayed->campaign_recipient_id)->toBeNull()
        ->and($replayed->classification)->toBe(ThreadClassification::Bounce)
        ->and($replayed->thread->local_state)->toBe(ThreadLocalState::Done)
        ->and($replayed->thread->is_unread)->toBeFalse();
});

it('reuses the existing local thread when a replay has no provider thread id', function () {
    $mailbox = Mailbox::factory()->create();
    $remote = campaignEffectsRemote('no-thread-id-1', 'friend@example.com');

    $first = app(UpsertRemoteMessage::class)->handle($mailbox, $remote);
    $second = app(UpsertRemoteMessage::class)->handle($mailbox, $remote);

    expect($second->thread_id)->toBe($first->thread_id)
        ->and(Thread::query()->where('mailbox_id', $mailbox->id)->count())->toBe(1)
        ->and(Message::query()->where('mailbox_id', $mailbox->id)->count())->toBe(1);
});

it('keeps the newest thread preview when provider rows arrive newest first', function () {
    $mailbox = Mailbox::factory()->create();
    $newestAt = now()->startOfSecond();

    $newest = app(UpsertRemoteMessage::class)->handle($mailbox, campaignEffectsRemote(
        remoteMessageId: 'preview-newest-1',
        from: 'newest@example.com',
        remoteThreadId: 'preview-thread',
        subject: 'Newest subject',
        body: 'Newest preview body',
        occurredAt: $newestAt,
        fromName: 'Newest Sender',
    ));
    app(UpsertRemoteMessage::class)->handle($mailbox, campaignEffectsRemote(
        remoteMessageId: 'preview-older-1',
        from: 'older@example.com',
        remoteThreadId: 'preview-thread',
        subject: 'Older subject',
        body: 'Older preview body',
        occurredAt: $newestAt->copy()->subDay(),
        fromName: 'Older Sender',
    ));

    $thread = $newest->thread->fresh();
    expect($thread->subject)->toBe('Newest subject')
        ->and($thread->snippet)->toBe('Newest preview body')
        ->and($thread->participant_name)->toBe('Newest Sender')
        ->and($thread->participant_email)->toBe('newest@example.com')
        ->and($thread->last_message_at?->equalTo($newestAt))->toBeTrue()
        ->and($thread->messages->pluck('remote_message_id')->all())
        ->toBe(['preview-older-1', 'preview-newest-1']);
});

it('does not let an older automated response demote a newer human thread', function (
    string $from,
    string $subject,
    string $body,
    array $headers,
    ThreadClassification $expectedClassification,
) {
    $mailbox = Mailbox::factory()->create();
    $recipient = campaignEffectsRecipient($mailbox, 'maya@stripe.com');
    $newestAt = now()->startOfSecond();

    $human = app(UpsertRemoteMessage::class)->handle($mailbox, campaignEffectsRemote(
        remoteMessageId: 'newest-human-before-automated',
        from: 'maya@stripe.com',
        remoteThreadId: 'classification-chronology-thread',
        occurredAt: $newestAt,
    ));
    $automated = app(UpsertRemoteMessage::class)->handle($mailbox, campaignEffectsRemote(
        remoteMessageId: 'older-automated-'.$expectedClassification->value,
        from: $from,
        remoteThreadId: 'classification-chronology-thread',
        subject: $subject,
        body: $body,
        headers: $headers,
        occurredAt: $newestAt->copy()->subDay(),
    ));

    expect($automated->classification)->toBe($expectedClassification)
        ->and($human->thread->fresh()->classification)->toBe(ThreadClassification::Human)
        ->and($human->thread->fresh()->local_state)->toBe(ThreadLocalState::NeedsAction)
        ->and($recipient->fresh()->status)->toBe(RecipientStatus::Replied);
})->with([
    'out of office' => [
        'maya@stripe.com',
        'Automatic reply: hiring',
        'I am out of the office.',
        ['Auto-Submitted' => 'auto-replied'],
        ThreadClassification::OutOfOffice,
    ],
    'bounce' => [
        'mailer-daemon@example.test',
        'Undeliverable',
        'Diagnostic-Code: smtp; 5.1.1 user unknown',
        [],
        ThreadClassification::Bounce,
    ],
]);

it('links a reply through an existing provider thread', function () {
    $mailbox = Mailbox::factory()->create();
    $recipient = campaignEffectsRecipient($mailbox, 'original@example.com');
    Thread::factory()->create([
        'user_id' => $mailbox->user_id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
        'remote_thread_id' => 'provider-thread-1',
    ]);

    $message = app(UpsertRemoteMessage::class)->handle(
        $mailbox,
        campaignEffectsRemote('thread-reply-1', 'alternate@example.com', 'provider-thread-1'),
    );

    expect($message->campaign_recipient_id)->toBe($recipient->id)
        ->and($message->classification)->toBe(ThreadClassification::Human)
        ->and($recipient->fresh()->status)->toBe(RecipientStatus::Replied);
});

it('keeps an established same-mailbox thread recipient ahead of conflicting references', function () {
    $mailbox = Mailbox::factory()->create();
    $threadRecipient = campaignEffectsRecipient($mailbox, 'thread-recipient@example.com');
    $referencedRecipient = campaignEffectsRecipient($mailbox, 'referenced-recipient@example.com');
    Thread::factory()->create([
        'user_id' => $mailbox->user_id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $threadRecipient->id,
        'remote_thread_id' => 'established-provider-thread',
    ]);
    $parentThread = Thread::factory()->create([
        'user_id' => $mailbox->user_id,
        'mailbox_id' => $mailbox->id,
    ]);
    Message::factory()->outbound()->create([
        'thread_id' => $parentThread->id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $referencedRecipient->id,
        'internet_message_id' => 'conflicting-parent@example.test',
    ]);

    $message = app(UpsertRemoteMessage::class)->handle($mailbox, campaignEffectsRemote(
        remoteMessageId: 'established-thread-conflict',
        from: 'alternate@example.test',
        remoteThreadId: 'established-provider-thread',
        references: ['conflicting-parent@example.test'],
    ));

    expect($message->campaign_recipient_id)->toBe($threadRecipient->id)
        ->and($message->thread->campaign_recipient_id)->toBe($threadRecipient->id)
        ->and($threadRecipient->fresh()->status)->toBe(RecipientStatus::Replied)
        ->and($referencedRecipient->fresh()->status)->toBe(RecipientStatus::Active);
});

it('links by normalized in-reply-to before an ambiguous sender address', function () {
    $mailbox = Mailbox::factory()->create();
    $recipient = campaignEffectsRecipient($mailbox, 'shared@example.com');
    $other = campaignEffectsRecipient($mailbox, 'shared@example.com');
    $parentThread = Thread::factory()->create([
        'user_id' => $mailbox->user_id,
        'mailbox_id' => $mailbox->id,
    ]);
    Message::factory()->outbound()->create([
        'thread_id' => $parentThread->id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
        'internet_message_id' => '<ABC@Outboss.Local>',
    ]);

    $message = app(UpsertRemoteMessage::class)->handle($mailbox, campaignEffectsRemote(
        remoteMessageId: 'header-reply-1',
        from: 'shared@example.com',
        remoteThreadId: 'new-provider-thread',
        internetMessageId: ' <INBOUND@Example.COM> ',
        inReplyTo: ' abc@outboss.local ',
        references: [' <OLDER@Example.COM> '],
    ));

    expect($message->campaign_recipient_id)->toBe($recipient->id)
        ->and($message->classification)->toBe(ThreadClassification::Human)
        ->and($message->internet_message_id)->toBe('inbound@example.com')
        ->and($message->in_reply_to)->toBe('abc@outboss.local')
        ->and($message->references)->toBe(['older@example.com'])
        ->and($recipient->fresh()->status)->toBe(RecipientStatus::Replied)
        ->and($other->fresh()->status)->toBe(RecipientStatus::Active);
});

it('links by references when in-reply-to is missing', function () {
    $mailbox = Mailbox::factory()->create();
    $recipient = campaignEffectsRecipient($mailbox, 'reference@example.com');
    $parentThread = Thread::factory()->create([
        'user_id' => $mailbox->user_id,
        'mailbox_id' => $mailbox->id,
    ]);
    Message::factory()->outbound()->create([
        'thread_id' => $parentThread->id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
        'internet_message_id' => '<PARENT@OUTBOSS.LOCAL>',
    ]);

    $message = app(UpsertRemoteMessage::class)->handle($mailbox, campaignEffectsRemote(
        remoteMessageId: 'reference-reply-1',
        from: 'different@example.com',
        remoteThreadId: 'reference-thread',
        references: ['<older@example.com>', ' parent@outboss.local '],
    ));

    expect($message->campaign_recipient_id)->toBe($recipient->id)
        ->and($message->classification)->toBe(ThreadClassification::Human)
        ->and($recipient->fresh()->status)->toBe(RecipientStatus::Replied);
});

it('does not link when two recent recipients share the sender address', function () {
    $mailbox = Mailbox::factory()->create();
    $first = campaignEffectsRecipient($mailbox, 'shared@example.com');
    $second = campaignEffectsRecipient($mailbox, 'shared@example.com');

    $message = app(UpsertRemoteMessage::class)->handle(
        $mailbox,
        campaignEffectsRemote('ambiguous-1', 'shared@example.com', 'ambiguous-thread'),
    );

    expect($message->campaign_recipient_id)->toBeNull()
        ->and($message->classification)->toBe(ThreadClassification::Unknown)
        ->and($message->thread->local_state)->toBe(ThreadLocalState::Open)
        ->and($first->fresh()->status)->toBe(RecipientStatus::Active)
        ->and($second->fresh()->status)->toBe(RecipientStatus::Active);
});

it('does not link by headers or email across mailboxes', function () {
    $mailbox = Mailbox::factory()->create();
    $otherMailbox = Mailbox::factory()->create(['user_id' => $mailbox->user_id]);
    $otherRecipient = campaignEffectsRecipient($otherMailbox, 'cross@example.com');
    $otherThread = Thread::factory()->create([
        'user_id' => $otherMailbox->user_id,
        'mailbox_id' => $otherMailbox->id,
    ]);
    Message::factory()->outbound()->create([
        'thread_id' => $otherThread->id,
        'mailbox_id' => $otherMailbox->id,
        'campaign_recipient_id' => $otherRecipient->id,
        'internet_message_id' => '<CROSS@OUTBOSS.LOCAL>',
    ]);

    $message = app(UpsertRemoteMessage::class)->handle($mailbox, campaignEffectsRemote(
        remoteMessageId: 'cross-mailbox-1',
        from: 'cross@example.com',
        remoteThreadId: 'cross-mailbox-thread',
        inReplyTo: 'cross@outboss.local',
    ));

    expect($message->campaign_recipient_id)->toBeNull()
        ->and($message->classification)->toBe(ThreadClassification::Unknown)
        ->and($otherRecipient->fresh()->status)->toBe(RecipientStatus::Active);
});

it('does not trust an existing thread recipient from another mailbox', function () {
    $mailbox = Mailbox::factory()->create();
    $otherMailbox = Mailbox::factory()->create(['user_id' => $mailbox->user_id]);
    $otherRecipient = campaignEffectsRecipient($otherMailbox, 'other@example.com');
    Thread::factory()->create([
        'user_id' => $mailbox->user_id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $otherRecipient->id,
        'remote_thread_id' => 'malformed-cross-mailbox-thread',
    ]);

    $message = app(UpsertRemoteMessage::class)->handle(
        $mailbox,
        campaignEffectsRemote('malformed-cross-mailbox-1', 'friend@example.com', 'malformed-cross-mailbox-thread'),
    );

    expect($message->campaign_recipient_id)->toBeNull()
        ->and($message->classification)->toBe(ThreadClassification::Unknown)
        ->and($otherRecipient->fresh()->status)->toBe(RecipientStatus::Active);
});

it('does not let a sent-folder upsert erase human reply triage', function () {
    $mailbox = Mailbox::factory()->create();
    $recipient = campaignEffectsRecipient($mailbox, 'maya@stripe.com');
    $replyAt = now()->startOfSecond();
    $message = app(UpsertRemoteMessage::class)->handle(
        $mailbox,
        campaignEffectsRemote(
            remoteMessageId: 'human-before-sent-1',
            from: 'maya@stripe.com',
            remoteThreadId: 'shared-provider-thread',
            subject: 'Newest reply',
            body: 'Newest reply preview',
            occurredAt: $replyAt,
            fromName: 'Maya',
        ),
    );

    app(UpsertRemoteMessage::class)->handle($mailbox, new RemoteMessage(
        remoteMessageId: 'older-sent-1',
        remoteThreadId: 'shared-provider-thread',
        direction: MessageDirection::Outbound,
        subject: 'Hiring',
        bodyText: 'Original campaign email',
        bodyHtml: '<p>Original campaign email</p>',
        fromEmail: $mailbox->email_address,
        fromName: $mailbox->display_name,
        to: [['email' => 'maya@stripe.com']],
        occurredAt: $replyAt->copy()->subDay(),
    ));

    $thread = $message->thread->fresh();
    expect($thread->classification)->toBe(ThreadClassification::Human)
        ->and($thread->local_state)->toBe(ThreadLocalState::NeedsAction)
        ->and($thread->subject)->toBe('Newest reply')
        ->and($thread->snippet)->toBe('Newest reply preview')
        ->and($thread->participant_name)->toBe('Maya')
        ->and($thread->last_message_at?->equalTo($replyAt))->toBeTrue()
        ->and($recipient->fresh()->status)->toBe(RecipientStatus::Replied);
});

it('keeps the external correspondent when a newer outbound message updates the preview', function () {
    $mailbox = Mailbox::factory()->create(['email_address' => 'owner@example.test']);
    $inboundAt = now()->startOfSecond();
    $message = app(UpsertRemoteMessage::class)->handle($mailbox, campaignEffectsRemote(
        remoteMessageId: 'participant-inbound-1',
        from: 'maya@example.test',
        remoteThreadId: 'participant-mixed-thread',
        occurredAt: $inboundAt,
        fromName: 'Maya',
    ));

    app(UpsertRemoteMessage::class)->handle($mailbox, new RemoteMessage(
        remoteMessageId: 'participant-outbound-1',
        remoteThreadId: 'participant-mixed-thread',
        direction: MessageDirection::Outbound,
        subject: 'Re: hiring',
        bodyText: 'Thanks Maya',
        bodyHtml: '<p>Thanks Maya</p>',
        fromEmail: 'owner@example.test',
        fromName: 'Owner',
        to: [['email' => 'maya@example.test', 'name' => 'Maya Recipient']],
        occurredAt: $inboundAt->copy()->addMinute(),
    ));

    $thread = $message->thread->fresh();
    expect($thread->participant_name)->toBe('Maya')
        ->and($thread->participant_email)->toBe('maya@example.test')
        ->and($thread->snippet)->toBe('Thanks Maya');
});

it('uses the first valid outbound recipient as the correspondent for a sent-only thread', function () {
    $mailbox = Mailbox::factory()->create(['email_address' => 'owner@example.test']);

    $message = app(UpsertRemoteMessage::class)->handle($mailbox, new RemoteMessage(
        remoteMessageId: 'participant-sent-only-1',
        remoteThreadId: 'participant-sent-only-thread',
        direction: MessageDirection::Outbound,
        subject: 'Introduction',
        bodyText: 'Hello Maya',
        bodyHtml: '<p>Hello Maya</p>',
        fromEmail: 'owner@example.test',
        fromName: 'Owner',
        to: [
            ['email' => 'not-an-email', 'name' => 'Invalid'],
            ['email' => ' MAYA@EXAMPLE.TEST ', 'name' => ' Maya Chen '],
        ],
    ));

    expect($message->thread->participant_name)->toBe('Maya Chen')
        ->and($message->thread->participant_email)->toBe('maya@example.test');
});

it('updates a sent-only correspondent when an inbound reply arrives and replays idempotently', function () {
    $mailbox = Mailbox::factory()->create(['email_address' => 'owner@example.test']);
    $sentAt = now()->startOfSecond();
    $sent = app(UpsertRemoteMessage::class)->handle($mailbox, new RemoteMessage(
        remoteMessageId: 'participant-before-reply-1',
        remoteThreadId: 'participant-reply-thread',
        direction: MessageDirection::Outbound,
        subject: 'Introduction',
        bodyText: 'Hello',
        bodyHtml: null,
        fromEmail: 'owner@example.test',
        fromName: 'Owner',
        to: [['email' => 'maya@example.test']],
        occurredAt: $sentAt,
    ));
    $reply = campaignEffectsRemote(
        remoteMessageId: 'participant-reply-1',
        from: 'reply-alias@example.test',
        remoteThreadId: 'participant-reply-thread',
        occurredAt: $sentAt->copy()->addMinute(),
        fromName: 'Maya Reply Alias',
    );

    app(UpsertRemoteMessage::class)->handle($mailbox, $reply);
    app(UpsertRemoteMessage::class)->handle($mailbox, $reply);

    expect($sent->thread->fresh()->participant_name)->toBe('Maya Reply Alias')
        ->and($sent->thread->fresh()->participant_email)->toBe('reply-alias@example.test')
        ->and($sent->thread->messages()->count())->toBe(2);
});

it('does not replace a known correspondent when outbound recipients are malformed', function () {
    $mailbox = Mailbox::factory()->create(['email_address' => 'owner@example.test']);
    $inboundAt = now()->startOfSecond();
    $message = app(UpsertRemoteMessage::class)->handle($mailbox, campaignEffectsRemote(
        remoteMessageId: 'participant-known-inbound-1',
        from: 'maya@example.test',
        remoteThreadId: 'participant-malformed-thread',
        occurredAt: $inboundAt,
        fromName: 'Maya',
    ));

    app(UpsertRemoteMessage::class)->handle($mailbox, new RemoteMessage(
        remoteMessageId: 'participant-malformed-outbound-1',
        remoteThreadId: 'participant-malformed-thread',
        direction: MessageDirection::Outbound,
        subject: 'Re: hiring',
        bodyText: 'Following up',
        bodyHtml: null,
        fromEmail: 'owner@example.test',
        fromName: 'Owner',
        to: [['email' => 'not-an-email']],
        occurredAt: $inboundAt->copy()->addMinute(),
    ));

    expect($message->thread->fresh()->participant_name)->toBe('Maya')
        ->and($message->thread->fresh()->participant_email)->toBe('maya@example.test');
});

it('keeps out-of-office from stopping the sequence after linking', function () {
    $mailbox = Mailbox::factory()->create();
    $recipient = campaignEffectsRecipient($mailbox, 'maya@stripe.com');
    $attempt = campaignEffectsAttempt($recipient);

    $message = app(UpsertRemoteMessage::class)->handle($mailbox, campaignEffectsRemote(
        remoteMessageId: 'ooo-1',
        from: 'maya@stripe.com',
        remoteThreadId: 'ooo-thread',
        subject: 'Automatic reply: hiring',
        body: 'I am out of the office.',
        headers: ['Auto-Submitted' => 'auto-replied'],
    ));

    expect($message->campaign_recipient_id)->toBe($recipient->id)
        ->and($message->classification)->toBe(ThreadClassification::OutOfOffice)
        ->and($message->thread->classification)->toBe(ThreadClassification::OutOfOffice)
        ->and($message->thread->local_state)->toBe(ThreadLocalState::OutOfOffice)
        ->and($recipient->fresh()->status)->toBe(RecipientStatus::Active)
        ->and($attempt->fresh()->status)->toBe(SendAttemptStatus::Scheduled);
});

it('keeps the production automatic replies out of leads without stopping their campaigns', function (string $subject, string $body) {
    $mailbox = Mailbox::factory()->create();
    $recipient = campaignEffectsRecipient($mailbox, 'automatic@example.com');
    $attempt = campaignEffectsAttempt($recipient);

    $message = app(UpsertRemoteMessage::class)->handle($mailbox, campaignEffectsRemote(
        remoteMessageId: 'production-automatic-'.md5($subject),
        from: 'automatic@example.com',
        remoteThreadId: 'production-automatic-thread-'.md5($subject),
        subject: $subject,
        body: $body,
    ));

    expect($message->classification)->toBe(ThreadClassification::OutOfOffice)
        ->and($message->thread->classification)->toBe(ThreadClassification::OutOfOffice)
        ->and($message->thread->local_state)->toBe(ThreadLocalState::OutOfOffice)
        ->and($recipient->fresh()->status)->toBe(RecipientStatus::Active)
        ->and($attempt->fresh()->status)->toBe(SendAttemptStatus::Scheduled);
})->with([
    'Toplife Italian automatic reply' => [
        'risposta automatica',
        'Buongiorno se ha scritto a questa mail per problemi tecnici o relativi a qualsiasi domande sui prodotti/servizi sul sito Toplifeproject.com non avrà alcuna risposta ma deve scrivere a support@toplifeproject.com.',
    ],
    'TOTM automatic acknowledgement' => [
        'Re: Is checkout working on TOTM?',
        'Thank you for emailing TOTM. We carefully review all messages received and will respond if your inquiry is something we can help with.',
    ],
]);

it('preserves unsubscribe effects for a linked inbound message', function () {
    $mailbox = Mailbox::factory()->create();
    $recipient = campaignEffectsRecipient($mailbox, 'maya@stripe.com');
    $attempt = campaignEffectsAttempt($recipient);

    $message = app(UpsertRemoteMessage::class)->handle($mailbox, campaignEffectsRemote(
        remoteMessageId: 'unsubscribe-1',
        from: 'maya@stripe.com',
        remoteThreadId: 'unsubscribe-thread',
        subject: 'Re: hiring',
        body: 'Unsubscribe',
    ));

    expect($message->classification)->toBe(ThreadClassification::Unsubscribe)
        ->and($message->thread->local_state)->toBe(ThreadLocalState::Problem)
        ->and($recipient->fresh()->status)->toBe(RecipientStatus::Unsubscribed)
        ->and($attempt->fresh()->status)->toBe(SendAttemptStatus::Cancelled);
});

it('preserves bounce effects for a linked provider thread', function () {
    $mailbox = Mailbox::factory()->create();
    $recipient = campaignEffectsRecipient($mailbox, 'maya@stripe.com');
    $attempt = campaignEffectsAttempt($recipient);
    Thread::factory()->create([
        'user_id' => $mailbox->user_id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
        'remote_thread_id' => 'bounce-thread',
    ]);

    $message = app(UpsertRemoteMessage::class)->handle($mailbox, campaignEffectsRemote(
        remoteMessageId: 'bounce-1',
        from: 'mailer-daemon@example.com',
        remoteThreadId: 'bounce-thread',
        subject: 'Undeliverable',
        body: 'Diagnostic-Code: smtp; 5.1.1 user unknown',
    ));

    expect($message->classification)->toBe(ThreadClassification::Bounce)
        ->and($message->thread->local_state)->toBe(ThreadLocalState::Problem)
        ->and($recipient->fresh()->status)->toBe(RecipientStatus::Bounced)
        ->and($attempt->fresh()->status)->toBe(SendAttemptStatus::Cancelled);
});
