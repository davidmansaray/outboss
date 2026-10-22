<?php

use App\Domain\Mail\Enums\AuthScheme;
use App\Domain\Mail\Enums\ConnectionKind;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\MailExperimentDomainStrategy;
use App\Domain\Mail\Enums\MailExperimentStatus;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\ManagedMailboxAccountStatus;
use App\Domain\Mail\Enums\ManagedMailDomainKind;
use App\Domain\Mail\Enums\ManagedMailDomainStatus;
use App\Domain\Mail\Enums\RecipientMailboxProvider;
use App\Domain\Mail\Enums\SendAttemptKind;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Enums\SyncStatus;
use App\Domain\Mail\Services\CampaignSnapshotService;
use App\Domain\Mail\Services\DeterministicStratifiedAssignment;
use App\Domain\Mail\Services\ExperimentEvidenceExporter;
use App\Domain\Mail\Services\ExperimentMetricsService;
use App\Domain\Mail\Services\FakeDnsResolver;
use App\Domain\Mail\Services\MailExperimentAssignmentService;
use App\Domain\Mail\Services\MailExperimentLifecycleService;
use App\Domain\Mail\Services\RecipientMailboxProviderClassifier;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\MailboxSyncState;
use App\Models\MailExperiment;
use App\Models\MailExperimentCohort;
use App\Models\ManagedMailboxAccount;
use App\Models\ManagedMailDomain;
use App\Models\Message;
use App\Models\Person;
use App\Models\ProviderConnection;
use App\Models\SendAttempt;
use App\Models\Thread;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** @return list<Mailbox> */
function experimentManagedMailboxSet(MailExperiment $experiment, MailExperimentDomainStrategy $strategy, string $prefix): array
{
    $domainKind = $strategy === MailExperimentDomainStrategy::Subdomain
        ? ManagedMailDomainKind::Subdomain
        : ManagedMailDomainKind::Root;
    $domain = $strategy === MailExperimentDomainStrategy::Subdomain
        ? ManagedMailDomain::factory()->create([
            'workspace_id' => $experiment->workspace_id,
            'created_by_user_id' => $experiment->created_by_user_id,
            'domain' => $prefix.'-'.$experiment->id.'.example.com',
            'organisational_domain' => 'example.com',
            'domain_kind' => $domainKind,
            'provider' => MailProviderName::Stalwart,
            'status' => ManagedMailDomainStatus::Active,
            'verified_at' => now()->subDay(),
            'last_verified_at' => now()->subHour(),
        ])
        : null;

    $mailboxes = [];
    foreach (range(1, 3) as $position) {
        $mailDomain = $domain ?? ManagedMailDomain::factory()->create([
            'workspace_id' => $experiment->workspace_id,
            'created_by_user_id' => $experiment->created_by_user_id,
            'domain' => $prefix.'-'.$position.'-'.$experiment->id.'.example.com',
            'organisational_domain' => 'example.com',
            'domain_kind' => $domainKind,
            'provider' => MailProviderName::Stalwart,
            'status' => ManagedMailDomainStatus::Active,
            'verified_at' => now()->subDay(),
            'last_verified_at' => now()->subHour(),
        ]);
        $connection = ProviderConnection::factory()->create([
            'user_id' => $experiment->created_by_user_id,
            'workspace_id' => $experiment->workspace_id,
            'provider' => MailProviderName::Stalwart,
            'connection_kind' => ConnectionKind::Managed,
            'auth_scheme' => AuthScheme::AppPassword,
            'access_token' => null,
            'refresh_token' => null,
            'credential_payload' => [
                'username' => $prefix.$position.'@'.$mailDomain->domain,
                'password' => 'test-only-password-'.$position,
                'version' => 1,
            ],
            'status' => ConnectionStatus::Connected,
        ]);
        $mailbox = Mailbox::factory()->create([
            'user_id' => $experiment->created_by_user_id,
            'workspace_id' => $experiment->workspace_id,
            'provider_connection_id' => $connection->id,
            'email_address' => $prefix.$position.'@'.$mailDomain->domain,
            'status' => ConnectionStatus::Connected,
            'provider_metadata' => ['managed_mail_domain_id' => $mailDomain->id],
        ]);
        ManagedMailboxAccount::factory()->create([
            'managed_mail_domain_id' => $mailDomain->id,
            'mailbox_id' => $mailbox->id,
            'local_part' => $prefix.$position,
            'status' => ManagedMailboxAccountStatus::Active,
            'provisioned_at' => now()->subDay(),
            'last_verified_at' => now()->subHour(),
        ]);
        $mailboxes[] = $mailbox;
    }

    return $mailboxes;
}

it('classifies recipient MX records through the injected resolver', function () {
    FakeDnsResolver::setMany([
        'google.test' => ['10 ASPMX.L.GOOGLE.COM.'],
        'microsoft.test' => ['10 mail.protection.outlook.com.'],
        'yahoo.test' => ['10 mta5.am0.yahoodns.net.'],
        'other.test' => ['10 mx.other.test.'],
    ]);

    $classifier = app(RecipientMailboxProviderClassifier::class);

    expect($classifier->classify('person@google.test'))->toBe(RecipientMailboxProvider::Google)
        ->and($classifier->classify('person@microsoft.test'))->toBe(RecipientMailboxProvider::Microsoft)
        ->and($classifier->classify('person@yahoo.test'))->toBe(RecipientMailboxProvider::Yahoo)
        ->and($classifier->classify('person@other.test'))->toBe(RecipientMailboxProvider::Other)
        ->and($classifier->classify('person@missing.test'))->toBe(RecipientMailboxProvider::Unknown);
});

it('produces the same stratified assignment independent of candidate order', function () {
    $candidates = [
        ['email' => 'one@example.test', 'recipient_provider' => 'google'],
        ['email' => 'two@example.test', 'recipient_provider' => 'google'],
        ['email' => 'three@example.test', 'recipient_provider' => 'microsoft'],
        ['email' => 'four@example.test', 'recipient_provider' => 'microsoft'],
    ];
    $service = app(DeterministicStratifiedAssignment::class);
    $first = $service->assign('not-guessable-seed', $candidates, ['a', 'b']);
    $second = $service->assign('not-guessable-seed', array_reverse($candidates), ['a', 'b']);

    expect(array_map(fn ($item): array => $item->toArray(), $first))
        ->toBe(array_map(fn ($item): array => $item->toArray(), $second));

    foreach (['google', 'microsoft'] as $provider) {
        $members = array_values(array_filter($first, fn ($item): bool => $item->recipientProvider->value === $provider));
        expect(count(array_filter($members, fn ($item): bool => $item->cohortKey === 'a')))
            ->toBeLessThanOrEqual(1 + intdiv(count($members), 2));
    }
});

it('prepares, freezes, and prevents experimental input changes', function () {
    $experiment = MailExperiment::factory()->create(['randomisation_seed' => 'fixed-seed']);
    $cohortA = MailExperimentCohort::factory()->create([
        'mail_experiment_id' => $experiment->id,
        'key' => 'a',
        'domain_strategy' => MailExperimentDomainStrategy::Subdomain,
    ]);
    $cohortB = MailExperimentCohort::factory()->create([
        'mail_experiment_id' => $experiment->id,
        'key' => 'b',
        'domain_strategy' => MailExperimentDomainStrategy::SeparateRootDomains,
    ]);
    $mailboxesA = experimentManagedMailboxSet($experiment, MailExperimentDomainStrategy::Subdomain, 'subdomain');
    $mailboxesB = experimentManagedMailboxSet($experiment, MailExperimentDomainStrategy::SeparateRootDomains, 'root');
    app(MailExperimentLifecycleService::class)->attachMailboxes($cohortA, $mailboxesA);
    app(MailExperimentLifecycleService::class)->attachMailboxes($cohortB, $mailboxesB);

    $template = Campaign::factory()->create([
        'user_id' => $experiment->created_by_user_id,
        'workspace_id' => $experiment->workspace_id,
    ]);

    $prepared = app(MailExperimentLifecycleService::class)->prepare($experiment, [
        ['email' => 'one@google.test', 'recipient_provider' => 'google', 'compliance_eligible' => true, 'legal_eligible' => true],
        ['email' => 'two@google.test', 'recipient_provider' => 'google', 'compliance_eligible' => true, 'legal_eligible' => true],
        ['email' => 'three@microsoft.test', 'recipient_provider' => 'microsoft', 'compliance_eligible' => true, 'legal_eligible' => true],
    ], $template);
    expect($prepared->status)->toBe(MailExperimentStatus::Ready)
        ->and($prepared->assignments)->toHaveCount(3);

    $frozen = app(MailExperimentLifecycleService::class)->freeze($prepared);
    expect($frozen->isFrozen())->toBeTrue();

    expect(fn () => $frozen->update(['hypothesis' => 'changed']))
        ->toThrow(LogicException::class);

    $campaign = $frozen->cohorts()->orderBy('key')->firstOrFail()->campaign()->firstOrFail();
    $existingRecipient = $campaign->recipients()->firstOrFail();
    DB::table('campaign_recipients')
        ->where('id', $existingRecipient->id)
        ->update([
            'email_address' => 'imported-after-freeze@example.test',
            'email_normalized' => 'imported-after-freeze@example.test',
        ]);

    expect(fn () => app(MailExperimentLifecycleService::class)->launch($frozen))
        ->toThrow(LogicException::class);
    expect($experiment->fresh()->status)->toBe(MailExperimentStatus::Aborted)
        ->and($experiment->fresh()->incidents[0]['reason'])->toBe('cohort_campaign_snapshot_changed');
});

it('launches an unchanged frozen experiment whose hashes predate variation flags', function () {
    $experiment = MailExperiment::factory()->create(['randomisation_seed' => 'legacy-hash-seed']);
    $cohortA = MailExperimentCohort::factory()->create([
        'mail_experiment_id' => $experiment->id,
        'key' => 'a',
        'domain_strategy' => MailExperimentDomainStrategy::Subdomain,
    ]);
    $cohortB = MailExperimentCohort::factory()->create([
        'mail_experiment_id' => $experiment->id,
        'key' => 'b',
        'domain_strategy' => MailExperimentDomainStrategy::SeparateRootDomains,
    ]);
    $lifecycle = app(MailExperimentLifecycleService::class);
    $lifecycle->attachMailboxes($cohortA, experimentManagedMailboxSet($experiment, MailExperimentDomainStrategy::Subdomain, 'legacy-subdomain'));
    $lifecycle->attachMailboxes($cohortB, experimentManagedMailboxSet($experiment, MailExperimentDomainStrategy::SeparateRootDomains, 'legacy-root'));
    $template = Campaign::factory()->create([
        'user_id' => $experiment->created_by_user_id,
        'workspace_id' => $experiment->workspace_id,
    ]);
    $prepared = $lifecycle->prepare($experiment, [
        ['email' => 'one@google.test', 'recipient_provider' => 'google', 'compliance_eligible' => true, 'legal_eligible' => true],
        ['email' => 'two@microsoft.test', 'recipient_provider' => 'microsoft', 'compliance_eligible' => true, 'legal_eligible' => true],
    ], $template);
    $frozen = $lifecycle->freeze($prepared);
    $configuration = $frozen->getAttribute('configuration');
    $eligibility = is_array($configuration) && is_array($configuration['eligibility'] ?? null)
        ? $configuration['eligibility']
        : [];
    $snapshots = app(CampaignSnapshotService::class);

    foreach ($frozen->cohorts()->with('campaign')->get() as $cohort) {
        $snapshot = [
            'campaign' => $snapshots->snapshot($cohort->campaign, $eligibility, $frozen),
            'mailbox_topology' => $cohort->mailbox_topology_snapshot,
        ];
        $legacyHash = $snapshots->legacyHashIfVariationsDisabled($snapshot);
        expect($legacyHash)->not->toBeNull();
        DB::table('mail_experiment_cohorts')->where('id', $cohort->id)->update(['snapshot_hash' => $legacyHash]);
    }

    expect($lifecycle->launch($frozen->fresh())->status)->toBe(MailExperimentStatus::Running);
});

it('binds the target and ramp to preparation and refuses an under-filled freeze', function () {
    $experiment = MailExperiment::factory()->create([
        'target_assignments_per_cohort' => 2,
        'configuration' => [
            'ramp' => [
                'initial_per_mailbox' => 1,
                'review_after_healthy_days' => 2,
            ],
        ],
    ]);
    $cohortA = MailExperimentCohort::factory()->create([
        'mail_experiment_id' => $experiment->id,
        'key' => 'a',
        'domain_strategy' => MailExperimentDomainStrategy::Subdomain,
    ]);
    $cohortB = MailExperimentCohort::factory()->create([
        'mail_experiment_id' => $experiment->id,
        'key' => 'b',
        'domain_strategy' => MailExperimentDomainStrategy::SeparateRootDomains,
    ]);
    $lifecycle = app(MailExperimentLifecycleService::class);
    $lifecycle->attachMailboxes($cohortA, experimentManagedMailboxSet($experiment, MailExperimentDomainStrategy::Subdomain, 'target-subdomain'));
    $lifecycle->attachMailboxes($cohortB, experimentManagedMailboxSet($experiment, MailExperimentDomainStrategy::SeparateRootDomains, 'target-root'));
    $template = Campaign::factory()->create([
        'user_id' => $experiment->created_by_user_id,
        'workspace_id' => $experiment->workspace_id,
    ]);

    $prepared = $lifecycle->prepare($experiment, [
        ['email' => 'one@example.test', 'recipient_provider' => 'google', 'compliance_eligible' => true, 'legal_eligible' => true],
        ['email' => 'two@example.test', 'recipient_provider' => 'google', 'compliance_eligible' => true, 'legal_eligible' => true],
        ['email' => 'three@example.test', 'recipient_provider' => 'microsoft', 'compliance_eligible' => true, 'legal_eligible' => true],
    ], $template);

    expect($prepared->assignment_snapshot['target_assignments_per_cohort'])->toBe(2)
        ->and($prepared->assignment_snapshot['ramp'])->toBe([
            'initial_per_mailbox' => 1,
            'review_after_healthy_days' => 2,
        ])
        ->and($prepared->assignments()->whereNull('exclusion_reason')->count())->toBe(3);

    expect(fn () => $lifecycle->freeze($prepared))
        ->toThrow(LogicException::class, 'exactly 2 are required');
});

it('uses one recipient-level denominator when follow-ups are present and exports safe evidence', function () {
    $experiment = MailExperiment::factory()->create();
    $cohortA = MailExperimentCohort::factory()->create([
        'mail_experiment_id' => $experiment->id,
        'key' => 'a',
        'domain_strategy' => MailExperimentDomainStrategy::Subdomain,
    ]);
    $cohortB = MailExperimentCohort::factory()->create([
        'mail_experiment_id' => $experiment->id,
        'key' => 'b',
        'domain_strategy' => MailExperimentDomainStrategy::SeparateRootDomains,
    ]);
    $mailboxesA = experimentManagedMailboxSet($experiment, MailExperimentDomainStrategy::Subdomain, 'denominator-subdomain');
    $mailboxesB = experimentManagedMailboxSet($experiment, MailExperimentDomainStrategy::SeparateRootDomains, 'denominator-root');
    app(MailExperimentLifecycleService::class)->attachMailboxes($cohortA, $mailboxesA);
    app(MailExperimentLifecycleService::class)->attachMailboxes($cohortB, $mailboxesB);
    $mailbox = $mailboxesA[0];

    $campaign = Campaign::factory()->create([
        'user_id' => $experiment->created_by_user_id,
        'workspace_id' => $experiment->workspace_id,
    ]);
    $campaign->mailboxes()->sync([$mailbox->id => ['position' => 1]]);
    $cohortA->forceFill(['campaign_id' => $campaign->id])->save();

    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $experiment->workspace_id,
        'mailbox_id' => $mailbox->id,
        'email_address' => 'person@example.test',
        'email_normalized' => 'person@example.test',
        'status' => 'replied',
        'first_sent_at' => now()->subHour(),
        'replied_at' => now(),
    ]);
    $assignment = $experiment->assignments()->create([
        'mail_experiment_cohort_id' => $cohortA->id,
        'workspace_id' => $experiment->workspace_id,
        'person_id' => null,
        'account_id' => null,
        'campaign_id' => $campaign->id,
        'campaign_recipient_id' => $recipient->id,
        'email_normalized' => 'person@example.test',
        'recipient_domain' => 'example.test',
        'recipient_provider' => RecipientMailboxProvider::Google,
        'stratum_key' => 'google',
        'assignment_key' => hash('sha256', 'person@example.test'),
        'provider_mx_snapshot' => ['aspmx.l.google.com'],
        'assigned_at' => now()->subHour(),
    ]);
    $thread = Thread::factory()->create([
        'workspace_id' => $experiment->workspace_id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
    ]);
    $message = Message::factory()->outbound()->create([
        'workspace_id' => $experiment->workspace_id,
        'mailbox_id' => $mailbox->id,
        'thread_id' => $thread->id,
        'campaign_recipient_id' => $recipient->id,
        'status' => 'sent',
        'sent_at' => now()->subHour(),
    ]);
    SendAttempt::factory()->create([
        'workspace_id' => $experiment->workspace_id,
        'mailbox_id' => $mailbox->id,
        'message_id' => $message->id,
        'campaign_recipient_id' => $recipient->id,
        'kind' => SendAttemptKind::Campaign,
        'status' => SendAttemptStatus::Sent,
        'created_at' => now()->subHour(),
    ]);
    SendAttempt::factory()->create([
        'workspace_id' => $experiment->workspace_id,
        'mailbox_id' => $mailbox->id,
        'message_id' => $message->id,
        'campaign_recipient_id' => $recipient->id,
        'kind' => SendAttemptKind::Campaign,
        'status' => SendAttemptStatus::Sent,
        'created_at' => now()->subMinutes(30),
    ]);

    $report = app(ExperimentMetricsService::class)->report($experiment);
    expect($report['summary']['exposed_count'])->toBe(1)
        ->and($report['summary']['human_reply_count'])->toBe(1)
        ->and($report['summary']['human_reply_rate'])->toBe(1.0);

    $csv = app(ExperimentEvidenceExporter::class)->toCsv($experiment);
    expect($csv)->toContain('assignment_id')
        ->and($csv)->toContain('person@example.test');
});

it('persists an abort when a frozen assignment changes before launch', function () {
    $experiment = MailExperiment::factory()->create([
        'configuration' => [
            'approval_evidence' => [
                'compliance_review_confirmed' => true,
                'legal_review_confirmed' => true,
                'confirmed_at' => now()->toIso8601String(),
                'source' => 'candidate_records',
            ],
        ],
    ]);
    $cohortA = MailExperimentCohort::factory()->create([
        'mail_experiment_id' => $experiment->id,
        'key' => 'a',
        'domain_strategy' => MailExperimentDomainStrategy::Subdomain,
    ]);
    $cohortB = MailExperimentCohort::factory()->create([
        'mail_experiment_id' => $experiment->id,
        'key' => 'b',
        'domain_strategy' => MailExperimentDomainStrategy::SeparateRootDomains,
    ]);
    $mailboxA = experimentManagedMailboxSet($experiment, MailExperimentDomainStrategy::Subdomain, 'abort-subdomain');
    $mailboxB = experimentManagedMailboxSet($experiment, MailExperimentDomainStrategy::SeparateRootDomains, 'abort-root');
    $lifecycle = app(MailExperimentLifecycleService::class);
    $lifecycle->attachMailboxes($cohortA, $mailboxA);
    $lifecycle->attachMailboxes($cohortB, $mailboxB);

    $campaignA = Campaign::factory()->create([
        'user_id' => $experiment->created_by_user_id,
        'workspace_id' => $experiment->workspace_id,
    ]);
    $campaignB = Campaign::factory()->create([
        'user_id' => $experiment->created_by_user_id,
        'workspace_id' => $experiment->workspace_id,
    ]);
    $cohortA->forceFill(['campaign_id' => $campaignA->id])->save();
    $cohortB->forceFill(['campaign_id' => $campaignB->id])->save();
    $experiment->assignments()->create([
        'mail_experiment_cohort_id' => $cohortA->id,
        'workspace_id' => $experiment->workspace_id,
        'email_normalized' => 'person@example.test',
        'recipient_domain' => 'example.test',
        'recipient_provider' => RecipientMailboxProvider::Other,
        'stratum_key' => 'other',
        'assignment_key' => hash('sha256', 'person@example.test'),
        'provider_mx_snapshot' => ['mx.example.test'],
        'assigned_at' => now(),
    ]);
    $experiment->assignments()->create([
        'mail_experiment_cohort_id' => $cohortB->id,
        'workspace_id' => $experiment->workspace_id,
        'email_normalized' => 'person-two@example.test',
        'recipient_domain' => 'example.test',
        'recipient_provider' => RecipientMailboxProvider::Other,
        'stratum_key' => 'other',
        'assignment_key' => hash('sha256', 'person-two@example.test'),
        'provider_mx_snapshot' => ['mx.example.test'],
        'assigned_at' => now(),
    ]);

    $frozen = $lifecycle->freeze($experiment);
    DB::table('mail_experiment_assignments')
        ->where('mail_experiment_id', $experiment->id)
        ->update(['stratum_key' => 'mutated-after-freeze']);

    expect(fn () => $lifecycle->launch($frozen))
        ->toThrow(LogicException::class);
    expect($experiment->fresh()->status)->toBe(MailExperimentStatus::Aborted)
        ->and($experiment->fresh()->incident_count)->toBe(1);
});

it('keeps preparation idempotent when the same candidate set is retried', function () {
    $experiment = MailExperiment::factory()->create();
    MailExperimentCohort::factory()->create(['mail_experiment_id' => $experiment->id, 'key' => 'a']);
    MailExperimentCohort::factory()->create(['mail_experiment_id' => $experiment->id, 'key' => 'b']);
    $candidates = [
        ['email' => 'one@example.test', 'recipient_provider' => 'google'],
        ['email' => 'two@example.test', 'recipient_provider' => 'google'],
    ];

    $service = app(MailExperimentAssignmentService::class);
    $first = $service->handle($experiment, $candidates);
    $second = $service->handle($experiment, array_reverse($candidates));

    expect($first->pluck('assignment_key')->all())
        ->toBe($second->pluck('assignment_key')->all())
        ->and($experiment->fresh()->assignments)->toHaveCount(2);
});

it('clones equivalent cohort campaigns and enrolls each assigned recipient once', function () {
    $experiment = MailExperiment::factory()->create();
    $cohortA = MailExperimentCohort::factory()->create([
        'mail_experiment_id' => $experiment->id,
        'key' => 'a',
        'domain_strategy' => MailExperimentDomainStrategy::Subdomain,
    ]);
    $cohortB = MailExperimentCohort::factory()->create([
        'mail_experiment_id' => $experiment->id,
        'key' => 'b',
        'domain_strategy' => MailExperimentDomainStrategy::SeparateRootDomains,
    ]);
    $lifecycle = app(MailExperimentLifecycleService::class);
    $lifecycle->attachMailboxes($cohortA, experimentManagedMailboxSet($experiment, MailExperimentDomainStrategy::Subdomain, 'clone-subdomain'));
    $lifecycle->attachMailboxes($cohortB, experimentManagedMailboxSet($experiment, MailExperimentDomainStrategy::SeparateRootDomains, 'clone-root'));
    $template = Campaign::factory()->create([
        'user_id' => $experiment->created_by_user_id,
        'workspace_id' => $experiment->workspace_id,
    ]);
    $candidates = [
        ['email' => 'one@example.test', 'recipient_provider' => 'google', 'compliance_eligible' => true, 'legal_eligible' => true],
        ['email' => 'two@example.test', 'recipient_provider' => 'google', 'compliance_eligible' => true, 'legal_eligible' => true],
        ['email' => 'three@example.test', 'recipient_provider' => 'microsoft', 'compliance_eligible' => true, 'legal_eligible' => true],
        ['email' => 'four@example.test', 'recipient_provider' => 'microsoft', 'compliance_eligible' => true, 'legal_eligible' => true],
    ];

    $prepared = $lifecycle->prepare($experiment, $candidates, $template);
    $preparedCohorts = $prepared->fresh(['cohorts.campaign.draftVersion.activeSteps', 'cohorts.mailboxes'])->cohorts;
    $campaigns = $preparedCohorts->map(fn (MailExperimentCohort $cohort): ?Campaign => $cohort->campaign)->filter()->values();
    $snapshots = app(CampaignSnapshotService::class);

    expect($campaigns)->toHaveCount(2)
        ->and($prepared->assignments()->whereNotNull('campaign_recipient_id')->count())->toBe(4)
        ->and($snapshots->equivalent($campaigns[0], $campaigns[1]))->toBeTrue();
});

it('enforces one experiment cohort per mailbox at the pivot level', function () {
    $experiment = MailExperiment::factory()->create();
    $cohortA = MailExperimentCohort::factory()->create(['mail_experiment_id' => $experiment->id, 'key' => 'a']);
    $cohortB = MailExperimentCohort::factory()->create(['mail_experiment_id' => $experiment->id, 'key' => 'b']);
    $mailbox = Mailbox::factory()->create(['workspace_id' => $experiment->workspace_id]);

    $cohortA->mailboxes()->attach($mailbox->id, ['position' => 1]);

    expect(fn () => $cohortB->mailboxes()->attach($mailbox->id, ['position' => 1]))
        ->toThrow(QueryException::class);
    expect($cohortA->mailboxes()->first()->pivot->mail_experiment_id)->toBe($experiment->id);
});

it('keeps unknown sends out of exposure while reporting mailbox health evidence', function () {
    $experiment = MailExperiment::factory()->create();
    $cohortA = MailExperimentCohort::factory()->create([
        'mail_experiment_id' => $experiment->id,
        'key' => 'a',
        'domain_strategy' => MailExperimentDomainStrategy::Subdomain,
    ]);
    MailExperimentCohort::factory()->create([
        'mail_experiment_id' => $experiment->id,
        'key' => 'b',
        'domain_strategy' => MailExperimentDomainStrategy::SeparateRootDomains,
    ]);
    $mailboxes = experimentManagedMailboxSet($experiment, MailExperimentDomainStrategy::Subdomain, 'unknown-subdomain');
    $mailbox = $mailboxes[0];
    app(MailExperimentLifecycleService::class)->attachMailboxes($cohortA, $mailboxes);

    $campaign = Campaign::factory()->create([
        'user_id' => $experiment->created_by_user_id,
        'workspace_id' => $experiment->workspace_id,
    ]);
    $campaign->mailboxes()->sync([$mailbox->id => ['position' => 1]]);
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $experiment->workspace_id,
        'mailbox_id' => $mailbox->id,
        'email_address' => 'unknown@example.test',
        'email_normalized' => 'unknown@example.test',
        'status' => 'replied',
        'first_sent_at' => now()->subHour(),
        'replied_at' => now(),
    ]);
    $experiment->assignments()->create([
        'mail_experiment_cohort_id' => $cohortA->id,
        'workspace_id' => $experiment->workspace_id,
        'campaign_id' => $campaign->id,
        'campaign_recipient_id' => $recipient->id,
        'email_normalized' => 'unknown@example.test',
        'recipient_domain' => 'example.test',
        'recipient_provider' => RecipientMailboxProvider::Unknown,
        'stratum_key' => 'unknown',
        'assignment_key' => hash('sha256', 'unknown@example.test'),
        'provider_mx_snapshot' => [],
        'assigned_at' => now()->subHour(),
    ]);
    SendAttempt::factory()->create([
        'workspace_id' => $experiment->workspace_id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
        'kind' => SendAttemptKind::Campaign,
        'status' => SendAttemptStatus::Unknown,
    ]);
    $mailbox->update(['sending_paused_at' => now(), 'sending_paused_reason' => 'provider_error_threshold']);
    MailboxSyncState::factory()->create([
        'mailbox_id' => $mailbox->id,
        'status' => SyncStatus::Failed,
        'last_error' => 'sync failed',
    ]);

    $summary = app(ExperimentMetricsService::class)->report($experiment)['summary'];

    expect($summary['exposed_count'])->toBe(0)
        ->and($summary['human_reply_count'])->toBe(0)
        ->and($summary['unknown_first_attempt_count'])->toBe(1)
        ->and($summary['unknown_or_manual_review_rate'])->toBe(1.0)
        ->and($summary['mailbox_health_pause_count'])->toBe(1)
        ->and($summary['sync_failure_count'])->toBe(1)
        ->and($summary['sync_failure_event_count'])->toBe(1);
});

it('records a second address for one person as an exclusion without violating uniqueness', function () {
    $experiment = MailExperiment::factory()->create();
    MailExperimentCohort::factory()->create(['mail_experiment_id' => $experiment->id, 'key' => 'a']);
    MailExperimentCohort::factory()->create(['mail_experiment_id' => $experiment->id, 'key' => 'b']);
    $person = Person::factory()->create(['workspace_id' => $experiment->workspace_id]);
    $candidates = [
        [
            'person_id' => $person->id,
            'email' => 'first@example.test',
            'recipient_provider' => 'google',
        ],
        [
            'person_id' => $person->id,
            'email' => 'second@example.test',
            'recipient_provider' => 'google',
        ],
    ];

    $assignments = app(MailExperimentAssignmentService::class)->handle($experiment, $candidates);

    expect($assignments)->toHaveCount(2)
        ->and($assignments->whereNotNull('person_id'))->toHaveCount(1)
        ->and($assignments->where('exclusion_reason', 'duplicate_person'))->toHaveCount(1);
});
