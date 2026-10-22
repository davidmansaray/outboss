<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\ImmutableRecord;
use App\Domain\Objectives\Enums\PolicyEvaluationResult;
use App\Domain\Objectives\Enums\PolicyEvaluationStage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $workspace_id
 * @property string $outreach_policy_version_id
 * @property string $execution_plan_id
 * @property string $execution_plan_action_id
 * @property PolicyEvaluationStage $stage
 * @property PolicyEvaluationResult $result
 * @property string $input_hash
 * @property string $dependency_hash
 * @property list<string> $reason_codes
 * @property list<array{code:string,message:string,action_id?:string}> $safe_details
 * @property string $evaluator_version
 * @property Carbon $evaluated_at
 * @property Carbon|null $valid_until
 * @property Carbon $created_at
 */
#[Fillable([
    'id', 'workspace_id', 'outreach_policy_version_id', 'execution_plan_id',
    'execution_plan_action_id', 'stage', 'result', 'input_hash', 'dependency_hash',
    'reason_codes', 'safe_details', 'evaluator_version', 'evaluated_at', 'valid_until',
    'created_at',
])]
class PolicyEvaluation extends Model
{
    use HasUuids;
    use ImmutableRecord;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'stage' => PolicyEvaluationStage::class,
            'result' => PolicyEvaluationResult::class,
            'reason_codes' => 'array',
            'safe_details' => 'array',
            'evaluated_at' => 'datetime',
            'valid_until' => 'datetime',
            'created_at' => 'datetime',
        ];
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

    /** @return BelongsTo<OutreachPolicyVersion, $this> */
    public function policyVersion(): BelongsTo
    {
        return $this->belongsTo(OutreachPolicyVersion::class, 'outreach_policy_version_id');
    }
}
