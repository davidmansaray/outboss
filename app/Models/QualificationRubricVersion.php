<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'id', 'workspace_id', 'rubric_id', 'version', 'schema_version', 'gtm_profile_version_id', 'offer_version_id',
    'dimensions', 'required_facts', 'disqualifiers', 'confidence_bands', 'sensitive_predicates',
    'decision_rules', 'content_hash', 'actor_snapshot', 'actor_user_id', 'created_at',
])]
class QualificationRubricVersion extends Model
{
    use HasUuids;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Qualification rubric versions are immutable.');
        });
        static::deleting(function (): never {
            throw new LogicException('Qualification rubric versions are immutable.');
        });
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'schema_version' => 'integer',
            'dimensions' => 'array',
            'required_facts' => 'array',
            'disqualifiers' => 'array',
            'confidence_bands' => 'array',
            'sensitive_predicates' => 'array',
            'decision_rules' => 'array',
            'actor_snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<QualificationRubric, $this> */
    public function rubric(): BelongsTo
    {
        return $this->belongsTo(QualificationRubric::class, 'rubric_id');
    }

    /** @return BelongsTo<GtmProfileVersion, $this> */
    public function gtmProfileVersion(): BelongsTo
    {
        return $this->belongsTo(GtmProfileVersion::class, 'gtm_profile_version_id');
    }

    /** @return BelongsTo<OfferVersion, $this> */
    public function offerVersion(): BelongsTo
    {
        return $this->belongsTo(OfferVersion::class, 'offer_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return HasMany<QualificationDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(QualificationDecision::class, 'rubric_version_id');
    }
}
