<?php

namespace App\Models;

use App\Domain\Gtm\Enums\GtmProfileAwaitingReason;
use App\Domain\Gtm\Enums\GtmProfileResearchState;
use Database\Factories\GtmProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property int|null $workspace_id
 * @property string|null $current_version_id
 * @property string|null $current_research_run_id
 */
#[Fillable([
    'id',
    'workspace_id',
    'current_version_id',
    'research_state',
    'awaiting_reason',
    'current_research_run_id',
    'last_researched_at',
    'last_research_failed_at',
    'safe_error_code',
    'last_researched_website_url',
    'lock_version',
])]
class GtmProfile extends Model
{
    /** @use HasFactory<GtmProfileFactory> */
    use HasFactory;

    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'research_state' => GtmProfileResearchState::class,
            'awaiting_reason' => GtmProfileAwaitingReason::class,
            'last_researched_at' => 'datetime',
            'last_research_failed_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<GtmProfileVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(GtmProfileVersion::class, 'current_version_id');
    }

    /** @return HasMany<GtmProfileVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(GtmProfileVersion::class);
    }

    /** @return HasMany<GtmProfileSource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(GtmProfileSource::class);
    }

    /** @return HasMany<GtmProfileReviewItem, $this> */
    public function reviewItems(): HasMany
    {
        return $this->hasMany(GtmProfileReviewItem::class);
    }

    /** @return HasMany<GtmProfileResearchRun, $this> */
    public function researchRuns(): HasMany
    {
        return $this->hasMany(GtmProfileResearchRun::class);
    }

    /** @return BelongsTo<GtmProfileResearchRun, $this> */
    public function currentResearchRun(): BelongsTo
    {
        return $this->belongsTo(GtmProfileResearchRun::class, 'current_research_run_id');
    }

    /** @return HasMany<GtmProfileClientOperation, $this> */
    public function clientOperations(): HasMany
    {
        return $this->hasMany(GtmProfileClientOperation::class);
    }
}
