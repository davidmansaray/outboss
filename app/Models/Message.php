<?php

namespace App\Models;

use App\Domain\Mail\Enums\BounceSubtype;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\MessageStatus;
use App\Domain\Mail\Enums\ThreadClassification;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'local_key',
    'workspace_id',
    'thread_id',
    'mailbox_id',
    'campaign_recipient_id',
    'sequence_step_id',
    'reply_to_message_id',
    'remote_message_id',
    'client_message_marker',
    'internet_message_id',
    'tracking_token',
    'in_reply_to',
    'references',
    'direction',
    'classification',
    'bounce_subtype',
    'classification_confidence',
    'status',
    'from_name',
    'from_email',
    'to_recipients',
    'cc_recipients',
    'bcc_recipients',
    'subject',
    'body_html_sanitized',
    'body_text',
    'rendered_payload',
    'has_attachments',
    'provider_metadata',
    'sent_at',
    'received_at',
])]
#[Hidden(['rendered_payload'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'status' => MessageStatus::class,
            'classification' => ThreadClassification::class,
            'bounce_subtype' => BounceSubtype::class,
            'references' => 'array',
            'to_recipients' => 'array',
            'cc_recipients' => 'array',
            'bcc_recipients' => 'array',
            'provider_metadata' => 'array',
            'rendered_payload' => 'encrypted',
            'has_attachments' => 'boolean',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'classification_confidence' => 'decimal:2',
        ];
    }

    /**
     * @return list<array{email: string, name?: string|null}>
     */
    public function outboundRecipients(): array
    {
        $stored = $this->getAttribute('to_recipients');

        if (! is_array($stored)) {
            return [];
        }

        $recipients = [];

        foreach ($stored as $recipient) {
            if (! is_array($recipient) || ! is_string($recipient['email'] ?? null)) {
                continue;
            }

            $normalized = ['email' => $recipient['email']];

            if (array_key_exists('name', $recipient)) {
                $normalized['name'] = is_string($recipient['name']) || $recipient['name'] === null
                    ? $recipient['name']
                    : null;
            }

            $recipients[] = $normalized;
        }

        return $recipients;
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
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

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class, 'campaign_recipient_id');
    }

    public function sequenceStep(): BelongsTo
    {
        return $this->belongsTo(SequenceStep::class);
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_message_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MessageEvent::class);
    }

    public function sendAttempts(): HasMany
    {
        return $this->hasMany(SendAttempt::class);
    }
}
