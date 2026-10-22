<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'id', 'workspace_id', 'account_id', 'qualification_decision_id', 'successor_id', 'why_account', 'why_offer',
    'why_now', 'factual_basis', 'internal_rationale', 'confidence', 'fresh_until', 'expires_at',
    'safe_for_message', 'sensitive_inference', 'prohibited_inference', 'content_hash',
])]
class ReasonToContactVersion extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Reasons to contact are immutable; append a successor version.');
        });
        static::deleting(function (): never {
            throw new LogicException('Reasons to contact are immutable.');
        });
    }

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:4',
            'safe_for_message' => 'boolean',
            'sensitive_inference' => 'boolean',
            'prohibited_inference' => 'boolean',
            'fresh_until' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<QualificationDecision, $this> */
    public function qualificationDecision(): BelongsTo
    {
        return $this->belongsTo(QualificationDecision::class, 'qualification_decision_id');
    }

    /** @return BelongsTo<ReasonToContactVersion, $this> */
    public function successor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'successor_id');
    }

    /** @return HasMany<ReasonToContactClaim, $this> */
    public function claimLinks(): HasMany
    {
        return $this->hasMany(ReasonToContactClaim::class, 'reason_to_contact_version_id');
    }
}
