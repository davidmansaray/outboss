<?php

namespace App\Models;

use App\Domain\Objectives\Enums\ObjectiveTargetKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'id', 'workspace_id', 'objective_id', 'objective_version_id', 'target_kind',
    'target_count', 'target_value', 'currency', 'attributed_count', 'attributed_value',
    'completion_percent', 'input_hash', 'rebuilt_at',
])]
final class ObjectiveOutcomeProgress extends Model
{
    use HasUuids;

    protected $table = 'objective_outcome_progress';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'target_kind' => ObjectiveTargetKind::class,
            'target_count' => 'integer',
            'target_value' => 'decimal:6',
            'attributed_count' => 'integer',
            'attributed_value' => 'decimal:6',
            'completion_percent' => 'decimal:4',
            'rebuilt_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Objective, $this> */
    public function objective(): BelongsTo
    {
        return $this->belongsTo(Objective::class);
    }

    /** @return BelongsTo<ObjectiveVersion, $this> */
    public function objectiveVersion(): BelongsTo
    {
        return $this->belongsTo(ObjectiveVersion::class);
    }
}
