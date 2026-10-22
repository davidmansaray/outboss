<?php

namespace App\Models;

use App\Domain\Intelligence\Enums\QualificationDecisionClaimRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['id', 'workspace_id', 'decision_id', 'claim_id', 'role'])]
class QualificationDecisionClaim extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Qualification decision claim links are immutable.');
        });
        static::deleting(function (): never {
            throw new LogicException('Qualification decision claim links are immutable.');
        });
    }

    protected function casts(): array
    {
        return ['role' => QualificationDecisionClaimRole::class];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<QualificationDecision, $this> */
    public function decision(): BelongsTo
    {
        return $this->belongsTo(QualificationDecision::class, 'decision_id');
    }

    /** @return BelongsTo<ResearchClaim, $this> */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(ResearchClaim::class, 'claim_id');
    }
}
