<?php

use App\Domain\Api\TokenScope;
use App\Jobs\Mail\SyncMailbox;
use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('rejects mailbox list requests without a token', function () {
    $this->getJson('/api/v1/mailboxes')
        ->assertUnauthorized()
        ->assertJsonPath('error.type', 'unauthenticated');
});

it('rejects mailbox writes when the token only has mailboxes:read', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);

    $this->withToken(apiToken($user, [TokenScope::MailboxesRead->value]))
        ->postJson('/api/v1/mailboxes/'.$mailbox->id.'/sync')
        ->assertForbidden()
        ->assertJsonPath('error.type', 'insufficient_scope');
});

it('lists, shows, and checks auth for a mailbox', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create([
        'user_id' => $user->id,
        'email_address' => 'sender@outboss.test',
    ]);
    $token = apiToken($user, [TokenScope::MailboxesRead->value]);

    $this->withToken($token)
        ->getJson('/api/v1/mailboxes')
        ->assertOk()
        ->assertJsonPath('data.0.id', $mailbox->id)
        ->assertJsonPath('data.0.status', 'connected')
        ->assertJsonStructure(['data', 'meta']);

    $this->withToken($token)
        ->getJson('/api/v1/mailboxes/'.$mailbox->id)
        ->assertOk()
        ->assertJsonPath('data.email', 'sender@outboss.test');

    $this->withToken($token)
        ->getJson('/api/v1/mailboxes/'.$mailbox->id.'/auth-check')
        ->assertOk()
        ->assertJsonPath('domain', 'outboss.test');
});

it('syncs, updates sending, and resumes sending with mailboxes:write', function () {
    Queue::fake();
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create([
        'user_id' => $user->id,
        'sending_paused_at' => now(),
        'sending_paused_reason' => 'hard_bounce_threshold',
    ]);
    $token = apiToken($user, [TokenScope::MailboxesWrite->value]);

    $this->withToken($token)
        ->postJson('/api/v1/mailboxes/'.$mailbox->id.'/sync')
        ->assertOk()
        ->assertJsonPath('queued', true);
    Queue::assertPushed(SyncMailbox::class);

    $this->withToken($token)
        ->patchJson('/api/v1/mailboxes/'.$mailbox->id.'/sending', ['daily_send_cap' => 25])
        ->assertOk()
        ->assertJsonPath('data.daily_send_cap', 25);

    $this->withToken($token)
        ->postJson('/api/v1/mailboxes/'.$mailbox->id.'/resume-sending')
        ->assertOk()
        ->assertJsonPath('data.sending_paused_at', null);
});

it('returns a validation error for an invalid daily send cap', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);

    $this->withToken(apiToken($user, [TokenScope::MailboxesWrite->value]))
        ->patchJson('/api/v1/mailboxes/'.$mailbox->id.'/sending', ['daily_send_cap' => 0])
        ->assertStatus(422)
        ->assertJsonPath('error.type', 'validation_error');
});

it('hides another workspace mailbox', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $owner->id]);

    $this->withToken(apiToken($intruder, [TokenScope::MailboxesRead->value]))
        ->getJson('/api/v1/mailboxes/'.$mailbox->id)
        ->assertForbidden();
});
