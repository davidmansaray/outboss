<?php

namespace App\Models;

use App\Domain\Mail\Enums\MessageEventType;
use Database\Factories\MessageEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'message_id',
    'campaign_recipient_id',
    'type',
    'event_bucket',
    'occurred_at',
    'ip_hash',
    'user_agent_hash',
    'metadata',
])]
class MessageEvent extends Model
{
    /** @use HasFactory<MessageEventFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'type' => MessageEventType::class,
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class, 'campaign_recipient_id');
    }
}
