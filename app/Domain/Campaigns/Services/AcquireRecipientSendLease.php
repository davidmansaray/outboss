<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Services\MailExperimentRuntimeGuard;
use App\Domain\Mail\Services\ManagedMailCapability;
use App\Domain\Objectives\Enums\PolicyEvaluationStage;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\ProviderConnection;
use App\Models\SendAttempt;
use App\Models\SequenceStep;
use App\Models\SuppressionEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AcquireRecipientSendLease
{
    public function __construct(
        private MailboxSendBudget $budget,
        private ManagedMailCapability $managedMail,
        private MailExperimentRuntimeGuard $experimentGuard,
        private GovernedCampaignExecutionGuard $governedExecution,
        private GovernedCampaignInputGuard $governedInputs,
    ) {}

    public function handle(SendAttempt $attempt): ?SendAttempt
    {
        $budget = $this->budget;
        $managedMail = $this->managedMail;
        $experimentGuard = $this->experimentGuard;
        $governedExecution = $this->governedExecution;

        return DB::transaction(function () use ($attempt, $budget, $managedMail, $experimentGuard, $governedExecution) {
            // Resolve the candidate without row locks first. Experiment pause
            // takes the experiment lock before recipient locks; taking a
            // recipient lock before this preflight could deadlock a worker
            // against that pause transaction.
            $candidateAttempt = SendAttempt::query()->whereKey($attempt->id)->firstOrFail();

            if (! in_array($candidateAttempt->status, [
                SendAttemptStatus::Scheduled,
                SendAttemptStatus::Queued,
                SendAttemptStatus::Deferred,
                SendAttemptStatus::Failed,
            ], true)) {
                return null;
            }

            $candidateRecipient = CampaignRecipient::query()
                ->whereKey($candidateAttempt->campaign_recipient_id)
                ->first();

            if (! $candidateRecipient) {
                return null;
            }

            if (! config('outboss.campaign_sending_enabled')) {
                $attempt = SendAttempt::query()->whereKey($candidateAttempt->id)->lockForUpdate()->firstOrFail();
                $attempt->forceFill(['status' => SendAttemptStatus::Deferred, 'deferred_reason' => 'kill_switch'])->save();

                return null;
            }

            $campaign = $candidateRecipient->campaign()->firstOrFail();
            if (! $managedMail->campaignRuntimeAllows($campaign)) {
                return null;
            }

            // Lock the experiment before locking its campaign. This ordering
            // matches the lifecycle pause path and keeps a guardrail breach
            // atomic without introducing a campaign/experiment deadlock.
            $preflight = $experimentGuard->check($campaign);
            if (! $preflight->allowed) {
                return null;
            }

            $attempt = SendAttempt::query()->whereKey($candidateAttempt->id)->lockForUpdate()->firstOrFail();
            $recipient = CampaignRecipient::query()->whereKey($attempt->campaign_recipient_id)->lockForUpdate()->first();

            if (! $recipient || ! in_array($attempt->status, [
                SendAttemptStatus::Scheduled,
                SendAttemptStatus::Queued,
                SendAttemptStatus::Deferred,
                SendAttemptStatus::Failed,
            ], true)) {
                return null;
            }

            $campaign = Campaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();

            $mailbox = $recipient->mailbox()->lockForUpdate()->firstOrFail();

            $runtime = $experimentGuard->check($campaign, $mailbox, $recipient);
            if (! $runtime->allowed) {
                return null;
            }

            $message = Message::query()->findOrFail($attempt->message_id);
            $connection = ProviderConnection::query()->findOrFail($mailbox->provider_connection_id);

            if (! $this->workspaceConsistent($attempt, $recipient, $campaign, $mailbox, $message, $connection)) {
                $this->failForWorkspaceMismatch($attempt, $recipient, (int) $campaign->getAttribute('workspace_id'));

                return null;
            }

            $suppressed = SuppressionEntry::query()
                ->where('workspace_id', $campaign->workspace_id)
                ->where('email_normalized', $recipient->email_normalized)
                ->exists();

            if (
                $campaign->status !== CampaignStatus::Active
                || $recipient->status !== RecipientStatus::Active
                || $mailbox->status !== ConnectionStatus::Connected
                || $mailbox->getAttribute('sending_paused_at') !== null
                || $suppressed
            ) {
                $reason = $mailbox->getAttribute('sending_paused_at') !== null
                    && $campaign->status === CampaignStatus::Active
                    && $recipient->status === RecipientStatus::Active
                    && $mailbox->status === ConnectionStatus::Connected
                    && ! $suppressed
                    ? 'mailbox_unhealthy'
                    : 'preflight';
                $attempt->forceFill(['status' => SendAttemptStatus::Deferred, 'deferred_reason' => $reason])->save();

                return null;
            }

            // The campaign row is locked above, so this decision serializes
            // the campaign-wide target across all of its mailboxes.
            $decision = $budget->gate(
                $mailbox,
                $recipient,
                $campaign,
                includeReservedCampaign: true,
                excludeCampaignAttemptId: (int) $attempt->id,
            );

            if (! $decision->allowed) {
                $attempt->forceFill([
                    'status' => SendAttemptStatus::Deferred,
                    'deferred_reason' => $decision->deferredReason,
                ])->save();

                return null;
            }

            $step = SequenceStep::query()->whereKey($attempt->sequence_step_id)->first();
            $governed = $governedExecution->forRecipient(
                $recipient,
                PolicyEvaluationStage::Lease,
                $step,
            );
            if (! $step instanceof SequenceStep && $governed->governed) {
                $attempt->forceFill([
                    'status' => SendAttemptStatus::Deferred,
                    'deferred_reason' => 'governed_action_missing',
                ])->save();

                return null;
            }
            // Run under the recipient/attempt/mailbox locks immediately before
            // granting a fresh lease. Ordinary Campaigns receive no extra gate.
            if (! $governed->allowed()) {
                $attempt->forceFill([
                    'status' => SendAttemptStatus::Deferred,
                    'deferred_reason' => $governed->primaryCode(),
                ])->save();

                return null;
            }

            $token = (string) Str::uuid();
            $this->governedInputs->runRecipientExecutionState($recipient, function () use ($recipient, $token): void {
                $recipient->forceFill([
                    'send_lease_token' => $token,
                    'send_lease_expires_at' => now()->addMinutes(5),
                    'lock_version' => $recipient->lock_version + 1,
                ])->save();
            });

            $attempt->forceFill([
                'status' => SendAttemptStatus::LeaseAcquired,
                'lease_acquired_at' => now(),
            ])->save();

            return $attempt->fresh();
        });
    }

    protected function workspaceConsistent(
        SendAttempt $attempt,
        CampaignRecipient $recipient,
        Campaign $campaign,
        Mailbox $mailbox,
        Message $message,
        ProviderConnection $connection,
    ): bool {
        $workspaceId = (int) $campaign->getAttribute('workspace_id');

        return $workspaceId > 0
            && (int) $attempt->getAttribute('workspace_id') === $workspaceId
            && (int) $recipient->getAttribute('workspace_id') === $workspaceId
            && (int) $mailbox->getAttribute('workspace_id') === $workspaceId
            && (int) $message->getAttribute('workspace_id') === $workspaceId
            && (int) $connection->getAttribute('workspace_id') === $workspaceId
            && (int) $attempt->getAttribute('campaign_recipient_id') === (int) $recipient->id
            && (int) $attempt->getAttribute('mailbox_id') === (int) $mailbox->id
            && (int) $attempt->getAttribute('message_id') === (int) $message->id
            && (int) $recipient->getAttribute('campaign_id') === (int) $campaign->id
            && (int) $recipient->getAttribute('mailbox_id') === (int) $mailbox->id
            && (int) $message->getAttribute('mailbox_id') === (int) $mailbox->id
            && (int) $message->getAttribute('campaign_recipient_id') === (int) $recipient->id;
    }

    protected function failForWorkspaceMismatch(SendAttempt $attempt, CampaignRecipient $recipient, int $workspaceId): void
    {
        $attempt->forceFill([
            'status' => SendAttemptStatus::Failed,
            'error_code' => 'workspace_mismatch',
            'safe_error_message' => 'Send blocked because its records belong to different workspaces.',
            'provider_phase' => null,
        ])->save();

        if ($workspaceId > 0
            && (int) $attempt->getAttribute('workspace_id') === $workspaceId
            && (int) $recipient->getAttribute('workspace_id') === $workspaceId) {
            $this->governedInputs->runRecipientExecutionState($recipient, function () use ($recipient): void {
                $recipient->forceFill([
                    'send_lease_token' => null,
                    'send_lease_expires_at' => null,
                ])->save();
            });
        }
    }
}
