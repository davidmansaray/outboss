<?php

namespace App\Models;

use App\Domain\Objectives\Enums\SegmentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $workspace_id
 * @property string|null $current_version_id
 * @property SegmentStatus $status
 * @property int $lock_version
 * @property Carbon|null $created_at
 * @property SegmentVersion|null $currentVersion
 */
#[Fillable(['id', 'workspace_id', 'current_version_id', 'status', 'created_by_user_id', 'archived_by_user_id', 'archived_at', 'lock_version'])]
class Segment extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['status' => SegmentStatus::class, 'archived_at' => 'datetime', 'lock_version' => 'integer'];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<SegmentVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(SegmentVersion::class, 'current_version_id');
    }

    /** @return HasMany<SegmentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(SegmentVersion::class);
    }
}
