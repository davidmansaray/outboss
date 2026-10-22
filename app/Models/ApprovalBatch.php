<?php

namespace App\Models;

use App\Domain\Objectives\Enums\ApprovalBatchState;
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
 * @property string $execution_plan_id
 * @property string $plan_hash
 * @property ApprovalBatchState $state
 * @property int $action_count
 * @property string $display_manifest_hash
 * @property string $decision_input_hash
 * @property int|null $decision_by_user_id
 * @property Carbon|null $decided_at
 * @property Carbon|null $expires_at
 * @property string|null $reason_code
 * @property string|null $reason_detail
 * @property-read User|null $decisionBy
 * @property-read Collection<int, ApprovalGrant> $grants
 * @property-read Collection<int, ApprovalEvent> $events
 */
#[Fillable([
    'id', 'workspace_id', 'execution_plan_id', 'plan_hash', 'state', 'action_count',
    'display_manifest_hash', 'decision_input_hash', 'decision_by_user_id', 'decided_at',
    'expires_at', 'reason_code', 'reason_detail',
])]
class ApprovalBatch extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'state' => ApprovalBatchState::class,
            'action_count' => 'integer',
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ExecutionPlan, $this> */
    public function executionPlan(): BelongsTo
    {
        return $this->belongsTo(ExecutionPlan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decisionBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decision_by_user_id');
    }

    /** @return HasMany<ApprovalGrant, $this> */
    public function grants(): HasMany
    {
        return $this->hasMany(ApprovalGrant::class);
    }

    /** @return HasMany<ApprovalEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ApprovalEvent::class);
    }
}
