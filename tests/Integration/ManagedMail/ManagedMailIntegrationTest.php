<?php

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Enums\SequenceSendAs;
use App\Domain\Campaigns\Services\CampaignLaunchReadiness;
use App\Domain\Campaigns\Services\LaunchCampaignService;
use App\Domain\Campaigns\Services\ScheduleDueCampaignSends;
use App\Domain\Campaigns\Services\SendCampaignMessage;
use App\Domain\Mail\DTOs\OutgoingMessage;
use App\Domain\Mail\DTOs\RemoteMessageReference;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\SendAttemptKind;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Providers\StalwartJmapClient;
use App\Domain\Mail\Services\ProvisionManagedDomain;
use App\Domain\Mail\Services\ProvisionManagedMailbox;
use App\Domain\Mail\Services\ReconcileUnknownSend;
use App\Domain\Mail\Services\RotateManagedMailboxCredential;
use App\Domain\Mail\Services\SuspendManagedMailbox;
use App\Domain\Mail\Services\SyncMailboxService;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Message;
use App\Models\SendAttempt;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert;
use Tests\Integration\ManagedMail\ManagedMailIntegrationFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    if (getenv('MANAGED_MAIL_INTEGRATION') !== '1') {
        Assert::markTestSkipped(
            'Managed-mail integration is opt-in. Run scripts/managed-mail-integration.sh test on the disposable Stalwart host.',
        );
    }
});

it('round-trips disposable managed mail through provisioning, JMAP, campaign send, reply, rotation, and cleanup', function (): void {
    $owner = User::factory()->create([
        'name' => 'Managed Mail Integration Owner',
        'email' => 'managed-mail-it-owner@example.com',
    ]);
    $workspace = $owner->currentWorkspace();

    config()->set('outboss.campaign_sending_enabled', true);
    config()->set('outboss.sending.spacing_min_seconds', 0);
    config()->set('outboss.sending.spacing_max_seconds', 0);
    config()->set('outboss.sending.default_daily_cap', 50);
    config()->set('outboss.sending.domain_hourly_limit', 50);

    $fixture = new ManagedMailIntegrationFixture;

    try {
        $domainA = $fixture->provisionDomain($workspace, $owner, $fixture->domainName('sender'));
        $domainB = $fixture->provisionDomain($workspace, $owner, $fixture->domainName('recipient'));

        // A repeated Outboss request returns the same local/remote domain and
        // never needs a second remote principal.
        $sameDomain = app(ProvisionManagedDomain::class)->handle(
            $workspace,
            $owner,
            $domainA->domain,
            'internal',
        );
        expect($sameDomain->remote_domain_id)->toBe($domainA->remote_domain_id);

        $accountA = $fixture->provisionMailbox($workspace, $owner, $domainA, 'sender');
        $sameAccountA = app(ProvisionManagedMailbox::class)->handle(
            $workspace,
            $owner,
            $domainA,
            'sender',
            'Sender Integration',
        );
        $accountB = $fixture->provisionMailbox($workspace, $owner, $domainB, 'recipient');

        expect($sameAccountA->remote_account_id)->toBe($accountA->remote_account_id)
            ->and($sameAccountA->mailbox_id)->toBe($accountA->mailbox_id)
            ->and($accountA->mailbox)->not->toBeNull()
            ->and($accountB->mailbox)->not->toBeNull();

        $mailboxA = $accountA->fresh(['mailbox.connection'])->mailbox;
        $mailboxB = $accountB->fresh(['mailbox.connection'])->mailbox;
        expect($mailboxA)->not->toBeNull()->and($mailboxB)->not->toBeNull();

        // Runtime discovery is the readiness check required before local
        // activation. It uses each account's encrypted password, never the
        // management credential.
        $contextA = $fixture->runtime->mailboxContext($mailboxA->connection);
        $contextB = $fixture->runtime->mailboxContext($mailboxB->connection);
        expect($contextA['account_id'])->not->toBe('')
            ->and($contextA['inbox_id'])->not->toBeNull()
            ->and($contextA['sent_id'])->not->toBeNull()
            ->and($contextB['inbox_id'])->not->toBeNull()
            ->and($contextB['sent_id'])->not->toBeNull();

        $accountA = app(ProvisionManagedMailbox::class)->activate($workspace, $owner, $accountA);
        $accountB = app(ProvisionManagedMailbox::class)->activate($workspace, $owner, $accountB);
        expect($accountA->isActive())->toBeTrue()->and($accountB->isActive())->toBeTrue();

        $mailboxA = $accountA->fresh(['mailbox.connection'])->mailbox;
        $mailboxB = $accountB->fresh(['mailbox.connection'])->mailbox;

        // Give the campaign a second step so the inbound reply can prove that
        // stop-on-reply prevents a follow-up from being scheduled.
        $campaign = Campaign::factory()->create([
            'user_id' => $owner->id,
            'workspace_id' => $workspace->id,
            'name' => 'Managed mail integration campaign',
            'status' => CampaignStatus::Draft,
            'timezone' => 'UTC',
            'send_window_start' => '00:00:00',
            'send_window_end' => '23:59:59',
            'business_days_only' => false,
        ]);
        $campaign->mailboxes()->sync([$mailboxA->id => ['position' => 1]]);
        $firstStep = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->firstOrFail();
        SequenceStep::factory()->create([
            'sequence_version_id' => $firstStep->sequence_version_id,
            'position' => 2,
            'title' => 'Reply step',
            'delay_minutes' => 0,
            'send_as' => SequenceSendAs::Reply,
            'subject_template' => 'Following up',
            'body_template' => 'Following up from the managed integration fixture.',
        ]);

        $recipient = CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'workspace_id' => $workspace->id,
            'mailbox_id' => $mailboxA->id,
            'sequence_version_id' => $campaign->draft_sequence_version_id,
            'next_sequence_step_id' => $firstStep->id,
            'status' => RecipientStatus::Queued,
            'email_address' => $mailboxB->email_address,
            'email_normalized' => strtolower($mailboxB->email_address),
            'email_domain' => substr(strrchr($mailboxB->email_address, '@') ?: '', 1),
            'first_name' => 'Recipient',
            'full_name' => 'Recipient Integration',
            'company' => 'Managed Mail Fixture',
        ]);

        app(LaunchCampaignService::class)->handle($campaign->fresh(), $user);
        expect(app(CampaignLaunchReadiness::class)->blockers($campaign->fresh()))->toBe([]);
        expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(1);

        $attempt = SendAttempt::query()
            ->where('campaign_recipient_id', $recipient->id)
            ->where('kind', SendAttemptKind::Campaign)
            ->firstOrFail();

        // Execute the same sender service the queue job uses. The provider
        // registry has been replaced with the real Stalwart transport above.
        app(SendCampaignMessage::class)->handle($attempt->id);
        $attempt->refresh();
        $sentMessage = Message::query()->findOrFail($attempt->message_id);

        expect($attempt->status)->toBe(SendAttemptStatus::Sent)
            ->and($sentMessage->remote_message_id)->not->toBeNull()
            ->and($sentMessage->internet_message_id)->not->toBeNull()
            ->and($recipient->fresh()->status)->toBe(RecipientStatus::Active);

        // A duplicate queue delivery after a successful acknowledgement is a
        // no-op, so it cannot submit a second remote copy.
        app(SendCampaignMessage::class)->handle($attempt->id);
        expect(SendAttempt::query()
            ->where('campaign_recipient_id', $recipient->id)
            ->where('kind', SendAttemptKind::Campaign)
            ->count())->toBe(1);

        // Simulate the worker losing the response after Stalwart accepted the
        // submission. ReconcileUnknownSend must find the stable marker in
        // Sent and restore the same local send without a second submission.
        $attempt->forceFill([
            'status' => SendAttemptStatus::Unknown,
            'provider_marker' => $sentMessage->client_message_marker,
            'provider_phase' => 'send_submitted',
            'provider_accepted_at' => null,
            'sent_at' => null,
        ])->save();

        expect(app(ReconcileUnknownSend::class)->handle($attempt->id))->toBe(1);
        $attempt->refresh();
        expect($attempt->status)->toBe(SendAttemptStatus::Sent)
            ->and($attempt->provider_phase)->toBe('reconciled_sent')
            ->and(Message::query()->findOrFail($attempt->message_id)->remote_message_id)
            ->toBe($sentMessage->remote_message_id);

        $rawSentMessage = [];
        $fixture->waitUntil(function () use ($mailboxA, $contextA, $sentMessage, &$rawSentMessage): bool {
            $rawSent = app(StalwartJmapClient::class)->getMessages(
                $mailboxA->connection,
                $contextA['account_id'],
                [(string) $sentMessage->remote_message_id],
            );
            $rawSentMessage = $rawSent[0] ?? [];

            return $rawSentMessage !== [];
        });
        $unsubscribeHeader = managedMailIntegrationHeaderValue($rawSentMessage, 'List-Unsubscribe');
        expect($rawSentMessage)->not->toBe([])
            // Keep the token-bearing header out of assertion diagnostics.
            ->and($unsubscribeHeader !== null && str_contains($unsubscribeHeader, '/unsub/'))->toBeTrue()
            ->and(managedMailIntegrationHeaderValue($rawSentMessage, 'List-Unsubscribe-Post'))->toBe('List-Unsubscribe=One-Click');

        $sync = app(SyncMailboxService::class);
        $fixture->waitUntil(function () use ($sync, $mailboxA, $mailboxB, $sentMessage): bool {
            $sync->handle($mailboxA->id);
            $sync->handle($mailboxB->id);

            return Message::query()
                ->where('mailbox_id', $mailboxA->id)
                ->where('remote_message_id', $sentMessage->remote_message_id)
                ->exists()
                && Message::query()
                    ->where('mailbox_id', $mailboxB->id)
                    ->where('direction', MessageDirection::Inbound)
                    ->where('from_email', $mailboxA->email_address)
                    ->exists();
        });

        $sentCount = Message::query()
            ->where('mailbox_id', $mailboxA->id)
            ->where('remote_message_id', $sentMessage->remote_message_id)
            ->count();
        $inboxCount = Message::query()
            ->where('mailbox_id', $mailboxB->id)
            ->where('direction', MessageDirection::Inbound)
            ->where('from_email', $mailboxA->email_address)
            ->count();

        // Replaying sync is safe: one remote copy produces one local row.
        $sync->handle($mailboxA->id);
        $sync->handle($mailboxB->id);
        expect(Message::query()
            ->where('mailbox_id', $mailboxA->id)
            ->where('remote_message_id', $sentMessage->remote_message_id)
            ->count())->toBe($sentCount)
            ->and(Message::query()
                ->where('mailbox_id', $mailboxB->id)
                ->where('direction', MessageDirection::Inbound)
                ->where('from_email', $mailboxA->email_address)
                ->count())->toBe($inboxCount);

        $reply = $fixture->provider()->sendReply(
            $mailboxB,
            new RemoteMessageReference(
                remoteMessageId: (string) $sentMessage->remote_message_id,
                remoteThreadId: $sentMessage->thread()->value('remote_thread_id'),
                internetMessageId: $sentMessage->internet_message_id,
                subject: $sentMessage->subject,
            ),
            new OutgoingMessage(
                subject: 'Re: '.$sentMessage->subject,
                bodyText: 'Thanks, please send me the details.',
                bodyHtml: '<p>Thanks, please send me the details.</p>',
                to: [['email' => $mailboxA->email_address]],
                fromEmail: $mailboxB->email_address,
                clientMessageMarker: 'managed-mail-it-reply-'.bin2hex(random_bytes(4)),
                inReplyTo: $sentMessage->internet_message_id,
                references: [$sentMessage->internet_message_id],
            ),
        );

        expect($reply->remoteMessageId)->not->toBe('');

        $fixture->waitUntil(function () use ($sync, $mailboxA, $sentMessage): bool {
            $sync->handle($mailboxA->id);

            return Message::query()
                ->where('mailbox_id', $mailboxA->id)
                ->where('direction', MessageDirection::Inbound)
                ->where('in_reply_to', $sentMessage->internet_message_id)
                ->exists();
        });

        $inboundReply = Message::query()
            ->where('mailbox_id', $mailboxA->id)
            ->where('direction', MessageDirection::Inbound)
            ->where('in_reply_to', $sentMessage->internet_message_id)
            ->firstOrFail();
        $recipient->refresh();

        expect($inboundReply->thread_id)->toBe($sentMessage->thread_id)
            ->and($inboundReply->references)->toContain($sentMessage->internet_message_id)
            ->and($recipient->status)->toBe(RecipientStatus::Replied)
            ->and($recipient->stop_reason)->toBe('human_reply')
            ->and($recipient->next_send_at)->toBeNull()
            ->and(app(ScheduleDueCampaignSends::class)->handle())->toBe(0)
            ->and(SendAttempt::query()->where('campaign_recipient_id', $recipient->id)->count())->toBe(1);

        $oldCredentialPayload = $mailboxA->connection->fresh()->getAttribute('credential_payload');
        $oldCredentials = is_array($oldCredentialPayload) ? $oldCredentialPayload : [];
        $oldUsername = (string) ($oldCredentials['username'] ?? '');
        $oldPassword = (string) ($oldCredentials['password'] ?? '');
        $oldVersion = (int) ($oldCredentials['version'] ?? 1);

        $rotated = app(RotateManagedMailboxCredential::class)->handle($workspace, $owner, $accountA);
        $newConnection = $rotated->fresh(['mailbox.connection'])->mailbox?->connection;
        if ($newConnection === null) {
            throw new RuntimeException('Managed mailbox rotation did not return a provider connection.');
        }
        $newCredentialPayload = $newConnection->getAttribute('credential_payload');
        $newCredentials = is_array($newCredentialPayload) ? $newCredentialPayload : [];

        expect((int) ($newCredentials['version'] ?? 0))->toBeGreaterThan($oldVersion)
            // Compare digests so a failed assertion cannot print a mailbox
            // password in PHPUnit diagnostics.
            ->and(hash('sha256', (string) ($newCredentials['password'] ?? '')))
            ->not->toBe(hash('sha256', $oldPassword));

        $oldLogin = Http::withBasicAuth($oldUsername, $oldPassword)
            ->acceptJson()
            ->withOptions(['verify' => false])
            ->get($fixture->baseUrl.'/.well-known/jmap');
        expect($oldLogin->status())->toBeIn([401, 403]);

        $newSession = (new StalwartJmapClient)->session($newConnection);
        expect($newSession['apiUrl'] ?? null)->toBeString();

        $suspended = app(SuspendManagedMailbox::class)->handle($workspace, $owner, $rotated);
        $suspendedMailbox = $suspended->fresh('mailbox')->mailbox;
        if ($suspendedMailbox === null) {
            throw new RuntimeException('Managed mailbox suspension did not return the mailbox.');
        }
        $suspendedStatus = $suspended->getAttribute('status');
        $suspendedStatus = $suspendedStatus instanceof BackedEnum ? $suspendedStatus->value : (string) $suspendedStatus;
        expect($suspendedStatus)->toBe('suspended')
            ->and($suspendedMailbox->status)->toBe(ConnectionStatus::NeedsReconnect)
            ->and($suspendedMailbox->sending_paused_at)->not->toBeNull()
            ->and($suspendedMailbox->isAvailable())->toBeFalse();

        // The scheduler must refuse a new campaign attempt as soon as the
        // sender mailbox is suspended, even if the campaign itself remains
        // active. No remote call is made and no local attempt is created.
        $campaign->forceFill(['status' => CampaignStatus::Active])->save();
        $blockedDomain = substr(strrchr($mailboxB->email_address, '@') ?: '', 1);
        $blockedEmail = 'blocked@'.$blockedDomain;
        $blockedRecipient = CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'workspace_id' => $workspace->id,
            'mailbox_id' => $suspendedMailbox->id,
            'sequence_version_id' => $campaign->draft_sequence_version_id,
            'next_sequence_step_id' => $firstStep->id,
            'next_send_at' => now()->subSecond(),
            'status' => RecipientStatus::Active,
            'email_address' => $blockedEmail,
            'email_normalized' => $blockedEmail,
            'email_domain' => $blockedDomain,
        ]);

        expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(0)
            ->and(SendAttempt::query()
                ->where('campaign_recipient_id', $blockedRecipient->id)
                ->exists())->toBeFalse();
    } finally {
        $fixture->cleanup();
    }
});

/** @param array<string, mixed> $message */
function managedMailIntegrationHeaderValue(array $message, string $header): ?string
{
    foreach ($message as $key => $value) {
        if (! str_starts_with(strtolower((string) $key), 'header:')) {
            continue;
        }

        $name = substr((string) $key, 7);
        $name = preg_replace('/:as[a-z]+(?::all)?$/i', '', $name) ?: $name;
        if (strcasecmp($name, $header) !== 0) {
            continue;
        }

        if (is_string($value)) {
            return trim($value);
        }

        if (is_array($value)) {
            foreach (array_reverse($value) as $item) {
                if (is_string($item) && trim($item) !== '') {
                    return trim($item);
                }
            }
        }
    }

    return null;
}
