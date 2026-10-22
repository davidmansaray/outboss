<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\ImmutableRecord;
use App\Domain\Objectives\Enums\ApprovalEventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $workspace_id
 * @property string $approval_batch_id
 * @property string $approval_grant_id
 * @property string $execution_plan_action_id
 * @property ApprovalEventType $event
 * @property string|null $prior_state
 * @property string $projected_state
 * @property string $reason_code
 * @property string $dependency_hash
 * @property string $event_input_hash
 * @property int|null $actor_user_id
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 */
#[Fillable([
    'id', 'workspace_id', 'approval_batch_id', 'approval_grant_id',
    'execution_plan_action_id', 'event', 'prior_state', 'projected_state',
    'reason_code', 'dependency_hash', 'event_input_hash', 'actor_user_id',
    'occurred_at', 'created_at',
])]
class ApprovalEvent extends Model
{
    use HasUuids;
    use ImmutableRecord;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'event' => ApprovalEventType::class,
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ApprovalBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ApprovalBatch::class, 'approval_batch_id');
    }

    /** @return BelongsTo<ApprovalGrant, $this> */
    public function grant(): BelongsTo
    {
        return $this->belongsTo(ApprovalGrant::class, 'approval_grant_id');
    }
}
