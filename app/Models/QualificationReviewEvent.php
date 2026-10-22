<?php

namespace App\Models;

use App\Domain\Intelligence\Enums\QualificationReviewAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'id', 'workspace_id', 'account_id', 'decision_id', 'action', 'actor_snapshot', 'actor_user_id', 'reason',
    'prior_review_state', 'projected_review_state', 'successor_decision_id', 'successor_run_id', 'created_at',
])]
class QualificationReviewEvent extends Model
{
    use HasUuids;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Qualification review events are append-only.');
        });
        static::deleting(function (): never {
            throw new LogicException('Qualification review events are append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'action' => QualificationReviewAction::class,
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

    /** @return BelongsTo<QualificationDecision, $this> */
    public function decision(): BelongsTo
    {
        return $this->belongsTo(QualificationDecision::class, 'decision_id');
    }

    /** @return BelongsTo<QualificationDecision, $this> */
    public function successorDecision(): BelongsTo
    {
        return $this->belongsTo(QualificationDecision::class, 'successor_decision_id');
    }

    /** @return BelongsTo<AccountResearchRun, $this> */
    public function successorRun(): BelongsTo
    {
        return $this->belongsTo(AccountResearchRun::class, 'successor_run_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
