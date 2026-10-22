<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\ImmutableRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'id', 'workspace_id', 'execution_plan_compilation_id', 'execution_plan_id',
    'execution_plan_action_id', 'execution_plan_campaign_id', 'campaign_id', 'campaign_recipient_id',
    'sequence_step_id', 'compiled_input_hash', 'rendered_content_hash',
])]
class ExecutionPlanActionCompilation extends Model
{
    use HasUuids;
    use ImmutableRecord;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<ExecutionPlanCompilation, $this> */
    public function compilation(): BelongsTo
    {
        return $this->belongsTo(ExecutionPlanCompilation::class, 'execution_plan_compilation_id');
    }

    /** @return BelongsTo<ExecutionPlan, $this> */
    public function executionPlan(): BelongsTo
    {
        return $this->belongsTo(ExecutionPlan::class);
    }

    /** @return BelongsTo<ExecutionPlanAction, $this> */
    public function action(): BelongsTo
    {
        return $this->belongsTo(ExecutionPlanAction::class, 'execution_plan_action_id');
    }

    /** @return BelongsTo<ExecutionPlanCampaign, $this> */
    public function campaignMapping(): BelongsTo
    {
        return $this->belongsTo(ExecutionPlanCampaign::class, 'execution_plan_campaign_id');
    }

    /** @return BelongsTo<CampaignRecipient, $this> */
    public function campaignRecipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class);
    }

    /** @return BelongsTo<SequenceStep, $this> */
    public function sequenceStep(): BelongsTo
    {
        return $this->belongsTo(SequenceStep::class);
    }
}
