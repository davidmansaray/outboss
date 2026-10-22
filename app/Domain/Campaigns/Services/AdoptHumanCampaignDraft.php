<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\DTOs\GovernedCampaignAction;
use App\Domain\Campaigns\DTOs\GovernedCampaignResult;
use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Enums\SequenceVersionStatus;
use App\Domain\Mail\Services\FrozenMailExperimentInputGuard;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Models\Campaign;
use App\Models\CampaignComposition;
use App\Models\CampaignRecipient;
use App\Models\SequenceStep;
use App\Models\SequenceVersion;
use App\Models\SuppressionEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Compiler-only projection into an existing human draft. Never creates recipients. */
final class AdoptHumanCampaignDraft
{
    public function __construct(
        private readonly GovernedCampaignInputGuard $guard,
        private readonly RenderSequenceMessage $renderer,
        private readonly FrozenMailExperimentInputGuard $experiments,
    ) {}

    /** @param list<GovernedCampaignAction> $actions */
    public function handle(Campaign $campaign, CampaignComposition $composition, array $actions, GovernedCampaignCompilationCapability $capability): GovernedCampaignResult
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Human Campaign adoption requires the compiler transaction.');
        }
        $workspaceId = (int) $campaign->getAttribute('workspace_id');
        $this->guard->assertCompilerCapability($capability, $workspaceId);
        $locked = Campaign::query()->where('workspace_id', $workspaceId)->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
        $this->guard->assertCampaignMutable($locked);
        $this->experiments->assertCampaignMutable($locked);
        $current = CampaignComposition::query()->where('workspace_id', $workspaceId)->where('campaign_id', $locked->id)
            ->orderByDesc('revision')->first();
        if ($locked->getAttribute('status') !== CampaignStatus::Draft || $locked->launched_at !== null
            || $locked->published_sequence_version_id !== null || $current === null
            || $current->id !== $composition->id || $current->objective_goal_version_id === null || $current->offer_version_id === null) {
            $this->reject('The selected composition is not the current complete, unlaunched draft.');
        }
        $version = SequenceVersion::query()->where('campaign_id', $locked->id)
            ->whereKey($locked->draft_sequence_version_id)->lockForUpdate()->firstOrFail();
        $steps = $version->activeSteps()->lockForUpdate()->get();
        if ($version->getAttribute('status') !== SequenceVersionStatus::Draft || $steps->count() !== 1) {
            $this->reject('The first human execution slice requires exactly one draft email step.');
        }
        /** @var SequenceStep $step */
        $step = $steps->first();
        if ((bool) $step->subject_spintax_enabled || (bool) $step->body_spintax_enabled) {
            $this->reject('Turn off message variations before compiling exact human messages.');
        }
        $recipients = CampaignRecipient::query()->where('workspace_id', $workspaceId)->where('campaign_id', $locked->id)
            ->humanReviewAudience()->orderBy('id')->lockForUpdate()->get();
        if ($actions === [] || $recipients->count() !== count($actions)
            || count(array_unique(array_column($actions, 'actionId'))) !== count($actions)
            || count(array_unique(array_column($actions, 'personId'))) !== count($actions)) {
            $this->reject('The exact action set must match the entire campaign audience once.');
        }
        $mailboxes = $locked->mailboxes()->where('mailboxes.workspace_id', $workspaceId)->pluck('mailboxes.id')->all();
        $mapped = [];
        foreach ($actions as $action) {
            $recipient = $recipients->firstWhere('person_id', $action->personId);
            if (! $recipient instanceof CampaignRecipient || $recipient->getAttribute('status') !== RecipientStatus::Queued
                || $recipient->first_sent_at !== null || $recipient->send_lease_token !== null
                || (int) $recipient->sequence_version_id !== (int) $version->id
                || (int) $recipient->account_id !== $action->accountId || (int) $recipient->mailbox_id !== $action->mailboxId
                || ! in_array($action->mailboxId, $mailboxes, true)
                || $recipient->email_address !== $action->emailAddress || $recipient->email_normalized !== $action->emailNormalized
                || $recipient->full_name !== $action->fullName || (string) $recipient->first_name !== $action->firstName || (string) $recipient->company !== $action->company
                || $step->subject_template !== $action->subjectTemplate || $step->body_template !== $action->bodyTemplate
                || $recipient->governed_subject_snapshot !== null || $recipient->governed_body_text_snapshot !== null) {
                $this->reject('A recipient, sender, or message input differs from the approved action.');
            }
            if (SuppressionEntry::query()->where('workspace_id', $workspaceId)->where('email_normalized', $action->emailNormalized)->exists()) {
                $this->reject('An approved recipient is now suppressed.');
            }
            $rendered = $this->renderer->handle($step, $recipient);
            if ($rendered['subject'] !== $action->subject || $rendered['body'] !== $action->bodyText
                || ! hash_equals($action->contentHash, CanonicalObjectivePayload::hash([
                    'subject' => $rendered['subject'], 'body_text' => $rendered['body'], 'body_html' => null,
                ]))) {
                $this->reject('The rendered human message differs from the exact approved bytes.');
            }
            $mapped[$action->actionId] = $recipient;
        }
        // Validate every input before writing any snapshots. The caller must add
        // the common compilation mappings and seal this campaign before commit.
        $capability->register($locked);
        foreach ($actions as $action) {
            $recipient = $mapped[$action->actionId];
            $recipient->forceFill([
                'next_sequence_step_id' => $step->id,
                'governed_subject_snapshot' => $action->subject,
                'governed_body_text_snapshot' => $action->bodyText,
            ])->save();
            $this->renderer->verifyGovernedSnapshot($step, $recipient);
        }

        return new GovernedCampaignResult($locked, $version, $step, $mapped);
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['campaign' => $message]);
    }
}
