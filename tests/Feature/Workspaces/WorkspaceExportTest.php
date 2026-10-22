<?php

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Mail\Enums\SuppressionReason;
use App\Models\Account;
use App\Models\AiProviderCredential;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\Person;
use App\Models\SuppressionEntry;
use App\Models\User;
use League\Csv\Reader;

/**
 * @return array<int, array<string, string>>
 */
function parseExportCsv(string $contents): array
{
    $csv = Reader::createFromString($contents);
    $csv->setHeaderOffset(0);

    return iterator_to_array($csv->getRecords(), false);
}

it('renders the data export page for a workspace member', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('data-export.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('data-export/Index')
            ->where('suppressionsCount', 0)
            ->where('isOwner', true));
});

it('redirects guests away from every export route', function () {
    $this->get(route('data-export.index'))->assertRedirect(route('login'));
    $this->get(route('exports.enrollments'))->assertRedirect(route('login'));
    $this->get(route('exports.suppressions'))->assertRedirect(route('login'));
    $this->get(route('exports.campaign-outcomes'))->assertRedirect(route('login'));
    $this->get(route('people.export'))->assertRedirect(route('login'));
    $this->get(route('accounts.export'))->assertRedirect(route('login'));
    $this->get(route('exports.s2', ['section' => 'research_runs']))->assertRedirect(route('login'));
});

it('streams only allowlisted S2 export sections to the workspace owner', function (): void {
    $owner = User::factory()->create();

    $response = $this->actingAs($owner)->get(route('exports.s2', ['section' => 'research_runs']));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toStartWith('text/csv')
        ->and($response->headers->get('cache-control'))->toContain('no-store')
        ->and($response->streamedContent())->toContain('run_id');

    $this->actingAs($owner)
        ->get(route('exports.s2', ['section' => 'not_a_section']))
        ->assertNotFound();

    $member = User::factory()->withoutWorkspace()->create();
    $owner->currentWorkspace()->memberships()->create([
        'user_id' => $member->id,
        'role' => 'member',
    ]);
    $this->actingAs($member)
        ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $owner->currentWorkspace()->id])
        ->get(route('exports.s2', ['section' => 'research_runs']))
        ->assertForbidden();
});

it('exports enrolment snapshots from recipient columns in id order', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $mailbox = Mailbox::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'email_address' => 'sender@outboss.test',
    ]);
    $campaign = Campaign::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'name' => 'Founders outreach',
    ]);
    $campaign->mailboxes()->attach($mailbox->id, ['position' => 1]);
    $person = Person::factory()->create([
        'workspace_id' => $workspace->id,
        'full_name' => 'Maya Chen',
        'email_address' => 'maya@northstar.studio',
        'email_normalized' => 'maya@northstar.studio',
    ]);
    $later = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $workspace->id,
        'mailbox_id' => $mailbox->id,
        'email_address' => 'later@acme.test',
        'email_normalized' => 'later@acme.test',
        'full_name' => 'Later Person',
        'status' => RecipientStatus::Queued,
    ]);
    $first = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $workspace->id,
        'mailbox_id' => $mailbox->id,
        'person_id' => $person->id,
        'email_address' => 'maya@northstar.studio',
        'email_normalized' => 'maya@northstar.studio',
        'email_domain' => 'northstar.studio',
        'full_name' => 'Maya Chen',
        'first_name' => 'Maya',
        'company' => 'Northstar',
        'status' => RecipientStatus::Replied,
        'stop_reason' => 'replied',
        'replied_at' => now()->subHour(),
    ]);

    $this->actingAs($user)
        ->patch(route('people.update', $person), [
            'email' => 'maya@new.test',
            'full_name' => 'Maya Changed',
        ])
        ->assertRedirect(route('prospects.index'));

    $response = $this->actingAs($user)->get(route('exports.enrollments'));
    $response->assertOk();
    expect($response->headers->get('content-type'))->toStartWith('text/csv');

    $records = parseExportCsv($response->streamedContent());

    expect($records)->toHaveCount(2)
        ->and(array_keys($records[0]))->toBe([
            'campaign',
            'campaign_status',
            'email',
            'full_name',
            'first_name',
            'company',
            'email_domain',
            'person_id',
            'account_id',
            'mailbox',
            'sequence_version',
            'status',
            'stop_reason',
            'enrolled_at',
            'first_sent_at',
            'last_sent_at',
            'replied_at',
            'stopped_at',
            'paused_at',
            'removed_at',
        ])
        ->and($records[0]['email'])->toBe('later@acme.test')
        ->and($records[1]['email'])->toBe('maya@northstar.studio');

    $maya = collect($records)->firstWhere('email', 'maya@northstar.studio');

    expect($maya)->not->toBeNull()
        ->and($maya['campaign'])->toBe('Founders outreach')
        ->and($maya['campaign_status'])->toBe('draft')
        ->and($maya['full_name'])->toBe('Maya Chen')
        ->and($maya['first_name'])->toBe('Maya')
        ->and($maya['company'])->toBe('Northstar')
        ->and($maya['email_domain'])->toBe('northstar.studio')
        ->and($maya['person_id'])->toBe((string) $person->id)
        ->and($maya['mailbox'])->toBe('sender@outboss.test')
        ->and($maya['status'])->toBe('replied')
        ->and($maya['stop_reason'])->toBe('replied')
        ->and($maya['enrolled_at'])->not->toBe('');
});

it('keeps enrolment snapshot identity after the person is deleted', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $mailbox = Mailbox::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
    ]);
    $campaign = Campaign::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'name' => 'History campaign',
    ]);
    $campaign->mailboxes()->attach($mailbox->id, ['position' => 1]);
    $person = Person::factory()->create([
        'workspace_id' => $workspace->id,
        'full_name' => 'Theo Martin',
        'email_address' => 'theo@acme.test',
        'email_normalized' => 'theo@acme.test',
    ]);
    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $workspace->id,
        'mailbox_id' => $mailbox->id,
        'person_id' => $person->id,
        'email_address' => 'theo@acme.test',
        'email_normalized' => 'theo@acme.test',
        'full_name' => 'Theo Martin',
        'first_name' => 'Theo',
        'company' => 'Acme',
    ]);

    $this->actingAs($user)
        ->delete(route('people.destroy', $person))
        ->assertRedirect(route('prospects.index'));

    $records = parseExportCsv(
        $this->actingAs($user)->get(route('exports.enrollments'))->streamedContent(),
    );

    expect($records)->toHaveCount(1)
        ->and($records[0]['email'])->toBe('theo@acme.test')
        ->and($records[0]['full_name'])->toBe('Theo Martin')
        ->and($records[0]['company'])->toBe('Acme')
        ->and($records[0]['person_id'])->toBe('');
});

it('exports workspace suppressions with the documented columns', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    SuppressionEntry::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $user->currentWorkspace()->id,
        'email_address' => 'opted-out@acme.test',
        'email_normalized' => 'opted-out@acme.test',
        'reason' => SuppressionReason::Unsubscribe,
    ]);
    SuppressionEntry::factory()->create([
        'user_id' => $other->id,
        'workspace_id' => $other->currentWorkspace()->id,
        'email_address' => 'secret@other.test',
        'email_normalized' => 'secret@other.test',
    ]);

    $response = $this->actingAs($user)->get(route('exports.suppressions'));
    $response->assertOk();

    $records = parseExportCsv($response->streamedContent());

    expect(array_keys($records[0]))->toBe(['email', 'reason', 'created_at'])
        ->and($records)->toHaveCount(1)
        ->and($records[0]['email'])->toBe('opted-out@acme.test')
        ->and($records[0]['reason'])->toBe('unsubscribe')
        ->and($records[0]['created_at'])->not->toBe('');
});

it('exports campaign outcome counts from CampaignOutcomeCounts', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $mailbox = Mailbox::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
    ]);
    $campaign = Campaign::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'name' => 'Outcome campaign',
    ]);
    $campaign->mailboxes()->attach($mailbox->id, ['position' => 1]);
    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $workspace->id,
        'mailbox_id' => $mailbox->id,
        'status' => RecipientStatus::Queued,
        'first_sent_at' => null,
    ]);
    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $workspace->id,
        'mailbox_id' => $mailbox->id,
        'status' => RecipientStatus::Interested,
        'first_sent_at' => now()->subDay(),
        'replied_at' => now()->subHour(),
    ]);
    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $workspace->id,
        'mailbox_id' => $mailbox->id,
        'status' => RecipientStatus::Bounced,
        'first_sent_at' => now()->subDay(),
    ]);

    $response = $this->actingAs($user)->get(route('exports.campaign-outcomes'));
    $response->assertOk();

    $records = parseExportCsv($response->streamedContent());

    expect(array_keys($records[0]))->toBe([
        'campaign',
        'status',
        'created_at',
        'launched_at',
        'completed_at',
        'queued',
        'sent',
        'replied',
        'interested',
        'bounced',
        'unsubscribed',
        'needs_review',
    ])
        ->and($records)->toHaveCount(1)
        ->and($records[0]['campaign'])->toBe('Outcome campaign')
        ->and($records[0]['status'])->toBe('draft')
        ->and($records[0]['queued'])->toBe('1')
        ->and($records[0]['sent'])->toBe('2')
        ->and($records[0]['replied'])->toBe('1')
        ->and($records[0]['interested'])->toBe('1')
        ->and($records[0]['bounced'])->toBe('1')
        ->and($records[0]['unsubscribed'])->toBe('0')
        ->and($records[0]['needs_review'])->toBe('0');
});

it('restricts every export header to the documented allow-list', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    Account::factory()->create(['workspace_id' => $workspace->id]);
    Person::factory()->create(['workspace_id' => $workspace->id]);
    $mailbox = Mailbox::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
    ]);
    $campaign = Campaign::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
    ]);
    $campaign->mailboxes()->attach($mailbox->id, ['position' => 1]);
    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $workspace->id,
        'mailbox_id' => $mailbox->id,
        'unsubscribe_token' => '00000000-0000-4000-8000-000000000001',
    ]);
    SuppressionEntry::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
    ]);
    $aiCredential = AiProviderCredential::query()->create([
        'workspace_id' => $workspace->id,
        'capability' => 'llm',
        'provider' => 'openrouter',
        'status' => 'healthy',
    ]);
    $aiCredential->versions()->create([
        'version' => 1,
        'encrypted_secret' => 'sk-or-export-boundary-secret',
        'status' => 'active',
    ]);

    $allowLists = [
        'people.export' => [
            'name', 'email', 'job_title', 'account', 'notes', 'tags', 'source',
            'source_count', 'source_ids', 'source_names', 'latest_observed_at', 'source_omission_reason',
            'eligibility', 'campaigns_count', 'created_at', 'updated_at',
        ],
        'accounts.export' => [
            'name', 'website', 'domain', 'notes', 'tags', 'source',
            'source_count', 'source_ids', 'source_names', 'latest_observed_at', 'source_omission_reason',
            'people_count', 'created_at', 'updated_at',
        ],
        'exports.enrollments' => [
            'campaign', 'campaign_status', 'email', 'full_name', 'first_name',
            'company', 'email_domain', 'person_id', 'account_id', 'mailbox',
            'sequence_version', 'status', 'stop_reason', 'enrolled_at',
            'first_sent_at', 'last_sent_at', 'replied_at', 'stopped_at',
            'paused_at', 'removed_at',
        ],
        'exports.suppressions' => ['email', 'reason', 'created_at'],
        'exports.campaign-outcomes' => [
            'campaign', 'status', 'created_at', 'launched_at', 'completed_at',
            'queued', 'sent', 'replied', 'interested', 'bounced',
            'unsubscribed', 'needs_review',
        ],
    ];
    $forbidden = [
        'unsubscribe_token',
        'tracking_token',
        'access_token',
        'refresh_token',
        'idempotency_key',
        'body',
        'html_body',
        '00000000-0000-4000-8000-000000000001',
        'sk-or-export-boundary-secret',
        'ai_provider_credential',
    ];

    foreach ($allowLists as $route => $headers) {
        $contents = $this->actingAs($user)->get(route($route))->streamedContent();
        $records = parseExportCsv($contents);

        expect(array_keys($records[0]))->toBe($headers);

        foreach ($forbidden as $needle) {
            expect(strtolower($contents))->not->toContain($needle);
        }
    }
});

it('prefixes spreadsheet formulas in exported notes', function () {
    $user = User::factory()->create();
    Person::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'full_name' => 'Formula Person',
        'email_address' => 'formula@acme.test',
        'email_normalized' => 'formula@acme.test',
        'notes' => '=HYPERLINK("http://evil.test")',
    ]);

    $contents = $this->actingAs($user)->get(route('people.export'))->streamedContent();

    expect($contents)->toContain("'=HYPERLINK")
        ->and($contents)->not->toMatch('/^[=+\-@]/m');
});
