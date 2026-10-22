<?php

namespace App\Models;

use App\Domain\Gtm\Enums\GtmResearchPhaseStatus;
use Database\Factories\GtmProfileResearchPhaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $research_run_id
 * @property string $phase
 * @property GtmResearchPhaseStatus $status
 * @property array<string, mixed>|null $successful_checkpoint
 * @property string|null $checkpoint_hash
 */
#[Fillable([
    'research_run_id',
    'phase',
    'status',
    'successful_checkpoint',
    'checkpoint_hash',
    'started_at',
    'succeeded_at',
    'failed_at',
])]
class GtmProfileResearchPhase extends Model
{
    /** @use HasFactory<GtmProfileResearchPhaseFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => GtmResearchPhaseStatus::class,
            'successful_checkpoint' => 'array',
            'started_at' => 'datetime',
            'succeeded_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GtmProfileResearchRun, $this> */
    public function researchRun(): BelongsTo
    {
        return $this->belongsTo(GtmProfileResearchRun::class, 'research_run_id');
    }

    /** @return HasMany<GtmProfileResearchAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(GtmProfileResearchAttempt::class, 'phase_id');
    }
}
