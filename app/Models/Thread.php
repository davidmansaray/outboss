<?php

namespace App\Models;

use App\Domain\Mail\Enums\BounceSubtype;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\MessageStatus;
use App\Domain\Mail\Enums\ThreadClassification;
use App\Domain\Mail\Enums\ThreadLocalState;
use Database\Factories\ThreadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'user_id',
    'workspace_id',
    'mailbox_id',
    'campaign_recipient_id',
    'local_key',
    'remote_thread_id',
    'subject',
    'subject_normalized',
    'snippet',
    'participant_name',
    'participant_email',
    'last_message_at',
    'classification',
    'bounce_subtype',
    'classification_confidence',
    'local_state',
    'is_unread',
])]
class Thread extends Model
{
    /** @use HasFactory<ThreadFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'classification' => ThreadClassification::class,
            'bounce_subtype' => BounceSubtype::class,
            'local_state' => ThreadLocalState::class,
            'last_message_at' => 'datetime',
            'is_unread' => 'boolean',
            'classification_confidence' => 'decimal:2',
        ];
    }

    /**
     * @param  Builder<Thread>  $query
     * @return Builder<Thread>
     */
    public function scopeVisibleInInbox(Builder $query): Builder
    {
        return $query->whereHas('messages', fn (Builder $messages) => $messages
            ->where('direction', MessageDirection::Inbound)
            ->whereColumn('messages.workspace_id', 'threads.workspace_id')
            ->whereColumn('messages.mailbox_id', 'threads.mailbox_id'));
    }

    /**
     * @param  Builder<Thread>  $query
     * @return Builder<Thread>
     */
    public function scopeVisibleInSent(Builder $query): Builder
    {
        return $query->whereHas('messages', fn (Builder $messages) => $messages
            ->where('direction', MessageDirection::Outbound)
            ->where('status', MessageStatus::Sent)
            ->whereColumn('messages.workspace_id', 'threads.workspace_id')
            ->whereColumn('messages.mailbox_id', 'threads.mailbox_id'));
    }

    /**
     * Keep malformed denormalized relationships out of workspace read paths.
     *
     * @param  Builder<Thread>  $query
     * @return Builder<Thread>
     */
    public function scopeInWorkspace(Builder $query, int $workspaceId): Builder
    {
        return $query
            ->where('threads.workspace_id', $workspaceId)
            ->whereHas('mailbox', fn (Builder $mailbox) => $mailbox->where('workspace_id', $workspaceId))
            ->where(function (Builder $thread) use ($workspaceId): void {
                $thread
                    ->whereNull('campaign_recipient_id')
                    ->orWhereHas('recipient', fn (Builder $recipient) => $recipient
                        ->where('workspace_id', $workspaceId)
                        ->whereHas('campaign', fn (Builder $campaign) => $campaign
                            ->where('workspace_id', $workspaceId)));
            });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Mailbox, $this> */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    /** @return BelongsTo<CampaignRecipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class, 'campaign_recipient_id');
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)
            ->orderByRaw('COALESCE(received_at, sent_at)')
            ->orderBy('id');
    }

    /** @return HasOne<Message, $this> */
    public function latestSentMessage(): HasOne
    {
        return $this->hasOne(Message::class)
            ->ofMany(['id' => 'max'], fn (Builder $query) => $query
                ->where('direction', MessageDirection::Outbound)
                ->where('status', MessageStatus::Sent));
    }
}
