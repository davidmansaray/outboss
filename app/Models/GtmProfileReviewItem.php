<?php

namespace App\Models;

use App\Domain\Gtm\Enums\GtmProfileReviewStatus;
use App\Domain\Gtm\Enums\GtmProfileReviewType;
use Database\Factories\GtmProfileReviewItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'id',
    'gtm_profile_id',
    'field_path',
    'type',
    'proposed_value',
    'source_ids',
    'proposed_value_hash',
    'status',
    'resolved_value',
    'resolved_version_id',
    'resolved_by_user_id',
    'resolved_at',
])]
class GtmProfileReviewItem extends Model
{
    /** @use HasFactory<GtmProfileReviewItemFactory> */
    use HasFactory;

    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'type' => GtmProfileReviewType::class,
            'status' => GtmProfileReviewStatus::class,
            'proposed_value' => 'array',
            'source_ids' => 'array',
            'resolved_value' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GtmProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(GtmProfile::class, 'gtm_profile_id');
    }

    /** @return BelongsTo<GtmProfileVersion, $this> */
    public function resolvedVersion(): BelongsTo
    {
        return $this->belongsTo(GtmProfileVersion::class, 'resolved_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
