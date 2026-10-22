<?php

use App\Domain\Api\TokenScope;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\MessageStatus;
use App\Domain\Mail\Enums\ThreadClassification;
use App\Domain\Mail\Enums\ThreadLocalState;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Person;
use App\Models\Thread;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  array<string, mixed>  $attributes
 */
function inboxThread(User $user, Mailbox $mailbox, array $attributes = [], MessageDirection $direction = MessageDirection::Inbound): Thread
{
    $thread = Thread::factory()->create([
        'user_id' => $user->id,
        'mailbox_id' => $mailbox->id,
        'local_state' => ThreadLocalState::NeedsAction,
        'classification' => ThreadClassification::Human,
        ...$attributes,
    ]);

    Message::factory()->create([
        'thread_id' => $thread->id,
        'mailbox_id' => $mailbox->id,
        'direction' => $direction,
        'status' => $direction === MessageDirection::Inbound ? MessageStatus::Received : MessageStatus::Sent,
    ]);

    return $thread;
}

function inboxToken(User $user, TokenScope ...$scopes): string
{
    app('auth')->forgetGuards();

    return apiToken($user, array_map(fn (TokenScope $scope) => $scope->value, $scopes ?: [TokenScope::InboxRead, TokenScope::InboxSend]));
}

it('returns the same folder counts as the web Unibox, plus unread counts and the badge', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);

    inboxThread($user, $mailbox, ['is_unread' => true]);
    inboxThread($user, $mailbox, ['is_unread' => false]);
    inboxThread($user, $mailbox, ['local_state' => ThreadLocalState::Open, 'classification' => ThreadClassification::Unknown]);
    inboxThread($user, $mailbox, ['local_state' => ThreadLocalState::OutOfOffice, 'classification' => ThreadClassification::OutOfOffice]);
    inboxThread($user, $mailbox, ['local_state' => ThreadLocalState::Problem, 'classification' => ThreadClassification::Bounce]);
    inboxThread($user, $mailbox, ['local_state' => ThreadLocalState::Problem, 'classification' => ThreadClassification::Unsubscribe]);
    inboxThread($user, $mailbox, ['local_state' => ThreadLocalState::Done, 'is_unread' => false]);
    inboxThread($user, $mailbox, ['local_state' => ThreadLocalState::Open], MessageDirection::Outbound);
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'status' => RecipientStatus::Interested,
    ]);
    inboxThread($user, $mailbox, ['campaign_recipient_id' => $recipient->id, 'is_unread' => true]);

    $webCounts = null;
    $this->actingAs($user)->get('/unibox')
        ->assertInertia(function (Assert $page) use (&$webCounts) {
            $webCounts = $page->toArray()['props']['counts'];
        });

    $response = $this->withToken(inboxToken($user, TokenScope::InboxRead))
        ->getJson('/api/v1/inbox/counts')
        ->assertOk();

    expect($response->json('data.folders'))->toBe($webCounts)
        ->and($response->json('data.folders.needs'))->toBe(3)
        ->and($response->json('data.folders.opt_outs'))->toBe(1)
        ->and($response->json('data.folders.delivery'))->toBe(1)
        ->and($response->json('data.folders.sent'))->toBe(1)
        ->and($response->json('data.unread'))->toBe(['needs' => 2, 'leads' => 2, 'all_open' => 6])
        ->and($response->json('data.badge'))->toBe(2);
});

it('applies the mailbox filter to counts', function () {
    $user = User::factory()->create();
    $first = Mailbox::factory()->create(['user_id' => $user->id]);
    $second = Mailbox::factory()->create(['user_id' => $user->id]);
    inboxThread($user, $first);
    inboxThread($user, $second);
    inboxThread($user, $second);

    $this->withToken(inboxToken($user, TokenScope::InboxRead))
        ->getJson('/api/v1/inbox/counts?mailbox='.$second->id)
        ->assertOk()
        ->assertJsonPath('data.folders.needs', 2);
});

it('counts the badge across every workspace the person belongs to', function () {
    $user = User::factory()->create();
    $teammate = User::factory()->create();
    $team = $teammate->currentWorkspace();
    WorkspaceMembership::query()->create(['workspace_id' => $team->id, 'user_id' => $user->id, 'role' => 'member']);

    inboxThread($user, Mailbox::factory()->create(['user_id' => $user->id]));
    inboxThread($teammate, Mailbox::factory()->create(['user_id' => $teammate->id]));
    inboxThread($teammate, Mailbox::factory()->create(['user_id' => $teammate->id]), ['is_unread' => false]);
    $outsider = User::factory()->create();
    inboxThread($outsider, Mailbox::factory()->create(['user_id' => $outsider->id]));

    $this->withToken(inboxToken($user, TokenScope::InboxRead))
        ->getJson('/api/v1/inbox/counts')
        ->assertJsonPath('data.unread.needs', 1)
        ->assertJsonPath('data.badge', 2);
});

it('searches subject, preview and the other person and filters to unread threads', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $pipeline = inboxThread($user, $mailbox, ['subject' => 'Re: Q4 pipeline review', 'is_unread' => false]);
    $jordan = inboxThread($user, $mailbox, ['participant_name' => 'Jordan Lee', 'participant_email' => 'jordan@northwind.test']);
    $percent = inboxThread($user, $mailbox, ['snippet' => 'We can offer 100% of the seats']);
    inboxThread($user, $mailbox, ['subject' => 'Something else', 'participant_name' => 'Sam', 'participant_email' => 'sam@acme.test', 'snippet' => 'Hi']);
    $token = inboxToken($user, TokenScope::InboxRead);

    $ids = fn (string $query) => collect($this->withToken($token)->getJson('/api/v1/threads?folder=all_open&'.$query)->assertOk()->json('data'))->pluck('id')->all();

    expect($ids('q=pipeline'))->toBe([$pipeline->id])
        ->and($ids('q=northwind'))->toBe([$jordan->id])
        ->and($ids('q=JORDAN'))->toBe([$jordan->id])
        ->and($ids('q=100%25'))->toBe([$percent->id])
        ->and($ids('q=%25'))->toHaveCount(4)
        ->and($ids('q=pipeline&unread=1'))->toBe([])
        ->and($ids('unread=1'))->not->toContain($pipeline->id);
});

it('adds the mailbox id, participant name and updated_at to thread summaries', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $thread = inboxThread($user, $mailbox, ['participant_name' => 'Jordan Lee']);

    $this->withToken(inboxToken($user, TokenScope::InboxRead))
        ->getJson('/api/v1/threads?folder=needs')
        ->assertJsonPath('data.0.id', $thread->id)
        ->assertJsonPath('data.0.mailbox_id', $mailbox->id)
        ->assertJsonPath('data.0.mailbox', $mailbox->email_address)
        ->assertJsonPath('data.0.participant_name', 'Jordan Lee')
        ->assertJsonPath('data.0.updated_at', $thread->fresh()->updated_at->toIso8601String());
});

it('includes the linked person\'s job title on thread summaries and the changes feed', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $titled = Person::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'full_name' => 'Maya Chen',
        'email_address' => 'maya@stripe.com',
        'email_normalized' => 'maya@stripe.com',
        'job_title' => 'Head of Growth',
    ]);
    Person::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'full_name' => 'Sam Rivera',
        'email_address' => 'sam@example.test',
        'email_normalized' => 'sam@example.test',
        'job_title' => '  ',
    ]);
    $maya = inboxThread($user, $mailbox, ['participant_email' => 'maya@stripe.com', 'last_message_at' => now()->subMinute()]);
    $sam = inboxThread($user, $mailbox, ['participant_email' => 'sam@example.test', 'last_message_at' => now()->subMinutes(2)]);
    $token = inboxToken($user, TokenScope::InboxRead);

    $threads = collect($this->withToken($token)->getJson('/api/v1/threads?folder=needs')->assertOk()->json('data'))->keyBy('id');

    expect($threads[$maya->id]['person'])->toBe(['id' => $titled->id, 'name' => 'Maya Chen', 'title' => 'Head of Growth'])
        ->and($threads[$sam->id]['person']['title'])->toBeNull();

    // The changes feed carries the same summary.
    $this->travel(1)->minutes();
    $since = $this->withToken($token)->getJson('/api/v1/threads/changes')->assertOk()->json('meta.next_since');
    $this->travel(1)->minutes();
    $this->withToken(inboxToken($user))->postJson('/api/v1/threads/'.$maya->id.'/read')->assertOk();
    $this->travel(1)->minutes();

    $changes = $this->withToken($token)->getJson('/api/v1/threads/changes?since='.urlencode($since))->assertOk();
    expect($changes->json('data.0.id'))->toBe($maya->id)
        ->and($changes->json('data.0.person.title'))->toBe('Head of Growth');
});

it('pages with a cursor without skipping or repeating threads while new mail arrives', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $threads = collect(range(1, 5))->map(fn (int $i) => inboxThread($user, $mailbox, [
        'last_message_at' => now()->subHours($i),
    ]));
    // Two threads sharing a timestamp are ordered by id.
    $tied = inboxThread($user, $mailbox, ['last_message_at' => now()->subHours(3)]);
    $token = inboxToken($user, TokenScope::InboxRead);

    $first = $this->withToken($token)->getJson('/api/v1/threads?folder=needs&pagination=cursor&per_page=2')->assertOk();
    expect($first->json('meta.per_page'))->toBe(2)
        ->and($first->json('meta.next_cursor'))->toBeString()
        ->and($first->json('links'))->toBeNull();

    // A new reply lands at the top between page loads.
    inboxThread($user, $mailbox, ['last_message_at' => now()]);

    $seen = collect($first->json('data'))->pluck('id');
    $cursor = $first->json('meta.next_cursor');

    while ($cursor !== null) {
        $page = $this->withToken($token)
            ->getJson('/api/v1/threads?folder=needs&pagination=cursor&per_page=2&cursor='.urlencode($cursor))
            ->assertOk();
        $seen = $seen->merge(collect($page->json('data'))->pluck('id'));
        $cursor = $page->json('meta.next_cursor');
    }

    $expected = $threads->take(2)
        ->push($tied->id > $threads[2]->id ? $tied : $threads[2])
        ->push($tied->id > $threads[2]->id ? $threads[2] : $tied)
        ->merge($threads->slice(3))
        ->pluck('id');

    expect($seen->all())->toBe($expected->all())
        ->and($seen->duplicates())->toBeEmpty();
});

it('rejects a malformed cursor', function () {
    $user = User::factory()->create();

    $this->withToken(inboxToken($user, TokenScope::InboxRead))
        ->getJson('/api/v1/threads?pagination=cursor&cursor=not-a-cursor')
        ->assertStatus(422)
        ->assertJsonPath('error.type', 'validation_error');
});

it('keeps page-number paging as the default for existing integrations', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    inboxThread($user, $mailbox);

    $this->withToken(inboxToken($user, TokenScope::InboxRead))
        ->getJson('/api/v1/threads')
        ->assertOk()
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonStructure(['links' => ['first', 'next'], 'meta' => ['total']]);
});

it('returns a starting watermark and then every thread changed after it', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $unchanged = inboxThread($user, $mailbox);
    $token = inboxToken($user);

    $this->travel(1)->minutes();
    $start = $this->withToken($token)->getJson('/api/v1/threads/changes')->assertOk();
    expect($start->json('data'))->toBe([])
        ->and($start->json('meta.has_more'))->toBeFalse();
    $since = $start->json('meta.next_since');

    $this->travel(1)->minutes();
    $read = inboxThread($user, $mailbox);
    $this->withToken($token)->postJson('/api/v1/threads/'.$unchanged->id.'/done')->assertOk();
    $draft = Thread::factory()->create(['user_id' => $user->id, 'mailbox_id' => $mailbox->id]);
    $otherUser = User::factory()->create();
    inboxThread($otherUser, Mailbox::factory()->create(['user_id' => $otherUser->id]));

    $this->travel(1)->minutes();
    $changes = $this->withToken($token)->getJson('/api/v1/threads/changes?since='.urlencode($since))->assertOk();
    $rows = collect($changes->json('data'))->keyBy('id');

    expect($rows->keys()->sort()->values()->all())->toBe(collect([$unchanged->id, $read->id, $draft->id])->sort()->values()->all())
        ->and($rows[$unchanged->id]['visible'])->toBeTrue()
        ->and($rows[$unchanged->id]['state'])->toBe('done')
        ->and($rows[$read->id]['subject'])->toBe($read->subject)
        ->and($rows[$draft->id])->toBe(['id' => $draft->id, 'visible' => false, 'updated_at' => $draft->fresh()->updated_at->toIso8601String()])
        ->and($changes->json('meta.has_more'))->toBeFalse();

    // Changes older than the overlap are not sent again.
    $this->travel(1)->minutes();
    $again = $this->withToken($token)->getJson('/api/v1/threads/changes?since='.urlencode($changes->json('meta.next_since')))->assertOk();
    expect($again->json('data'))->toBe([]);

    $this->withToken($token)->postJson('/api/v1/threads/'.$read->id.'/read')->assertOk();
    $this->travel(1)->minutes();
    $afterRead = $this->withToken($token)->getJson('/api/v1/threads/changes?since='.urlencode($again->json('meta.next_since')))->assertOk();
    expect(collect($afterRead->json('data'))->pluck('id')->all())->toBe([$read->id])
        ->and($afterRead->json('data.0.unread'))->toBeFalse();
});

it('re-reads a short overlap so a late commit is not missed', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $token = inboxToken($user, TokenScope::InboxRead);
    $first = inboxThread($user, $mailbox);
    $since = $this->withToken($token)->getJson('/api/v1/threads/changes')->json('meta.next_since');

    // Written five seconds "in the past", as a slow transaction would be.
    $late = inboxThread($user, $mailbox);
    Thread::withoutTimestamps(fn () => $late->forceFill(['updated_at' => now()->subSeconds(5)])->save());

    $ids = collect($this->withToken($token)->getJson('/api/v1/threads/changes?since='.urlencode($since))->json('data'))->pluck('id');

    expect($ids)->toContain($late->id)->toContain($first->id);
});

it('pages through a large batch of changes with has_more', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $token = inboxToken($user, TokenScope::InboxRead);
    $since = $this->withToken($token)->getJson('/api/v1/threads/changes')->json('meta.next_since');
    $this->travel(1)->minutes();
    $created = collect(range(1, 5))->map(fn () => inboxThread($user, $mailbox)->id);

    $seen = collect();
    $calls = 0;

    do {
        $page = $this->withToken($token)->getJson('/api/v1/threads/changes?limit=2&since='.urlencode($since))->assertOk();
        $seen = $seen->merge(collect($page->json('data'))->pluck('id'));
        $since = $page->json('meta.next_since');
        $calls++;
    } while ($page->json('meta.has_more') && $calls < 10);

    expect($calls)->toBe(3)
        ->and($seen->unique()->sort()->values()->all())->toBe($created->sort()->values()->all());
});

it('asks the app to reload when the watermark is too old or malformed', function () {
    $user = User::factory()->create();
    $token = inboxToken($user, TokenScope::InboxRead);
    $since = $this->withToken($token)->getJson('/api/v1/threads/changes')->json('meta.next_since');

    $this->travel(31)->days();

    $this->withToken($token)->getJson('/api/v1/threads/changes?since='.urlencode($since))
        ->assertStatus(410)
        ->assertJsonPath('error.type', 'sync_reset_required');
    $this->withToken($token)->getJson('/api/v1/threads/changes?since=garbage')
        ->assertStatus(410)
        ->assertJsonPath('error.type', 'sync_reset_required');
});

it('marks threads read and unread with inbox:send', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $thread = inboxThread($user, $mailbox, ['is_unread' => true]);

    $this->withToken(inboxToken($user, TokenScope::InboxRead))
        ->postJson('/api/v1/threads/'.$thread->id.'/read')
        ->assertForbidden()
        ->assertJsonPath('error.type', 'insufficient_scope');

    $token = inboxToken($user, TokenScope::InboxSend);
    $this->withToken($token)->postJson('/api/v1/threads/'.$thread->id.'/read')
        ->assertOk()
        ->assertJsonPath('data.id', $thread->id)
        ->assertJsonPath('data.unread', false);
    expect($thread->fresh()->is_unread)->toBeFalse();

    $this->withToken($token)->postJson('/api/v1/threads/'.$thread->id.'/unread')
        ->assertOk()
        ->assertJsonPath('data.unread', true);
    expect($thread->fresh()->is_unread)->toBeTrue();
});

it('does not let another workspace change read state', function () {
    $owner = User::factory()->create();
    $thread = inboxThread($owner, Mailbox::factory()->create(['user_id' => $owner->id]));

    $this->withToken(inboxToken(User::factory()->create(), TokenScope::InboxSend))
        ->postJson('/api/v1/threads/'.$thread->id.'/read')
        ->assertForbidden();
    expect($thread->fresh()->is_unread)->toBeTrue();
});

it('returns sender, recipients, subject and attachment flags on each message without marking the thread read', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $thread = Thread::factory()->create(['user_id' => $user->id, 'mailbox_id' => $mailbox->id, 'is_unread' => true]);
    Message::factory()->create([
        'thread_id' => $thread->id,
        'mailbox_id' => $mailbox->id,
        'from_name' => 'Jordan Lee',
        'from_email' => 'jordan@northwind.test',
        'to_recipients' => [['email' => $mailbox->email_address, 'name' => 'Sam Rivera']],
        'cc_recipients' => [['email' => 'ops@northwind.test']],
        'subject' => 'Re: Quick question',
        'has_attachments' => true,
    ]);

    $this->withToken(inboxToken($user, TokenScope::InboxRead))
        ->getJson('/api/v1/threads/'.$thread->id)
        ->assertOk()
        ->assertJsonPath('data.messages.0.from', 'Jordan Lee')
        ->assertJsonPath('data.messages.0.from_name', 'Jordan Lee')
        ->assertJsonPath('data.messages.0.from_email', 'jordan@northwind.test')
        ->assertJsonPath('data.messages.0.to', [['email' => $mailbox->email_address, 'name' => 'Sam Rivera']])
        ->assertJsonPath('data.messages.0.cc', [['email' => 'ops@northwind.test', 'name' => null]])
        ->assertJsonPath('data.messages.0.subject', 'Re: Quick question')
        ->assertJsonPath('data.messages.0.has_attachments', true);

    expect($thread->fresh()->is_unread)->toBeTrue();
});

it('returns the updated thread and send attempt after a reply and a compose', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $thread = inboxThread($user, $mailbox);
    $token = inboxToken($user, TokenScope::InboxSend);

    $this->withToken($token)
        ->postJson('/api/v1/threads/'.$thread->id.'/reply', [
            'body' => 'Thursday works.',
            'client_operation_id' => (string) Str::uuid(),
        ])
        ->assertOk()
        ->assertJsonPath('sent', true)
        ->assertJsonPath('thread_id', $thread->id)
        ->assertJsonPath('data.thread.id', $thread->id)
        ->assertJsonPath('data.thread.state', 'done')
        ->assertJsonStructure(['send_attempt_id', 'data' => ['send_attempt' => ['id', 'status']]]);

    $compose = $this->withToken($token)
        ->postJson('/api/v1/messages', [
            'mailbox_id' => $mailbox->id,
            'to' => 'maya@stripe.com',
            'subject' => 'Hello',
            'body' => 'Quick note',
            'client_operation_id' => (string) Str::uuid(),
        ])
        ->assertCreated()
        ->assertJsonPath('sent', true);

    expect($compose->json('data.thread.id'))->toBe($compose->json('thread_id'))
        ->and($compose->json('data.send_attempt.id'))->toBe($compose->json('send_attempt_id'));
});

it('shows which address each mailbox replies from', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);

    $this->withToken(inboxToken($user, TokenScope::MailboxesRead))
        ->getJson('/api/v1/mailboxes')
        ->assertOk()
        ->assertJsonPath('data.0.id', $mailbox->id)
        ->assertJsonPath('data.0.effective_reply_identity.email', strtolower($mailbox->email_address));
});

it('keeps workspace isolation on every new inbox endpoint', function () {
    $owner = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $owner->id]);
    inboxThread($owner, $mailbox, ['subject' => 'Private pipeline']);
    $intruder = User::factory()->create();
    $token = inboxToken($intruder, TokenScope::InboxRead);

    $this->withToken($token)->getJson('/api/v1/threads?q=pipeline&pagination=cursor')->assertJsonPath('data', []);
    $this->withToken($token)->getJson('/api/v1/inbox/counts')->assertJsonPath('data.folders.needs', 0);
    $since = $this->withToken($token)->getJson('/api/v1/threads/changes')->json('meta.next_since');
    inboxThread($owner, $mailbox);
    $this->withToken($token)->getJson('/api/v1/threads/changes?since='.urlencode($since))->assertJsonPath('data', []);
    $this->withToken($token)->getJson('/api/v1/inbox/counts?mailbox='.$mailbox->id)->assertJsonPath('data.folders.needs', 0);
    expect(Workspace::query()->count())->toBe(2);
});
