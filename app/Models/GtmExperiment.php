<?php

namespace App\Models;

use App\Domain\Objectives\Enums\GtmExperimentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property GtmExperimentStatus $status
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 */
#[Fillable([
    'id', 'workspace_id', 'objective_id', 'objective_version_id', 'audience_snapshot_id',
    'conversion_definition_id', 'conversion_definition_version_id', 'name', 'hypothesis', 'status',
    'assignment_unit', 'assignment_seed', 'assignment_seed_hash', 'assignment_algorithm_version',
    'allocation_version', 'guardrails', 'starts_at', 'ends_at', 'lock_version', 'created_by_user_id',
    'updated_by_user_id', 'assigned_at',
])]
class GtmExperiment extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'status' => GtmExperimentStatus::class,
            'assignment_seed' => 'encrypted',
            'allocation_version' => 'integer',
            'guardrails' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'lock_version' => 'integer',
            'assigned_at' => 'datetime',
        ];
    }

    /** @return HasMany<GtmExperimentArm, $this> */
    public function arms(): HasMany
    {
        return $this->hasMany(GtmExperimentArm::class);
    }

    /** @return HasMany<GtmExperimentAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(GtmExperimentAssignment::class);
    }
}
