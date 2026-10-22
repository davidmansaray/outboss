<?php

namespace App\Models;

use App\Domain\Mail\Enums\MailExperimentDomainStrategy;
use Database\Factories\MailExperimentCohortFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'mail_experiment_id',
    'key',
    'label',
    'domain_strategy',
    'configuration',
    'snapshot_hash',
    'mailbox_topology_snapshot',
    'campaign_id',
])]
class MailExperimentCohort extends Model
{
    /** @use HasFactory<MailExperimentCohortFactory> */
    use HasFactory;

    /** @var list<string> */
    public const IMMUTABLE_ATTRIBUTES = [
        'mail_experiment_id',
        'key',
        'label',
        'domain_strategy',
        'configuration',
        'snapshot_hash',
        'mailbox_topology_snapshot',
        'campaign_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $cohort): void {
            if (! $cohort->exists || ! $cohort->isDirty(self::IMMUTABLE_ATTRIBUTES)) {
                return;
            }

            $experiment = $cohort->relationLoaded('experiment')
                ? $cohort->experiment
                : $cohort->experiment()->first();

            if ($experiment?->isFrozen()) {
                throw new LogicException('A frozen mail experiment cannot change its cohorts.');
            }
        });

        static::deleting(function (self $cohort): void {
            $experiment = $cohort->relationLoaded('experiment')
                ? $cohort->experiment
                : $cohort->experiment()->first();

            if ($experiment?->isFrozen()) {
                throw new LogicException('A frozen mail experiment cannot delete its cohorts.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'domain_strategy' => MailExperimentDomainStrategy::class,
            'configuration' => 'array',
            'mailbox_topology_snapshot' => 'array',
        ];
    }

    /** @return BelongsTo<MailExperiment, $this> */
    public function experiment(): BelongsTo
    {
        return $this->belongsTo(MailExperiment::class, 'mail_experiment_id');
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsToMany<Mailbox, $this, MailExperimentCohortMailbox> */
    public function mailboxes(): BelongsToMany
    {
        return $this->belongsToMany(
            Mailbox::class,
            'mail_experiment_cohort_mailbox',
            'mail_experiment_cohort_id',
            'mailbox_id',
        )->using(MailExperimentCohortMailbox::class)
            ->withPivot(['mail_experiment_id', 'position'])
            ->orderByPivot('position');
    }

    /** @return HasMany<MailExperimentAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(MailExperimentAssignment::class);
    }

    public function isFrozen(): bool
    {
        return $this->experiment?->isFrozen() ?? false;
    }
}
