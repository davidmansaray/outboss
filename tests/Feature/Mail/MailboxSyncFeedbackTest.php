<?php

use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\SyncScope;
use App\Domain\Mail\Enums\SyncStatus;
use App\Domain\Mail\Providers\FakeMailProvider;
use App\Domain\Mail\Services\SyncMailboxService;
use App\Domain\Mail\Support\MailboxSyncFeedback;
use App\Jobs\Mail\InitialSyncMailbox;
use App\Jobs\Mail\SyncMailbox;
use App\Models\Mailbox;
use App\Models\MailboxSyncState;
use App\Models\OAuthAttempt;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $mailboxAttributes
 * @param  array<string, array<string, mixed>>  $states
 */
function mailboxForSyncFeedback(array $mailboxAttributes = [], array $states = []): Mailbox
{
    $mailbox = Mailbox::factory()->create($mailboxAttributes);

    foreach ([SyncScope::Inbox, SyncScope::Sent] as $scope) {
        MailboxSyncState::factory()->create(array_merge([
            'mailbox_id' => $mailbox->id,
            'scope' => $scope,
            'status' => SyncStatus::Idle,
        ], $states[$scope->value] ?? []));
    }

    return $mailbox->fresh(['connection', 'syncStates']);
}

it('presents idle never-synced connected mailboxes', function () {
    $mailbox = mailboxForSyncFeedback();

    expect(MailboxSyncFeedback::for($mailbox))->toMatchArray([
        'phase' => 'idle',
        'percent' => null,
        'button_label' => 'Sync',
        'last_sync_label' => 'Never synced',
        'detail' => 'Inbox + Sent',
        'scope_label' => 'Inbox + Sent',
        'active_scope' => null,
    ]);
});

it('presents queued when a sync has been requested and the worker has not started', function () {
    $mailbox = mailboxForSyncFeedback([
        'last_synced_at' => now()->subHour(),
        'sync_requested_at' => now(),
    ]);

    expect(MailboxSyncFeedback::for($mailbox))->toMatchArray([
        'phase' => 'queued',
        'percent' => null,
        'phase_label' => 'Sync queued',
        'detail' => 'Waiting for the background worker to start',
        'button_label' => 'Queued',
        'last_sync_label' => 'Sync queued',
        'scope_label' => 'Inbox + Sent',
    ]);
});

it('presents inbox backfill as importing inbox', function () {
    $mailbox = mailboxForSyncFeedback([], [
        'inbox' => [
            'status' => SyncStatus::Syncing,
            'scan_state' => ['phase' => 'backfill', 'backfill_progress' => 42],
        ],
    ]);

    expect(MailboxSyncFeedback::for($mailbox))->toMatchArray([
        'phase' => 'syncing',
        'percent' => 42,
        'phase_label' => 'Importing inbox',
        'detail' => 'Last 30 days · Inbox',
        'button_label' => 'Syncing · 42%',
        'last_sync_label' => 'Syncing now',
        'scope_label' => 'Inbox',
        'active_scope' => 'inbox',
    ]);
});

it('starts inbox backfill at one percent before the first page lands', function () {
    $mailbox = mailboxForSyncFeedback([], [
        'inbox' => [
            'status' => SyncStatus::Syncing,
            'scan_state' => ['phase' => 'backfill'],
        ],
    ]);

    expect(MailboxSyncFeedback::for($mailbox))->toMatchArray([
        'phase' => 'syncing',
        'percent' => 1,
        'button_label' => 'Syncing · 1%',
    ]);
});

it('presents a stale syncing state as failed instead of syncing forever', function () {
    $mailbox = mailboxForSyncFeedback([
        'last_sync_error' => 'App\\Jobs\\Mail\\SyncMailbox has timed out',
    ], [
        'inbox' => [
            'status' => SyncStatus::Syncing,
            'scan_state' => ['phase' => 'backfill'],
            'last_started_at' => now()->subSeconds(601),
        ],
    ]);

    expect(MailboxSyncFeedback::for($mailbox))->toMatchArray([
        'phase' => 'failed',
        'percent' => null,
        'phase_label' => 'Sync failed',
        'detail' => 'App\\Jobs\\Mail\\SyncMailbox has timed out',
        'button_label' => 'Sync',
        'last_sync_label' => 'Sync failed',
    ]);
});

it('presents sent syncing while inbox is idle as checking sent mail', function () {
    $mailbox = mailboxForSyncFeedback([], [
        'inbox' => [
            'status' => SyncStatus::Idle,
            'scan_state' => ['phase' => 'incremental'],
        ],
        'sent' => [
            'status' => SyncStatus::Syncing,
            'scan_state' => ['phase' => 'incremental'],
        ],
    ]);

    expect(MailboxSyncFeedback::for($mailbox))->toMatchArray([
        'phase' => 'syncing',
        'percent' => null,
        'phase_label' => 'Checking sent mail',
        'detail' => 'Sent',
        'button_label' => 'Syncing',
        'last_sync_label' => 'Syncing now',
        'scope_label' => 'Sent',
        'active_scope' => 'sent',
    ]);
});

it('presents needs-reconnect as paused with a reconnect action', function () {
    $user = User::factory()->create();
    $mailbox = mailboxForSyncFeedback([
        'user_id' => $user->id,
        'status' => ConnectionStatus::NeedsReconnect,
        'last_synced_at' => now()->subDay(),
        'last_sync_error' => 'Token expired',
    ]);

    expect(MailboxSyncFeedback::for($mailbox))->toMatchArray([
        'phase' => 'failed',
        'detail' => 'Sync paused',
        'scope_label' => 'Sync paused',
        'last_sync_label' => 'Last synced '.$mailbox->last_synced_at->diffForHumans(),
    ]);

    $this->actingAs($user)
        ->get(route('mailboxes.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('mailboxes/Index')
            ->where('mailboxes.0.status', 'needs_reconnect')
            ->where('mailboxes.0.sync.detail', 'Sync paused')
            ->where('mailboxes.0.sync.phase', 'failed'));
});

it('clears the sync request and presents idle after a successful sync', function () {
    $mailbox = Mailbox::factory()->create([
        'sync_requested_at' => now()->subMinute(),
    ]);
    FakeMailProvider::seedMailbox(
        $mailbox->email_address,
        $mailbox->connection->provider,
        $mailbox->connection->provider_user_id,
    );

    app(SyncMailboxService::class)->handle($mailbox->id);

    $mailbox = $mailbox->fresh(['connection', 'syncStates']);

    expect($mailbox->sync_requested_at)->toBeNull()
        ->and($mailbox->last_synced_at)->not->toBeNull()
        ->and(MailboxSyncFeedback::for($mailbox)['phase'])->toBe('idle')
        ->and(MailboxSyncFeedback::for($mailbox)['button_label'])->toBe('Sync')
        ->and(MailboxSyncFeedback::for($mailbox)['last_sync_label'])->toStartWith('Last synced ');
});

it('queues a sync without a flash and returns queued feedback', function () {
    Bus::fake();

    $user = User::factory()->create();
    $mailbox = mailboxForSyncFeedback([
        'user_id' => $user->id,
        'last_synced_at' => now()->subHour(),
    ]);

    $this->actingAs($user)
        ->from(route('mailboxes.index'))
        ->post(route('mailboxes.sync', $mailbox))
        ->assertRedirect()
        ->assertSessionMissing('success');

    expect($mailbox->fresh()->sync_requested_at)->not->toBeNull();

    Bus::assertDispatched(SyncMailbox::class, fn (SyncMailbox $job) => $job->mailboxId === $mailbox->id);

    $this->actingAs($user)
        ->get(route('mailboxes.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('mailboxes/Index')
            ->where('mailboxes.0.sync.phase', 'queued')
            ->where('mailboxes.0.sync.button_label', 'Queued')
            ->where('mailboxes.0.sync.detail', 'Waiting for the background worker to start')
            ->where('mailboxes.0.sync.last_sync_label', 'Sync queued'));
});

it('presents a failed scope with the truncated error and a retry button', function () {
    $mailbox = mailboxForSyncFeedback([
        'last_sync_error' => 'Zoho returned 500 for folder list',
        'last_synced_at' => now()->subHour(),
    ], [
        'inbox' => [
            'status' => SyncStatus::Failed,
            'last_error' => 'Zoho returned 500 for folder list',
            'last_started_at' => now()->subMinute(),
        ],
    ]);

    expect(MailboxSyncFeedback::for($mailbox))->toMatchArray([
        'phase' => 'failed',
        'percent' => null,
        'phase_label' => 'Sync failed',
        'detail' => 'Zoho returned 500 for folder list',
        'button_label' => 'Sync',
        'last_sync_label' => 'Sync failed',
    ]);

    $this->actingAs($mailbox->user)
        ->get(route('mailboxes.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('mailboxes.0.error', 'Zoho returned 500 for folder list')
            ->where('mailboxes.0.sync.phase', 'failed')
            ->where('mailboxes.0.sync.button_label', 'Sync'));
});

it('marks a mailbox queued when connect dispatches the initial sync', function () {
    Bus::fake();
    $user = User::factory()->create();
    FakeMailProvider::seedMailbox('david@outboss.io', MailProviderName::Gmail, 'fake-gmail');

    $this->actingAs($user)
        ->get(route('oauth.redirect', ['provider' => 'gmail']))
        ->assertRedirect();

    $attempt = OAuthAttempt::query()->first();
    $state = Str::random(40);
    $attempt->forceFill(['state_hash' => hash('sha256', $state)])->save();

    $response = $this->actingAs($user)
        ->get(route('oauth.callback', ['provider' => 'gmail', 'code' => 'ok', 'state' => $state]));

    $response->assertRedirect(route('mailboxes.index'));

    $mailbox = Mailbox::query()->first();

    expect(session('error'))->toBeNull();

    expect($mailbox)->not->toBeNull()
        ->and($mailbox->sync_requested_at)->not->toBeNull();

    Bus::assertDispatched(InitialSyncMailbox::class);
});

it('treats a stale sync request as timed out', function () {
    $mailbox = mailboxForSyncFeedback([
        'sync_requested_at' => now()->subSeconds(1081),
        'last_synced_at' => now()->subDay(),
    ]);

    expect(MailboxSyncFeedback::for($mailbox))->toMatchArray([
        'phase' => 'failed',
        'detail' => 'Sync timed out',
        'button_label' => 'Sync',
        'last_sync_label' => 'Sync failed',
    ]);
});
