<?php

namespace App\Models;

use App\Domain\Intelligence\Enums\ReasonToContactClaimRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['id', 'workspace_id', 'reason_to_contact_version_id', 'claim_id', 'role'])]
class ReasonToContactClaim extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Reason-to-contact claim links are immutable.');
        });
        static::deleting(function (): never {
            throw new LogicException('Reason-to-contact claim links are immutable.');
        });
    }

    protected function casts(): array
    {
        return ['role' => ReasonToContactClaimRole::class];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<ReasonToContactVersion, $this> */
    public function reasonToContact(): BelongsTo
    {
        return $this->belongsTo(ReasonToContactVersion::class, 'reason_to_contact_version_id');
    }

    /** @return BelongsTo<ResearchClaim, $this> */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(ResearchClaim::class, 'claim_id');
    }
}
