<?php

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\ImportRowIssue;
use App\Domain\Campaigns\Enums\ImportSource;
use App\Domain\Campaigns\Enums\ImportStatus;
use App\Domain\Campaigns\Enums\MailboxAssignment;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Enums\SendMode;
use App\Domain\Campaigns\Enums\SequenceSendAs;
use App\Domain\Campaigns\Enums\SequenceVersionStatus;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\MessageStatus;
use App\Domain\Mail\Enums\OAuthIntent;
use App\Domain\Mail\Enums\SendAttemptKind;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Enums\SuppressionReason;
use App\Domain\Mail\Enums\SyncScope;
use App\Domain\Mail\Enums\SyncStatus;
use App\Domain\Mail\Enums\ThreadClassification;
use App\Domain\Mail\Enums\ThreadLocalState;
use App\Models\Account;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\OAuthAttempt;
use App\Models\Person;
use App\Models\ProviderConnection;
use App\Models\RecipientImport;
use App\Models\RecipientImportRow;
use App\Models\SendAttempt;
use App\Models\SequenceStep;
use App\Models\SequenceVersion;
use App\Models\SuppressionEntry;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

it('inserts every initial lifecycle row with nullable transition fields', function () {
    $user = User::factory()->create(['timezone' => 'UTC']);
    $workspace = $user->currentWorkspace();

    $attempt = OAuthAttempt::factory()->create([
        'user_id' => $user->id,
        'provider' => MailProviderName::Gmail,
        'intent' => OAuthIntent::ConnectMailbox,
        'consumed_at' => null,
    ]);

    $connection = ProviderConnection::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'status' => ConnectionStatus::Connected,
        'last_error_code' => null,
        'last_error_message' => null,
    ]);

    $mailbox = Mailbox::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'provider_connection_id' => $connection->id,
        'last_synced_at' => null,
        'last_sync_error' => null,
    ]);

    $mailbox->syncStates()->create([
        'scope' => SyncScope::Inbox,
        'status' => SyncStatus::Idle,
        'cursor' => null,
        'high_water_timestamp' => null,
        'last_error' => null,
    ]);

    $campaign = Campaign::query()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'name' => 'Q3 founders',
        'status' => CampaignStatus::Draft,
        'send_mode' => SendMode::Rotate,
        'timezone' => 'UTC',
    ]);

    $version = SequenceVersion::query()->create([
        'campaign_id' => $campaign->id,
        'version' => 1,
        'status' => SequenceVersionStatus::Draft,
        'published_at' => null,
    ]);

    $step = SequenceStep::query()->create([
        'sequence_version_id' => $version->id,
        'position' => 1,
        'title' => 'Intro',
        'delay_minutes' => 0,
        'send_as' => SequenceSendAs::NewThread,
        'subject_template' => 'Hello {{company}}',
        'body_template' => '{{first_name}} — hello',
        'deleted_at' => null,
    ]);

    $campaign->forceFill(['draft_sequence_version_id' => $version->id])->save();
    expect($step->fresh()->subject_spintax_enabled)->toBeFalse()
        ->and($step->fresh()->body_spintax_enabled)->toBeFalse();
    $campaign->mailboxes()->attach($mailbox->id, ['position' => 1]);

    $account = Account::query()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Stripe',
        'website' => 'https://www.stripe.com',
        'domain_normalized' => 'stripe.com',
        'notes' => 'Payments',
        'tags' => ['fintech', 'saas'],
        'source' => Account::SOURCE_MANUAL,
        'created_by_user_id' => $user->id,
    ]);

    $person = Person::query()->create([
        'workspace_id' => $workspace->id,
        'account_id' => $account->id,
        'full_name' => 'Maya Chen',
        'first_name' => 'Maya',
        'email_address' => 'maya@stripe.com',
        'email_normalized' => 'maya@stripe.com',
        'job_title' => 'Founder',
        'notes' => null,
        'tags' => ['founder'],
        'source' => Person::SOURCE_MANUAL,
        'created_by_user_id' => $user->id,
    ]);

    $recipient = CampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $workspace->id,
        'mailbox_id' => $mailbox->id,
        'sequence_version_id' => $version->id,
        'account_id' => $account->id,
        'person_id' => $person->id,
        'email_address' => 'maya@stripe.com',
        'email_normalized' => 'maya@stripe.com',
        'status' => RecipientStatus::Queued,
        'mailbox_assignment' => MailboxAssignment::Rotation,
        'next_send_at' => null,
        'first_sent_at' => null,
        'first_opened_at' => null,
    ]);

    $import = RecipientImport::query()->create([
        'campaign_id' => $campaign->id,
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'source' => ImportSource::Paste,
        'source_hash' => hash('sha256', 'sample'),
        'headers' => ['email'],
        'mapping' => ['email' => 'email'],
        'status' => ImportStatus::Previewed,
    ]);

    RecipientImportRow::query()->create([
        'recipient_import_id' => $import->id,
        'row_number' => 1,
        'raw_values' => ['email' => 'maya@stripe.com'],
        'email_address' => 'maya@stripe.com',
        'email_normalized' => 'maya@stripe.com',
        'issue' => ImportRowIssue::Ready,
        'row_hash' => hash('sha256', 'maya@stripe.com'),
    ]);

    $thread = Thread::query()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
        'local_key' => (string) Str::uuid(),
        'remote_thread_id' => null,
        'subject' => 'Hello Stripe',
        'subject_normalized' => 'hello stripe',
        'classification' => ThreadClassification::Unknown,
        'local_state' => ThreadLocalState::Open,
        'last_message_at' => null,
    ]);

    $message = Message::query()->create([
        'workspace_id' => $workspace->id,
        'local_key' => (string) Str::uuid(),
        'thread_id' => $thread->id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
        'sequence_step_id' => $step->id,
        'client_message_marker' => (string) Str::uuid(),
        'remote_message_id' => null,
        'internet_message_id' => null,
        'direction' => MessageDirection::Outbound,
        'status' => MessageStatus::Queued,
        'to_recipients' => [['email' => 'maya@stripe.com']],
        'cc_recipients' => [],
        'bcc_recipients' => [],
        'subject' => 'Hello Stripe',
        'body_text' => 'Maya — hello',
        'provider_metadata' => [],
        'sent_at' => null,
        'received_at' => null,
    ]);

    $sendAttempt = SendAttempt::query()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'mailbox_id' => $mailbox->id,
        'message_id' => $message->id,
        'campaign_recipient_id' => $recipient->id,
        'sequence_step_id' => $step->id,
        'kind' => SendAttemptKind::Campaign,
        'idempotency_key' => (string) Str::uuid(),
        'status' => SendAttemptStatus::Scheduled,
        'due_at' => now(),
        'claimed_at' => null,
        'lease_acquired_at' => null,
        'provider_accepted_at' => null,
        'sent_at' => null,
    ]);

    SuppressionEntry::query()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'email_address' => 'gone@example.com',
        'email_normalized' => 'gone@example.com',
        'reason' => SuppressionReason::Manual,
        'source_message_id' => null,
    ]);

    expect($attempt->consumed_at)->toBeNull()
        ->and($connection->last_error_code)->toBeNull()
        ->and($mailbox->last_synced_at)->toBeNull()
        ->and($recipient->first_sent_at)->toBeNull()
        ->and($recipient->account_id)->toBe($account->id)
        ->and($recipient->person_id)->toBe($person->id)
        ->and($person->fresh()->tags)->toBe(['founder'])
        ->and($account->fresh()->tags)->toBe(['fintech', 'saas'])
        ->and($thread->remote_thread_id)->toBeNull()
        ->and($message->remote_message_id)->toBeNull()
        ->and($sendAttempt->lease_acquired_at)->toBeNull();
});

it('enforces a unique normalised domain per workspace and allows domain-less accounts', function () {
    $account = Account::factory()->create([
        'domain_normalized' => 'acme.com',
    ]);

    expect(fn () => Account::factory()->create([
        'workspace_id' => $account->workspace_id,
        'domain_normalized' => 'acme.com',
    ]))->toThrow(QueryException::class);

    Account::factory()->create([
        'workspace_id' => $account->workspace_id,
        'website' => null,
        'domain_normalized' => null,
    ]);
    Account::factory()->create([
        'workspace_id' => $account->workspace_id,
        'website' => null,
        'domain_normalized' => null,
    ]);

    expect(Account::query()->where('workspace_id', $account->workspace_id)->whereNull('domain_normalized')->count())->toBe(2);
});

it('enforces a unique normalised email per workspace and allows the same email across workspaces', function () {
    $person = Person::factory()->create([
        'email_address' => 'maya@stripe.com',
        'email_normalized' => 'maya@stripe.com',
        'tags' => ['founder', 'design'],
    ]);

    expect($person->fresh()->tags)->toBe(['founder', 'design']);

    expect(fn () => Person::factory()->create([
        'workspace_id' => $person->workspace_id,
        'email_address' => 'Maya@Stripe.com',
        'email_normalized' => 'maya@stripe.com',
    ]))->toThrow(QueryException::class);

    $other = Person::factory()->create([
        'email_address' => 'maya@stripe.com',
        'email_normalized' => 'maya@stripe.com',
    ]);

    expect($other->workspace_id)->not->toBe($person->workspace_id);
});

it('enforces a unique campaign recipient email', function () {
    $recipient = CampaignRecipient::factory()->create();

    expect(fn () => CampaignRecipient::factory()->create([
        'campaign_id' => $recipient->campaign_id,
        'mailbox_id' => $recipient->mailbox_id,
        'sequence_version_id' => $recipient->sequence_version_id,
        'email_address' => $recipient->email_address,
        'email_normalized' => $recipient->email_normalized,
    ]))->toThrow(QueryException::class);
});

it('enforces a unique campaign recipient person and allows the same person in two campaigns', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $person = Person::factory()->create(['workspace_id' => $workspace->id]);
    $first = Campaign::factory()->create(['user_id' => $user->id, 'workspace_id' => $workspace->id]);
    $second = Campaign::factory()->create(['user_id' => $user->id, 'workspace_id' => $workspace->id]);
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $first->id,
        'workspace_id' => $workspace->id,
        'person_id' => $person->id,
        'email_address' => 'maya@acme.test',
        'email_normalized' => 'maya@acme.test',
    ]);

    expect(fn () => CampaignRecipient::factory()->create([
        'campaign_id' => $first->id,
        'mailbox_id' => $recipient->mailbox_id,
        'sequence_version_id' => $recipient->sequence_version_id,
        'workspace_id' => $workspace->id,
        'person_id' => $person->id,
        'email_address' => 'other@acme.test',
        'email_normalized' => 'other@acme.test',
    ]))->toThrow(QueryException::class);

    $otherCampaign = CampaignRecipient::factory()->create([
        'campaign_id' => $second->id,
        'workspace_id' => $workspace->id,
        'person_id' => $person->id,
        'email_address' => 'maya@acme.test',
        'email_normalized' => 'maya@acme.test',
    ]);

    expect($otherCampaign->person_id)->toBe($person->id);
});

it('preserves threads when a mailbox is deleted', function () {
    $thread = Thread::factory()->create();
    $mailbox = $thread->mailbox;

    $mailbox->syncStates()->delete();
    $mailbox->delete();

    expect(Thread::query()->whereKey($thread->id)->exists())->toBeTrue()
        ->and($thread->fresh()->mailbox_id)->toBeNull();
});

it('cascades import rows when an import is purged', function () {
    $row = RecipientImportRow::factory()->create();
    $importId = $row->recipient_import_id;

    $row->import->delete();

    expect(RecipientImportRow::query()->where('recipient_import_id', $importId)->exists())->toBeFalse();
});

it('nulls suppression source messages on message delete after dependent rows are gone', function () {
    $message = Message::factory()->create();
    $suppression = SuppressionEntry::factory()->create([
        'user_id' => $message->mailbox->user_id,
        'source_message_id' => $message->id,
    ]);

    $message->delete();

    expect($suppression->fresh()->source_message_id)->toBeNull();
});
