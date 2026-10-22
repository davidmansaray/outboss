<?php

namespace App\Models;

use App\Domain\Mail\Enums\SendAttemptKind;
use App\Domain\Mail\Enums\SendAttemptStatus;
use Database\Factories\SendAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'workspace_id',
    'mailbox_id',
    'message_id',
    'campaign_recipient_id',
    'sequence_step_id',
    'kind',
    'idempotency_key',
    'client_operation_id',
    'status',
    'provider_phase',
    'provider_draft_id',
    'provider_request_id',
    'provider_marker',
    'due_at',
    'claimed_at',
    'lease_acquired_at',
    'provider_attempted_at',
    'provider_accepted_at',
    'sent_at',
    'attempt_count',
    'provider_unknown_count',
    'provider_manual_review_count',
    'provider_error_count',
    'manual_review_cycle',
    'deferred_reason',
    'error_code',
    'safe_error_message',
])]
class SendAttempt extends Model
{
    /** @use HasFactory<SendAttemptFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'kind' => SendAttemptKind::class,
            'status' => SendAttemptStatus::class,
            'due_at' => 'datetime',
            'claimed_at' => 'datetime',
            'lease_acquired_at' => 'datetime',
            'provider_attempted_at' => 'datetime',
            'provider_accepted_at' => 'datetime',
            'sent_at' => 'datetime',
            'provider_unknown_count' => 'integer',
            'provider_manual_review_count' => 'integer',
            'provider_error_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class, 'campaign_recipient_id');
    }

    public function sequenceStep(): BelongsTo
    {
        return $this->belongsTo(SequenceStep::class);
    }

    public function resolutions(): HasMany
    {
        return $this->hasMany(SendAttemptResolution::class);
    }
}
