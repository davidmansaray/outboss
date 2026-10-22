<?php

namespace App\Models;

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\SendMode;
use App\Domain\Campaigns\Services\GovernedCampaignInputGuard;
use App\Domain\Mail\Services\FrozenMailExperimentInputGuard;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'workspace_id',
    'name',
    'status',
    'send_mode',
    'timezone',
    'send_window_start',
    'send_window_end',
    'business_days_only',
    'draft_sequence_version_id',
    'published_sequence_version_id',
    'mail_experiment_cohort_id',
    'daily_send_limit',
    'automatic_sending_scope_id',
    'launched_at',
    'paused_at',
    'completed_at',
    'open_tracking_enabled',
])]
class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use HasFactory;

    /** @var list<string> */
    public const FROZEN_EXPERIMENT_INPUT_ATTRIBUTES = [
        'workspace_id',
        'user_id',
        'name',
        'send_mode',
        'timezone',
        'send_window_start',
        'send_window_end',
        'business_days_only',
        'draft_sequence_version_id',
        'published_sequence_version_id',
        'mail_experiment_cohort_id',
        'daily_send_limit',
        'automatic_sending_scope_id',
        'open_tracking_enabled',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $campaign): void {
            if ($campaign->exists && $campaign->getDirty() !== []) {
                $governed = app(GovernedCampaignInputGuard::class);
                $dirty = array_keys($campaign->getDirty());
                if (! $governed->runtimeAllowsCampaign($campaign, $dirty)) {
                    $governed->assertCampaignMutable($campaign);
                }
            }

            if (FrozenMailExperimentInputGuard::lifecycleMutationIsTrusted()) {
                return;
            }

            if (! $campaign->exists) {
                if ($campaign->getAttribute('mail_experiment_cohort_id') !== null) {
                    app(FrozenMailExperimentInputGuard::class)->assertCampaignMutable($campaign);
                }

                return;
            }

            if (! $campaign->isDirty(self::FROZEN_EXPERIMENT_INPUT_ATTRIBUTES)) {
                return;
            }

            app(FrozenMailExperimentInputGuard::class)->assertCampaignMutable($campaign);
        });

        static::deleting(function (self $campaign): void {
            app(GovernedCampaignInputGuard::class)->assertCampaignMutable($campaign);

            if (! FrozenMailExperimentInputGuard::lifecycleMutationIsTrusted()) {
                app(FrozenMailExperimentInputGuard::class)->assertCampaignMutable($campaign);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'send_mode' => SendMode::class,
            'business_days_only' => 'boolean',
            'daily_send_limit' => 'integer',
            'launched_at' => 'datetime',
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
            'open_tracking_enabled' => 'boolean',
        ];
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

    /** @return BelongsTo<SequenceVersion, $this> */
    public function draftVersion(): BelongsTo
    {
        return $this->belongsTo(SequenceVersion::class, 'draft_sequence_version_id');
    }

    /** @return BelongsTo<SequenceVersion, $this> */
    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(SequenceVersion::class, 'published_sequence_version_id');
    }

    /** @return HasMany<SequenceVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(SequenceVersion::class);
    }

    /** @return BelongsToMany<Mailbox, $this, CampaignMailbox> */
    public function mailboxes(): BelongsToMany
    {
        return $this->belongsToMany(Mailbox::class)
            ->using(CampaignMailbox::class)
            ->withPivot('position')
            ->orderByPivot('position');
    }

    /** @return HasMany<CampaignRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    /** @return HasMany<RecipientImport, $this> */
    public function imports(): HasMany
    {
        return $this->hasMany(RecipientImport::class);
    }

    /**
     * A cohort campaign is still a normal campaign. The relationship is kept
     * optional so the existing public campaign flow remains unchanged.
     */
    /** @return BelongsTo<MailExperimentCohort, $this> */
    public function mailExperimentCohort(): BelongsTo
    {
        return $this->belongsTo(MailExperimentCohort::class, 'mail_experiment_cohort_id');
    }

    /** @return BelongsTo<ExecutionAuthorityScope, $this> */
    public function automaticSendingScope(): BelongsTo
    {
        return $this->belongsTo(ExecutionAuthorityScope::class, 'automatic_sending_scope_id');
    }

    /** @return HasMany<ExecutionPlanCampaign, $this> */
    public function executionPlanMappings(): HasMany
    {
        return $this->hasMany(ExecutionPlanCampaign::class);
    }
}
