<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\ImmutableRecord;
use App\Domain\Objectives\Enums\ExposureBoundary;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'id', 'workspace_id', 'execution_plan_action_id', 'gtm_experiment_assignment_id',
    'gtm_experiment_arm_id', 'strategy_version_id', 'offer_version_id', 'objective_version_id',
    'objective_run_id', 'message_id', 'send_attempt_id', 'campaign_recipient_id', 'thread_id',
    'boundary', 'occurred_at', 'recorded_at', 'source_event_id', 'exposure_fingerprint', 'created_at',
])]
final class Exposure extends Model
{
    use HasUuids;
    use ImmutableRecord;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'boundary' => ExposureBoundary::class,
            'occurred_at' => 'datetime',
            'recorded_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ExecutionPlanAction, $this> */
    public function action(): BelongsTo
    {
        return $this->belongsTo(ExecutionPlanAction::class, 'execution_plan_action_id');
    }

    /** @return BelongsTo<ObjectiveVersion, $this> */
    public function objectiveVersion(): BelongsTo
    {
        return $this->belongsTo(ObjectiveVersion::class);
    }

    /** @return BelongsTo<GtmExperimentAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(GtmExperimentAssignment::class, 'gtm_experiment_assignment_id');
    }

    /** @return BelongsTo<GtmExperimentArm, $this> */
    public function arm(): BelongsTo
    {
        return $this->belongsTo(GtmExperimentArm::class, 'gtm_experiment_arm_id');
    }

    /** @return BelongsTo<ObjectiveRun, $this> */
    public function objectiveRun(): BelongsTo
    {
        return $this->belongsTo(ObjectiveRun::class);
    }

    /** @return BelongsTo<CampaignRecipient, $this> */
    public function campaignRecipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class);
    }

    /** @return BelongsTo<ExecutionActionEvent, $this> */
    public function sourceEvent(): BelongsTo
    {
        return $this->belongsTo(ExecutionActionEvent::class, 'source_event_id');
    }

    /** @return BelongsTo<SendAttempt, $this> */
    public function sendAttempt(): BelongsTo
    {
        return $this->belongsTo(SendAttempt::class);
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** @return BelongsTo<Thread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    /** @return HasMany<GtmConversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(GtmConversation::class);
    }
}
