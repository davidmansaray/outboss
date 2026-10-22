<?php

use App\Domain\Campaigns\Enums\CampaignStatus;
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
use App\Domain\Mail\Services\MailExperimentAssignmentService;
use App\Domain\Mail\Services\MailExperimentLifecycleService;
use App\Models\Campaign;
use App\Models\Mailbox;
use App\Models\MailExperiment;
use App\Models\MailExperimentCohort;
use App\Models\ManagedMailboxAccount;
use App\Models\ManagedMailDomain;
use App\Models\ProviderConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

/** @return list<Mailbox> */
function integrityMailboxSet(MailExperiment $experiment, MailExperimentDomainStrategy $strategy, string $prefix): array
{
    $kind = $strategy === MailExperimentDomainStrategy::Subdomain
        ? ManagedMailDomainKind::Subdomain
        : ManagedMailDomainKind::Root;
    $sharedDomain = $strategy === MailExperimentDomainStrategy::Subdomain
        ? ManagedMailDomain::factory()->create([
            'workspace_id' => $experiment->workspace_id,
            'created_by_user_id' => $experiment->created_by_user_id,
            'domain' => $prefix.'-'.$experiment->id.'.example.test',
            'organisational_domain' => 'example.test',
            'domain_kind' => $kind,
            'provider' => MailProviderName::Stalwart,
            'status' => ManagedMailDomainStatus::Active,
            'verified_at' => now()->subDay(),
            'last_verified_at' => now()->subHour(),
        ])
        : null;

    $mailboxes = [];
    foreach (range(1, 3) as $position) {
        $domain = $sharedDomain ?? ManagedMailDomain::factory()->create([
            'workspace_id' => $experiment->workspace_id,
            'created_by_user_id' => $experiment->created_by_user_id,
            'domain' => $prefix.'-'.$position.'-'.$experiment->id.'.example.test',
            'organisational_domain' => 'example.test',
            'domain_kind' => $kind,
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
            'credential_payload' => ['username' => $prefix.$position.'@'.$domain->domain, 'password' => 'test-password'],
            'status' => ConnectionStatus::Connected,
        ]);
        $mailbox = Mailbox::factory()->create([
            'user_id' => $experiment->created_by_user_id,
            'workspace_id' => $experiment->workspace_id,
            'provider_connection_id' => $connection->id,
            'email_address' => $prefix.$position.'@'.$domain->domain,
            'status' => ConnectionStatus::Connected,
            'provider_metadata' => ['managed_mail_domain_id' => $domain->id],
        ]);
        ManagedMailboxAccount::factory()->create([
            'managed_mail_domain_id' => $domain->id,
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

function integrityExperimentCohorts(MailExperiment $experiment): array
{
    return [
        MailExperimentCohort::factory()->create([
            'mail_experiment_id' => $experiment->id,
            'key' => 'a',
            'domain_strategy' => MailExperimentDomainStrategy::Subdomain,
        ]),
        MailExperimentCohort::factory()->create([
            'mail_experiment_id' => $experiment->id,
            'key' => 'b',
            'domain_strategy' => MailExperimentDomainStrategy::SeparateRootDomains,
        ]),
    ];
}

it('binds rules, approval evidence, and candidate evidence to assignment retries', function () {
    $experiment = MailExperiment::factory()->create([
        'configuration' => [
            'eligibility' => ['allowed_countries' => ['us']],
            'approval_evidence' => [
                'compliance_review_confirmed' => true,
                'legal_review_confirmed' => true,
                'source' => 'cli',
                'reference' => 'LEGAL-1',
                'confirmed_at' => now()->toIso8601String(),
            ],
        ],
    ]);
    integrityExperimentCohorts($experiment);
    $candidates = [
        [
            'email' => 'one@example.test',
            'recipient_provider' => RecipientMailboxProvider::Google,
            'country_code' => 'us',
            'compliance_eligible' => true,
            'legal_eligible' => true,
        ],
        [
            'email' => 'two@example.test',
            'recipient_provider' => RecipientMailboxProvider::Google,
            'country_code' => 'us',
            'compliance_eligible' => true,
            'legal_eligible' => true,
        ],
    ];
    $service = app(MailExperimentAssignmentService::class);
    $first = $service->handle($experiment, $candidates);
    $snapshotHash = $experiment->fresh()->assignment_snapshot_hash;

    expect($snapshotHash)->toBeString()
        ->and($experiment->fresh()->assignment_snapshot['eligibility']['allowed_countries'])->toBe(['us'])
        ->and($first->every(fn ($assignment): bool => $assignment->assignment_snapshot_hash === $snapshotHash))->toBeTrue();

    // Reordering the same canonical audience is an idempotent retry.
    expect($service->handle($experiment, array_reverse($candidates))->pluck('id')->all())
        ->toBe($first->pluck('id')->all());

    $changedCandidate = $candidates;
    $changedCandidate[0]['legal_eligible'] = false;
    expect(fn () => $service->handle($experiment, $changedCandidate))
        ->toThrow(LogicException::class);

    $experiment->forceFill([
        'configuration' => [
            'eligibility' => ['allowed_countries' => ['ca']],
            'approval_evidence' => $experiment->fresh()->configuration['approval_evidence'],
        ],
    ])->save();
    expect(fn () => $service->handle($experiment, $candidates))
        ->toThrow(LogicException::class);
    expect($experiment->fresh()->assignment_snapshot_hash)->toBe($snapshotHash)
        ->and($experiment->fresh()->assignments)->toHaveCount(2);
});

it('preserves the original approval evidence on a valid lifecycle retry', function () {
    $experiment = MailExperiment::factory()->create();
    integrityExperimentCohorts($experiment);
    $candidates = [
        ['email' => 'one@example.test', 'recipient_provider' => 'google'],
        ['email' => 'two@example.test', 'recipient_provider' => 'google'],
    ];
    $evidence = [
        'compliance_review_confirmed' => true,
        'legal_review_confirmed' => true,
        'source' => 'cli',
        'reference' => 'LEGAL-RETRY-1',
        'confirmed_by_user_id' => $experiment->created_by_user_id,
    ];
    $lifecycle = app(MailExperimentLifecycleService::class);
    $first = $lifecycle->prepare($experiment, $candidates, null, $evidence);
    $storedEvidence = $first->fresh()->configuration['approval_evidence'];
    $snapshotHash = $first->fresh()->assignment_snapshot_hash;

    $second = $lifecycle->prepare($experiment, array_reverse($candidates), null, $evidence);

    expect($second->assignments)->toHaveCount(2)
        ->and($second->assignment_snapshot_hash)->toBe($snapshotHash)
        ->and($second->configuration['approval_evidence'])->toBe($storedEvidence);

    $changedEvidence = [...$evidence, 'reference' => 'LEGAL-RETRY-2'];
    expect(fn () => $lifecycle->prepare($experiment, $candidates, null, $changedEvidence))
        ->toThrow(LogicException::class);
    expect($experiment->fresh()->configuration['approval_evidence'])->toBe($storedEvidence);
});

it('blocks frozen mailbox topology changes through relation and raw pivot APIs', function () {
    $experiment = MailExperiment::factory()->create([
        'status' => MailExperimentStatus::Ready,
        'frozen_at' => now(),
    ]);
    $cohort = MailExperimentCohort::factory()->create(['mail_experiment_id' => $experiment->id]);
    $mailbox = Mailbox::factory()->create(['workspace_id' => $experiment->workspace_id]);

    expect(fn () => $cohort->mailboxes()->attach($mailbox->id, ['position' => 1]))
        ->toThrow(LogicException::class);
    expect(fn () => DB::table('mail_experiment_cohort_mailbox')->insert([
        'mail_experiment_cohort_id' => $cohort->id,
        'mail_experiment_id' => $experiment->id,
        'mailbox_id' => $mailbox->id,
        'position' => 1,
    ]))->toThrow(QueryException::class);
    expect(DB::table('mail_experiment_cohort_mailbox')->count())->toBe(0);
});

it('stores the exact ordered mailbox topology in freeze evidence', function () {
    $experiment = MailExperiment::factory()->create([
        'configuration' => [
            'approval_evidence' => [
                'compliance_review_confirmed' => true,
                'legal_review_confirmed' => true,
                'source' => 'cli',
            ],
        ],
    ]);
    [$cohortA, $cohortB] = integrityExperimentCohorts($experiment);
    $mailboxesA = integrityMailboxSet($experiment, MailExperimentDomainStrategy::Subdomain, 'integrity-sub');
    $mailboxesB = integrityMailboxSet($experiment, MailExperimentDomainStrategy::SeparateRootDomains, 'integrity-root');
    $lifecycle = app(MailExperimentLifecycleService::class);
    $lifecycle->attachMailboxes($cohortA, $mailboxesA);
    $lifecycle->attachMailboxes($cohortB, $mailboxesB);
    $campaignA = Campaign::factory()->create(['workspace_id' => $experiment->workspace_id, 'user_id' => $experiment->created_by_user_id]);
    $campaignB = Campaign::factory()->create(['workspace_id' => $experiment->workspace_id, 'user_id' => $experiment->created_by_user_id]);
    $cohortA->forceFill(['campaign_id' => $campaignA->id])->save();
    $cohortB->forceFill(['campaign_id' => $campaignB->id])->save();
    $experiment->assignments()->create([
        'mail_experiment_cohort_id' => $cohortA->id,
        'workspace_id' => $experiment->workspace_id,
        'email_normalized' => 'one@example.test',
        'recipient_domain' => 'example.test',
        'recipient_provider' => RecipientMailboxProvider::Other,
        'stratum_key' => 'other',
        'assignment_key' => hash('sha256', 'integrity-one'),
        'provider_mx_snapshot' => [],
        'assigned_at' => now(),
    ]);
    $experiment->assignments()->create([
        'mail_experiment_cohort_id' => $cohortB->id,
        'workspace_id' => $experiment->workspace_id,
        'email_normalized' => 'two@example.test',
        'recipient_domain' => 'example.test',
        'recipient_provider' => RecipientMailboxProvider::Other,
        'stratum_key' => 'other',
        'assignment_key' => hash('sha256', 'integrity-two'),
        'provider_mx_snapshot' => [],
        'assigned_at' => now(),
    ]);

    $frozen = $lifecycle->freeze($experiment);
    $frozenCohort = $frozen->cohorts()->whereKey($cohortA->id)->firstOrFail();
    $topology = $frozenCohort->mailbox_topology_snapshot;
    expect($topology['mailboxes'])->toHaveCount(3)
        ->and(array_column($topology['mailboxes'], 'position'))->toBe([1, 2, 3])
        ->and(array_column($topology['mailboxes'], 'mailbox_id'))->toBe(array_map(fn (Mailbox $mailbox): int => $mailbox->id, $mailboxesA));

    // A raw attempt to replace a frozen row is rejected by the database even
    // when it bypasses both the lifecycle service and the custom pivot model.
    expect(fn () => DB::table('mail_experiment_cohort_mailbox')
        ->where('mail_experiment_cohort_id', $cohortA->id)
        ->where('mailbox_id', $mailboxesA[0]->id)
        ->update(['position' => 3]))
        ->toThrow(QueryException::class);
});

it('revalidates frozen campaign hashes before resuming after a pause', function (): void {
    $experiment = MailExperiment::factory()->create([
        'configuration' => [
            'approval_evidence' => [
                'compliance_review_confirmed' => true,
                'legal_review_confirmed' => true,
                'source' => 'resume-hash-test',
            ],
        ],
    ]);
    [$cohortA, $cohortB] = integrityExperimentCohorts($experiment);
    $lifecycle = app(MailExperimentLifecycleService::class);
    $lifecycle->attachMailboxes($cohortA, integrityMailboxSet($experiment, MailExperimentDomainStrategy::Subdomain, 'resume-subdomain'));
    $lifecycle->attachMailboxes($cohortB, integrityMailboxSet($experiment, MailExperimentDomainStrategy::SeparateRootDomains, 'resume-root'));

    $campaignA = Campaign::factory()->create([
        'user_id' => $experiment->created_by_user_id,
        'workspace_id' => $experiment->workspace_id,
        'status' => CampaignStatus::Active,
    ]);
    $campaignB = Campaign::factory()->create([
        'user_id' => $experiment->created_by_user_id,
        'workspace_id' => $experiment->workspace_id,
        'status' => CampaignStatus::Active,
    ]);
    $cohortA->forceFill(['campaign_id' => $campaignA->id])->save();
    $cohortB->forceFill(['campaign_id' => $campaignB->id])->save();
    $campaignA->forceFill(['mail_experiment_cohort_id' => $cohortA->id])->save();
    $campaignB->forceFill(['mail_experiment_cohort_id' => $cohortB->id])->save();

    $experiment->assignments()->createMany([
        [
            'mail_experiment_cohort_id' => $cohortA->id,
            'workspace_id' => $experiment->workspace_id,
            'email_normalized' => 'resume-one@example.test',
            'recipient_domain' => 'example.test',
            'recipient_provider' => RecipientMailboxProvider::Other,
            'stratum_key' => 'other',
            'assignment_key' => hash('sha256', 'resume-one'),
            'provider_mx_snapshot' => [],
            'assigned_at' => now(),
        ],
        [
            'mail_experiment_cohort_id' => $cohortB->id,
            'workspace_id' => $experiment->workspace_id,
            'email_normalized' => 'resume-two@example.test',
            'recipient_domain' => 'example.test',
            'recipient_provider' => RecipientMailboxProvider::Other,
            'stratum_key' => 'other',
            'assignment_key' => hash('sha256', 'resume-two'),
            'provider_mx_snapshot' => [],
            'assigned_at' => now(),
        ],
    ]);

    $frozen = $lifecycle->freeze($experiment);
    $frozen->forceFill(['status' => MailExperimentStatus::Running])->save();
    $lifecycle->pause($frozen);

    // Query-builder writes bypass model events; resume must still reject the
    // changed schedule by comparing the stored frozen campaign hash.
    DB::table('campaigns')
        ->where('id', $campaignA->id)
        ->update([
            'send_mode' => 'pin',
            'send_window_start' => '10:00:00',
        ]);

    expect(fn () => $lifecycle->resume($frozen->fresh()))
        ->toThrow(LogicException::class, 'cannot resume');
    expect($frozen->fresh()->status)->toBe(MailExperimentStatus::Paused)
        ->and($campaignA->fresh()->status)->toBe(CampaignStatus::Paused)
        ->and($campaignB->fresh()->status)->toBe(CampaignStatus::Paused);
});
