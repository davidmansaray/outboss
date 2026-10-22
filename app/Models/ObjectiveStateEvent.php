<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\ImmutableRecord;
use App\Domain\Objectives\Enums\ObjectiveStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property ObjectiveStatus|null $from_state
 * @property ObjectiveStatus $to_state
 * @property array<string, mixed> $context
 * @property Carbon $created_at
 */
#[Fillable(['id', 'workspace_id', 'objective_id', 'objective_version_id', 'from_state', 'to_state', 'reason_code', 'context', 'actor_user_id', 'created_at'])]
class ObjectiveStateEvent extends Model
{
    use HasUuids;
    use ImmutableRecord;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'from_state' => ObjectiveStatus::class,
            'to_state' => ObjectiveStatus::class,
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Objective, $this> */
    public function objective(): BelongsTo
    {
        return $this->belongsTo(Objective::class);
    }
}
