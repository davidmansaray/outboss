<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\GtmProfileResearchDispatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $gtm_profile_id
 * @property string $research_run_id
 * @property string $status
 * @property CarbonImmutable $available_at
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $lease_expires_at
 * @property string|null $lease_owner
 * @property int $publish_attempts
 */
#[Fillable([
    'gtm_profile_id',
    'research_run_id',
    'status',
    'available_at',
    'published_at',
    'lease_owner',
    'lease_expires_at',
    'publish_attempts',
    'last_error_code',
])]
class GtmProfileResearchDispatch extends Model
{
    /** @use HasFactory<GtmProfileResearchDispatchFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'available_at' => 'datetime',
            'published_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'publish_attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<GtmProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(GtmProfile::class, 'gtm_profile_id');
    }

    /** @return BelongsTo<GtmProfileResearchRun, $this> */
    public function researchRun(): BelongsTo
    {
        return $this->belongsTo(GtmProfileResearchRun::class, 'research_run_id');
    }
}
