<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\ImmutableRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'id', 'workspace_id', 'attribution_assessment_id', 'exposure_id', 'eligible',
    'reason_codes', 'time_distance_seconds', 'explicit_correlation', 'created_at',
])]
final class AttributionAssessmentCandidate extends Model
{
    use HasUuids;
    use ImmutableRecord;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'eligible' => 'boolean',
            'reason_codes' => 'array',
            'time_distance_seconds' => 'integer',
            'explicit_correlation' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AttributionAssessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(AttributionAssessment::class, 'attribution_assessment_id');
    }

    /** @return BelongsTo<Exposure, $this> */
    public function exposure(): BelongsTo
    {
        return $this->belongsTo(Exposure::class);
    }
}
