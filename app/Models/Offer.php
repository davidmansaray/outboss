<?php

namespace App\Models;

use App\Domain\Gtm\Enums\OfferReadinessState;
use App\Domain\Gtm\Enums\OfferStatus;
use Database\Factories\OfferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property OfferStatus $status
 * @property OfferReadinessState $availability_state
 */
#[Fillable([
    'id',
    'workspace_id',
    'current_version_id',
    'current_readiness_evaluation_id',
    'status',
    'availability_state',
    'active_name_normalized',
    'lock_version',
])]
class Offer extends Model
{
    /** @use HasFactory<OfferFactory> */
    use HasFactory;

    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'status' => OfferStatus::class,
            'availability_state' => OfferReadinessState::class,
            'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<OfferVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(OfferVersion::class, 'current_version_id');
    }

    /** @return HasMany<OfferVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(OfferVersion::class);
    }

    /** @return BelongsTo<OfferReadinessEvaluation, $this> */
    public function currentReadinessEvaluation(): BelongsTo
    {
        return $this->belongsTo(OfferReadinessEvaluation::class, 'current_readiness_evaluation_id');
    }

    /** @return HasMany<OfferReadinessEvaluation, $this> */
    public function readinessEvaluations(): HasMany
    {
        return $this->hasMany(OfferReadinessEvaluation::class);
    }

    /** @return HasMany<OfferVersionReviewEvent, $this> */
    public function versionReviewEvents(): HasMany
    {
        return $this->hasMany(OfferVersionReviewEvent::class);
    }

    /** @return HasMany<OfferClientOperation, $this> */
    public function clientOperations(): HasMany
    {
        return $this->hasMany(OfferClientOperation::class);
    }
}
