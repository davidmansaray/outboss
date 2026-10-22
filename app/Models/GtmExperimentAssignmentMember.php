<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\ImmutableRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id', 'workspace_id', 'gtm_experiment_assignment_id', 'audience_snapshot_member_id', 'person_id', 'member_hash', 'created_at'])]
class GtmExperimentAssignmentMember extends Model
{
    use HasUuids;
    use ImmutableRecord;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<GtmExperimentAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(GtmExperimentAssignment::class, 'gtm_experiment_assignment_id');
    }
}
