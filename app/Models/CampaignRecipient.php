<?php

namespace App\Models;

use App\Domain\Campaigns\Enums\MailboxAssignment;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Services\GovernedCampaignInputGuard;
use App\Domain\Mail\Services\FrozenMailExperimentInputGuard;
use Database\Factories\CampaignRecipientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'campaign_id',
    'workspace_id',
    'mailbox_id',
    'sequence_version_id',
    'account_id',
    'person_id',
    'email_address',
    'email_normalized',
    'email_domain',
    'unsubscribe_token',
    'full_name',
    'first_name',
    'company',
    'status',
    'next_sequence_step_id',
    'next_send_at',
    'first_sent_at',
    'last_sent_at',
    'first_opened_at',
    'replied_at',
    'stopped_at',
    'stop_reason',
    'mailbox_assignment',
    'paused_at',
    'blocked_at',
    'blocked_from_status',
    'remaining_delay_seconds',
    'send_lease_token',
    'send_lease_expires_at',
    'removed_at',
    'lock_version',
    'governed_subject_snapshot',
    'governed_body_text_snapshot',
])]
#[Hidden(['governed_subject_snapshot', 'governed_body_text_snapshot'])]
class CampaignRecipient extends Model
{
    /** @use HasFactory<CampaignRecipientFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $recipient): void {
            $governed = app(GovernedCampaignInputGuard::class);
            $dirty = array_keys($recipient->getDirty());
            if (! $governed->runtimeAllowsRecipient($recipient, $dirty)) {
                $governed->assertRecipientMutable($recipient);
            }

            // Existing recipient rows may continue through their normal
            // lifecycle after launch. Only creation or moving a row to a
            // different campaign is an audience mutation.
            if ($recipient->exists && ! $recipient->isDirty('campaign_id')) {
                return;
            }

            $campaignId = $recipient->getAttribute('campaign_id');
            if (is_numeric($campaignId)) {
                app(FrozenMailExperimentInputGuard::class)
                    ->assertCampaignAudienceMutable((int) $campaignId);
            }
        });

        static::deleting(function (self $recipient): void {
            app(GovernedCampaignInputGuard::class)->assertRecipientMutable($recipient);
        });
    }

    protected function casts(): array
    {
        return [
            'status' => RecipientStatus::class,
            'mailbox_assignment' => MailboxAssignment::class,
            'next_send_at' => 'datetime',
            'first_sent_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'first_opened_at' => 'datetime',
            'replied_at' => 'datetime',
            'stopped_at' => 'datetime',
            'paused_at' => 'datetime',
            'blocked_at' => 'datetime',
            'send_lease_expires_at' => 'datetime',
            'removed_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
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

    /** @return BelongsTo<SequenceVersion, $this> */
    public function sequenceVersion(): BelongsTo
    {
        return $this->belongsTo(SequenceVersion::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return BelongsTo<SequenceStep, $this> */
    public function nextStep(): BelongsTo
    {
        return $this->belongsTo(SequenceStep::class, 'next_sequence_step_id');
    }

    /** @return HasMany<Thread, $this> */
    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class);
    }

    /** @return HasMany<SendAttempt, $this> */
    public function sendAttempts(): HasMany
    {
        return $this->hasMany(SendAttempt::class);
    }

    /**
     * @param  Builder<CampaignRecipient>  $query
     * @return Builder<CampaignRecipient>
     */
    public function scopeQueued(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [
                RecipientStatus::Queued,
                RecipientStatus::Active,
                RecipientStatus::Paused,
                RecipientStatus::BlockedMailbox,
            ])
            ->whereNull('first_sent_at');
    }

    /**
     * Exclude only explicitly removed, never-attempted draft selections.
     * Keep ambiguous/sent history visible to the human preparation safety checks.
     *
     * @param  Builder<CampaignRecipient>  $query
     * @return Builder<CampaignRecipient>
     */
    public function scopeHumanReviewAudience(Builder $query): Builder
    {
        return $query->where(function (Builder $selected): void {
            $selected->where('status', '!=', RecipientStatus::Removed->value)
                ->orWhereNotNull('first_sent_at')->orWhereNotNull('send_lease_token')
                ->orWhereHas('sendAttempts');
        });
    }

    /**
     * @param  Builder<CampaignRecipient>  $query
     * @return Builder<CampaignRecipient>
     */
    public function scopeSent(Builder $query): Builder
    {
        return $query->whereNotNull('first_sent_at');
    }

    /**
     * @param  Builder<CampaignRecipient>  $query
     * @return Builder<CampaignRecipient>
     */
    public function scopeReplied(Builder $query): Builder
    {
        return $query->whereIn('status', [
            RecipientStatus::Replied,
            RecipientStatus::Interested,
            RecipientStatus::NotNow,
            RecipientStatus::WrongPerson,
        ]);
    }

    /**
     * @param  Builder<CampaignRecipient>  $query
     * @return Builder<CampaignRecipient>
     */
    public function scopeInterested(Builder $query): Builder
    {
        return $query->where('status', RecipientStatus::Interested);
    }

    /**
     * @param  Builder<CampaignRecipient>  $query
     * @return Builder<CampaignRecipient>
     */
    public function scopeBounced(Builder $query): Builder
    {
        return $query->where('status', RecipientStatus::Bounced);
    }

    /**
     * @param  Builder<CampaignRecipient>  $query
     * @return Builder<CampaignRecipient>
     */
    public function scopeUnsubscribed(Builder $query): Builder
    {
        return $query->where('status', RecipientStatus::Unsubscribed);
    }

    public function hasStartedSending(): bool
    {
        return $this->first_sent_at !== null;
    }

    public function displayStatus(): string
    {
        if ($this->status->isTerminal() || $this->status === RecipientStatus::Completed) {
            return $this->status->value;
        }

        if (in_array($this->status, [RecipientStatus::Paused, RecipientStatus::BlockedMailbox], true)) {
            return $this->status->value;
        }

        if ($this->first_opened_at !== null) {
            return 'opened';
        }

        return 'in_sequence';
    }
}
