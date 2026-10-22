<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\ImmutableRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** @property Carbon $assigned_at */
#[Fillable(['id', 'workspace_id', 'gtm_experiment_id', 'gtm_experiment_arm_id', 'audience_snapshot_id', 'account_id', 'assignment_unit_key_hash', 'bucket', 'allocation_version', 'algorithm_version', 'eligibility_snapshot_hash', 'assigned_at', 'created_at'])]
class GtmExperimentAssignment extends Model
{
    use HasUuids;
    use ImmutableRecord;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'bucket' => 'integer',
            'allocation_version' => 'integer',
            'assigned_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GtmExperimentArm, $this> */
    public function arm(): BelongsTo
    {
        return $this->belongsTo(GtmExperimentArm::class, 'gtm_experiment_arm_id');
    }

    /** @return HasMany<GtmExperimentAssignmentMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(GtmExperimentAssignmentMember::class);
    }
}
