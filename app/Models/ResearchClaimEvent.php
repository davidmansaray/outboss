<?php

namespace App\Models;

use App\Domain\Intelligence\Enums\ResearchClaimEventAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'id', 'workspace_id', 'account_id', 'claim_id', 'action', 'actor_snapshot', 'actor_user_id', 'reason',
    'successor_claim_id', 'prior_status', 'projected_status', 'created_at',
])]
class ResearchClaimEvent extends Model
{
    use HasUuids;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Research claim events are append-only.');
        });
        static::deleting(function (): never {
            throw new LogicException('Research claim events are append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'action' => ResearchClaimEventAction::class,
            'actor_snapshot' => 'array',
            'created_at' => 'datetime',
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

    /** @return BelongsTo<ResearchClaim, $this> */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(ResearchClaim::class, 'claim_id');
    }

    /** @return BelongsTo<ResearchClaim, $this> */
    public function successorClaim(): BelongsTo
    {
        return $this->belongsTo(ResearchClaim::class, 'successor_claim_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
