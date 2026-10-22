<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Enums\SequenceVersionStatus;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\SequenceStep;
use App\Models\SequenceVersion;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class PublishSequenceVersion
{
    public function __construct(private readonly GovernedCampaignInputGuard $governedInputs) {}

    public function cloneDraft(Campaign $campaign): SequenceVersion
    {
        $this->governedInputs->assertCampaignMutable($campaign);
        $source = $campaign->publishedVersion ?? $campaign->draftVersion;

        return DB::transaction(function () use ($campaign, $source) {
            $version = SequenceVersion::query()->create([
                'campaign_id' => $campaign->id,
                'version' => (int) $campaign->versions()->max('version') + 1,
                'status' => SequenceVersionStatus::Draft,
            ]);

            foreach ($source?->activeSteps ?? [] as $step) {
                SequenceStep::query()->create([
                    'sequence_version_id' => $version->id,
                    'position' => $step->position,
                    'title' => $step->title,
                    'delay_minutes' => $step->delay_minutes,
                    'send_as' => $step->send_as,
                    'subject_template' => $step->subject_template,
                    'body_template' => $step->body_template,
                    'subject_spintax_enabled' => $step->subject_spintax_enabled,
                    'body_spintax_enabled' => $step->body_spintax_enabled,
                ]);
            }

            $campaign->forceFill(['draft_sequence_version_id' => $version->id])->save();

            return $version;
        });
    }

    /**
     * Recipients who will move to a newly published version on resume.
     *
     * @return HasMany<CampaignRecipient, Campaign>
     */
    public function unstartedRecipients(Campaign $campaign): HasMany
    {
        return $campaign->recipients()
            ->whereNull('first_sent_at')
            ->whereNotIn('status', [
                RecipientStatus::Removed,
                RecipientStatus::Replied,
                RecipientStatus::Interested,
                RecipientStatus::NotNow,
                RecipientStatus::WrongPerson,
                RecipientStatus::Bounced,
                RecipientStatus::Unsubscribed,
            ])
            ->whereDoesntHave('sendAttempts', fn ($query) => $query->whereIn('status', [
                SendAttemptStatus::LeaseAcquired,
                SendAttemptStatus::Sending,
                SendAttemptStatus::Unknown,
                SendAttemptStatus::ManualReview,
                SendAttemptStatus::ProviderAccepted,
                SendAttemptStatus::Sent,
            ]));
    }

    public function migrateUnstarted(Campaign $campaign, SequenceVersion $version): void
    {
        $this->governedInputs->assertCampaignMutable($campaign);
        $first = $version->activeSteps()->orderBy('position')->first();

        $this->unstartedRecipients($campaign)->update([
            'sequence_version_id' => $version->id,
            'next_sequence_step_id' => $first?->id,
        ]);
    }
}
