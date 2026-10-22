<?php

namespace App\Models;

use App\Domain\Objectives\Enums\ObjectiveStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property ObjectiveStatus $status */
#[Fillable([
    'id', 'workspace_id', 'current_version_id', 'current_plan_id', 'status', 'status_reason_code',
    'lock_version', 'created_by_user_id', 'last_actor_user_id', 'ready_at', 'activated_at',
    'paused_at', 'completed_at', 'expired_at', 'cancelled_at',
])]
class Objective extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'status' => ObjectiveStatus::class,
            'lock_version' => 'integer',
            'ready_at' => 'datetime',
            'activated_at' => 'datetime',
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
            'expired_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<ObjectiveVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ObjectiveVersion::class, 'current_version_id');
    }

    /** @return BelongsTo<ExecutionPlan, $this> */
    public function currentPlan(): BelongsTo
    {
        return $this->belongsTo(ExecutionPlan::class, 'current_plan_id')
            ->where('execution_plans.workspace_id', $this->workspace_id);
    }

    /** @return HasMany<ObjectiveVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ObjectiveVersion::class);
    }

    /** @return BelongsTo<ObjectiveGoalVersion, $this> */
    public function currentGoalVersion(): BelongsTo
    {
        return $this->belongsTo(ObjectiveGoalVersion::class, 'current_goal_version_id');
    }

    /** @return HasMany<ObjectiveStateEvent, $this> */
    public function stateEvents(): HasMany
    {
        return $this->hasMany(ObjectiveStateEvent::class);
    }

    /** @return HasMany<AudienceSnapshot, $this> */
    public function audienceSnapshots(): HasMany
    {
        return $this->hasMany(AudienceSnapshot::class);
    }

    /** @return HasMany<GtmExperiment, $this> */
    public function experiments(): HasMany
    {
        return $this->hasMany(GtmExperiment::class);
    }

    /** @return HasMany<ObjectiveRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(ObjectiveRun::class);
    }
}
