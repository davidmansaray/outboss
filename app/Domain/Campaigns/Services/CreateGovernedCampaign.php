<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\DTOs\GovernedCampaignAction;
use App\Domain\Campaigns\DTOs\GovernedCampaignResult;
use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\SendMode;
use App\Domain\Campaigns\Enums\SequenceSendAs;
use App\Domain\Campaigns\Enums\SequenceVersionStatus;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Models\Campaign;
use App\Models\SequenceStep;
use App\Models\SequenceVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Build one exact draft Campaign graph for one commercial experiment arm. */
class CreateGovernedCampaign
{
    public function __construct(
        private readonly EnrollPeopleInCampaign $enrolment,
        private readonly RenderSequenceMessage $renderer,
        private readonly GovernedCampaignInputGuard $frozenInputs,
    ) {}

    /**
     * The caller owns the single outer transaction spanning every arm.
     *
     * @param  list<GovernedCampaignAction>  $actions
     */
    public function handle(
        User $actor,
        int $workspaceId,
        string $name,
        array $actions,
        GovernedCampaignCompilationCapability $capability,
    ): GovernedCampaignResult {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Governed Campaign creation requires the plan compiler transaction.');
        }
        $this->frozenInputs->assertCompilerCapability($capability, $workspaceId);
        if ($actions === []) {
            throw ValidationException::withMessages([
                'actions' => 'Every governed Campaign arm requires at least one exact action.',
            ]);
        }

        $actionIds = array_map(static fn (GovernedCampaignAction $action): string => $action->actionId, $actions);
        if (count(array_unique($actionIds)) !== count($actionIds)) {
            throw ValidationException::withMessages([
                'actions' => 'A governed Campaign cannot compile the same action twice.',
            ]);
        }

        $campaign = Campaign::query()->create([
            'user_id' => $actor->id,
            'workspace_id' => $workspaceId,
            'name' => mb_substr($name, 0, 255),
            'status' => CampaignStatus::Draft,
            'send_mode' => SendMode::Rotate,
            'timezone' => $actor->timezone ?: 'UTC',
            'open_tracking_enabled' => false,
        ]);
        $capability->register($campaign);
        $version = SequenceVersion::query()->create([
            'campaign_id' => $campaign->id,
            'version' => 1,
            'status' => SequenceVersionStatus::Draft,
        ]);
        $projection = $actions[0];
        $step = SequenceStep::query()->create([
            'sequence_version_id' => $version->id,
            'position' => 1,
            'title' => 'Approved message',
            'delay_minutes' => 0,
            'send_as' => SequenceSendAs::NewThread,
            'subject_template' => $projection->subjectTemplate,
            'body_template' => $projection->bodyTemplate,
            'subject_spintax_enabled' => false,
            'body_spintax_enabled' => false,
        ]);
        $campaign->forceFill(['draft_sequence_version_id' => $version->id])->save();

        $mailboxIds = collect($actions)->pluck('mailboxId')->map(intval(...))->unique()->sort()->values();
        $positions = [];
        foreach ($mailboxIds as $index => $mailboxId) {
            $positions[$mailboxId] = ['position' => $index + 1];
        }
        $campaign->mailboxes()->sync($positions);

        $recipients = $this->enrolment->handleGoverned($campaign, $version, $step, $actions, $capability);
        if (count($recipients) !== count($actions)) {
            throw ValidationException::withMessages([
                'actions' => 'The governed Campaign did not enrol every exact approved action.',
            ]);
        }
        foreach ($actions as $action) {
            $recipient = $recipients[$action->actionId] ?? null;
            if ($recipient === null) {
                throw ValidationException::withMessages([
                    'actions' => 'The governed Campaign recipient mapping is incomplete.',
                ]);
            }
            $rendered = $this->renderer->verifyGovernedSnapshot($step, $recipient);
            $contentHash = CanonicalObjectivePayload::hash([
                'subject' => $rendered['subject'],
                'body_text' => $rendered['body'],
                'body_html' => null,
            ]);
            if (! hash_equals($action->subject, $rendered['subject'])
                || ! hash_equals($action->bodyText, $rendered['body'])
                || ! hash_equals($action->contentHash, $contentHash)) {
                throw ValidationException::withMessages([
                    'actions' => 'Compiled message bytes differ from the exact approved action.',
                ]);
            }
        }

        return new GovernedCampaignResult(
            campaign: $campaign->fresh(),
            sequenceVersion: $version->fresh(),
            sequenceStep: $step->fresh(),
            recipientsByActionId: $recipients,
        );
    }
}
