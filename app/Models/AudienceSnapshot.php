<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\ImmutableRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $workspace_id
 * @property string $segment_version_id
 * @property string|null $objective_id
 * @property string $status
 * @property string $purpose
 * @property string $criteria_hash
 * @property string $evaluation_hash
 * @property int $included_people_count
 * @property int $excluded_people_count
 * @property int $uncertain_people_count
 * @property int $suppressed_people_count
 * @property int $included_accounts_count
 * @property int $excluded_accounts_count
 * @property int $uncertain_accounts_count
 * @property int $suppressed_accounts_count
 * @property list<array<string, mixed>> $source_rights_snapshot
 * @property Carbon $frozen_at
 */
#[Fillable(['id', 'workspace_id', 'segment_version_id', 'objective_id', 'purpose', 'status', 'evaluator_version', 'criteria_hash', 'evaluation_hash', 'included_people_count', 'excluded_people_count', 'uncertain_people_count', 'suppressed_people_count', 'included_accounts_count', 'excluded_accounts_count', 'uncertain_accounts_count', 'suppressed_accounts_count', 'source_rights_snapshot', 'frozen_by_user_id', 'frozen_at', 'created_at'])]
class AudienceSnapshot extends Model
{
    use HasUuids;
    use ImmutableRecord;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'source_rights_snapshot' => 'array',
            'frozen_at' => 'datetime',
            'created_at' => 'datetime',
            'included_people_count' => 'integer',
            'excluded_people_count' => 'integer',
            'uncertain_people_count' => 'integer',
            'suppressed_people_count' => 'integer',
            'included_accounts_count' => 'integer',
            'excluded_accounts_count' => 'integer',
            'uncertain_accounts_count' => 'integer',
            'suppressed_accounts_count' => 'integer',
        ];
    }

    /** @return BelongsTo<SegmentVersion, $this> */
    public function segmentVersion(): BelongsTo
    {
        return $this->belongsTo(SegmentVersion::class);
    }

    /** @return BelongsTo<Objective, $this> */
    public function objective(): BelongsTo
    {
        return $this->belongsTo(Objective::class);
    }

    /** @return HasMany<AudienceSnapshotMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(AudienceSnapshotMember::class);
    }
}
