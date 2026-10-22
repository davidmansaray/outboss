<?php

namespace App\Models;

use App\Domain\Conversions\Enums\ConversionEventState;
use App\Domain\Conversions\Enums\ConversionSourceType;
use App\Domain\Conversions\Enums\ConversionVerificationState;
use Database\Factories\ConversionEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * An immutable source observation with a small mutable projection for current
 * matching state. Corrections/reversals always create a successor event.
 */
#[Fillable([
    'id',
    'workspace_id',
    'conversion_definition_id',
    'conversion_definition_version_id',
    'receipt_id',
    'source_namespace',
    'source_type',
    'external_event_id',
    'properties',
    'identity_handles',
    'occurred_at',
    'received_at',
    'value',
    'currency',
    'verification_state',
    'state',
    'matched_account_id',
    'lineage_root_id',
    'corrects_event_id',
    'reverses_event_id',
    'safe_rejection_code',
    'created_at',
    'updated_at',
])]
class ConversionEvent extends Model
{
    /** @use HasFactory<ConversionEventFactory> */
    use HasFactory;

    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected static bool $projectionWrite = false;

    protected static function booted(): void
    {
        static::updating(static function (): void {
            if (! self::$projectionWrite) {
                throw new LogicException('Conversion events are immutable; append a correction or reversal.');
            }
        });
        static::deleting(static function (): void {
            throw new LogicException('Conversion events are append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'source_type' => ConversionSourceType::class,
            'properties' => 'array',
            'identity_handles' => 'array',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'value' => 'decimal:6',
            'verification_state' => ConversionVerificationState::class,
            'state' => ConversionEventState::class,
        ];
    }

    /** @return BelongsTo<Workspace,$this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<ConversionDefinition,$this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(ConversionDefinition::class, 'conversion_definition_id');
    }

    /** @return BelongsTo<ConversionDefinitionVersion,$this> */
    public function definitionVersion(): BelongsTo
    {
        return $this->belongsTo(ConversionDefinitionVersion::class, 'conversion_definition_version_id');
    }

    /** @return BelongsTo<ConversionEventReceipt,$this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(ConversionEventReceipt::class, 'receipt_id');
    }

    /** @return BelongsTo<Account,$this> */
    public function matchedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'matched_account_id');
    }

    /** @return BelongsTo<ConversionEvent,$this> */
    public function lineageRoot(): BelongsTo
    {
        return $this->belongsTo(self::class, 'lineage_root_id');
    }

    /** @return BelongsTo<ConversionEvent,$this> */
    public function correctsEvent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_event_id');
    }

    /** @return BelongsTo<ConversionEvent,$this> */
    public function reversesEvent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_event_id');
    }

    /** @return HasMany<ConversionEventMatch,$this> */
    public function matches(): HasMany
    {
        return $this->hasMany(ConversionEventMatch::class);
    }

    /** @return HasMany<ConversionEventReviewEvent,$this> */
    public function reviewEvents(): HasMany
    {
        return $this->hasMany(ConversionEventReviewEvent::class);
    }

    /** @return HasMany<AttributionAssessment,$this> */
    public function attributionAssessments(): HasMany
    {
        return $this->hasMany(AttributionAssessment::class)
            ->orderBy('assessed_at')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /** Explicitly separate observation/matching from causal attribution. */
    public function getAttributionStateAttribute(): string
    {
        $assessments = $this->relationLoaded('attributionAssessments')
            ? $this->attributionAssessments
            : $this->attributionAssessments()
                ->get();
        $supersededIds = $assessments
            ->pluck('prior_assessment_id')
            ->filter()
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
        $latest = $assessments
            ->reject(fn (AttributionAssessment $assessment): bool => in_array((string) $assessment->id, $supersededIds, true))
            ->last();

        $state = $latest?->getRawOriginal('state');

        return is_string($state) && $state !== '' ? $state : 'not_evaluated';
    }

    /** @param array<string,mixed> $attributes */
    public function project(array $attributes): bool
    {
        $allowed = array_intersect_key($attributes, array_flip([
            'state',
            'matched_account_id',
            'safe_rejection_code',
            'lineage_root_id',
            'corrects_event_id',
            'reverses_event_id',
        ]));

        self::$projectionWrite = true;
        try {
            return $this->forceFill($allowed)->saveQuietly();
        } finally {
            self::$projectionWrite = false;
        }
    }
}
