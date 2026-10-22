<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\ImmutableRecord;
use App\Domain\Objectives\Enums\ExecutionActionEventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $workspace_id
 * @property string $execution_plan_action_id
 * @property int|null $campaign_recipient_id
 * @property int|null $message_id
 * @property int|null $send_attempt_id
 * @property ExecutionActionEventType $event
 * @property string|null $provider_request_id
 * @property string|null $provider_generation_id
 * @property string|null $provider_message_id
 * @property string|null $attempt_fingerprint
 * @property string $source_event_fingerprint
 * @property string|null $safe_reason_code
 * @property Carbon $occurred_at
 * @property Carbon $recorded_at
 * @property Carbon $created_at
 */
#[Fillable([
    'id', 'workspace_id', 'execution_plan_action_id', 'campaign_recipient_id',
    'message_id', 'send_attempt_id', 'event', 'provider_request_id',
    'provider_generation_id', 'provider_message_id', 'attempt_fingerprint',
    'source_event_fingerprint', 'safe_reason_code', 'occurred_at', 'recorded_at',
    'created_at',
])]
class ExecutionActionEvent extends Model
{
    use HasUuids;
    use ImmutableRecord;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'event' => ExecutionActionEventType::class,
            'occurred_at' => 'datetime',
            'recorded_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ExecutionPlanAction, $this> */
    public function action(): BelongsTo
    {
        return $this->belongsTo(ExecutionPlanAction::class, 'execution_plan_action_id');
    }
}
