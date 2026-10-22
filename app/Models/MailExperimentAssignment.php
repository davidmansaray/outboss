<?php

namespace App\Models;

use App\Domain\Mail\Enums\RecipientMailboxProvider;
use Database\Factories\MailExperimentAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'mail_experiment_id',
    'mail_experiment_cohort_id',
    'workspace_id',
    'person_id',
    'account_id',
    'campaign_id',
    'campaign_recipient_id',
    'email_normalized',
    'recipient_domain',
    'recipient_provider',
    'stratum_key',
    'assignment_key',
    'provider_mx_snapshot',
    'assignment_snapshot_hash',
    'assigned_at',
    'exclusion_reason',
])]
class MailExperimentAssignment extends Model
{
    /** @use HasFactory<MailExperimentAssignmentFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $assignment): void {
            if ($assignment->exists && $assignment->isDirty()) {
                throw new LogicException('Mail experiment assignments are immutable evidence.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('Mail experiment assignments are immutable evidence.');
        });
    }

    protected function casts(): array
    {
        return [
            'recipient_provider' => RecipientMailboxProvider::class,
            'provider_mx_snapshot' => 'array',
            'assigned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MailExperiment, $this> */
    public function experiment(): BelongsTo
    {
        return $this->belongsTo(MailExperiment::class, 'mail_experiment_id');
    }

    /** @return BelongsTo<MailExperimentCohort, $this> */
    public function cohort(): BelongsTo
    {
        return $this->belongsTo(MailExperimentCohort::class, 'mail_experiment_cohort_id');
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<CampaignRecipient, $this> */
    public function campaignRecipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class);
    }

    public function isExcluded(): bool
    {
        return $this->getAttribute('exclusion_reason') !== null;
    }
}
