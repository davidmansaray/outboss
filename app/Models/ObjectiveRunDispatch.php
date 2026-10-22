<?php

namespace App\Models;

use App\Domain\Objectives\Enums\ObjectiveRunDispatchState;
use App\Domain\Objectives\Enums\ObjectiveRunPhaseName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $workspace_id
 * @property string $objective_run_id
 * @property ObjectiveRunPhaseName $phase
 * @property string $input_hash
 * @property ObjectiveRunDispatchState $state
 * @property int $attempts
 * @property Carbon $available_at
 * @property Carbon|null $published_at
 * @property Carbon|null $consumed_at
 * @property string|null $safe_error_code
 * @property string|null $safe_error_detail
 */
#[Fillable([
    'id', 'workspace_id', 'objective_run_id', 'phase', 'input_hash', 'state', 'attempts',
    'available_at', 'published_at', 'consumed_at', 'safe_error_code', 'safe_error_detail',
])]
class ObjectiveRunDispatch extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'phase' => ObjectiveRunPhaseName::class,
            'state' => ObjectiveRunDispatchState::class,
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'published_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<ObjectiveRun, $this> */
    public function objectiveRun(): BelongsTo
    {
        return $this->belongsTo(ObjectiveRun::class);
    }
}
