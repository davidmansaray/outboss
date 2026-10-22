<?php

namespace App\Models;

use App\Domain\Objectives\Enums\ObjectiveRunPhaseName;
use App\Domain\Objectives\Enums\ObjectiveRunPhaseState;
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
 * @property int $attempt_number
 * @property ObjectiveRunPhaseState $state
 * @property string $input_hash
 * @property string|null $output_hash
 * @property string|null $safe_error_code
 * @property string|null $safe_error_detail
 * @property string|null $lease_owner
 * @property Carbon|null $lease_expires_at
 * @property int $fencing_token
 * @property Carbon|null $queued_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
#[Fillable([
    'id', 'workspace_id', 'objective_run_id', 'phase', 'attempt_number', 'state', 'input_hash',
    'output_hash', 'safe_error_code', 'safe_error_detail', 'lease_owner', 'lease_expires_at',
    'fencing_token', 'queued_at', 'started_at', 'finished_at',
])]
class ObjectiveRunPhase extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'phase' => ObjectiveRunPhaseName::class,
            'attempt_number' => 'integer',
            'state' => ObjectiveRunPhaseState::class,
            'fencing_token' => 'integer',
            'lease_expires_at' => 'datetime',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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
