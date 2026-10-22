<?php

namespace App\Models;

use App\Domain\Campaigns\Enums\SequenceSendAs;
use App\Domain\Campaigns\Services\GovernedCampaignInputGuard;
use App\Domain\Mail\Services\FrozenMailExperimentInputGuard;
use Database\Factories\SequenceStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'sequence_version_id',
    'position',
    'title',
    'delay_minutes',
    'send_as',
    'subject_template',
    'body_template',
    'subject_spintax_enabled',
    'body_spintax_enabled',
    'deleted_at',
])]
class SequenceStep extends Model
{
    /** @use HasFactory<SequenceStepFactory> */
    use HasFactory;

    /** @var list<string> */
    public const FROZEN_EXPERIMENT_INPUT_ATTRIBUTES = [
        'sequence_version_id',
        'position',
        'title',
        'delay_minutes',
        'send_as',
        'subject_template',
        'body_template',
        'subject_spintax_enabled',
        'body_spintax_enabled',
        'deleted_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $step): void {
            app(GovernedCampaignInputGuard::class)->assertSequenceMutable($step);

            if (FrozenMailExperimentInputGuard::lifecycleMutationIsTrusted()) {
                return;
            }

            if (! $step->exists || $step->isDirty(self::FROZEN_EXPERIMENT_INPUT_ATTRIBUTES)) {
                app(FrozenMailExperimentInputGuard::class)->assertSequenceMutable($step);
            }
        });

        static::deleting(function (self $step): void {
            app(GovernedCampaignInputGuard::class)->assertSequenceMutable($step);

            if (! FrozenMailExperimentInputGuard::lifecycleMutationIsTrusted()) {
                app(FrozenMailExperimentInputGuard::class)->assertSequenceMutable($step);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'send_as' => SequenceSendAs::class,
            'delay_minutes' => 'integer',
            'subject_spintax_enabled' => 'boolean',
            'body_spintax_enabled' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SequenceVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(SequenceVersion::class, 'sequence_version_id');
    }

    /** @return HasMany<SendAttempt, $this> */
    public function sendAttempts(): HasMany
    {
        return $this->hasMany(SendAttempt::class);
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }
}
