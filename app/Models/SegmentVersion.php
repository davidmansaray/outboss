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
 * @property string $segment_id
 * @property int $version
 * @property string $name
 * @property string|null $description
 * @property array<string, mixed> $criteria
 * @property list<string> $buyer_roles
 * @property list<string> $required_qualification_outcomes
 * @property string $criteria_schema_version
 * @property string $evaluator_version
 * @property string $content_hash
 * @property Carbon $created_at
 */
#[Fillable(['id', 'workspace_id', 'segment_id', 'version', 'name', 'description', 'criteria', 'criteria_schema_version', 'missing_data_policy', 'buyer_roles', 'required_qualification_outcomes', 'evaluator_version', 'content_hash', 'created_by_user_id', 'created_at'])]
class SegmentVersion extends Model
{
    use HasUuids;
    use ImmutableRecord;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'criteria' => 'array',
            'buyer_roles' => 'array',
            'required_qualification_outcomes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Segment, $this> */
    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return HasMany<AudienceSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(AudienceSnapshot::class);
    }
}
