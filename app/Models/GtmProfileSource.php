<?php

namespace App\Models;

use Database\Factories\GtmProfileSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'id',
    'gtm_profile_id',
    'source_type',
    'canonical_url',
    'title',
    'observed_at',
    'published_at',
    'excerpt',
    'content_hash',
    'excerpt_hash',
    'provider_operation_id',
    'research_run_id',
])]
class GtmProfileSource extends Model
{
    /** @use HasFactory<GtmProfileSourceFactory> */
    use HasFactory;

    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GtmProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(GtmProfile::class, 'gtm_profile_id');
    }

    /** @return BelongsTo<AiProviderOperation, $this> */
    public function providerOperation(): BelongsTo
    {
        return $this->belongsTo(AiProviderOperation::class, 'provider_operation_id');
    }

    /** @return BelongsTo<GtmProfileResearchRun, $this> */
    public function researchRun(): BelongsTo
    {
        return $this->belongsTo(GtmProfileResearchRun::class, 'research_run_id');
    }
}
