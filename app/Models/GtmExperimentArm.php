<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\ImmutableRecord;
use App\Domain\Objectives\Enums\GtmExperimentArmKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property GtmExperimentArmKey $key */
#[Fillable(['id', 'workspace_id', 'gtm_experiment_id', 'strategy_version_id', 'offer_version_id', 'key', 'label', 'allocation_basis_points', 'declared_differences', 'created_at'])]
class GtmExperimentArm extends Model
{
    use HasUuids;
    use ImmutableRecord;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'key' => GtmExperimentArmKey::class,
            'allocation_basis_points' => 'integer',
            'declared_differences' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GtmExperiment, $this> */
    public function experiment(): BelongsTo
    {
        return $this->belongsTo(GtmExperiment::class, 'gtm_experiment_id');
    }

    /** @return HasMany<GtmExperimentAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(GtmExperimentAssignment::class);
    }
}
