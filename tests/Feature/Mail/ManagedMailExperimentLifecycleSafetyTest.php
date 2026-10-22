<?php

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Mail\Enums\MailExperimentStatus;
use App\Domain\Mail\Enums\SendAttemptKind;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Services\MailExperimentLifecycleService;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\MailExperiment;
use App\Models\MailExperimentCohort;
use App\Models\Message;
use App\Models\SendAttempt;
use App\Models\Thread;
use App\Models\User;
use LogicException;

/** @return array{experiment:MailExperiment,campaigns:list<Campaign>,attempt:SendAttempt} */
function completionSafetyFixture(SendAttemptStatus $status): array
{
    $owner = User::factory()->create();
    $workspaceId = $owner->currentWorkspace()->id;
    $experiment = MailExperiment::factory()->create([
        'created_by_user_id' => $owner->id,
        'workspace_id' => $workspaceId,
    ]);
    $campaigns = [];
    $attempt = null;

    foreach (['a', 'b'] as $index => $key) {
        $campaign = Campaign::factory()->create([
            'user_id' => $owner->id,
            'workspace_id' => $workspaceId,
            'status' => CampaignStatus::Active,
            'business_days_only' => false,
        ]);
        $cohort = MailExperimentCohort::factory()->create([
            'mail_experiment_id' => $experiment->id,
            'campaign_id' => $campaign->id,
            'key' => $key,
        ]);
        $campaign->forceFill(['mail_experiment_cohort_id' => $cohort->id])->save();
        $campaigns[] = $campaign;

        if ($index !== 0) {
            continue;
        }

        $mailbox = Mailbox::factory()->create([
            'user_id' => $owner->id,
            'workspace_id' => $workspaceId,
        ]);
        $recipient = CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'workspace_id' => $workspaceId,
            'mailbox_id' => $mailbox->id,
            'sequence_version_id' => $campaign->draft_sequence_version_id,
            'status' => 'active',
        ]);
        $thread = Thread::factory()->create([
            'user_id' => $owner->id,
            'workspace_id' => $workspaceId,
            'mailbox_id' => $mailbox->id,
            'campaign_recipient_id' => $recipient->id,
        ]);
        $message = Message::factory()->outbound()->create([
            'workspace_id' => $workspaceId,
            'mailbox_id' => $mailbox->id,
            'thread_id' => $thread->id,
            'campaign_recipient_id' => $recipient->id,
        ]);
        $attempt = SendAttempt::factory()->create([
            'user_id' => $owner->id,
            'workspace_id' => $workspaceId,
            'mailbox_id' => $mailbox->id,
            'message_id' => $message->id,
            'campaign_recipient_id' => $recipient->id,
            'kind' => SendAttemptKind::Campaign,
            'status' => $status,
        ]);
    }

    // Links and campaign inputs are established before the experiment is
    // frozen, matching the production preparation/freeze sequence.
    $experiment->forceFill([
        'status' => 'running',
        'frozen_at' => now(),
        'started_at' => now(),
    ])->save();

    return [
        'experiment' => $experiment,
        'campaigns' => $campaigns,
        'attempt' => $attempt,
    ];
}

it('rejects completion while any send attempt can still submit or is uncertain', function (SendAttemptStatus $status): void {
    $fixture = completionSafetyFixture($status);

    expect(fn () => app(MailExperimentLifecycleService::class)->complete($fixture['experiment']))
        ->toThrow(LogicException::class, 'unsettled send attempts');

    expect($fixture['experiment']->fresh()->status->value)->toBe('running')
        ->and($fixture['campaigns'][0]->fresh()->status)->toBe(CampaignStatus::Active)
        ->and($fixture['campaigns'][1]->fresh()->status)->toBe(CampaignStatus::Active)
        ->and($fixture['attempt']->fresh()->status)->toBe($status);
})->with([
    SendAttemptStatus::Scheduled,
    SendAttemptStatus::Queued,
    SendAttemptStatus::LeaseAcquired,
    SendAttemptStatus::Sending,
    SendAttemptStatus::Unknown,
    SendAttemptStatus::ManualReview,
]);

it('allows completion after deferred or pre-provider failed work is explicitly excluded', function (SendAttemptStatus $status): void {
    $fixture = completionSafetyFixture($status);

    $completed = app(MailExperimentLifecycleService::class)->complete($fixture['experiment']);

    expect($completed->status)->toBe(MailExperimentStatus::Completed)
        ->and($fixture['campaigns'][0]->fresh()->status)->toBe(CampaignStatus::Completed)
        ->and($fixture['campaigns'][1]->fresh()->status)->toBe(CampaignStatus::Completed);
})->with([
    SendAttemptStatus::Deferred,
    SendAttemptStatus::Failed,
]);
