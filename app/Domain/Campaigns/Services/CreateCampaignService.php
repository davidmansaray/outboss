<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\SendMode;
use App\Domain\Campaigns\Enums\SequenceSendAs;
use App\Domain\Campaigns\Enums\SequenceVersionStatus;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Models\Campaign;
use App\Models\CampaignComposition;
use App\Models\SequenceStep;
use App\Models\SequenceVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateCampaignService
{
    public function __construct(private ListReadyWorkspaceMailboxes $readyMailboxes) {}

    public function handle(User $user, string $name): Campaign
    {
        $workspace = $user->currentWorkspace();

        return DB::transaction(function () use ($user, $name, $workspace) {
            $campaign = Campaign::query()->create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'name' => $name,
                'status' => CampaignStatus::Draft,
                'send_mode' => SendMode::Rotate,
                'timezone' => $user->timezone ?: 'UTC',
            ]);

            $version = SequenceVersion::query()->create([
                'campaign_id' => $campaign->id,
                'version' => 1,
                'status' => SequenceVersionStatus::Draft,
            ]);

            SequenceStep::query()->create([
                'sequence_version_id' => $version->id,
                'position' => 1,
                'title' => 'Intro',
                'delay_minutes' => 0,
                'send_as' => SequenceSendAs::NewThread,
                'subject_template' => '',
                'body_template' => '',
            ]);

            $campaign->forceFill(['draft_sequence_version_id' => $version->id])->save();

            // All new manual/agent-tool drafts use exact approval. Revision zero
            // carries no business authority and leaves the first selection at one.
            CampaignComposition::query()->create([
                'workspace_id' => $workspace->id, 'campaign_id' => $campaign->id,
                'revision' => 0, 'objective_goal_version_id' => null, 'offer_version_id' => null,
                'operation_id' => (string) Str::uuid(),
                'request_hash' => CanonicalObjectivePayload::hash(['origin' => 'campaign_creation']),
                'created_by_user_id' => $user->id,
            ]);

            $readyIds = $this->readyMailboxes->idsForWorkspace((int) $workspace->id);
            if ($readyIds !== []) {
                $campaign->mailboxes()->sync(
                    collect($readyIds)
                        ->mapWithKeys(fn (int $id, int $index): array => [$id => ['position' => $index + 1]])
                        ->all()
                );
            }

            return $campaign->fresh(['draftVersion.activeSteps', 'mailboxes']);
        });
    }
}
