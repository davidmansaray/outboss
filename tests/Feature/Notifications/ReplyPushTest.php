<?php

use App\Domain\Api\TokenScope;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Devices\Enums\PushPreference;
use App\Domain\Devices\Support\DeviceScopes;
use App\Domain\Mail\DTOs\RemoteMessage;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\ThreadLocalState;
use App\Domain\Mail\Providers\FakeMailProvider;
use App\Domain\Mail\Services\SyncMailboxService;
use App\Domain\Mail\Services\UniboxInbox;
use App\Domain\Notifications\ApnsPushGateway;
use App\Domain\Notifications\NullPushGateway;
use App\Domain\Notifications\PushConfiguration;
use App\Domain\Notifications\PushGateway;
use App\Domain\Notifications\ReplyNotificationDispatcher;
use App\Jobs\Notifications\SendReplyPush;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\DeviceSession;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\PersonalAccessToken;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function apnsTestKey(): string
{
    static $pem = null;

    if ($pem === null) {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
    }

    return $pem;
}

function configureApns(): void
{
    config([
        'outboss.push.apns.key_id' => 'ABC123DEFG',
        'outboss.push.apns.team_id' => 'TEAM123456',
        'outboss.push.apns.bundle_id' => 'com.outboss.ios',
        'outboss.push.apns.private_key' => apnsTestKey(),
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function pushDevice(User $user, array $attributes = []): DeviceSession
{
    return DeviceSession::query()->create([
        'user_id' => $user->id,
        'workspace_id' => $user->currentWorkspace()->id,
        'install_id' => (string) Str::uuid(),
        'name' => 'Work iPhone',
        'platform' => 'ios',
        'apns_token' => str_repeat('ab', 32),
        'apns_environment' => 'production',
        'notify' => PushPreference::Needs,
        ...$attributes,
    ]);
}

function deviceAccessToken(DeviceSession $device): string
{
    $user = User::query()->findOrFail($device->user_id);
    $secret = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'iOS',
        'token' => hash('sha256', $secret),
        'abilities' => DeviceScopes::all(),
        'workspace_id' => $device->workspace_id,
        'device_session_id' => $device->id,
        'expires_at' => now()->addHour(),
    ]);
    app('auth')->forgetGuards();

    return $token->getKey().'|'.$secret;
}

function syncInboundReply(Mailbox $mailbox, string $from = 'maya@stripe.com', ?DateTimeInterface $at = null, string $subject = 'Re: hiring'): void
{
    // Replies from campaign recipients are what Outboss classifies as human.
    if (! CampaignRecipient::query()->where('mailbox_id', $mailbox->id)->where('email_normalized', $from)->exists()) {
        CampaignRecipient::factory()->create([
            'campaign_id' => Campaign::factory()->create(['user_id' => $mailbox->user_id])->id,
            'mailbox_id' => $mailbox->id,
            'email_address' => $from,
            'email_normalized' => $from,
        ]);
    }

    FakeMailProvider::seedMailbox($mailbox->email_address, MailProviderName::Gmail, $mailbox->connection->provider_user_id);
    FakeMailProvider::seedMessage($mailbox->email_address, new RemoteMessage(
        remoteMessageId: 'in-'.Str::random(6),
        remoteThreadId: 'thr-'.Str::random(6),
        direction: MessageDirection::Inbound,
        subject: $subject,
        bodyText: 'Yes, let’s talk next week.',
        bodyHtml: '<p>Yes, let’s talk next week.</p>',
        fromEmail: $from,
        fromName: 'Maya Chen',
        occurredAt: $at === null ? now() : Carbon::instance($at),
    ));

    app(SyncMailboxService::class)->handle($mailbox->id);
}

it('queues a push for each signed-in phone when a fresh reply lands in Needs reply', function () {
    configureApns();
    Queue::fake();
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $device = pushDevice($user);
    pushDevice(User::factory()->create());

    syncInboundReply($mailbox);

    $thread = Thread::query()->where('mailbox_id', $mailbox->id)->sole();
    expect($thread->local_state)->toBe(ThreadLocalState::NeedsAction);
    Queue::assertPushed(SendReplyPush::class, 1);
    Queue::assertPushed(SendReplyPush::class, fn (SendReplyPush $job) => $job->deviceSessionId === $device->id && $job->threadId === $thread->id);
});

it('does not alert for old replies from a first sync or backfill', function () {
    configureApns();
    Queue::fake();
    $user = User::factory()->create();
    pushDevice($user);

    syncInboundReply(Mailbox::factory()->create(['user_id' => $user->id]), at: now()->subMinutes(16));

    expect(Thread::query()->sole()->local_state)->toBe(ThreadLocalState::NeedsAction);
    Queue::assertNotPushed(SendReplyPush::class);
});

it('does not alert for out-of-office replies or opt-outs', function () {
    configureApns();
    Queue::fake();
    $user = User::factory()->create();
    pushDevice($user);
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);

    syncInboundReply($mailbox, subject: 'Automatic reply: Out of office');
    syncInboundReply($mailbox, from: 'mailer-daemon@googlemail.com', subject: 'Delivery Status Notification (Failure)');

    expect(Thread::query()->where('local_state', ThreadLocalState::NeedsAction)->count())->toBe(0);
    Queue::assertNotPushed(SendReplyPush::class);
});

it('does not alert when push is not configured, the phone is off, or signed out', function () {
    Queue::fake();
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    pushDevice($user);

    syncInboundReply($mailbox);
    expect(Thread::query()->where('local_state', ThreadLocalState::NeedsAction)->count())->toBe(1);
    Queue::assertNotPushed(SendReplyPush::class);

    configureApns();
    DeviceSession::query()->update(['notify' => PushPreference::Off->value]);
    pushDevice($user, ['revoked_at' => now()]);
    pushDevice($user, ['apns_token' => null]);

    syncInboundReply($mailbox, from: 'jordan@northwind.test');
    expect(Thread::query()->where('local_state', ThreadLocalState::NeedsAction)->count())->toBe(2);
    Queue::assertNotPushed(SendReplyPush::class);
});

it('alerts lead-only phones just for interested recipients', function () {
    configureApns();
    Queue::fake();
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $leadsOnly = pushDevice($user, ['notify' => PushPreference::Leads]);
    $thread = Thread::factory()->create(['user_id' => $user->id, 'mailbox_id' => $mailbox->id, 'local_state' => ThreadLocalState::NeedsAction]);
    $message = Message::factory()->create(['thread_id' => $thread->id, 'mailbox_id' => $mailbox->id]);
    $dispatcher = app(ReplyNotificationDispatcher::class);

    $dispatcher->inboundMessageStored($message, true);
    Queue::assertNotPushed(SendReplyPush::class);

    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => Campaign::factory()->create(['user_id' => $user->id])->id,
        'mailbox_id' => $mailbox->id,
        'status' => RecipientStatus::Interested,
    ]);
    $thread->forceFill(['campaign_recipient_id' => $recipient->id])->save();

    $dispatcher->inboundMessageStored($message, true);
    Queue::assertPushed(SendReplyPush::class, fn (SendReplyPush $job) => $job->deviceSessionId === $leadsOnly->id);

    $dispatcher->inboundMessageStored($message, false);
    Queue::assertPushed(SendReplyPush::class, 1);
});

it('sends an APNs alert with sender, subject, badge and thread ids but no message body', function () {
    configureApns();
    Http::fake(['https://api.push.apple.com/*' => Http::response('', 200)]);
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id, 'email_address' => 'sam@acme.test']);
    $device = pushDevice($user);
    $thread = Thread::factory()->create([
        'user_id' => $user->id,
        'mailbox_id' => $mailbox->id,
        'participant_name' => 'Jordan Lee',
        'subject' => 'Re: Quick question about Q4 pipeline',
        'snippet' => 'Thursday works for me',
        'local_state' => ThreadLocalState::NeedsAction,
    ]);
    Message::factory()->create(['thread_id' => $thread->id, 'mailbox_id' => $mailbox->id, 'body_text' => 'Thursday works for me']);

    (new SendReplyPush($device->id, $thread->id))->handle(app(PushGateway::class), app(UniboxInbox::class));

    Http::assertSent(function (HttpRequest $request) use ($thread, $device) {
        $jwt = explode('.', str_replace('bearer ', '', $request->header('authorization')[0]));
        $header = json_decode(base64_decode(strtr($jwt[0], '-_', '+/')), true);
        $payload = $request->data();

        return $request->url() === 'https://api.push.apple.com/3/device/'.$device->apns_token
            && $request->header('apns-topic')[0] === 'com.outboss.ios'
            && $request->header('apns-push-type')[0] === 'alert'
            && $request->header('apns-collapse-id')[0] === 'thread-'.$thread->id
            && $header === ['alg' => 'ES256', 'kid' => 'ABC123DEFG']
            && strlen(base64_decode(strtr($jwt[2], '-_', '+/'))) === 64
            && $payload['aps']['alert'] === ['title' => 'Jordan Lee', 'subtitle' => 'via sam@acme.test', 'body' => 'Re: Quick question about Q4 pipeline']
            && $payload['aps']['badge'] === 1
            && $payload['aps']['thread-id'] === 'outboss-thread-'.$thread->id
            && $payload['outboss'] === ['workspace_id' => (int) $thread->workspace_id, 'thread_id' => $thread->id]
            && ! str_contains(json_encode($payload), 'Thursday');
    });
});

it('produces an ES256 signature Apple can verify', function () {
    configureApns();
    $data = 'header.claims';
    openssl_sign($data, $der, apnsTestKey(), OPENSSL_ALGO_SHA256);
    $jose = ApnsPushGateway::derToJose($der);

    // Rebuild DER from r||s and verify with the public key.
    $int = fn (string $bytes) => "\x02".chr(strlen($bytes = (ord($bytes[0]) & 0x80 ? "\x00" : '').ltrim($bytes, "\x00") ?: "\x00")).$bytes;
    $sequence = $int(substr($jose, 0, 32)).$int(substr($jose, 32));
    $rebuilt = "\x30".chr(strlen($sequence)).$sequence;
    $public = openssl_pkey_get_details(openssl_pkey_get_private(apnsTestKey()))['key'];

    expect(strlen($jose))->toBe(64)
        ->and(openssl_verify($data, $rebuilt, $public, OPENSSL_ALGO_SHA256))->toBe(1);
});

it('uses the sandbox host for debug builds', function () {
    configureApns();
    Http::fake(['https://api.sandbox.push.apple.com/*' => Http::response('', 200)]);
    $user = User::factory()->create();
    $device = pushDevice($user, ['apns_environment' => 'sandbox']);
    $thread = Thread::factory()->create(['user_id' => $user->id, 'mailbox_id' => Mailbox::factory()->create(['user_id' => $user->id])->id]);

    (new SendReplyPush($device->id, $thread->id))->handle(app(PushGateway::class), app(UniboxInbox::class));

    Http::assertSent(fn (HttpRequest $request) => str_starts_with($request->url(), 'https://api.sandbox.push.apple.com/3/device/'));
});

it('forgets a device token Apple reports as unregistered', function () {
    configureApns();
    Http::fake(['*' => Http::response(['reason' => 'Unregistered'], 410)]);
    $user = User::factory()->create();
    $device = pushDevice($user);
    $thread = Thread::factory()->create(['user_id' => $user->id, 'mailbox_id' => Mailbox::factory()->create(['user_id' => $user->id])->id]);

    (new SendReplyPush($device->id, $thread->id))->handle(app(PushGateway::class), app(UniboxInbox::class));

    expect($device->fresh()->apns_token)->toBeNull();
});

it('retries when Apple throttles', function () {
    configureApns();
    Http::fake(['*' => Http::response(['reason' => 'TooManyRequests'], 429)]);
    $user = User::factory()->create();
    $device = pushDevice($user);
    $thread = Thread::factory()->create(['user_id' => $user->id, 'mailbox_id' => Mailbox::factory()->create(['user_id' => $user->id])->id]);

    expect(fn () => (new SendReplyPush($device->id, $thread->id))->handle(app(PushGateway::class), app(UniboxInbox::class)))
        ->toThrow(RuntimeException::class);
    expect($device->fresh()->apns_token)->not->toBeNull();
});

it('skips the send when the person has left the workspace or the phone signed out', function () {
    configureApns();
    Http::fake();
    $owner = User::factory()->create();
    $thread = Thread::factory()->create(['user_id' => $owner->id, 'mailbox_id' => Mailbox::factory()->create(['user_id' => $owner->id])->id]);
    $outsider = pushDevice(User::factory()->create());
    $signedOut = pushDevice($owner, ['revoked_at' => now()]);

    foreach ([$outsider, $signedOut] as $device) {
        (new SendReplyPush($device->id, $thread->id))->handle(app(PushGateway::class), app(UniboxInbox::class));
    }

    Http::assertNothingSent();
});

it('binds the APNs gateway only when the configuration is valid', function () {
    expect(app(PushGateway::class))->toBeInstanceOf(NullPushGateway::class)
        ->and(app(PushConfiguration::class)->check()['problem'])->toBe('APNs is not configured.');

    configureApns();
    expect(app(PushGateway::class))->toBeInstanceOf(ApnsPushGateway::class);

    config(['outboss.push.apns.key_id' => 'short']);
    expect(app(PushGateway::class))->toBeInstanceOf(NullPushGateway::class)
        ->and(app(PushConfiguration::class)->check()['problem'])->toContain('APNS_KEY_ID');

    config(['outboss.push.apns.key_id' => 'ABC123DEFG', 'outboss.push.apns.private_key' => 'not a key']);
    expect(app(PushConfiguration::class)->check()['problem'])->toContain('not a valid EC');
});

it('reports push status from the check command and /me', function () {
    $this->artisan('outboss:push:check')->assertFailed();

    configureApns();
    $this->artisan('outboss:push:check')
        ->expectsOutputToContain('Push notifications are on for com.outboss.ios')
        ->assertSuccessful();

    $user = User::factory()->create();
    $this->withToken(apiToken($user, [TokenScope::InboxRead->value]))
        ->getJson('/api/v1/me')
        ->assertJsonPath('data.capabilities.push', true);
});

it('registers, moves and removes a device push token', function () {
    configureApns();
    $user = User::factory()->create();
    $device = pushDevice($user, ['apns_token' => null]);
    $previousOwner = pushDevice(User::factory()->create(), ['apns_token' => str_repeat('cd', 32)]);
    $token = deviceAccessToken($device);

    $this->withToken($token)->putJson('/api/v1/devices/current/push', [
        'apns_token' => strtoupper(str_repeat('cd', 32)),
        'environment' => 'sandbox',
        'notify' => 'leads',
    ])
        ->assertOk()
        ->assertJsonPath('data.push_available', true)
        ->assertJsonPath('data.notify', 'leads');

    expect($device->fresh()->apns_token)->toBe(str_repeat('cd', 32))
        ->and($device->fresh()->apns_environment)->toBe('sandbox')
        ->and($device->fresh()->notify)->toBe(PushPreference::Leads)
        ->and($previousOwner->fresh()->apns_token)->toBeNull();

    app('auth')->forgetGuards();
    $this->withToken($token)->putJson('/api/v1/devices/current/push', ['apns_token' => 'xyz', 'environment' => 'prod', 'notify' => 'all'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['apns_token', 'environment', 'notify'], 'error.errors');

    app('auth')->forgetGuards();
    $this->withToken($token)->deleteJson('/api/v1/devices/current/push')->assertNoContent();
    expect($device->fresh()->apns_token)->toBeNull();
});

it('refuses push registration from a personal API key', function () {
    $user = User::factory()->create();

    $this->withToken(apiToken($user, [TokenScope::InboxRead->value]))
        ->putJson('/api/v1/devices/current/push', ['apns_token' => str_repeat('ab', 32), 'environment' => 'production', 'notify' => 'needs'])
        ->assertForbidden()
        ->assertJsonPath('error.type', 'device_session_required');

    expect(PersonalAccessToken::query()->whereNotNull('device_session_id')->count())->toBe(0);
});
