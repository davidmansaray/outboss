<?php

use App\Domain\Campaigns\DTOs\EnrollmentCandidate;
use App\Domain\Campaigns\Enums\ImportSource;
use App\Domain\Campaigns\Enums\SendMode;
use App\Domain\Campaigns\Services\CommitRecipientImport;
use App\Domain\Campaigns\Services\EnrollPeopleInCampaign;
use App\Domain\Campaigns\Services\PreviewRecipientImport;
use App\Domain\Mail\Enums\MailExperimentStatus;
use App\Domain\Mail\Exceptions\FrozenMailExperimentInputMutation;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\MailExperiment;
use App\Models\MailExperimentCohort;
use App\Models\Person;
use App\Models\RecipientImport;
use App\Models\RecipientImportRow;
use App\Models\SequenceStep;
use App\Models\SequenceVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** @return array{experiment:MailExperiment,campaign:Campaign,version:SequenceVersion,step:SequenceStep,mailbox:Mailbox} */
function frozenInputMutationFixture(): array
{
    $owner = User::factory()->create();
    $workspaceId = $owner->currentWorkspace()->id;
    $experiment = MailExperiment::factory()->create([
        'created_by_user_id' => $owner->id,
        'workspace_id' => $workspaceId,
        'status' => MailExperimentStatus::Ready,
    ]);
    $campaign = Campaign::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $workspaceId,
    ]);
    $cohort = MailExperimentCohort::factory()->create([
        'mail_experiment_id' => $experiment->id,
        'campaign_id' => $campaign->id,
        'key' => 'a',
    ]);
    $campaign->forceFill(['mail_experiment_cohort_id' => $cohort->id])->save();
    $mailbox = Mailbox::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $workspaceId,
    ]);
    $campaign->mailboxes()->attach($mailbox->id, ['position' => 1]);

    $version = $campaign->draftVersion()->firstOrFail();
    $step = $version->steps()->firstOrFail();
    $experiment->forceFill(['frozen_at' => now()])->save();

    return compact('experiment', 'campaign', 'version', 'step', 'mailbox');
}

it('rejects every campaign schedule and identity mutation after freeze', function (): void {
    $fixture = frozenInputMutationFixture();

    foreach ([
        'name' => 'changed after freeze',
        'send_mode' => SendMode::Pin,
        'timezone' => 'America/New_York',
        'send_window_start' => '10:00:00',
        'send_window_end' => '18:00:00',
        'business_days_only' => false,
        'open_tracking_enabled' => true,
    ] as $attribute => $value) {
        $campaign = $fixture['campaign']->fresh();
        $campaign->forceFill([$attribute => $value]);

        expect(fn () => $campaign->save())
            ->toThrow(FrozenMailExperimentInputMutation::class);
    }
});

it('rejects sequence version and step mutations after freeze', function (): void {
    $fixture = frozenInputMutationFixture();

    $version = $fixture['version']->fresh();
    $version->forceFill(['version' => 2]);
    expect(fn () => $version->save())
        ->toThrow(FrozenMailExperimentInputMutation::class);

    $step = $fixture['step']->fresh();
    $step->forceFill(['body_template' => 'changed after freeze']);
    expect(fn () => $step->save())
        ->toThrow(FrozenMailExperimentInputMutation::class);

    $step = $fixture['step']->fresh();
    $step->forceFill(['body_spintax_enabled' => true]);
    expect(fn () => $step->save())
        ->toThrow(FrozenMailExperimentInputMutation::class);

    expect(fn () => SequenceStep::query()->create([
        'sequence_version_id' => $fixture['version']->id,
        'position' => 2,
        'title' => 'New step',
        'delay_minutes' => 1,
        'body_template' => 'new body',
    ]))->toThrow(FrozenMailExperimentInputMutation::class);

    expect(fn () => $step->delete())
        ->toThrow(FrozenMailExperimentInputMutation::class);
});

it('rejects campaign mailbox attach and detach mutations after freeze', function (): void {
    $fixture = frozenInputMutationFixture();
    $replacement = Mailbox::factory()->create([
        'user_id' => $fixture['campaign']->user_id,
        'workspace_id' => $fixture['experiment']->workspace_id,
    ]);

    expect(fn () => $fixture['campaign']->mailboxes()->attach($replacement->id, ['position' => 2]))
        ->toThrow(FrozenMailExperimentInputMutation::class);
    expect(fn () => $fixture['campaign']->mailboxes()->detach($fixture['mailbox']->id))
        ->toThrow(FrozenMailExperimentInputMutation::class);
    expect($fixture['campaign']->fresh()->mailboxes)->toHaveCount(1);
});

it('rejects deleting a frozen cohort campaign', function (): void {
    $fixture = frozenInputMutationFixture();

    expect(fn () => $fixture['campaign']->delete())
        ->toThrow(FrozenMailExperimentInputMutation::class);
    expect(Campaign::query()->whereKey($fixture['campaign']->id)->exists())->toBeTrue();
});

it('checks every cohort link before allowing a campaign input mutation', function (): void {
    $fixture = frozenInputMutationFixture();
    $otherExperiment = MailExperiment::factory()->create([
        'workspace_id' => $fixture['experiment']->workspace_id,
        'created_by_user_id' => $fixture['experiment']->created_by_user_id,
        'status' => MailExperimentStatus::Ready,
        'frozen_at' => now(),
    ]);
    MailExperimentCohort::factory()->create([
        'mail_experiment_id' => $otherExperiment->id,
        'campaign_id' => $fixture['campaign']->id,
        'key' => 'other',
    ]);

    $campaign = $fixture['campaign']->fresh();
    $campaign->forceFill(['send_window_start' => '11:00:00']);

    expect(fn () => $campaign->save())
        ->toThrow(FrozenMailExperimentInputMutation::class);
});

it('rejects post-freeze preview, commit, enrolment, and direct recipient/import creation', function (): void {
    $fixture = frozenInputMutationFixture();
    $person = Person::factory()->create([
        'workspace_id' => $fixture['experiment']->workspace_id,
    ]);
    $candidate = new EnrollmentCandidate(
        personId: $person->id,
        accountId: $person->account_id,
        emailAddress: $person->email_address,
        emailNormalized: $person->email_normalized,
        fullName: $person->full_name,
        firstName: $person->first_name,
        company: null,
    );

    expect(fn () => app(PreviewRecipientImport::class)->handle(
        User::query()->findOrFail($fixture['experiment']->created_by_user_id),
        $fixture['campaign'],
        ImportSource::Paste,
        [['email' => $person->email_address]],
        ['email' => 'email'],
    ))->toThrow(FrozenMailExperimentInputMutation::class)
        ->and(fn () => app(EnrollPeopleInCampaign::class)->handle($fixture['campaign'], [$candidate]))
        ->toThrow(FrozenMailExperimentInputMutation::class)
        ->and(fn () => CampaignRecipient::query()->create([
            'campaign_id' => $fixture['campaign']->id,
            'workspace_id' => $fixture['experiment']->workspace_id,
            'mailbox_id' => $fixture['mailbox']->id,
            'sequence_version_id' => $fixture['version']->id,
            'email_address' => 'late@example.test',
            'email_normalized' => 'late@example.test',
            'status' => 'queued',
            'mailbox_assignment' => 'rotation',
        ]))->toThrow(FrozenMailExperimentInputMutation::class)
        ->and(fn () => RecipientImport::query()->create([
            'campaign_id' => $fixture['campaign']->id,
            'user_id' => $fixture['experiment']->created_by_user_id,
            'workspace_id' => $fixture['experiment']->workspace_id,
            'source' => ImportSource::Paste,
            'source_hash' => hash('sha256', 'late-import'),
            'headers' => ['email'],
            'mapping' => ['email' => 'email'],
            'mapping_version' => 1,
            'status' => 'previewed',
            'expires_at' => now()->addDay(),
        ]))->toThrow(FrozenMailExperimentInputMutation::class);
});

it('rejects raw audience inserts into a frozen cohort campaign', function (): void {
    $fixture = frozenInputMutationFixture();

    expect(fn () => DB::table('campaign_recipients')->insert([
        'campaign_id' => $fixture['campaign']->id,
        'workspace_id' => $fixture['experiment']->workspace_id,
        'mailbox_id' => $fixture['mailbox']->id,
        'sequence_version_id' => $fixture['version']->id,
        'email_address' => 'raw-late@example.test',
        'email_normalized' => 'raw-late@example.test',
        'status' => 'queued',
        'mailbox_assignment' => 'rotation',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects committing an import that was reviewed before the experiment froze', function (): void {
    $fixture = frozenInputMutationFixture();
    $fixture['experiment']->forceFill([
        'frozen_at' => null,
        'status' => MailExperimentStatus::Ready,
    ])->save();
    $import = RecipientImport::factory()->create([
        'campaign_id' => $fixture['campaign']->id,
        'user_id' => $fixture['experiment']->created_by_user_id,
        'workspace_id' => $fixture['experiment']->workspace_id,
    ]);
    RecipientImportRow::factory()->create([
        'recipient_import_id' => $import->id,
        'email_address' => 'reviewed-before-freeze@example.test',
        'email_normalized' => 'reviewed-before-freeze@example.test',
        'issue' => 'ready',
    ]);
    $fixture['experiment']->forceFill([
        'frozen_at' => now(),
        'status' => MailExperimentStatus::Running,
    ])->save();

    expect(fn () => app(CommitRecipientImport::class)->handle($import, 1))
        ->toThrow(FrozenMailExperimentInputMutation::class);
});
