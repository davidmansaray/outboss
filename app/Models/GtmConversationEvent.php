<?php

namespace App\Models;

use App\Domain\Objectives\Concerns\ImmutableRecord;
use App\Domain\Objectives\Enums\GtmConversationEventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'id', 'workspace_id', 'gtm_conversation_id', 'thread_id', 'message_id', 'event',
    'classification', 'confidence', 'source', 'model_version', 'prompt_version', 'prior_event_id',
    'safe_rationale', 'actor_user_id', 'occurred_at', 'recorded_at', 'event_fingerprint', 'created_at',
])]
final class GtmConversationEvent extends Model
{
    use HasUuids;
    use ImmutableRecord;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'event' => GtmConversationEventType::class,
            'confidence' => 'decimal:4',
            'occurred_at' => 'datetime',
            'recorded_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GtmConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(GtmConversation::class, 'gtm_conversation_id');
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
