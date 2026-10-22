<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\ImmutableRecord;
use App\Domain\Objectives\Enums\AttributionAssessmentState;
use App\Domain\Objectives\Enums\AttributionMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'id', 'workspace_id', 'conversion_event_id', 'objective_id', 'objective_version_id',
    'conversion_definition_version_id', 'selected_exposure_id', 'prior_assessment_id', 'state',
    'method', 'window_started_at', 'window_ended_at', 'candidate_count', 'rules_version',
    'input_hash', 'candidate_hash', 'content_hash', 'reason_codes', 'actor_type', 'actor_user_id',
    'idempotency_fingerprint', 'assessed_at', 'created_at',
])]
final class AttributionAssessment extends Model
{
    use HasUuids;
    use ImmutableRecord;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'state' => AttributionAssessmentState::class,
            'method' => AttributionMethod::class,
            'window_started_at' => 'datetime',
            'window_ended_at' => 'datetime',
            'candidate_count' => 'integer',
            'reason_codes' => 'array',
            'assessed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ConversionEvent, $this> */
    public function conversionEvent(): BelongsTo
    {
        return $this->belongsTo(ConversionEvent::class);
    }

    /** @return BelongsTo<Objective, $this> */
    public function objective(): BelongsTo
    {
        return $this->belongsTo(Objective::class);
    }

    /** @return BelongsTo<ObjectiveVersion, $this> */
    public function objectiveVersion(): BelongsTo
    {
        return $this->belongsTo(ObjectiveVersion::class);
    }

    /** @return BelongsTo<ConversionDefinitionVersion, $this> */
    public function conversionDefinitionVersion(): BelongsTo
    {
        return $this->belongsTo(ConversionDefinitionVersion::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<Exposure, $this> */
    public function selectedExposure(): BelongsTo
    {
        return $this->belongsTo(Exposure::class, 'selected_exposure_id');
    }

    /** @return BelongsTo<AttributionAssessment, $this> */
    public function priorAssessment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'prior_assessment_id');
    }

    /** @return HasMany<AttributionAssessmentCandidate, $this> */
    public function candidates(): HasMany
    {
        return $this->hasMany(AttributionAssessmentCandidate::class);
    }
}
