<?php

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Services\GovernedCampaignInputGuard;
use App\Domain\Conversions\Services\CorrectConversionEvent;
use App\Domain\Conversions\Services\IngestConversionEvent;
use App\Domain\Conversions\Services\ReverseConversionEvent;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\MessageStatus;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Enums\ThreadClassification;
use App\Domain\Mail\Services\ApplyInboundCampaignEffects;
use App\Domain\Objectives\Enums\ExecutionActionEventType;
use App\Domain\Objectives\Exceptions\AttributionConflict;
use App\Domain\Objectives\Services\AssessObjectiveAttribution;
use App\Domain\Objectives\Services\ObjectiveOutcomeSummary;
use App\Domain\Objectives\Services\OverrideObjectiveAttribution;
use App\Domain\Objectives\Services\RebuildObjectiveProgress;
use App\Domain\Objectives\Services\ReconcileObjectiveAttribution;
use App\Domain\Objectives\Services\ReconcileObjectiveConversations;
use App\Domain\Objectives\Services\ReconcileObjectiveExecution;
use App\Domain\Objectives\Services\RecordObjectiveExposure;
use App\Models\AttributionAssessment;
use App\Models\ConversionDefinitionVersion;
use App\Models\ExecutionActionEvent;
use App\Models\Exposure;
use App\Models\GtmConversation;
use App\Models\Message;
use App\Models\SendAttempt;
use App\Models\Thread;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

require_once __DIR__.'/../../Support/ObjectiveScenarioFixtures.php';

/**
 * @return array<string, mixed>
 */
function objectiveOutcomeSentFixture(): array
{
    $fixture = compiledS3GuardFixture();
    $owner = $fixture['owner'];
    $workspace = $owner->currentWorkspace();
    $recipient = $fixture['recipient'];
    $thread = Thread::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'mailbox_id' => $fixture['mailbox']->id,
        'campaign_recipient_id' => $recipient->id,
        'participant_email' => $recipient->email_address,
    ]);
    $message = Message::factory()->outbound()->create([
        'workspace_id' => $workspace->id,
        'thread_id' => $thread->id,
        'mailbox_id' => $fixture['mailbox']->id,
        'campaign_recipient_id' => $recipient->id,
        'sequence_step_id' => $fixture['step']->id,
        'status' => MessageStatus::ProviderAccepted,
        'from_email' => $fixture['mailbox']->email_address,
        'to_recipients' => [['email' => $recipient->email_address]],
        'sent_at' => null,
    ]);
    $attempt = SendAttempt::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'mailbox_id' => $fixture['mailbox']->id,
        'message_id' => $message->id,
        'campaign_recipient_id' => $recipient->id,
        'sequence_step_id' => $fixture['step']->id,
        'status' => SendAttemptStatus::ProviderAccepted,
        'provider_accepted_at' => now(),
    ]);
    $exposure = app(RecordObjectiveExposure::class)->handle((int) $workspace->id, (int) $attempt->id);

    expect($exposure)->toBeInstanceOf(Exposure::class);

    return [
        ...$fixture,
        'workspace' => $workspace,
        'thread' => $thread,
        'message' => $message,
        'attempt' => $attempt,
        'exposure' => $exposure,
    ];
}

it('repairs exact send and reply projections idempotently without crossing tenants', function (): void {
    $fixture = objectiveOutcomeSentFixture();
    $workspace = $fixture['workspace'];
    $attempt = $fixture['attempt'];
    $message = $fixture['message'];
    $recipient = $fixture['recipient'];

    expect(app(RecordObjectiveExposure::class)->handle(999999, (int) $attempt->id))->toBeNull()
        ->and(Exposure::query()->count())->toBe(1);

    $attempt->forceFill(['status' => SendAttemptStatus::Sent, 'sent_at' => now()])->save();
    $message->forceFill(['status' => MessageStatus::Sent, 'sent_at' => now()])->save();

    expect(app(ReconcileObjectiveExecution::class)->handle((int) $workspace->id))->toBe(1)
        ->and(app(ReconcileObjectiveExecution::class)->handle((int) $workspace->id))->toBe(0)
        ->and(Exposure::query()->count())->toBe(1)
        ->and(ExecutionActionEvent::query()
            ->where('event', ExecutionActionEventType::ConfirmedSent->value)
            ->where('send_attempt_id', $attempt->id)
            ->count())->toBe(1);

    app(GovernedCampaignInputGuard::class)->runRecipientExecutionState(
        $recipient->fresh(),
        function () use ($recipient): void {
            $recipient = $recipient->fresh();
            $recipient->forceFill([
                'status' => RecipientStatus::Completed,
                'next_sequence_step_id' => null,
                'next_send_at' => null,
                'first_sent_at' => now(),
                'last_sent_at' => now(),
            ])->save();
        },
    );
    $reply = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'thread_id' => $fixture['thread']->id,
        'mailbox_id' => $fixture['mailbox']->id,
        'campaign_recipient_id' => $recipient->id,
        'direction' => MessageDirection::Inbound,
        'classification' => ThreadClassification::Unknown,
        'status' => MessageStatus::Received,
        'from_email' => $recipient->email_address,
        'received_at' => now(),
    ]);
    app(ApplyInboundCampaignEffects::class)->handle($reply);

    expect($recipient->fresh()->status)->toBe(RecipientStatus::Replied)
        ->and($recipient->fresh()->stop_reason)->toBe('human_reply')
        ->and(GtmConversation::query()->count())->toBe(0)
        ->and(app(ReconcileObjectiveConversations::class)->handle((int) $workspace->id))->toBe(1)
        ->and(app(ReconcileObjectiveConversations::class)->handle((int) $workspace->id))->toBe(0)
        ->and(GtmConversation::query()->count())->toBe(1);
});

it('keeps attribution append-only and derives progress and bounded safe lineage from the current conversion head', function (): void {
    $fixture = objectiveOutcomeSentFixture();
    $workspace = $fixture['workspace'];
    $attempt = $fixture['attempt'];
    $message = $fixture['message'];

    $attempt->forceFill(['status' => SendAttemptStatus::Sent, 'sent_at' => now()])->save();
    $message->forceFill(['status' => MessageStatus::Sent, 'sent_at' => now()])->save();
    app(ReconcileObjectiveExecution::class)->handle((int) $workspace->id);

    $result = app(IngestConversionEvent::class)->handle(
        $workspace,
        $fixture['plan']->conversion_definition_version_id,
        [
            'external_event_id' => 'objective-outcome-1',
            'occurred_at' => now()->toIso8601String(),
            'properties' => [],
            'identities' => [['type' => 'account_id', 'value' => $fixture['account']->id]],
        ],
        'manual',
        null,
        $fixture['owner'],
        'objective-outcome-1',
    );
    $event = $result->event;
    $this->travelTo(now()->addSecond()->startOfSecond());
    $initialAssessmentId = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
    Str::createUuidsUsingSequence([
        Uuid::fromString($initialAssessmentId),
        Uuid::fromString('ffffffff-ffff-4fff-8fff-fffffffffffe'),
    ]);
    try {
        $repaired = app(ReconcileObjectiveAttribution::class)->handle((int) $workspace->id);
    } finally {
        Str::createUuidsNormally();
    }
    expect($event)->not->toBeNull()
        ->and($repaired)->toBe(1)
        ->and(app(ReconcileObjectiveAttribution::class)->handle((int) $workspace->id))->toBe(0);

    $assessment = AttributionAssessment::query()->where('conversion_event_id', $event->id)->sole();
    expect($assessment->id)->toBe($initialAssessmentId)
        ->and($assessment->getRawOriginal('state'))->toBe('attributed')
        ->and($assessment->selected_exposure_id)->toBe($fixture['exposure']->id)
        ->and(app(RebuildObjectiveProgress::class)->handle((int) $workspace->id, (string) $fixture['plan']->objective_version_id)['attributed_count'])->toBe(1);

    $overrideAssessmentId = '00000000-0000-4000-8000-000000000001';
    Str::createUuidsUsingSequence([
        Uuid::fromString($overrideAssessmentId),
        Uuid::fromString('00000000-0000-4000-8000-000000000002'),
    ]);
    try {
        $override = app(OverrideObjectiveAttribution::class)->handle(
            $assessment,
            $fixture['owner'],
            null,
            'Contact jane@example.test requested manual review.',
            'objective-override-1',
        );
    } finally {
        Str::createUuidsNormally();
    }
    expect($override->id)->toBe($overrideAssessmentId);
    expect(app(OverrideObjectiveAttribution::class)->handle(
        $assessment,
        $fixture['owner'],
        null,
        'Contact jane@example.test requested manual review.',
        'objective-override-1',
    )->id)->toBe($override->id)
        ->and(fn () => app(OverrideObjectiveAttribution::class)->handle(
            $assessment,
            $fixture['owner'],
            (string) $fixture['exposure']->id,
            'Changed replay payload.',
            'objective-override-1',
        ))->toThrow(AttributionConflict::class)
        ->and(app(RebuildObjectiveProgress::class)->handle((int) $workspace->id, (string) $fixture['plan']->objective_version_id)['attributed_count'])->toBe(0);

    $corrected = app(CorrectConversionEvent::class)->handle(
        $event,
        $fixture['owner'],
        ['occurred_at' => now()->toIso8601String()],
        'Correct timestamp.',
        'objective-correction-1',
    );
    expect(app(ReconcileObjectiveAttribution::class)->handle((int) $workspace->id))->toBe(1);
    $correctedAssessment = AttributionAssessment::query()->where('conversion_event_id', $corrected->id)->sole();
    expect($correctedAssessment->prior_assessment_id)->toBe($override->id)
        ->and(app(RebuildObjectiveProgress::class)->handle((int) $workspace->id, (string) $fixture['plan']->objective_version_id)['attributed_count'])->toBe(1);

    $reversal = app(ReverseConversionEvent::class)->handle(
        $corrected,
        $fixture['owner'],
        'Customer cancelled.',
        'objective-reversal-1',
    );
    expect(app(ReconcileObjectiveAttribution::class)->handle((int) $workspace->id))->toBe(1)
        ->and(app(RebuildObjectiveProgress::class)->handle((int) $workspace->id, (string) $fixture['plan']->objective_version_id)['attributed_count'])->toBe(0);

    $summary = app(ObjectiveOutcomeSummary::class)->forPlan($fixture['plan']);
    $lineage = app(ObjectiveOutcomeSummary::class)->forPlanLineage($fixture['plan']);
    expect($summary['exposures'])->toMatchArray(['total' => 1, 'confirmed_sent' => 1])
        ->and($summary['conversions']['total'])->toBe(1)
        ->and($summary['attributions']['rejected'])->toBe(1)
        ->and($lineage['conversions'][0]['event_id'])->toBe($reversal->id)
        ->and($lineage['conversions'][0]['assessment_state'])->toBe('reversed')
        ->and($lineage['exposures'][0])->toHaveKeys([
            'id', 'action_id', 'send_attempt_id', 'message_id', 'thread_id', 'source_event_id', 'boundary', 'occurred_at',
        ])
        ->and(json_encode($lineage, JSON_THROW_ON_ERROR))->not->toContain('jane@example.test');
});

it('rejects unsigned explicit correlation without falling back to an otherwise eligible exposure', function (): void {
    $fixture = objectiveOutcomeSentFixture();
    $workspace = $fixture['workspace'];
    $version = ConversionDefinitionVersion::query()
        ->where('workspace_id', $workspace->id)
        ->whereKey($fixture['plan']->conversion_definition_version_id)
        ->firstOrFail();
    $schema = $version->property_schema;
    $schema['properties']['outboss_exposure_id'] = ['type' => 'string'];
    DB::table('conversion_definition_versions')->where('id', $version->id)->update([
        'property_schema' => json_encode($schema, JSON_THROW_ON_ERROR),
    ]);

    $result = app(IngestConversionEvent::class)->handle(
        $workspace,
        $version->id,
        [
            'external_event_id' => 'objective-explicit-unsigned',
            'occurred_at' => now()->toIso8601String(),
            'properties' => ['outboss_exposure_id' => $fixture['exposure']->id],
            'identities' => [['type' => 'account_id', 'value' => $fixture['account']->id]],
        ],
        'manual',
        null,
        $fixture['owner'],
        'objective-explicit-unsigned',
    );
    $assessments = app(AssessObjectiveAttribution::class)->handle(
        (int) $workspace->id,
        (string) $result->event?->id,
    );

    expect($assessments)->toHaveCount(1)
        ->and($assessments[0]->getRawOriginal('state'))->toBe('ineligible')
        ->and($assessments[0]->getRawOriginal('method'))->toBe('explicit_correlation')
        ->and($assessments[0]->selected_exposure_id)->toBeNull()
        ->and($assessments[0]->candidate_count)->toBe(1)
        ->and($assessments[0]->reason_codes)->toContain('explicit_correlation_not_signed');
});
