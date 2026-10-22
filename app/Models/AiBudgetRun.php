<?php

namespace App\Models;

use App\Domain\Ai\Enums\AiTask;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $workspace_id
 * @property AiTask $task
 * @property string $status
 * @property bool $orchestration_owned
 * @property int $held_micros
 * @property int $committed_micros
 * @property string|null $budget_envelope_id
 */
#[Fillable(['id', 'workspace_id', 'task', 'budget_envelope_id', 'initiated_by_user_id', 'limit_snapshot_micros', 'held_micros', 'committed_micros', 'status', 'orchestration_owned', 'opened_at', 'closed_at'])]
class AiBudgetRun extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'task' => AiTask::class,
            'budget_envelope_id' => 'string',
            'limit_snapshot_micros' => 'integer',
            'held_micros' => 'integer',
            'committed_micros' => 'integer',
            'orchestration_owned' => 'boolean',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function shouldAutoClose(): bool
    {
        return ! $this->orchestration_owned;
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<AiBudgetEnvelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(AiBudgetEnvelope::class, 'budget_envelope_id');
    }
}
