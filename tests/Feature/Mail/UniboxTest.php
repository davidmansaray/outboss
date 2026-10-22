<?php

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Mail\DTOs\RemoteMessage;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\MessageStatus;
use App\Domain\Mail\Enums\ThreadClassification;
use App\Domain\Mail\Enums\ThreadLocalState;
use App\Domain\Mail\Providers\FakeMailProvider;
use App\Domain\Mail\Services\SyncMailboxService;
use App\Models\Account;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Person;
use App\Models\SendAttempt;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Support\Str;

function uniboxLinkedThread(User $user, Mailbox $mailbox, ?Campaign $campaign, ThreadLocalState $state = ThreadLocalState::NeedsAction): Thread
{
    $recipient = $campaign
        ? CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'mailbox_id' => $mailbox->id,
        ])
        : null;

    $thread = Thread::factory()->create([
        'user_id' => $user->id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient?->id,
        'local_state' => $state,
    ]);

    Message::factory()->create([
        'thread_id' => $thread->id,
        'mailbox_id' => $mailbox->id,
    ]);

    return $thread;
}

it('filters unibox threads by campaign and mailbox together and scopes folder counts', function () {
    $user = User::factory()->create();
    $firstBox = Mailbox::factory()->create(['user_id' => $user->id]);
    $secondBox = Mailbox::factory()->create(['user_id' => $user->id]);
    $sdr = Campaign::factory()->create(['user_id' => $user->id, 'name' => 'Q3 SDR hire']);
    $fintech = Campaign::factory()->create(['user_id' => $user->id, 'name' => 'Fintech founders']);
    $other = Campaign::factory()->create(['name' => 'Someone else']);

    $maya = uniboxLinkedThread($user, $firstBox, $sdr);
    uniboxLinkedThread($user, $secondBox, $sdr);
    uniboxLinkedThread($user, $firstBox, $fintech);
    uniboxLinkedThread($user, $firstBox, null);
    uniboxLinkedThread($user, $firstBox, $sdr, ThreadLocalState::OutOfOffice);

    $this->actingAs($user)
        ->get(route('unibox.index', ['folder' => 'needs']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('unibox/Index')
            ->where('campaign', 'all')
            ->where('mailbox', null)
            ->has('threads.data', 4)
            ->has('campaigns', 2)
            ->where('counts.needs', 4)
            ->where('counts.ooo', 1)
            ->where('counts.all_open', 5)
            ->missing('campaigns.2'));

    $this->actingAs($user)
        ->get(route('unibox.index', ['folder' => 'needs', 'campaign' => $sdr->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('unibox/Index')
            ->where('campaign', (string) $sdr->id)
            ->has('threads.data', 2)
            ->where('counts.needs', 2)
            ->where('counts.ooo', 1)
            ->where('counts.all_open', 3)
            ->where('counts.done', 0));

    $this->actingAs($user)
        ->get(route('unibox.index', [
            'folder' => 'needs',
            'campaign' => $sdr->id,
            'mailbox' => $firstBox->id,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('threads.data', 1)
            ->where('threads.data.0.id', $maya->id)
            ->where('mailbox', $firstBox->id)
            ->where('counts.needs', 1));

    $this->actingAs($user)
        ->get(route('unibox.index', ['folder' => 'needs', 'campaign' => 'none']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('campaign', 'none')
            ->has('threads.data', 1)
            ->where('threads.data.0.campaign_id', null));

    $this->actingAs($user)
        ->get(route('unibox.index', ['folder' => 'needs', 'campaign' => $other->id]))
        ->assertNotFound();
});

it('splits delivery issues and opt-outs into distinct unibox views', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $bounce = uniboxLinkedThread($user, $mailbox, null, ThreadLocalState::Problem);
    $bounce->forceFill(['classification' => ThreadClassification::Bounce])->save();
    $manualDeliveryProblem = uniboxLinkedThread($user, $mailbox, null, ThreadLocalState::Problem);
    $unsubscribe = uniboxLinkedThread($user, $mailbox, null, ThreadLocalState::Problem);
    $unsubscribe->forceFill(['classification' => ThreadClassification::Unsubscribe])->save();

    $this->actingAs($user)
        ->get(route('unibox.index', ['folder' => 'delivery']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('folder', 'delivery')
            ->where('counts.delivery', 2)
            ->where('counts.opt_outs', 1)
            ->where('counts.problems', 3)
            ->where('threads.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all()
                === collect([$bounce->id, $manualDeliveryProblem->id])->sort()->values()->all()));

    $this->actingAs($user)
        ->get(route('unibox.index', ['folder' => 'opt_outs']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('folder', 'opt_outs')
            ->has('threads.data', 1)
            ->where('threads.data.0.id', $unsubscribe->id));
});

it('treats active human replies and interested outcomes as leads', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);

    $newReply = uniboxLinkedThread($user, $mailbox, $campaign, ThreadLocalState::NeedsAction);
    $newReply->forceFill(['classification' => ThreadClassification::Human])->save();

    $interested = uniboxLinkedThread($user, $mailbox, $campaign, ThreadLocalState::Done);
    $interested->forceFill(['classification' => ThreadClassification::Human])->save();
    $interested->recipient->forceFill(['status' => RecipientStatus::Interested])->save();

    $wrongPerson = uniboxLinkedThread($user, $mailbox, $campaign, ThreadLocalState::Done);
    $wrongPerson->forceFill(['classification' => ThreadClassification::Human])->save();
    $wrongPerson->recipient->forceFill(['status' => RecipientStatus::WrongPerson])->save();

    uniboxLinkedThread($user, $mailbox, $campaign, ThreadLocalState::OutOfOffice);

    $this->actingAs($user)
        ->get(route('unibox.index', ['folder' => 'leads']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('counts.leads', 2)
            ->where('counts.all_replies', 3)
            ->where('counts.closed', 2)
            ->where('threads.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all()
                === collect([$newReply->id, $interested->id])->sort()->values()->all())
            ->where('threads.data', fn ($rows) => collect($rows)->keyBy('id')->get($interested->id)['recipient_status'] === RecipientStatus::Interested->value));

    $this->actingAs($user)
        ->get(route('unibox.index', ['folder' => 'all_replies']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('threads.data', 3)
            ->where('threads.data', fn ($rows) => collect($rows)->pluck('id')->contains($wrongPerson->id)));
});

it('lists only the authenticated user threads and keeps mailbox boundaries', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    uniboxLinkedThread($user, $mailbox, null);
    $otherMailbox = Mailbox::factory()->create(['user_id' => $other->id]);
    uniboxLinkedThread($other, $otherMailbox, null);

    $this->actingAs($user)
        ->get(route('unibox.index', ['folder' => 'needs']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('unibox/Index')
            ->has('threads.data', 1));
});

it('separates inbox workflow views from successfully sent conversations', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $inbound = uniboxLinkedThread($user, $mailbox, null, ThreadLocalState::NeedsAction);
    $inbound->forceFill(['classification' => ThreadClassification::Human])->save();
    $mixed = uniboxLinkedThread($user, $mailbox, null, ThreadLocalState::Done);
    $sentOnly = Thread::factory()->create([
        'user_id' => $user->id,
        'mailbox_id' => $mailbox->id,
        'local_state' => ThreadLocalState::Open,
    ]);

    Message::factory()->outbound()->create([
        'thread_id' => $mixed->id,
        'mailbox_id' => $mailbox->id,
        'status' => MessageStatus::Sent,
        'sent_at' => now(),
    ]);
    Message::factory()->outbound()->create([
        'thread_id' => $sentOnly->id,
        'mailbox_id' => $mailbox->id,
        'status' => MessageStatus::Sent,
        'sent_at' => now(),
    ]);
    foreach ([
        MessageStatus::Queued,
        MessageStatus::Sending,
        MessageStatus::ProviderAccepted,
        MessageStatus::Failed,
        MessageStatus::Unknown,
    ] as $status) {
        $ineligible = Thread::factory()->create([
            'user_id' => $user->id,
            'mailbox_id' => $mailbox->id,
            'local_state' => ThreadLocalState::Open,
        ]);
        Message::factory()->outbound()->create([
            'thread_id' => $ineligible->id,
            'mailbox_id' => $mailbox->id,
            'status' => $status,
        ]);
    }

    $this->actingAs($user)
        ->get(route('unibox.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('folder', 'all_open')
            ->has('threads.data', 1)
            ->where('threads.data.0.id', $inbound->id)
            ->where('counts.needs', 1)
            ->where('counts.open', 0)
            ->where('counts.done', 1)
            ->where('counts.all_open', 1)
            ->where('counts.sent', 2));

    $this->actingAs($user)
        ->get(route('unibox.index', ['folder' => 'sent']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('folder', 'sent')
            ->has('threads.data', 2)
            ->where('threads.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all()
                === collect([$mixed->id, $sentOnly->id])->sort()->values()->all())
            ->where('threads.data', fn ($rows) => collect($rows)->keyBy('id')->get($mixed->id)['has_inbound'] === true
                && collect($rows)->keyBy('id')->get($sentOnly->id)['has_inbound'] === false)
            ->where('counts.sent', 2));
});

it('returns complete chronological history and inbound eligibility for a mixed thread', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $thread = Thread::factory()->create([
        'user_id' => $user->id,
        'mailbox_id' => $mailbox->id,
        'participant_email' => 'maya@example.test',
        'last_message_at' => now(),
    ]);
    $inbound = Message::factory()->create([
        'thread_id' => $thread->id,
        'mailbox_id' => $mailbox->id,
        'remote_message_id' => 'received-first',
        'received_at' => now()->subMinute(),
    ]);
    $outbound = Message::factory()->outbound()->create([
        'thread_id' => $thread->id,
        'mailbox_id' => $mailbox->id,
        'remote_message_id' => 'sent-second',
        'status' => MessageStatus::Sent,
        'sent_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('threads.show', $thread))
        ->assertOk()
        ->assertJsonPath('thread.has_inbound', true)
        ->assertJsonPath('thread.participant_email', 'maya@example.test')
        ->assertJsonPath('thread.last_message_at', $thread->last_message_at->toIso8601String())
        ->assertJsonPath('thread.messages.0.id', $inbound->id)
        ->assertJsonPath('thread.messages.0.direction', MessageDirection::Inbound->value)
        ->assertJsonPath('thread.messages.1.id', $outbound->id)
        ->assertJsonPath('thread.messages.1.direction', MessageDirection::Outbound->value);
});

it('derives a sent-only correspondent from the recipient for historical threads', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create([
        'user_id' => $user->id,
        'email_address' => 'owner@example.test',
    ]);
    $thread = Thread::factory()->create([
        'user_id' => $user->id,
        'mailbox_id' => $mailbox->id,
        'participant_name' => 'Mailbox Owner',
        'participant_email' => 'owner@example.test',
    ]);
    Message::factory()->outbound()->create([
        'thread_id' => $thread->id,
        'mailbox_id' => $mailbox->id,
        'status' => MessageStatus::Sent,
        'sent_at' => now(),
        'to_recipients' => [['email' => 'maya@example.test', 'name' => 'Maya Chen']],
    ]);

    $this->actingAs($user)
        ->get(route('unibox.index', ['folder' => 'sent']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('threads.data', 1)
            ->where('threads.data.0.id', $thread->id)
            ->where('threads.data.0.from', 'Maya Chen')
            ->where('threads.data.0.has_inbound', false));
});

it('counts sent threads once and applies campaign and mailbox filters', function () {
    $user = User::factory()->create();
    $firstMailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $secondMailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $matching = uniboxLinkedThread($user, $firstMailbox, $campaign);
    $otherMailbox = uniboxLinkedThread($user, $secondMailbox, $campaign);
    $noCampaign = uniboxLinkedThread($user, $firstMailbox, null);

    foreach ([$matching, $matching, $otherMailbox, $noCampaign] as $index => $thread) {
        Message::factory()->outbound()->create([
            'thread_id' => $thread->id,
            'mailbox_id' => $thread->mailbox_id,
            'remote_message_id' => "sent-{$index}",
            'status' => MessageStatus::Sent,
            'sent_at' => now()->addSeconds($index),
        ]);
    }

    $this->actingAs($user)
        ->get(route('unibox.index', [
            'folder' => 'sent',
            'mailbox' => $firstMailbox->id,
            'campaign' => $campaign->id,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('threads.data', 1)
            ->where('threads.data.0.id', $matching->id)
            ->where('counts.sent', 1)
            ->where('counts.needs', 1));
});

it('normalizes unknown folders to the complete inbox', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $thread = uniboxLinkedThread($user, $mailbox, null, ThreadLocalState::Open);
    $thread->forceFill(['classification' => ThreadClassification::Human])->save();
    $done = uniboxLinkedThread($user, $mailbox, null, ThreadLocalState::Done);

    $this->actingAs($user)
        ->get(route('unibox.index', ['folder' => 'surprise']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('folder', 'all_open')
            ->has('threads.data', 1)
            ->where('threads.data.0.id', $thread->id)
            ->where('counts.all_open', 1)
            ->where('counts.done', 1));
});

it('syncs fake provider mail into unibox and classifies a human reply', function () {
    $mailbox = Mailbox::factory()->create();
    CampaignRecipient::factory()->create([
        'campaign_id' => Campaign::factory()->create(['user_id' => $mailbox->user_id])->id,
        'mailbox_id' => $mailbox->id,
        'email_address' => 'maya@stripe.com',
        'email_normalized' => 'maya@stripe.com',
    ]);
    FakeMailProvider::seedMailbox($mailbox->email_address, MailProviderName::Gmail, $mailbox->connection->provider_user_id);
    FakeMailProvider::seedMessage($mailbox->email_address, new RemoteMessage(
        remoteMessageId: 'in-1',
        remoteThreadId: 'thr-1',
        direction: MessageDirection::Inbound,
        subject: 'Re: hiring',
        bodyText: 'Yes, let’s talk next week.',
        bodyHtml: '<p>Yes, let’s talk next week.</p><img src="https://evil.test/x.png">',
        fromEmail: 'maya@stripe.com',
        fromName: 'Maya Chen',
    ));

    app(SyncMailboxService::class)->handle($mailbox->id);

    $thread = Thread::query()->where('mailbox_id', $mailbox->id)->first();
    expect($thread->classification)->toBe(ThreadClassification::Human)
        ->and($thread->local_state)->toBe(ThreadLocalState::NeedsAction)
        ->and($thread->messages()->first()->body_html_sanitized)->toContain('<img src="https://evil.test/x.png"');
});

it('sends a reply from the receiving mailbox and is idempotent', function () {
    $thread = Thread::factory()->create();
    $newest = Message::factory()->create([
        'thread_id' => $thread->id,
        'mailbox_id' => $thread->mailbox_id,
        'remote_message_id' => 'parent-newest',
        'internet_message_id' => 'newest@example.test',
        'received_at' => now(),
    ]);
    Message::factory()->create([
        'thread_id' => $thread->id,
        'mailbox_id' => $thread->mailbox_id,
        'remote_message_id' => 'parent-older-inserted-last',
        'internet_message_id' => 'older@example.test',
        'received_at' => now()->subDay(),
    ]);
    $operation = (string) Str::uuid();

    $this->actingAs($thread->user)
        ->post(route('threads.reply', $thread), [
            'body' => 'Thanks Maya',
            'client_operation_id' => $operation,
        ])
        ->assertRedirect();

    $this->actingAs($thread->user)
        ->post(route('threads.reply', $thread), [
            'body' => 'Thanks Maya',
            'client_operation_id' => $operation,
        ])
        ->assertRedirect();

    $this->actingAs($thread->user)
        ->post(route('threads.reply', $thread), [
            'body' => 'Different reply',
            'client_operation_id' => $operation,
        ])
        ->assertSessionHasErrors('reply');

    $reply = SendAttempt::query()->firstOrFail()->message;
    expect(SendAttempt::query()->count())->toBe(1)
        ->and($reply->reply_to_message_id)->toBe($newest->id)
        ->and($reply->in_reply_to)->toBe('newest@example.test')
        ->and($thread->fresh()->messages->take(2)->pluck('remote_message_id')->all())
        ->toBe(['parent-older-inserted-last', 'parent-newest'])
        ->and($thread->fresh()->local_state)->toBe(ThreadLocalState::Done);
});

it('exposes workspace-checked person and account chips on campaign threads', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'name' => 'Stripe',
    ]);
    $person = Person::factory()->forAccount($account)->create([
        'full_name' => 'Maya Chen',
        'email_address' => 'maya@stripe.com',
        'email_normalized' => 'maya@stripe.com',
        'job_title' => 'Head of Growth',
    ]);
    $thread = uniboxLinkedThread($user, $mailbox, $campaign);
    $thread->recipient->forceFill([
        'person_id' => $person->id,
        'account_id' => $account->id,
    ])->save();

    $this->actingAs($user)
        ->get(route('unibox.index', ['folder' => 'needs']))
        ->assertInertia(fn ($page) => $page
            ->where('threads.data.0.person.id', $person->id)
            ->where('threads.data.0.person.name', 'Maya Chen')
            ->where('threads.data.0.person.title', 'Head of Growth')
            ->where('threads.data.0.account.id', $account->id)
            ->where('threads.data.0.account.name', 'Stripe'));
});

it('falls back to a unique registry person for unlinked threads', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $person = Person::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'full_name' => 'Sam Rivera',
        'email_address' => 'sam@example.test',
        'email_normalized' => 'sam@example.test',
    ]);
    $thread = uniboxLinkedThread($user, $mailbox, null);
    $thread->forceFill(['participant_email' => 'sam@example.test'])->save();

    $this->actingAs($user)
        ->get(route('unibox.index', ['folder' => 'needs']))
        ->assertInertia(fn ($page) => $page
            ->where('threads.data.0.person.id', $person->id)
            ->where('threads.data.0.person.name', 'Sam Rivera')
            ->where('threads.data.0.person.title', null)
            ->where('threads.data.0.account', null));

    $thread->forceFill(['participant_email' => ''])->save();

    $this->actingAs($user)
        ->get(route('unibox.index', ['folder' => 'needs']))
        ->assertInertia(fn ($page) => $page
            ->where('threads.data.0.person', null));
});

it('keeps corrected interested threads in leads and false bounces out of delivery', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $thread = uniboxLinkedThread($user, $mailbox, $campaign, ThreadLocalState::NeedsAction);
    $thread->forceFill(['classification' => ThreadClassification::Human])->save();
    $thread->recipient->forceFill(['status' => RecipientStatus::Replied])->save();

    $this->actingAs($user)
        ->post(route('threads.classify', $thread), ['action' => 'interested'])
        ->assertRedirect();

    $this->actingAs($user)
        ->get(route('unibox.index', ['folder' => 'leads']))
        ->assertInertia(fn ($page) => $page
            ->where('threads.data', fn ($rows) => collect($rows)->pluck('id')->contains($thread->id)));

    $bounce = uniboxLinkedThread($user, $mailbox, $campaign, ThreadLocalState::Problem);
    $bounce->forceFill(['classification' => ThreadClassification::Bounce])->save();
    $bounce->recipient->forceFill(['status' => RecipientStatus::Bounced])->save();

    $this->actingAs($user)
        ->post(route('threads.classify', $bounce), ['action' => 'replied'])
        ->assertRedirect();

    $this->actingAs($user)
        ->get(route('unibox.index', ['folder' => 'delivery']))
        ->assertInertia(fn ($page) => $page
            ->where('threads.data', fn ($rows) => ! collect($rows)->pluck('id')->contains($bounce->id)));
});
