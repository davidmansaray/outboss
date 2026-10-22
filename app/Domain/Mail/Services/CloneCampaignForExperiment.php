<?php

namespace App\Domain\Mail\Services;

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\SequenceVersionStatus;
use App\Domain\Campaigns\Services\GovernedCampaignInputGuard;
use App\Models\Campaign;
use App\Models\MailExperimentCohort;
use App\Models\SequenceStep;
use App\Models\SequenceVersion;
use Illuminate\Support\Facades\DB;
use LogicException;

class CloneCampaignForExperiment
{
    public function __construct(
        private CampaignSnapshotService $snapshots,
        private GovernedCampaignInputGuard $governedInputs,
    ) {}

    /**
     * Clone a template's sequence and scheduling inputs for one cohort. The
     * clone starts as a draft and receives no recipients; assignments are
     * enrolled separately after both cohort campaigns exist.
     *
     * @param  array<string, mixed>  $eligibility
     */
    public function handle(
        Campaign $template,
        MailExperimentCohort $cohort,
        ?string $name = null,
        array $eligibility = [],
    ): Campaign {
        $experiment = $cohort->experiment()->firstOrFail();
        $workspaceId = (int) $experiment->getAttribute('workspace_id');

        if ((int) $template->getAttribute('workspace_id') !== $workspaceId) {
            throw new LogicException('The template campaign must belong to the experiment workspace.');
        }

        $this->governedInputs->assertNotGoverned($template);

        if ($cohort->getAttribute('campaign_id') !== null) {
            $existing = $cohort->campaign()->first();
            if ($existing instanceof Campaign) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($template, $cohort, $name, $eligibility, $workspaceId): Campaign {
            $template->loadMissing(['draftVersion.activeSteps', 'publishedVersion.activeSteps', 'mailboxes']);
            $sourceVersion = $template->publishedVersion ?? $template->draftVersion;

            if (! $sourceVersion instanceof SequenceVersion) {
                throw new LogicException('The template campaign must have a sequence version.');
            }

            $campaign = $template->replicate([
                'id',
                'created_at',
                'updated_at',
                'draft_sequence_version_id',
                'published_sequence_version_id',
                'mail_experiment_cohort_id',
                'launched_at',
                'paused_at',
                'completed_at',
            ]);
            $campaign->forceFill([
                'workspace_id' => $workspaceId,
                'user_id' => $template->getAttribute('user_id'),
                'name' => $name ?: $template->getAttribute('name').' — '.$cohort->getAttribute('label'),
                'status' => CampaignStatus::Draft,
                'draft_sequence_version_id' => null,
                'published_sequence_version_id' => null,
                'launched_at' => null,
                'paused_at' => null,
                'completed_at' => null,
                'open_tracking_enabled' => false,
            ])->save();

            $version = SequenceVersion::query()->create([
                'campaign_id' => $campaign->id,
                'version' => 1,
                'status' => SequenceVersionStatus::Draft,
            ]);

            foreach ($sourceVersion->activeSteps as $sourceStep) {
                SequenceStep::query()->create([
                    'sequence_version_id' => $version->id,
                    'position' => $sourceStep->getAttribute('position'),
                    'title' => $sourceStep->getAttribute('title'),
                    'delay_minutes' => $sourceStep->getAttribute('delay_minutes'),
                    'send_as' => $sourceStep->getAttribute('send_as'),
                    'subject_template' => $sourceStep->getAttribute('subject_template'),
                    'body_template' => $sourceStep->getAttribute('body_template'),
                    'subject_spintax_enabled' => $sourceStep->getAttribute('subject_spintax_enabled'),
                    'body_spintax_enabled' => $sourceStep->getAttribute('body_spintax_enabled'),
                ]);
            }

            $campaign->forceFill(['draft_sequence_version_id' => $version->id])->save();

            $pivot = [];
            foreach ($cohort->mailboxes()->orderByPivot('position')->get() as $position => $mailbox) {
                $pivot[$mailbox->id] = ['position' => $position + 1];
            }

            if ($pivot !== []) {
                $campaign->mailboxes()->sync($pivot);
            }

            // Establish the reverse cohort link before setting the campaign's
            // direct foreign key. The frozen-input guard intentionally treats
            // an inconsistent link as unsafe, so cloning must not expose a
            // transient half-linked campaign to a later save.
            $snapshot = $this->snapshots->snapshot($campaign->fresh(['draftVersion.activeSteps', 'mailboxes']), $eligibility);
            $cohort->forceFill(['campaign_id' => $campaign->id, 'snapshot_hash' => $this->snapshots->hash($snapshot)])->save();
            $campaign->forceFill(['mail_experiment_cohort_id' => $cohort->id])->save();

            return $campaign->fresh(['draftVersion.activeSteps', 'mailboxes']);
        });
    }

    /** @param array<string, mixed> $eligibility */
    public function clone(Campaign $template, MailExperimentCohort $cohort, ?string $name = null, array $eligibility = []): Campaign
    {
        return $this->handle($template, $cohort, $name, $eligibility);
    }
}
