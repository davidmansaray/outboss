<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\ImmutableRecord;
use App\Domain\Objectives\Enums\ExecutionPlanCompilationState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $workspace_id
 * @property string $execution_plan_id
 * @property string|null $succeeded_plan_id
 * @property int $attempt
 * @property ExecutionPlanCompilationState $state
 * @property string $input_hash
 * @property string|null $output_hash
 * @property int $created_by_user_id
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 */
#[Fillable([
    'id', 'workspace_id', 'execution_plan_id', 'succeeded_plan_id', 'attempt', 'state',
    'input_hash', 'output_hash', 'safe_error_code', 'safe_error_detail', 'created_by_user_id',
    'started_at', 'finished_at',
])]
class ExecutionPlanCompilation extends Model
{
    use HasUuids;
    use ImmutableRecord;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'state' => ExecutionPlanCompilationState::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<ExecutionPlan, $this> */
    public function executionPlan(): BelongsTo
    {
        return $this->belongsTo(ExecutionPlan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<ExecutionPlanCampaign, $this> */
    public function campaigns(): HasMany
    {
        return $this->hasMany(ExecutionPlanCampaign::class);
    }

    /** @return HasMany<ExecutionPlanActionCompilation, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(ExecutionPlanActionCompilation::class);
    }
}
