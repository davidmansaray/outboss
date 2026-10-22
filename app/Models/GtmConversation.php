<?php

namespace App\Models;

use App\Domain\Objectives\Enums\GtmConversationState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'id', 'workspace_id', 'thread_id', 'objective_id', 'objective_version_id', 'objective_run_id',
    'execution_plan_action_id', 'exposure_id', 'account_id', 'person_id', 'offer_version_id',
    'owner_user_id', 'fulfilment_request_id', 'state', 'latest_classification',
    'classification_confidence', 'next_action', 'next_action_due_at', 'lock_version',
])]
final class GtmConversation extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'state' => GtmConversationState::class,
            'classification_confidence' => 'decimal:4',
            'next_action_due_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Thread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
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

    /** @return BelongsTo<ExecutionPlanAction, $this> */
    public function action(): BelongsTo
    {
        return $this->belongsTo(ExecutionPlanAction::class, 'execution_plan_action_id');
    }

    /** @return BelongsTo<ObjectiveRun, $this> */
    public function objectiveRun(): BelongsTo
    {
        return $this->belongsTo(ObjectiveRun::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return BelongsTo<Exposure, $this> */
    public function exposure(): BelongsTo
    {
        return $this->belongsTo(Exposure::class);
    }

    /** @return HasMany<GtmConversationEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(GtmConversationEvent::class)->orderBy('occurred_at')->orderBy('id');
    }
}
