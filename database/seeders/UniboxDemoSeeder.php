<?php

namespace Database\Seeders;

use App\Domain\Campaigns\Enums\MailboxAssignment;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Services\CreateCampaignService;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\MessageStatus;
use App\Domain\Mail\Enums\ThreadClassification;
use App\Domain\Mail\Enums\ThreadLocalState;
use App\Domain\People\Services\BackfillAccountsFromRecipients;
use App\Domain\People\Services\BackfillPeopleFromRecipients;
use App\Domain\Workspaces\Services\ProvisionWorkspace;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\ProviderConnection;
use App\Models\Thread;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * @phpstan-type DemoMessage array{direction: MessageDirection, minutes_ago: int, body: string}
 * @phpstan-type DemoThread array{
 *     key: string,
 *     mailbox: string,
 *     campaign: string|null,
 *     participant_name: string,
 *     participant_email: string,
 *     company: string,
 *     recipient_status: RecipientStatus|null,
 *     subject: string,
 *     classification: ThreadClassification,
 *     state: ThreadLocalState,
 *     unread: bool,
 *     messages: list<DemoMessage>
 * }
 */
class UniboxDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public const USER_EMAIL = 'david@outboss.test';

    public function run(): void
    {
        $user = User::query()->updateOrCreate(
            ['email' => self::USER_EMAIL],
            [
                'name' => 'David Mansaray',
                'password' => 'password',
                'timezone' => 'America/Halifax',
                'email_verified_at' => now(),
            ],
        );
        $workspace = $this->workspaceFor($user);

        $mailboxes = [
            'primary' => $this->mailbox(
                $user,
                $workspace,
                MailProviderName::Gmail,
                'david@outboss.test',
                'David · Primary',
            ),
            'founders' => $this->mailbox(
                $user,
                $workspace,
                MailProviderName::Microsoft,
                'founders@outboss.test',
                'Outboss · Founders',
            ),
        ];

        $campaigns = [
            'founders' => $this->campaign($user, $workspace, 'Q3 Series A founders', array_values($mailboxes)),
            'ecommerce' => $this->campaign($user, $workspace, 'Ecommerce platform leaders', array_values($mailboxes)),
        ];

        foreach ($this->threadFixtures() as $fixture) {
            $mailbox = $mailboxes[$fixture['mailbox']];
            $recipient = $fixture['campaign'] !== null && $fixture['recipient_status'] !== null
                ? $this->recipient(
                    $campaigns[$fixture['campaign']],
                    $mailbox,
                    $fixture['participant_name'],
                    $fixture['participant_email'],
                    $fixture['company'],
                    $fixture['recipient_status'],
                )
                : null;

            $this->thread($user, $mailbox, $recipient, $fixture);
        }

        (new BackfillAccountsFromRecipients)->handle();
        (new BackfillPeopleFromRecipients)->handle();
    }

    private function mailbox(
        User $user,
        Workspace $workspace,
        MailProviderName $provider,
        string $email,
        string $name,
    ): Mailbox {
        $connection = ProviderConnection::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_user_id' => 'demo-'.$provider->value,
            ],
            [
                'workspace_id' => $workspace->id,
                'access_token' => 'demo-access',
                'refresh_token' => 'demo-refresh',
                'token_expires_at' => now()->addHour(),
                'token_version' => 0,
                'granted_scopes' => ['mail.read', 'mail.send'],
                'provider_metadata' => ['fixture' => 'unibox-demo'],
                'status' => ConnectionStatus::Connected,
            ],
        );

        return Mailbox::query()->updateOrCreate(
            [
                'provider_connection_id' => $connection->id,
                'provider_mailbox_id' => 'demo-'.$provider->value,
            ],
            [
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'email_address' => $email,
                'display_name' => $name,
                'status' => ConnectionStatus::Connected,
                'last_synced_at' => now()->subMinutes(4),
                'provider_metadata' => ['fixture' => 'unibox-demo'],
            ],
        );
    }

    /**
     * @param  list<Mailbox>  $mailboxes
     */
    private function campaign(User $user, Workspace $workspace, string $name, array $mailboxes): Campaign
    {
        $campaign = Campaign::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspace->id)
            ->where('name', $name)
            ->first();

        if (! $campaign) {
            $campaign = app(CreateCampaignService::class)->handle($user, $name);
        }

        if ((int) $campaign->getAttribute('workspace_id') !== (int) $workspace->id) {
            $campaign->forceFill(['workspace_id' => $workspace->id])->save();
        }

        $campaign->mailboxes()->sync(collect($mailboxes)
            ->values()
            ->mapWithKeys(fn (Mailbox $mailbox, int $position) => [
                $mailbox->id => ['position' => $position + 1],
            ])
            ->all());

        $campaign->load(['draftVersion.activeSteps']);

        return $campaign;
    }

    private function recipient(
        Campaign $campaign,
        Mailbox $mailbox,
        string $name,
        string $email,
        string $company,
        RecipientStatus $status,
    ): CampaignRecipient {
        $hasReplied = in_array($status, [
            RecipientStatus::Replied,
            RecipientStatus::Interested,
            RecipientStatus::NotNow,
            RecipientStatus::WrongPerson,
            RecipientStatus::Unsubscribed,
        ], true);

        return CampaignRecipient::query()->updateOrCreate(
            [
                'campaign_id' => $campaign->id,
                'email_normalized' => strtolower($email),
            ],
            [
                'mailbox_id' => $mailbox->id,
                'workspace_id' => $campaign->getAttribute('workspace_id'),
                'sequence_version_id' => $campaign->draft_sequence_version_id,
                'email_address' => $email,
                'full_name' => $name,
                'first_name' => explode(' ', $name)[0],
                'company' => $company,
                'status' => $status,
                'mailbox_assignment' => MailboxAssignment::Rotation,
                'next_sequence_step_id' => null,
                'replied_at' => $hasReplied ? now()->subDay() : null,
                'stopped_at' => $status->isTerminal() ? now()->subDay() : null,
                'stop_reason' => $status->isTerminal() ? $status->value : null,
            ],
        );
    }

    /**
     * @param  DemoThread  $fixture
     */
    private function thread(
        User $user,
        Mailbox $mailbox,
        ?CampaignRecipient $recipient,
        array $fixture,
    ): void {
        $remoteThreadId = 'demo:'.$fixture['key'];
        $thread = Thread::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('remote_thread_id', $remoteThreadId)
            ->first();

        if (! $thread) {
            $thread = Thread::query()
                ->where('user_id', $user->id)
                ->where('mailbox_id', $mailbox->id)
                ->where('participant_email', $fixture['participant_email'])
                ->where('subject', $fixture['subject'])
                ->first();
        }

        $adoptingLegacyFixture = $thread && $thread->remote_thread_id === null;
        $thread ??= new Thread;

        if (! $thread->exists) {
            $thread->local_key = (string) str()->uuid();
        }

        $latestMessage = $fixture['messages'][count($fixture['messages']) - 1];

        $thread->fill([
            'user_id' => $user->id,
            'workspace_id' => $mailbox->getAttribute('workspace_id'),
            'mailbox_id' => $mailbox->id,
            'campaign_recipient_id' => $recipient?->id,
            'remote_thread_id' => $remoteThreadId,
            'subject' => $fixture['subject'],
            'subject_normalized' => strtolower($fixture['subject']),
            'snippet' => $latestMessage['body'],
            'participant_name' => $fixture['participant_name'],
            'participant_email' => $fixture['participant_email'],
            'last_message_at' => now()->startOfMinute()->subMinutes($latestMessage['minutes_ago']),
            'classification' => $fixture['classification'],
            'local_state' => $fixture['state'],
            'is_unread' => $fixture['unread'],
        ])->save();

        if ($adoptingLegacyFixture) {
            $thread->messages()->delete();
        }

        $remoteMessageIds = [];
        $previous = null;

        foreach ($fixture['messages'] as $index => $messageFixture) {
            $remoteMessageId = $remoteThreadId.':message:'.($index + 1);
            $remoteMessageIds[] = $remoteMessageId;
            $message = Message::query()->firstOrNew([
                'mailbox_id' => $mailbox->id,
                'remote_message_id' => $remoteMessageId,
            ]);

            if (! $message->exists) {
                $message->local_key = (string) str()->uuid();
            }

            $direction = $messageFixture['direction'];
            $at = now()->startOfMinute()->subMinutes($messageFixture['minutes_ago']);

            $message->fill([
                'thread_id' => $thread->id,
                'workspace_id' => $mailbox->getAttribute('workspace_id'),
                'campaign_recipient_id' => $recipient?->id,
                'reply_to_message_id' => $previous?->id,
                'internet_message_id' => $fixture['key'].'-'.($index + 1).'@outboss.test',
                'in_reply_to' => $previous?->internet_message_id,
                'direction' => $direction,
                'classification' => $direction === MessageDirection::Inbound
                    ? $fixture['classification']
                    : null,
                'status' => $direction === MessageDirection::Outbound
                    ? MessageStatus::Sent
                    : MessageStatus::Received,
                'from_name' => $direction === MessageDirection::Outbound
                    ? $user->name
                    : $fixture['participant_name'],
                'from_email' => $direction === MessageDirection::Outbound
                    ? $mailbox->email_address
                    : $fixture['participant_email'],
                'to_recipients' => [[
                    'email' => $direction === MessageDirection::Outbound
                        ? $fixture['participant_email']
                        : $mailbox->email_address,
                    'name' => $direction === MessageDirection::Outbound
                        ? $fixture['participant_name']
                        : $user->name,
                ]],
                'cc_recipients' => [],
                'bcc_recipients' => [],
                'subject' => $fixture['subject'],
                'body_text' => $messageFixture['body'],
                'body_html_sanitized' => '<p>'.nl2br(e($messageFixture['body'])).'</p>',
                'provider_metadata' => ['fixture' => 'unibox-demo'],
                'sent_at' => $direction === MessageDirection::Outbound ? $at : null,
                'received_at' => $direction === MessageDirection::Inbound ? $at : null,
            ]);

            if ($direction === MessageDirection::Outbound && ! $message->client_message_marker) {
                $message->client_message_marker = (string) str()->uuid();
            }

            $message->save();
            $previous = $message;
        }

        $thread->messages()
            ->where('remote_message_id', 'like', $remoteThreadId.':message:%')
            ->whereNotIn('remote_message_id', $remoteMessageIds)
            ->delete();
    }

    private function workspaceFor(User $user): Workspace
    {
        $workspace = $user->workspaces()
            ->where('workspaces.type', 'personal')
            ->orderBy('workspaces.id')
            ->first();

        if ($workspace !== null) {
            return app(ProvisionWorkspace::class)->ensure($workspace, $user);
        }

        return app(ProvisionWorkspace::class)->create(
            $user,
            $user->name."'s Workspace",
            'personal',
        );
    }

    /**
     * @return list<DemoThread>
     */
    private function threadFixtures(): array
    {
        return [
            [
                'key' => 'maya-stripe-interest',
                'mailbox' => 'primary',
                'campaign' => 'founders',
                'participant_name' => 'Maya Chen',
                'participant_email' => 'maya@stripe.com',
                'company' => 'Stripe',
                'recipient_status' => RecipientStatus::Interested,
                'subject' => 'Re: Quick question about Stripe hiring',
                'classification' => ThreadClassification::Human,
                'state' => ThreadLocalState::NeedsAction,
                'unread' => true,
                'messages' => [
                    ['direction' => MessageDirection::Outbound, 'minutes_ago' => 4320, 'body' => "Hi Maya,\n\nI noticed Stripe is expanding the platform team. Are outbound handoffs part of what you are reviewing this quarter?"],
                    ['direction' => MessageDirection::Inbound, 'minutes_ago' => 2880, 'body' => 'Potentially. We are trying to reduce the amount of manual research before an SDR can start a useful conversation.'],
                    ['direction' => MessageDirection::Outbound, 'minutes_ago' => 1440, 'body' => 'That is exactly the workflow we are working on. I can show you how the research, campaign context, and replies stay connected.'],
                    ['direction' => MessageDirection::Inbound, 'minutes_ago' => 18, 'body' => 'Yes — Thursday at 10:00 ET works. Send over a calendar hold?'],
                ],
            ],
            [
                'key' => 'sam-northstar-open',
                'mailbox' => 'founders',
                'campaign' => 'founders',
                'participant_name' => 'Sam Rivera',
                'participant_email' => 'sam@northstar.example',
                'company' => 'Northstar Labs',
                'recipient_status' => RecipientStatus::Replied,
                'subject' => 'Re: Northstar outbound workflow',
                'classification' => ThreadClassification::Human,
                'state' => ThreadLocalState::Open,
                'unread' => false,
                'messages' => [
                    ['direction' => MessageDirection::Outbound, 'minutes_ago' => 5760, 'body' => "Hi Sam,\n\nCurious how Northstar is managing replies across the founder and sales inboxes today."],
                    ['direction' => MessageDirection::Inbound, 'minutes_ago' => 4320, 'body' => 'Mostly labels and a shared spreadsheet. It works until two people respond to the same lead.'],
                    ['direction' => MessageDirection::Outbound, 'minutes_ago' => 2880, 'body' => 'That collision is one of the reasons we built a single conversation queue with clear ownership.'],
                    ['direction' => MessageDirection::Inbound, 'minutes_ago' => 720, 'body' => 'Makes sense. Could you send a short overview before we find time to talk?'],
                ],
            ],
            [
                'key' => 'priya-acme-interested',
                'mailbox' => 'founders',
                'campaign' => 'ecommerce',
                'participant_name' => 'Priya Shah',
                'participant_email' => 'priya@acmecommerce.example',
                'company' => 'Acme Commerce',
                'recipient_status' => RecipientStatus::Interested,
                'subject' => 'Re: Acme Commerce prospecting',
                'classification' => ThreadClassification::Human,
                'state' => ThreadLocalState::Done,
                'unread' => false,
                'messages' => [
                    ['direction' => MessageDirection::Outbound, 'minutes_ago' => 10080, 'body' => "Hi Priya,\n\nAre you still building the merchant partnerships team at Acme Commerce?"],
                    ['direction' => MessageDirection::Inbound, 'minutes_ago' => 8640, 'body' => 'We are. The immediate priority is finding qualified platform partners without adding more tools for the team.'],
                    ['direction' => MessageDirection::Outbound, 'minutes_ago' => 7200, 'body' => 'Understood. Outboss keeps prospecting and the resulting conversations in one workspace, so the team has less context switching.'],
                    ['direction' => MessageDirection::Inbound, 'minutes_ago' => 5760, 'body' => 'That sounds relevant. Can you include our partnerships lead in a walkthrough?'],
                    ['direction' => MessageDirection::Outbound, 'minutes_ago' => 4320, 'body' => 'Absolutely. I have sent a few options for next week and marked this conversation complete for now.'],
                ],
            ],
            [
                'key' => 'luis-no-campaign-closed',
                'mailbox' => 'primary',
                'campaign' => null,
                'participant_name' => 'Luis Ortega',
                'participant_email' => 'luis@harbor.example',
                'company' => 'Harbor',
                'recipient_status' => null,
                'subject' => 'Re: Introduction from Nina',
                'classification' => ThreadClassification::Human,
                'state' => ThreadLocalState::Done,
                'unread' => false,
                'messages' => [
                    ['direction' => MessageDirection::Inbound, 'minutes_ago' => 8640, 'body' => 'Nina suggested I reach out. We are evaluating a few ways to make founder-led outbound less manual.'],
                    ['direction' => MessageDirection::Outbound, 'minutes_ago' => 7200, 'body' => 'Thanks Luis. That is a strong fit for what we are building. How large is the team handling replies?'],
                    ['direction' => MessageDirection::Inbound, 'minutes_ago' => 5760, 'body' => 'Three people today. Let us reconnect after our planning week.'],
                    ['direction' => MessageDirection::Outbound, 'minutes_ago' => 4320, 'body' => 'Sounds good. I will close this for now and follow up next month.'],
                ],
            ],
            [
                'key' => 'emma-wrong-person',
                'mailbox' => 'founders',
                'campaign' => 'ecommerce',
                'participant_name' => 'Emma Brooks',
                'participant_email' => 'emma@orbitretail.example',
                'company' => 'Orbit Retail',
                'recipient_status' => RecipientStatus::WrongPerson,
                'subject' => 'Re: Orbit Retail growth team',
                'classification' => ThreadClassification::Human,
                'state' => ThreadLocalState::Done,
                'unread' => false,
                'messages' => [
                    ['direction' => MessageDirection::Outbound, 'minutes_ago' => 7200, 'body' => "Hi Emma,\n\nAre you the right person to speak with about outbound operations at Orbit Retail?"],
                    ['direction' => MessageDirection::Inbound, 'minutes_ago' => 5760, 'body' => 'Not my area, but Theo Grant leads growth operations and would be the right person.'],
                    ['direction' => MessageDirection::Outbound, 'minutes_ago' => 4320, 'body' => 'Thanks for pointing me in the right direction. I will reach out to Theo instead.'],
                ],
            ],
            [
                'key' => 'jordan-out-of-office',
                'mailbox' => 'primary',
                'campaign' => 'founders',
                'participant_name' => 'Jordan Lee',
                'participant_email' => 'jordan@notion.so',
                'company' => 'Notion',
                'recipient_status' => RecipientStatus::Active,
                'subject' => 'Out of office: back Monday',
                'classification' => ThreadClassification::OutOfOffice,
                'state' => ThreadLocalState::OutOfOffice,
                'unread' => true,
                'messages' => [
                    ['direction' => MessageDirection::Outbound, 'minutes_ago' => 2880, 'body' => "Hi Jordan,\n\nWould it be useful to compare notes on how Notion routes positive replies today?"],
                    ['direction' => MessageDirection::Inbound, 'minutes_ago' => 2760, 'body' => 'I am out of the office until Monday with limited access to email. I will respond when I return.'],
                ],
            ],
            [
                'key' => 'delivery-failure',
                'mailbox' => 'founders',
                'campaign' => 'founders',
                'participant_name' => 'Mail Delivery Subsystem',
                'participant_email' => 'mailer-daemon@google.com',
                'company' => 'Northwind',
                'recipient_status' => RecipientStatus::Bounced,
                'subject' => 'Delivery Status Notification (Failure)',
                'classification' => ThreadClassification::Bounce,
                'state' => ThreadLocalState::Problem,
                'unread' => true,
                'messages' => [
                    ['direction' => MessageDirection::Outbound, 'minutes_ago' => 2160, 'body' => "Hi Alex,\n\nIs Northwind still expanding its outbound team this quarter?"],
                    ['direction' => MessageDirection::Inbound, 'minutes_ago' => 2155, 'body' => 'Delivery failed. Diagnostic-Code: smtp; 5.1.1 The email account that you tried to reach does not exist.'],
                ],
            ],
            [
                'key' => 'casey-opt-out',
                'mailbox' => 'primary',
                'campaign' => 'ecommerce',
                'participant_name' => 'Casey Morgan',
                'participant_email' => 'casey@brightcart.example',
                'company' => 'BrightCart',
                'recipient_status' => RecipientStatus::Unsubscribed,
                'subject' => 'Re: BrightCart partnerships',
                'classification' => ThreadClassification::Unsubscribe,
                'state' => ThreadLocalState::Problem,
                'unread' => true,
                'messages' => [
                    ['direction' => MessageDirection::Outbound, 'minutes_ago' => 1440, 'body' => "Hi Casey,\n\nWould a simpler way to manage partner outreach be useful for BrightCart?"],
                    ['direction' => MessageDirection::Inbound, 'minutes_ago' => 1380, 'body' => 'Thanks, but please remove me from this campaign and do not follow up.'],
                ],
            ],
            [
                'key' => 'grace-sent-only',
                'mailbox' => 'founders',
                'campaign' => null,
                'participant_name' => 'Grace Kim',
                'participant_email' => 'grace@cedar.example',
                'company' => 'Cedar',
                'recipient_status' => null,
                'subject' => 'Quick question about Cedar',
                'classification' => ThreadClassification::Human,
                'state' => ThreadLocalState::Open,
                'unread' => false,
                'messages' => [
                    ['direction' => MessageDirection::Outbound, 'minutes_ago' => 1000, 'body' => "Hi Grace,\n\nI noticed Cedar is hiring its first sales operations lead. Is improving outbound workflow part of the role?"],
                    ['direction' => MessageDirection::Outbound, 'minutes_ago' => 120, 'body' => 'Just following up in case this is timely. Happy to send a two-minute overview instead.'],
                ],
            ],
        ];
    }
}
