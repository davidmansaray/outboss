<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Mail\Enums\SendAttemptKind;
use App\Domain\Mail\Services\ComposeMessageService;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\SequenceStep;
use App\Models\SequenceVersion;
use App\Models\User;
use RuntimeException;

class TestSequenceStep
{
    public function __construct(
        private RenderSequenceMessage $renderer,
        private ResolveStepSubject $subjects,
        private ComposeMessageService $compose,
        private GovernedCampaignInputGuard $governedInputs,
    ) {}

    public function handle(User $user, SequenceStep $step, Mailbox $mailbox, string $to, string $operationId): void
    {
        $version = SequenceVersion::query()->find($step->sequence_version_id);
        $campaign = $version
            ? Campaign::query()->find($version->campaign_id)
            : null;
        $workspaceId = (int) $campaign?->getAttribute('workspace_id');

        if (! $campaign
            || $workspaceId <= 0
            || (int) $mailbox->getAttribute('workspace_id') !== $workspaceId
            || (int) $user->currentWorkspace()->id !== $workspaceId) {
            throw new RuntimeException('workspace_mismatch');
        }

        $this->governedInputs->assertNotGoverned($campaign);

        $preview = CampaignRecipient::make([
            'workspace_id' => $workspaceId,
            'first_name' => 'Jane',
            'company' => 'Acme',
            'email_address' => $to,
        ]);

        $subjectSource = $this->subjects->source($step);
        $clone = tap(clone $step, fn (SequenceStep $copy) => $copy->subject_template = $this->subjects->handle($step));
        $rendered = $this->renderer->handle(
            $clone,
            $preview,
            test: true,
            subjectSeedStep: $subjectSource,
            recipientSeed: 'sample',
        );

        $this->compose->handle(
            $user,
            $mailbox,
            $to,
            $rendered['subject'],
            $rendered['body'],
            $operationId,
            SendAttemptKind::Test,
        );
    }
}
