<?php

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\ThreadClassification;
use App\Domain\Mail\Providers\FakeMailProvider;
use App\Domain\Mail\Services\MailboxSenderIdentityService;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\OAuthAttempt;
use App\Models\ProviderConnection;
use App\Models\Thread;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Str;

it('sends a full-page redirect to the provider authorization url', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('oauth.redirect', ['provider' => 'zoho']))
        ->assertRedirect()
        ->assertRedirectContains('fake.oauth.test/zoho');
});

it('returns an inertia location header when connect is intercepted', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withHeader('X-Inertia', 'true')
        ->get(route('oauth.redirect', ['provider' => 'zoho']))
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location');
});

it('shows connect zoho on the mailboxes page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('mailboxes.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('mailboxes/Index'));
});

it('shows connect inbox on unibox when no mailbox is connected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('unibox.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('unibox/Index')
            ->where('mailboxes', []));
});

it('connects a mailbox through a valid oauth callback', function () {
    $user = User::factory()->create();
    FakeMailProvider::seedMailbox('david@outboss.io', MailProviderName::Gmail, 'fake-gmail');

    $this->actingAs($user)
        ->get(route('oauth.redirect', ['provider' => 'gmail']))
        ->assertRedirect();

    $attempt = OAuthAttempt::query()->first();
    $state = Str::random(40);
    $attempt->forceFill(['state_hash' => hash('sha256', $state)])->save();

    $this->actingAs($user)
        ->get(route('oauth.callback', ['provider' => 'gmail', 'code' => 'ok', 'state' => $state]))
        ->assertRedirect(route('mailboxes.index'));

    $mailbox = Mailbox::query()->first();
    expect($mailbox)->not->toBeNull()
        ->and($mailbox->email_address)->toBe('david@outboss.io')
        ->and($mailbox->status)->toBe(ConnectionStatus::Connected)
        ->and($mailbox->connection->refresh_token)->not->toBeNull();
});

it('completes oauth in the workspace where it started after the browser switches', function () {
    $user = User::factory()->create();
    $origin = $user->currentWorkspace();
    $other = Workspace::factory()->create(['type' => 'team']);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $other->id,
        'user_id' => $user->id,
        'role' => WorkspaceMembership::ROLE_OWNER,
    ]);
    FakeMailProvider::seedMailbox('origin@outboss.test', MailProviderName::Gmail, 'fake-gmail');

    $this->actingAs($user)
        ->get(route('oauth.redirect', ['provider' => 'gmail']))
        ->assertRedirect();

    $attempt = OAuthAttempt::query()->sole();
    $state = Str::random(40);
    $attempt->forceFill(['state_hash' => hash('sha256', $state)])->save();

    $this->actingAs($user)
        ->post(route('workspaces.switch', $other->id))
        ->assertRedirect(route('unibox.index'));

    $this->actingAs($user)
        ->get(route('oauth.callback', ['provider' => 'gmail', 'code' => 'ok', 'state' => $state]))
        ->assertRedirect(route('mailboxes.index'));

    expect($attempt->workspace_id)->toBe($origin->id)
        ->and(Mailbox::query()->sole()->workspace_id)->toBe($origin->id)
        ->and(session(User::ACTIVE_WORKSPACE_SESSION_KEY))->toBe($origin->id);
});

it('lets another workspace member reconnect the shared provider identity', function () {
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    $workspace = Workspace::factory()->create(['type' => 'team']);

    foreach ([$firstUser, $secondUser] as $member) {
        WorkspaceMembership::factory()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $member->id,
            'role' => WorkspaceMembership::ROLE_OWNER,
        ]);
    }

    FakeMailProvider::seedMailbox('shared@outboss.test', MailProviderName::Gmail, 'fake-gmail');

    foreach ([$firstUser, $secondUser] as $member) {
        $this->actingAs($member)
            ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $workspace->id])
            ->get(route('oauth.redirect', ['provider' => 'gmail']))
            ->assertRedirect();

        $attempt = OAuthAttempt::query()
            ->where('user_id', $member->id)
            ->where('workspace_id', $workspace->id)
            ->sole();
        $state = Str::random(40);
        $attempt->forceFill(['state_hash' => hash('sha256', $state)])->save();

        $this->actingAs($member)
            ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $workspace->id])
            ->get(route('oauth.callback', ['provider' => 'gmail', 'code' => 'ok', 'state' => $state]))
            ->assertRedirect(route('mailboxes.index'));
    }

    expect(ProviderConnection::query()->where('workspace_id', $workspace->id)->count())->toBe(1)
        ->and(ProviderConnection::query()->where('workspace_id', $workspace->id)->sole()->user_id)
        ->toBe($secondUser->id)
        ->and(Mailbox::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
});

it('allows the same provider identity to connect independently in two workspaces', function () {
    $user = User::factory()->create();
    $first = $user->currentWorkspace();
    $second = Workspace::factory()->create(['type' => 'team']);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $second->id,
        'user_id' => $user->id,
        'role' => WorkspaceMembership::ROLE_OWNER,
    ]);
    FakeMailProvider::seedMailbox('shared@outboss.test', MailProviderName::Gmail, 'fake-gmail');

    foreach ([$first, $second] as $workspace) {
        $this->actingAs($user)
            ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $workspace->id])
            ->get(route('oauth.redirect', ['provider' => 'gmail']))
            ->assertRedirect();

        $attempt = OAuthAttempt::query()->where('workspace_id', $workspace->id)->sole();
        $state = Str::random(40);
        $attempt->forceFill(['state_hash' => hash('sha256', $state)])->save();

        $this->actingAs($user)
            ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $workspace->id])
            ->get(route('oauth.callback', ['provider' => 'gmail', 'code' => 'ok', 'state' => $state]))
            ->assertRedirect(route('mailboxes.index'));
    }

    expect(ProviderConnection::query()->count())->toBe(2)
        ->and(ProviderConnection::query()->pluck('workspace_id')->sort()->values()->all())
        ->toBe(collect([$first->id, $second->id])->sort()->values()->all());
});

it('persists the deterministic Zoho sender identity during oauth completion', function () {
    $user = User::factory()->create();
    FakeMailProvider::seedMailbox('david@outboss.io', MailProviderName::Zoho, 'fake-zoho');

    $this->actingAs($user)
        ->get(route('oauth.redirect', ['provider' => 'zoho']))
        ->assertRedirect();

    $attempt = OAuthAttempt::query()->first();
    $state = Str::random(40);
    $attempt->forceFill(['state_hash' => hash('sha256', $state)])->save();

    $this->actingAs($user)
        ->get(route('oauth.callback', ['provider' => 'zoho', 'code' => 'ok', 'state' => $state]))
        ->assertRedirect(route('mailboxes.index'));

    $mailbox = Mailbox::query()->firstOrFail();

    expect($mailbox->selected_sender_identity_key)
        ->toBe(MailboxSenderIdentityService::keyForEmail('david@outboss.io'))
        ->and($mailbox->sender_identities[0]['email'])->toBe('david@outboss.io');
});

it('rejects an expired or reused oauth state', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('oauth.callback', ['provider' => 'gmail', 'code' => 'ok', 'state' => 'missing']))
        ->assertRedirect(route('mailboxes.index'));

    expect(Mailbox::query()->count())->toBe(0);
});

it('does not let another user complete an oauth attempt', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $state = 'shared-state';

    OAuthAttempt::factory()->create([
        'user_id' => $owner->id,
        'state_hash' => hash('sha256', $state),
    ]);

    $this->actingAs($intruder)
        ->get(route('oauth.callback', ['provider' => 'gmail', 'code' => 'ok', 'state' => $state]))
        ->assertRedirect(route('mailboxes.index'));

    expect(Mailbox::query()->count())->toBe(0);
});

it('keeps the stored refresh token when reconnect omits one', function () {
    $user = User::factory()->create();
    FakeMailProvider::seedMailbox('david@outboss.io', MailProviderName::Zoho, 'fake-zoho');

    $this->actingAs($user)
        ->get(route('oauth.redirect', ['provider' => 'zoho']))
        ->assertRedirect();

    $attempt = OAuthAttempt::query()->first();
    $state = Str::random(40);
    $attempt->forceFill(['state_hash' => hash('sha256', $state)])->save();

    $this->actingAs($user)
        ->get(route('oauth.callback', ['provider' => 'zoho', 'code' => 'ok', 'state' => $state]))
        ->assertRedirect(route('mailboxes.index'));

    $mailbox = Mailbox::query()->first();
    $original = $mailbox->connection->refresh_token;

    $this->actingAs($user)
        ->post(route('mailboxes.reconnect', $mailbox))
        ->assertRedirect();

    $reconnect = OAuthAttempt::query()->latest('id')->first();
    $reconnectState = Str::random(40);
    $reconnect->forceFill(['state_hash' => hash('sha256', $reconnectState)])->save();
    FakeMailProvider::$omitRefreshToken = true;

    $this->actingAs($user)
        ->get(route('oauth.callback', ['provider' => 'zoho', 'code' => 'ok', 'state' => $reconnectState]))
        ->assertRedirect(route('mailboxes.index'));

    expect($mailbox->connection->fresh()->refresh_token)->toBe($original);
});

it('rejects a new connection that does not include a refresh token', function () {
    $user = User::factory()->create();
    FakeMailProvider::seedMailbox('david@outboss.io', MailProviderName::Zoho, 'fake-zoho');
    FakeMailProvider::$omitRefreshToken = true;

    $this->actingAs($user)
        ->get(route('oauth.redirect', ['provider' => 'zoho']))
        ->assertRedirect();

    $attempt = OAuthAttempt::query()->first();
    $state = Str::random(40);
    $attempt->forceFill(['state_hash' => hash('sha256', $state)])->save();

    $this->actingAs($user)
        ->get(route('oauth.callback', ['provider' => 'zoho', 'code' => 'ok', 'state' => $state]))
        ->assertRedirect(route('mailboxes.index'))
        ->assertSessionHas('error', 'The provider did not grant offline access. Try connecting again.');

    expect(Mailbox::query()->count())->toBe(0);
});

it('rejects a new gmail connection that does not include a refresh token', function () {
    $user = User::factory()->create();
    FakeMailProvider::seedMailbox('david@outboss.io', MailProviderName::Gmail, 'fake-gmail');
    FakeMailProvider::$omitRefreshToken = true;

    $this->actingAs($user)
        ->get(route('oauth.redirect', ['provider' => 'gmail']))
        ->assertRedirect();

    $attempt = OAuthAttempt::query()->first();
    $state = Str::random(40);
    $attempt->forceFill(['state_hash' => hash('sha256', $state)])->save();

    $this->actingAs($user)
        ->get(route('oauth.callback', ['provider' => 'gmail', 'code' => 'ok', 'state' => $state]))
        ->assertRedirect(route('mailboxes.index'))
        ->assertSessionHas('error', 'The provider did not grant offline access. Try connecting again.');

    expect(Mailbox::query()->count())->toBe(0);
});

it('passes zoho location extras from the callback query string', function () {
    $user = User::factory()->create();
    FakeMailProvider::seedMailbox('david@outboss.io', MailProviderName::Zoho, 'fake-zoho');

    $this->actingAs($user)
        ->get(route('oauth.redirect', ['provider' => 'zoho']))
        ->assertRedirect();

    $attempt = OAuthAttempt::query()->first();
    $state = Str::random(40);
    $attempt->forceFill(['state_hash' => hash('sha256', $state)])->save();

    $this->actingAs($user)
        ->get(route('oauth.callback', [
            'provider' => 'zoho',
            'code' => 'ok',
            'state' => $state,
            'location' => 'eu',
            'accounts-server' => 'https://accounts.zoho.eu',
        ]))
        ->assertRedirect(route('mailboxes.index'));

    expect(FakeMailProvider::$lastCallback)->not->toBeNull()
        ->and(FakeMailProvider::$lastCallback->extras['location'])->toBe('eu')
        ->and(FakeMailProvider::$lastCallback->extras['accounts_server'])->toBe('https://accounts.zoho.eu');
});

it('disconnects a mailbox while keeping its history visible', function () {
    $mailbox = Mailbox::factory()->create();
    $connection = $mailbox->connection;
    $thread = Thread::factory()->create([
        'mailbox_id' => $mailbox->id,
        'user_id' => $mailbox->user_id,
        'classification' => ThreadClassification::Human,
    ]);
    $message = Message::factory()->create([
        'thread_id' => $thread->id,
        'mailbox_id' => $mailbox->id,
        'workspace_id' => $mailbox->workspace_id,
    ]);

    $this->actingAs($mailbox->user)
        ->from(route('mailboxes.index'))
        ->delete(route('mailboxes.destroy', $mailbox))
        ->assertRedirect(route('mailboxes.index'));

    expect($mailbox->fresh()->status)->toBe(ConnectionStatus::Disconnected)
        ->and($thread->fresh())->not->toBeNull()
        ->and($thread->fresh()->mailbox_id)->toBe($mailbox->id)
        ->and($message->fresh()->mailbox_id)->toBe($mailbox->id)
        ->and($connection->fresh()->access_token)->toBeNull()
        ->and($connection->fresh()->status)->toBe(ConnectionStatus::Disconnected);

    $this->actingAs($mailbox->user)
        ->get(route('unibox.index', ['folder' => 'all_replies']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('threads.data.0.id', $thread->id)
            ->where('threads.data.0.mailbox', $mailbox->email_address));
});

it('reconnects a disconnected mailbox in place with its history intact', function () {
    $user = User::factory()->create();
    $email = 'david@outboss.io';
    $providerUserId = 'fake-zoho';
    $connection = ProviderConnection::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $user->currentWorkspace()->id,
        'provider' => MailProviderName::Zoho,
        'provider_user_id' => $providerUserId,
    ]);
    $mailbox = Mailbox::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $user->currentWorkspace()->id,
        'provider_connection_id' => $connection->id,
        'provider_mailbox_id' => $providerUserId.'-'.$email,
        'email_address' => $email,
    ]);
    $thread = Thread::factory()->create([
        'mailbox_id' => $mailbox->id,
        'user_id' => $user->id,
    ]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $campaign->mailboxes()->attach($mailbox, ['position' => 1]);
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $mailbox->workspace_id,
        'mailbox_id' => $mailbox->id,
        'status' => RecipientStatus::Active,
        'next_send_at' => now()->addHour(),
    ]);
    FakeMailProvider::seedMailbox($email, MailProviderName::Zoho, $providerUserId);

    $this->actingAs($user)
        ->delete(route('mailboxes.destroy', $mailbox))
        ->assertRedirect(route('mailboxes.index'));

    expect($recipient->fresh()->status)->toBe(RecipientStatus::BlockedMailbox)
        ->and($recipient->fresh()->mailbox_id)->toBe($mailbox->id)
        ->and($campaign->mailboxes()->whereKey($mailbox->id)->exists())->toBeTrue();

    $this->actingAs($user)
        ->get(route('oauth.redirect', ['provider' => 'zoho']))
        ->assertRedirect();
    $attempt = OAuthAttempt::query()->latest('id')->firstOrFail();
    $state = Str::random(40);
    $attempt->forceFill(['state_hash' => hash('sha256', $state)])->save();

    $this->actingAs($user)
        ->get(route('oauth.callback', ['provider' => 'zoho', 'code' => 'ok', 'state' => $state]))
        ->assertRedirect(route('mailboxes.index'));

    expect(Mailbox::query()->count())->toBe(1)
        ->and($mailbox->fresh()->status)->toBe(ConnectionStatus::Connected)
        ->and($mailbox->fresh()->id)->toBe($mailbox->id)
        ->and($thread->fresh()->mailbox_id)->toBe($mailbox->id)
        ->and($recipient->fresh()->status)->toBe(RecipientStatus::Active)
        ->and($recipient->fresh()->mailbox_id)->toBe($mailbox->id)
        ->and($recipient->fresh()->next_send_at)->not->toBeNull()
        ->and($connection->fresh()->status)->toBe(ConnectionStatus::Connected)
        ->and($connection->fresh()->access_token)->not->toBeNull();
});

it('hides disconnected mailboxes on the mailboxes page', function () {
    $user = User::factory()->create();
    $live = Mailbox::factory()->create([
        'user_id' => $user->id,
        'email_address' => 'live@outboss.test',
    ]);
    Mailbox::factory()->create([
        'user_id' => $user->id,
        'email_address' => 'gone@outboss.test',
        'status' => ConnectionStatus::Disconnected,
    ]);

    $this->actingAs($user)
        ->get(route('mailboxes.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('mailboxes/Index')
            ->has('mailboxes', 1)
            ->where('mailboxes.0.id', $live->id)
            ->where('mailboxes.0.email', 'live@outboss.test'));
});

it('removes the inbox from the mailboxes page after disconnect', function () {
    $mailbox = Mailbox::factory()->create([
        'email_address' => 'leave@outboss.test',
    ]);

    $this->actingAs($mailbox->user)
        ->from(route('mailboxes.index'))
        ->delete(route('mailboxes.destroy', $mailbox))
        ->assertRedirect(route('mailboxes.index'));

    $this->actingAs($mailbox->user)
        ->get(route('mailboxes.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('mailboxes/Index')
            ->has('mailboxes', 0));
});

it('revokes the connection when its last enabled mailbox is disconnected', function () {
    $user = User::factory()->create();
    $connection = ProviderConnection::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $user->currentWorkspace()->id,
    ]);
    Mailbox::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $user->currentWorkspace()->id,
        'provider_connection_id' => $connection->id,
        'status' => ConnectionStatus::Disconnected,
    ]);
    $live = Mailbox::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $user->currentWorkspace()->id,
        'provider_connection_id' => $connection->id,
    ]);

    $this->actingAs($user)
        ->delete(route('mailboxes.destroy', $live))
        ->assertRedirect(route('mailboxes.index'));

    expect($live->fresh()->status)->toBe(ConnectionStatus::Disconnected)
        ->and($connection->fresh()->status)->toBe(ConnectionStatus::Disconnected)
        ->and($connection->fresh()->access_token)->toBeNull()
        ->and($connection->enabledMailboxes()->count())->toBe(0);
});

it('disconnects the mailbox even if provider revoke fails', function () {
    FakeMailProvider::$failRevoke = true;
    $mailbox = Mailbox::factory()->create();
    $connection = $mailbox->connection;

    $this->actingAs($mailbox->user)
        ->delete(route('mailboxes.destroy', $mailbox))
        ->assertRedirect(route('mailboxes.index'));

    expect($mailbox->fresh()->status)->toBe(ConnectionStatus::Disconnected)
        ->and($connection->fresh()->access_token)->toBeNull();
});
