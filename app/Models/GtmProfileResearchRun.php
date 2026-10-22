<?php

namespace App\Models;

use App\Domain\Gtm\Enums\GtmProfileAwaitingReason;
use App\Domain\Gtm\Enums\GtmResearchRunState;
use Database\Factories\GtmProfileResearchRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string $gtm_profile_id
 * @property GtmResearchRunState $state
 * @property GtmProfileAwaitingReason|null $awaiting_reason
 * @property string|null $budget_run_id
 * @property string|null $website_url_snapshot
 * @property int|null $initiated_by_user_id
 */
#[Fillable([
    'id',
    'gtm_profile_id',
    'state',
    'awaiting_reason',
    'budget_run_id',
    'parent_run_id',
    'lineage_type',
    'website_url_snapshot',
    'base_profile_version_id',
    'input_hash',
    'initiated_by_user_id',
    'safe_failure_code',
    'queued_at',
    'started_at',
    'finished_at',
    'failed_at',
])]
class GtmProfileResearchRun extends Model
{
    /** @use HasFactory<GtmProfileResearchRunFactory> */
    use HasFactory;

    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'state' => GtmResearchRunState::class,
            'awaiting_reason' => GtmProfileAwaitingReason::class,
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GtmProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(GtmProfile::class, 'gtm_profile_id');
    }

    /** @return BelongsTo<GtmProfileResearchRun, $this> */
    public function parentRun(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_run_id');
    }

    /** @return BelongsTo<AiBudgetRun, $this> */
    public function budgetRun(): BelongsTo
    {
        return $this->belongsTo(AiBudgetRun::class, 'budget_run_id');
    }

    /** @return BelongsTo<GtmProfileVersion, $this> */
    public function baseProfileVersion(): BelongsTo
    {
        return $this->belongsTo(GtmProfileVersion::class, 'base_profile_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    /** @return HasMany<GtmProfileResearchPhase, $this> */
    public function phases(): HasMany
    {
        return $this->hasMany(GtmProfileResearchPhase::class, 'research_run_id');
    }

    /** @return HasMany<GtmProfileResearchAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(GtmProfileResearchAttempt::class, 'research_run_id');
    }

    /** @return HasOne<GtmProfileResearchDispatch, $this> */
    public function dispatch(): HasOne
    {
        return $this->hasOne(GtmProfileResearchDispatch::class, 'research_run_id');
    }
}
