<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\ImmutableRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'id', 'workspace_id', 'execution_plan_compilation_id', 'execution_plan_id',
    'gtm_experiment_arm_id', 'campaign_id', 'sequence_version_id', 'sequence_step_id',
])]
class ExecutionPlanCampaign extends Model
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

    /** @return BelongsTo<GtmExperimentArm, $this> */
    public function arm(): BelongsTo
    {
        return $this->belongsTo(GtmExperimentArm::class, 'gtm_experiment_arm_id');
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<SequenceVersion, $this> */
    public function sequenceVersion(): BelongsTo
    {
        return $this->belongsTo(SequenceVersion::class);
    }

    /** @return BelongsTo<SequenceStep, $this> */
    public function sequenceStep(): BelongsTo
    {
        return $this->belongsTo(SequenceStep::class);
    }

    /** @return HasMany<ExecutionPlanActionCompilation, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(ExecutionPlanActionCompilation::class);
    }
}
