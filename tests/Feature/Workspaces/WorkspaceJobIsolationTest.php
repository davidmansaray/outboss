<?php

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Services\AcquireRecipientSendLease;
use App\Domain\Campaigns\Services\ScheduleDueCampaignSends;
use App\Domain\Campaigns\Services\SendCampaignMessage;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\SendAttemptKind;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Providers\FakeMailProvider;
use App\Domain\Mail\Services\DisconnectMailboxService;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\ProviderConnection;
use App\Models\SendAttempt;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

it('does not schedule a recipient when its mailbox is in another workspace', function () {
    FakeMailProvider::reset();
    Config::set('outboss.campaign_sending_enabled', true);

    $owner = User::factory()->create();
    $other = User::factory()->create();
    $campaign = Campaign::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $owner->currentWorkspace()->id,
        'status' => CampaignStatus::Active,
        'business_days_only' => false,
    ]);
    $ownerMailbox = Mailbox::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $owner->currentWorkspace()->id,
    ]);
    $otherMailbox = Mailbox::factory()->create([
        'user_id' => $other->id,
        'workspace_id' => $other->currentWorkspace()->id,
    ]);
    $campaign->mailboxes()->attach($ownerMailbox->id, ['position' => 1]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->firstOrFail();
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $campaign->workspace_id,
        'mailbox_id' => $ownerMailbox->id,
        'sequence_version_id' => $step->sequence_version_id,
        'next_sequence_step_id' => $step->id,
        'next_send_at' => now()->subMinute(),
        'status' => RecipientStatus::Active,
    ]);

    $recipient->forceFill(['mailbox_id' => $otherMailbox->id])->save();

    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(0)
        ->and(SendAttempt::query()->where('campaign_recipient_id', $recipient->id)->count())->toBe(0)
        ->and(Message::query()->where('campaign_recipient_id', $recipient->id)->count())->toBe(0)
        ->and($recipient->fresh()->status)->toBe(RecipientStatus::BlockedMailbox)
        ->and($recipient->fresh()->stop_reason)->toBe('workspace_mismatch')
        ->and(FakeMailProvider::$store['sends'] ?? [])->toBe([]);
});

it('fails a poisoned send attempt before calling the provider', function () {
    FakeMailProvider::reset();
    Config::set('outboss.campaign_sending_enabled', true);

    $owner = User::factory()->create();
    $other = User::factory()->create();
    $ownerWorkspaceId = $owner->currentWorkspace()->id;
    $otherWorkspaceId = $other->currentWorkspace()->id;
    $mailbox = Mailbox::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $ownerWorkspaceId,
    ]);
    $campaign = Campaign::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $ownerWorkspaceId,
        'status' => CampaignStatus::Active,
        'business_days_only' => false,
    ]);
    $campaign->mailboxes()->attach($mailbox->id, ['position' => 1]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->firstOrFail();
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $ownerWorkspaceId,
        'mailbox_id' => $mailbox->id,
        'sequence_version_id' => $step->sequence_version_id,
        'next_sequence_step_id' => $step->id,
        'status' => RecipientStatus::Active,
    ]);
    $message = Message::factory()->outbound()->create([
        'workspace_id' => $ownerWorkspaceId,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
        'direction' => MessageDirection::Outbound,
    ]);
    $attempt = SendAttempt::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $ownerWorkspaceId,
        'mailbox_id' => $mailbox->id,
        'message_id' => $message->id,
        'campaign_recipient_id' => $recipient->id,
        'sequence_step_id' => $step->id,
        'kind' => SendAttemptKind::Campaign,
        'status' => SendAttemptStatus::Queued,
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $message->forceFill(['workspace_id' => $otherWorkspaceId])->save();

    app(SendCampaignMessage::class)->handle($attempt->id);

    expect($attempt->fresh()->status)->toBe(SendAttemptStatus::Failed)
        ->and($attempt->fresh()->error_code)->toBe('workspace_mismatch')
        ->and($attempt->fresh()->attempt_count)->toBe(0)
        ->and(FakeMailProvider::$store['sends'] ?? [])->toBe([]);
});

it('does not mutate a foreign recipient when scheduling detects a workspace mismatch', function () {
    FakeMailProvider::reset();
    Config::set('outboss.campaign_sending_enabled', true);

    $owner = User::factory()->create();
    $other = User::factory()->create();
    $ownerWorkspaceId = $owner->currentWorkspace()->id;
    $otherWorkspaceId = $other->currentWorkspace()->id;
    $campaign = Campaign::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $ownerWorkspaceId,
        'status' => CampaignStatus::Active,
        'business_days_only' => false,
    ]);
    $foreignMailbox = Mailbox::factory()->create([
        'user_id' => $other->id,
        'workspace_id' => $otherWorkspaceId,
    ]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->firstOrFail();
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $otherWorkspaceId,
        'mailbox_id' => $foreignMailbox->id,
        'sequence_version_id' => $step->sequence_version_id,
        'next_sequence_step_id' => $step->id,
        'next_send_at' => now()->subMinute(),
        'status' => RecipientStatus::Active,
        'stop_reason' => null,
    ]);

    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(0)
        ->and($recipient->fresh()->status)->toBe(RecipientStatus::Active)
        ->and($recipient->fresh()->stop_reason)->toBeNull()
        ->and($recipient->fresh()->send_lease_token)->toBeNull();
});

it('does not clear a foreign recipient lease when acquiring a poisoned attempt', function () {
    FakeMailProvider::reset();
    Config::set('outboss.campaign_sending_enabled', true);

    $owner = User::factory()->create();
    $other = User::factory()->create();
    $ownerWorkspaceId = $owner->currentWorkspace()->id;
    $otherWorkspaceId = $other->currentWorkspace()->id;
    $mailbox = Mailbox::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $ownerWorkspaceId,
    ]);
    $foreignMailbox = Mailbox::factory()->create([
        'user_id' => $other->id,
        'workspace_id' => $otherWorkspaceId,
    ]);
    $campaign = Campaign::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $ownerWorkspaceId,
        'status' => CampaignStatus::Active,
    ]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->firstOrFail();
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $otherWorkspaceId,
        'mailbox_id' => $foreignMailbox->id,
        'sequence_version_id' => $step->sequence_version_id,
        'next_sequence_step_id' => $step->id,
        'status' => RecipientStatus::Active,
        'send_lease_token' => 'keep-me',
        'send_lease_expires_at' => now()->addMinutes(5),
    ]);
    $message = Message::factory()->outbound()->create([
        'workspace_id' => $ownerWorkspaceId,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
    ]);
    $attempt = SendAttempt::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $ownerWorkspaceId,
        'mailbox_id' => $mailbox->id,
        'message_id' => $message->id,
        'campaign_recipient_id' => $recipient->id,
        'sequence_step_id' => $step->id,
        'kind' => SendAttemptKind::Campaign,
        'status' => SendAttemptStatus::Queued,
        'idempotency_key' => (string) Str::uuid(),
    ]);

    expect(app(AcquireRecipientSendLease::class)->handle($attempt))->toBeNull()
        ->and($attempt->fresh()->status)->toBe(SendAttemptStatus::Failed)
        ->and($recipient->fresh()->send_lease_token)->toBe('keep-me');
});

it('rejects a campaign reply whose parent message belongs to another workspace', function () {
    FakeMailProvider::reset();
    Config::set('outboss.campaign_sending_enabled', true);

    $owner = User::factory()->create();
    $other = User::factory()->create();
    $ownerWorkspaceId = $owner->currentWorkspace()->id;
    $otherWorkspaceId = $other->currentWorkspace()->id;
    $mailbox = Mailbox::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $ownerWorkspaceId,
    ]);
    $foreignMailbox = Mailbox::factory()->create([
        'user_id' => $other->id,
        'workspace_id' => $otherWorkspaceId,
    ]);
    $campaign = Campaign::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $ownerWorkspaceId,
        'status' => CampaignStatus::Active,
    ]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->firstOrFail();
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $ownerWorkspaceId,
        'mailbox_id' => $mailbox->id,
        'sequence_version_id' => $step->sequence_version_id,
        'next_sequence_step_id' => $step->id,
        'status' => RecipientStatus::Active,
    ]);
    $thread = Thread::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $ownerWorkspaceId,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
    ]);
    $foreignThread = Thread::factory()->create([
        'user_id' => $other->id,
        'workspace_id' => $otherWorkspaceId,
        'mailbox_id' => $foreignMailbox->id,
    ]);
    $parent = Message::factory()->outbound()->create([
        'workspace_id' => $otherWorkspaceId,
        'thread_id' => $foreignThread->id,
        'mailbox_id' => $foreignMailbox->id,
        'remote_message_id' => 'foreign-parent',
        'internet_message_id' => '<foreign-parent@example.test>',
    ]);
    $message = Message::factory()->outbound()->create([
        'workspace_id' => $ownerWorkspaceId,
        'thread_id' => $thread->id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
        'reply_to_message_id' => $parent->id,
    ]);
    $attempt = SendAttempt::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $ownerWorkspaceId,
        'mailbox_id' => $mailbox->id,
        'message_id' => $message->id,
        'campaign_recipient_id' => $recipient->id,
        'sequence_step_id' => $step->id,
        'kind' => SendAttemptKind::Campaign,
        'status' => SendAttemptStatus::Queued,
        'idempotency_key' => (string) Str::uuid(),
    ]);

    app(SendCampaignMessage::class)->handle($attempt->id);

    expect($attempt->fresh()->status)->toBe(SendAttemptStatus::Failed)
        ->and($attempt->fresh()->error_code)->toBe('workspace_mismatch')
        ->and(FakeMailProvider::$store['sends'] ?? [])->toBe([]);
});

it('does not revoke a provider connection from another workspace when disconnecting a poisoned mailbox', function () {
    FakeMailProvider::reset();

    $owner = User::factory()->create();
    $other = User::factory()->create();
    $ownerWorkspaceId = $owner->currentWorkspace()->id;
    $otherWorkspaceId = $other->currentWorkspace()->id;
    $mailbox = Mailbox::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $ownerWorkspaceId,
    ]);
    $foreignConnection = ProviderConnection::factory()->create([
        'user_id' => $other->id,
        'workspace_id' => $otherWorkspaceId,
        'provider_user_id' => 'foreign-connection',
    ]);
    $mailbox->forceFill(['provider_connection_id' => $foreignConnection->id])->save();

    expect(fn () => app(DisconnectMailboxService::class)->handle($mailbox->fresh()))
        ->toThrow(RuntimeException::class, 'workspace_mismatch');

    expect($mailbox->fresh())->not->toBeNull()
        ->and($foreignConnection->fresh()->status)->not->toBe(ConnectionStatus::Disconnected)
        ->and($foreignConnection->fresh()->access_token)->not->toBeNull()
        ->and(FakeMailProvider::$store['revocations'] ?? [])->toBe([]);
});
