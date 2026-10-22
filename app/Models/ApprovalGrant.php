<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\ImmutableRecord;
use App\Domain\Objectives\Enums\ApprovalGrantDecision;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $workspace_id
 * @property string $approval_batch_id
 * @property string $execution_plan_id
 * @property string $execution_plan_action_id
 * @property string $policy_evaluation_id
 * @property string $plan_hash
 * @property string $action_fingerprint
 * @property string $outreach_policy_version_id
 * @property string $policy_input_hash
 * @property ApprovalGrantDecision $decision
 * @property int $decision_by_user_id
 * @property Carbon|null $approved_at
 * @property Carbon|null $rejected_at
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 * @property-read Collection<int, ApprovalEvent> $events
 */
#[Fillable([
    'id', 'workspace_id', 'approval_batch_id', 'execution_plan_id',
    'execution_plan_action_id', 'policy_evaluation_id', 'plan_hash',
    'action_fingerprint', 'outreach_policy_version_id', 'policy_input_hash',
    'decision', 'decision_by_user_id', 'approved_at', 'rejected_at', 'expires_at',
    'created_at',
])]
class ApprovalGrant extends Model
{
    use HasUuids;
    use ImmutableRecord;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'decision' => ApprovalGrantDecision::class,
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ApprovalBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ApprovalBatch::class, 'approval_batch_id');
    }

    /** @return BelongsTo<ExecutionPlanAction, $this> */
    public function action(): BelongsTo
    {
        return $this->belongsTo(ExecutionPlanAction::class, 'execution_plan_action_id');
    }

    /** @return BelongsTo<PolicyEvaluation, $this> */
    public function policyEvaluation(): BelongsTo
    {
        return $this->belongsTo(PolicyEvaluation::class);
    }

    /** @return HasMany<ApprovalEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ApprovalEvent::class);
    }
}
