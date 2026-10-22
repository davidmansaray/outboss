<?php

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\ThreadClassification;
use App\Domain\Mail\Enums\ThreadLocalState;
use App\Models\Account;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Person;
use App\Models\PersonImport;
use App\Models\ProviderConnection;
use App\Models\RecipientImport;
use App\Models\SendAttempt;
use App\Models\SequenceStep;
use App\Models\SuppressionEntry;
use App\Models\Thread;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{user: User, mailbox: Mailbox, campaign: Campaign, recipient: CampaignRecipient, thread: Thread, message: Message, step: SequenceStep, import: RecipientImport, suppression: SuppressionEntry, attempt: SendAttempt, connection: ProviderConnection, account: Account, person: Person, personImport: PersonImport}
 */
function makeWorkspaceIsolationGraph(): array
{
    $user = User::factory()->create(['name' => 'Workspace A']);
    $workspace = $user->currentWorkspace();
    $mailbox = Mailbox::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
    ]);
    $campaign = Campaign::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'name' => 'Workspace A campaign',
    ]);
    $campaign->mailboxes()->attach($mailbox->id, ['position' => 1]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->firstOrFail();
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $workspace->id,
        'mailbox_id' => $mailbox->id,
        'sequence_version_id' => $step->sequence_version_id,
        'next_sequence_step_id' => $step->id,
        'status' => RecipientStatus::Active,
        'email_address' => 'person-a@example.test',
        'email_normalized' => 'person-a@example.test',
        'full_name' => 'Person A',
        'first_name' => 'Person',
        'company' => 'Workspace A Co',
    ]);
    $thread = Thread::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
        'classification' => ThreadClassification::Human,
        'local_state' => ThreadLocalState::NeedsAction,
        'participant_email' => $recipient->email_address,
    ]);
    $message = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'thread_id' => $thread->id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
        'direction' => MessageDirection::Inbound,
        'classification' => ThreadClassification::Human,
        'remote_message_id' => 'workspace-a-message',
    ]);
    $import = RecipientImport::factory()->create([
        'campaign_id' => $campaign->id,
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
    ]);
    $suppression = SuppressionEntry::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'email_address' => 'suppressed-a@example.test',
        'email_normalized' => 'suppressed-a@example.test',
    ]);
    $attempt = SendAttempt::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'mailbox_id' => $mailbox->id,
        'message_id' => Message::factory()->outbound()->create([
            'workspace_id' => $workspace->id,
            'thread_id' => $thread->id,
            'mailbox_id' => $mailbox->id,
            'campaign_recipient_id' => $recipient->id,
        ])->id,
        'campaign_recipient_id' => $recipient->id,
        'sequence_step_id' => $step->id,
    ]);
    $account = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Workspace A Co',
        'domain_normalized' => 'workspace-a.test',
    ]);
    $person = Person::factory()->create([
        'workspace_id' => $workspace->id,
        'account_id' => $account->id,
        'full_name' => 'Person A',
        'email_address' => 'person-a@example.test',
        'email_normalized' => 'person-a@example.test',
    ]);
    $recipient->forceFill([
        'account_id' => $account->id,
        'person_id' => $person->id,
    ])->save();

    $personImport = PersonImport::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    return compact('user', 'mailbox', 'campaign', 'recipient', 'thread', 'message', 'step', 'import', 'suppression', 'attempt', 'account', 'person', 'personImport') + [
        'connection' => $mailbox->connection,
    ];
}

it('denies every policy for records from another workspace', function () {
    $graph = makeWorkspaceIsolationGraph();
    $intruder = User::factory()->create(['name' => 'Workspace B']);

    foreach ([
        [$graph['campaign'], 'view'],
        [$graph['campaign'], 'update'],
        [$graph['recipient'], 'view'],
        [$graph['mailbox'], 'view'],
        [$graph['message'], 'view'],
        [$graph['connection'], 'view'],
        [$graph['import'], 'view'],
        [$graph['attempt'], 'view'],
        [$graph['step'], 'view'],
        [$graph['step'], 'update'],
        [$graph['suppression'], 'delete'],
        [$graph['thread'], 'view'],
        [$graph['account'], 'view'],
        [$graph['account'], 'update'],
        [$graph['account'], 'delete'],
        [$graph['person'], 'view'],
        [$graph['person'], 'update'],
        [$graph['person'], 'delete'],
        [$graph['personImport'], 'view'],
        [$graph['personImport'], 'commit'],
    ] as [$model, $ability]) {
        expect(Gate::forUser($intruder)->allows($ability, $model))->toBeFalse();
    }
});

it('denies records from an inactive workspace even when the user is a member', function () {
    $user = User::factory()->create();
    $activeWorkspace = $user->currentWorkspace();
    $otherWorkspace = Workspace::factory()->create(['type' => 'team']);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceMembership::ROLE_OWNER,
    ]);
    $account = Account::factory()->create([
        'workspace_id' => $otherWorkspace->id,
    ]);

    expect($user->currentWorkspace()->is($activeWorkspace))->toBeTrue()
        ->and(Gate::forUser($user)->allows('view', $account))->toBeFalse()
        ->and(Gate::forUser($user)->allows('update', $account))->toBeFalse();

    $this->actingAs($user)
        ->patch(route('accounts.update', $account), ['name' => 'Crossed boundary'])
        ->assertForbidden();
});

it('blocks cross-workspace model routes and mailbox ids', function () {
    $graph = makeWorkspaceIsolationGraph();
    $intruder = User::factory()->create(['name' => 'Workspace B']);
    $this->actingAs($intruder);

    $requests = [
        ['get', route('campaigns.show', $graph['campaign'])],
        ['patch', route('campaigns.update', $graph['campaign']), ['send_mode' => 'rotate']],
        ['post', route('campaigns.launch', $graph['campaign'])],
        ['post', route('campaigns.pause', $graph['campaign'])],
        ['post', route('campaigns.resume', $graph['campaign'])],
        ['post', route('campaigns.imports.preview', $graph['campaign']), [
            'source' => 'paste',
            'rows' => [['email' => 'intruder@example.test']],
            'mapping' => ['email' => 'email'],
        ]],
        ['post', route('campaigns.imports.commit', [$graph['campaign'], $graph['import']]), [
            'expected_mapping_version' => $graph['import']->mapping_version,
        ]],
        ['get', route('campaigns.enrollable-people', $graph['campaign'])],
        ['post', route('campaigns.enrollments.store', $graph['campaign']), [
            'person_ids' => [$graph['person']->id],
        ]],
        ['get', route('sequence-steps.preview', $graph['step'])],
        ['post', route('campaigns.steps.store', $graph['campaign']), ['title' => 'Nope']],
        ['put', route('campaigns.steps.reorder', $graph['campaign']), [
            'step_ids' => [$graph['step']->id],
        ]],
        ['patch', route('sequence-steps.update', $graph['step']), ['title' => 'Nope']],
        ['delete', route('sequence-steps.destroy', $graph['step'])],
        ['post', route('sequence-steps.test', $graph['step']), [
            'mailbox_id' => $graph['mailbox']->id,
            'to' => 'intruder@example.test',
            'client_operation_id' => (string) Str::uuid(),
        ]],
        ['patch', route('campaign-recipients.update', $graph['recipient']), ['action' => 'pause']],
        ['delete', route('campaign-recipients.destroy', $graph['recipient'])],
        ['get', route('threads.show', $graph['thread'])],
        ['post', route('threads.reply', $graph['thread']), [
            'body' => 'Nope',
            'client_operation_id' => (string) Str::uuid(),
        ]],
        ['post', route('threads.done', $graph['thread'])],
        ['post', route('threads.classify', $graph['thread']), ['action' => 'interested']],
        ['post', route('mailboxes.reconnect', $graph['mailbox'])],
        ['post', route('mailboxes.sync', $graph['mailbox'])],
        ['patch', route('mailboxes.reply-identity.update', $graph['mailbox']), ['identity_key' => str_repeat('a', 64)]],
        ['delete', route('mailboxes.destroy', $graph['mailbox'])],
        ['delete', route('suppressions.destroy', $graph['suppression'])],
        ['patch', route('accounts.update', $graph['account']), ['name' => 'Nope']],
        ['delete', route('accounts.destroy', $graph['account'])],
        ['patch', route('people.update', $graph['person']), ['email' => 'nope@example.test']],
        ['delete', route('people.destroy', $graph['person'])],
        ['post', route('people.imports.commit', $graph['personImport']), [
            'expected_mapping_version' => $graph['personImport']->mapping_version,
        ]],
    ];

    foreach ($requests as $request) {
        [$method, $url] = $request;
        $payload = $request[2] ?? [];
        $response = $this->{$method}($url, $payload);
        expect($response->status())->toBeIn([403, 404]);
    }

    $this->post(route('messages.store'), [
        'mailbox_id' => $graph['mailbox']->id,
        'to' => 'intruder@example.test',
        'subject' => 'Nope',
        'body' => 'Nope',
        'client_operation_id' => (string) Str::uuid(),
    ])->assertNotFound();
});

it('keeps all workspace list endpoints isolated', function () {
    $graph = makeWorkspaceIsolationGraph();
    $intruder = User::factory()->create(['name' => 'Workspace B']);

    $this->actingAs($intruder)
        ->get(route('campaigns.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('campaigns/Index')
            ->has('campaigns', 0));

    $this->actingAs($intruder)
        ->get(route('unibox.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('unibox/Index')
            ->has('threads.data', 0)
            ->has('campaigns', 0)
            ->has('mailboxes', 0));

    $this->actingAs($graph['user'])
        ->get(route('unibox.index', ['folder' => 'needs']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('threads.data.0.person.id', $graph['person']->id)
            ->where('threads.data.0.account.id', $graph['account']->id)
            ->where('threads.data.0.person.name', 'Person A'));

    $this->actingAs($intruder)
        ->get(route('prospects.index', ['person' => $graph['person']->id]))
        ->assertNotFound();

    $this->actingAs($intruder)
        ->get(route('prospects.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('people/Index')
            ->has('people', 0)
            ->has('accounts', 0));

    $this->actingAs($intruder)
        ->get(route('mailboxes.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('mailboxes/Index')
            ->has('mailboxes', 0));

    $this->actingAs($intruder)
        ->get(route('suppressions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('suppressions/Index')
            ->has('entries', 0));

    $export = $this->actingAs($intruder)->get(route('accounts.export'));
    $export->assertOk();
    expect($export->streamedContent())->not->toContain('Workspace A Co')
        ->and($export->streamedContent())->not->toContain($graph['account']->name);

    $peopleExport = $this->actingAs($intruder)->get(route('people.export'));
    $peopleExport->assertOk();
    expect($peopleExport->streamedContent())->not->toContain('Person A')
        ->and($peopleExport->streamedContent())->not->toContain($graph['person']->email_address);

    $enrollments = $this->actingAs($intruder)->get(route('exports.enrollments'));
    $enrollments->assertOk();
    expect($enrollments->streamedContent())->not->toContain('person-a@example.test')
        ->and($enrollments->streamedContent())->not->toContain('Workspace A campaign');

    $suppressions = $this->actingAs($intruder)->get(route('exports.suppressions'));
    $suppressions->assertOk();
    expect($suppressions->streamedContent())->not->toContain($graph['suppression']->email_address);

    $outcomes = $this->actingAs($intruder)->get(route('exports.campaign-outcomes'));
    $outcomes->assertOk();
    expect($outcomes->streamedContent())->not->toContain('Workspace A campaign');

    expect($graph['campaign']->workspace_id)->not->toBe($intruder->currentWorkspace()->id);
});

it('rejects manual thread actions when the thread points at another workspace recipient', function () {
    $graph = makeWorkspaceIsolationGraph();
    $other = User::factory()->create(['name' => 'Workspace B']);
    $otherWorkspace = $other->currentWorkspace();
    $foreignMailbox = Mailbox::factory()->create([
        'user_id' => $other->id,
        'workspace_id' => $otherWorkspace->id,
    ]);
    $foreignCampaign = Campaign::factory()->create([
        'user_id' => $other->id,
        'workspace_id' => $otherWorkspace->id,
    ]);
    $foreignRecipient = CampaignRecipient::factory()->create([
        'campaign_id' => $foreignCampaign->id,
        'workspace_id' => $otherWorkspace->id,
        'mailbox_id' => $foreignMailbox->id,
        'email_address' => 'foreign-recipient@example.test',
        'email_normalized' => 'foreign-recipient@example.test',
        'status' => RecipientStatus::Active,
    ]);
    $graph['thread']->forceFill(['campaign_recipient_id' => $foreignRecipient->id])->save();

    $this->actingAs($graph['user'])
        ->post(route('threads.classify', $graph['thread']), ['action' => 'remove_bad_address'])
        ->assertForbidden();

    expect($foreignRecipient->fresh()->status)->toBe(RecipientStatus::Active)
        ->and(SuppressionEntry::query()->where('email_normalized', 'foreign-recipient@example.test')->exists())->toBeFalse();
});

it('does not list a thread whose mailbox belongs to another workspace', function () {
    $graph = makeWorkspaceIsolationGraph();
    $other = User::factory()->create(['name' => 'Workspace B']);
    $foreignMailbox = Mailbox::factory()->create([
        'user_id' => $other->id,
        'workspace_id' => $other->currentWorkspace()->id,
    ]);
    $poisonedThread = Thread::factory()->create([
        'user_id' => $graph['user']->id,
        'workspace_id' => $graph['user']->currentWorkspace()->id,
        'mailbox_id' => $foreignMailbox->id,
        'campaign_recipient_id' => null,
        'local_state' => ThreadLocalState::Open,
    ]);
    Message::factory()->create([
        'workspace_id' => $graph['user']->currentWorkspace()->id,
        'thread_id' => $poisonedThread->id,
        'mailbox_id' => $foreignMailbox->id,
        'direction' => MessageDirection::Inbound,
        'classification' => ThreadClassification::Human,
    ]);

    $this->actingAs($graph['user'])
        ->get(route('unibox.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('unibox/Index')
            ->where('threads.data', fn ($threads): bool => collect($threads)->every(
                fn (array $thread): bool => $thread['id'] !== $poisonedThread->id,
            )));
});
