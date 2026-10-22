<?php

namespace App\Models;

use App\Domain\Campaigns\Enums\SequenceVersionStatus;
use App\Domain\Campaigns\Services\GovernedCampaignInputGuard;
use App\Domain\Mail\Services\FrozenMailExperimentInputGuard;
use Database\Factories\SequenceVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'campaign_id',
    'version',
    'status',
    'published_at',
])]
class SequenceVersion extends Model
{
    /** @use HasFactory<SequenceVersionFactory> */
    use HasFactory;

    /** @var list<string> */
    public const FROZEN_EXPERIMENT_INPUT_ATTRIBUTES = [
        'campaign_id',
        'version',
        'status',
        'published_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $version): void {
            app(GovernedCampaignInputGuard::class)->assertSequenceMutable($version);

            if (FrozenMailExperimentInputGuard::lifecycleMutationIsTrusted()) {
                return;
            }

            if (! $version->exists || $version->isDirty(self::FROZEN_EXPERIMENT_INPUT_ATTRIBUTES)) {
                app(FrozenMailExperimentInputGuard::class)->assertSequenceMutable($version);
            }
        });

        static::deleting(function (self $version): void {
            app(GovernedCampaignInputGuard::class)->assertSequenceMutable($version);

            if (! FrozenMailExperimentInputGuard::lifecycleMutationIsTrusted()) {
                app(FrozenMailExperimentInputGuard::class)->assertSequenceMutable($version);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => SequenceVersionStatus::class,
            'published_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return HasMany<SequenceStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(SequenceStep::class)->orderBy('position');
    }

    /** @return HasMany<SequenceStep, $this> */
    public function activeSteps(): HasMany
    {
        return $this->steps()->whereNull('deleted_at');
    }

    public function isImmutable(): bool
    {
        return $this->status !== SequenceVersionStatus::Draft;
    }
}
