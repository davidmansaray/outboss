<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\ImmutableRecord;
use App\Domain\Objectives\Enums\ObjectiveTargetKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $name
 * @property ObjectiveTargetKind $target_kind
 * @property int|null $target_count
 * @property numeric-string|null $target_value
 * @property string|null $currency
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property list<string> $required_qualification_outcomes
 * @property array<string, mixed> $exclusions
 * @property array<string, mixed> $authority_envelope
 * @property array<string, mixed> $forecast_assumptions
 * @property array<string, mixed> $forecast_projection
 */
#[Fillable([
    'id', 'workspace_id', 'objective_id', 'version', 'name', 'description', 'conversion_definition_id',
    'conversion_definition_version_id', 'segment_version_id', 'audience_snapshot_id', 'offer_id',
    'offer_version_id', 'target_kind', 'target_count', 'target_value', 'currency', 'starts_at',
    'ends_at', 'attribution_window_days', 'maximum_people', 'maximum_accounts', 'maximum_messages',
    'maximum_ai_cost_micros', 'required_qualification_outcomes', 'minimum_qualification_confidence',
    'maximum_evidence_age_days', 'exclusions', 'authority_envelope', 'forecast_assumptions',
    'forecast_projection', 'schema_version', 'content_hash', 'created_by_user_id', 'created_at',
])]
class ObjectiveVersion extends Model
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
            'target_kind' => ObjectiveTargetKind::class,
            'target_count' => 'integer',
            'target_value' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'attribution_window_days' => 'integer',
            'maximum_people' => 'integer',
            'maximum_accounts' => 'integer',
            'maximum_messages' => 'integer',
            'maximum_ai_cost_micros' => 'integer',
            'required_qualification_outcomes' => 'array',
            'minimum_qualification_confidence' => 'decimal:4',
            'maximum_evidence_age_days' => 'integer',
            'exclusions' => 'array',
            'authority_envelope' => 'array',
            'forecast_assumptions' => 'array',
            'forecast_projection' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Objective, $this> */
    public function objective(): BelongsTo
    {
        return $this->belongsTo(Objective::class);
    }

    /** @return BelongsTo<AudienceSnapshot, $this> */
    public function audienceSnapshot(): BelongsTo
    {
        return $this->belongsTo(AudienceSnapshot::class);
    }

    /** @return BelongsTo<ConversionDefinitionVersion, $this> */
    public function conversionDefinitionVersion(): BelongsTo
    {
        return $this->belongsTo(ConversionDefinitionVersion::class);
    }

    /** @return BelongsTo<OfferVersion, $this> */
    public function offerVersion(): BelongsTo
    {
        return $this->belongsTo(OfferVersion::class);
    }

    /** @return HasMany<Exposure, $this> */
    public function exposures(): HasMany
    {
        return $this->hasMany(Exposure::class);
    }

    /** @return HasMany<AttributionAssessment, $this> */
    public function attributionAssessments(): HasMany
    {
        return $this->hasMany(AttributionAssessment::class);
    }

    /** @return HasOne<ObjectiveOutcomeProgress, $this> */
    public function outcomeProgress(): HasOne
    {
        return $this->hasOne(ObjectiveOutcomeProgress::class);
    }
}
