<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\DTOs\SequenceIssue;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Enums\SendMode;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Exceptions\SenderIdentityUnavailable;
use App\Domain\Mail\Services\MailboxSenderIdentityService;
use App\Domain\Objectives\Enums\PolicyEvaluationStage;
use App\Models\Campaign;
use App\Models\Mailbox;
use App\Models\SequenceVersion;

class CampaignLaunchReadiness
{
    public function __construct(
        private MailboxSenderIdentityService $senderIdentities,
        private ValidateSequenceStructure $sequences,
        private GovernedCampaignExecutionGuard $governedExecution,
    ) {}

    /**
     * Named launch blockers for a campaign. Empty means the campaign is ready.
     *
     * @return list<array{id: string, section: 'audience'|'sequence'|'sending', label: string, message: string}>
     */
    public function blockers(Campaign $campaign): array
    {
        $versionId = $campaign->getAttribute('draft_sequence_version_id')
            ?? $campaign->getAttribute('published_sequence_version_id');
        $version = $versionId
            ? SequenceVersion::query()->with('activeSteps')->find($versionId)
            : null;
        $attached = Mailbox::query()
            ->whereIn('id', $campaign->mailboxes()->select('mailboxes.id'))
            ->where('workspace_id', $campaign->getAttribute('workspace_id'))
            ->get();
        $mailboxes = $attached->filter(
            fn (Mailbox $mailbox): bool => $mailbox->getAttribute('status') === ConnectionStatus::Connected
                || $mailbox->getAttribute('status') === ConnectionStatus::Connected->value,
        );
        $recipients = $campaign->recipients()
            ->where('workspace_id', $campaign->getAttribute('workspace_id'))
            ->whereIn('status', [
                RecipientStatus::Queued,
                RecipientStatus::Active,
                RecipientStatus::Paused,
            ])
            ->count();

        $blockers = [];

        foreach ($attached as $mailbox) {
            $status = $mailbox->getAttribute('status');
            $needsReconnect = $status === ConnectionStatus::NeedsReconnect
                || $status === ConnectionStatus::NeedsReconnect->value;

            if ($needsReconnect) {
                $blockers[] = $this->blocker(
                    'mailbox_needs_reconnect',
                    'sending',
                    'Sending',
                    $mailbox->email_address.' needs reconnecting before this campaign can send.',
                );
            }

            if ($mailbox->getAttribute('sending_paused_at') !== null) {
                $reason = $mailbox->getAttribute('sending_paused_reason') === 'provider_error_threshold'
                    ? 'provider errors'
                    : 'hard bounces';
                $blockers[] = $this->blocker(
                    'mailbox_sending_paused',
                    'sending',
                    'Sending',
                    $mailbox->email_address.' is paused after '.$reason.'. Resume sending from Settings → Mailboxes.',
                );
            }
        }

        if ($mailboxes->isEmpty()) {
            $blockers[] = $this->blocker(
                'no_connected_mailbox',
                'sending',
                'Sending',
                'Select at least one connected mailbox.',
            );
        } else {
            foreach ($mailboxes as $mailbox) {
                try {
                    $this->senderIdentities->effective($mailbox);
                } catch (SenderIdentityUnavailable) {
                    $blockers[] = $this->blocker(
                        'sender_identity_unavailable',
                        'sending',
                        'Sending',
                        SenderIdentityUnavailable::USER_MESSAGE,
                    );
                    break;
                }
            }
        }

        if ($this->isSendMode($campaign->getAttribute('send_mode'), SendMode::Pin) && $mailboxes->count() !== 1) {
            $blockers[] = $this->blocker(
                'pin_needs_one_mailbox',
                'sending',
                'Sending',
                'Pin mode needs exactly one mailbox.',
            );
        }

        if ($recipients === 0) {
            $blockers[] = $this->blocker(
                'no_recipients',
                'audience',
                'Audience',
                'Add at least one recipient.',
            );
        }

        foreach ($this->sequences->handle($version instanceof SequenceVersion ? $version : null) as $issue) {
            $blockers[] = $this->blocker(
                $this->sequenceIssueId($issue),
                'sequence',
                'Sequence',
                $issue->message,
            );
        }

        $governed = $this->governedExecution->forCampaign($campaign, PolicyEvaluationStage::Launch);
        foreach ($governed->blockers as $governedBlocker) {
            $blockers[] = $this->blocker(
                (string) $governedBlocker['code'],
                'sending',
                'Approval',
                (string) $governedBlocker['message'],
            );
        }

        return $blockers;
    }

    /**
     * @param  'audience'|'sequence'|'sending'  $section
     * @return array{id: string, section: 'audience'|'sequence'|'sending', label: string, message: string}
     */
    private function blocker(string $id, string $section, string $label, string $message): array
    {
        return [
            'id' => $id,
            'section' => $section,
            'label' => $label,
            'message' => $message,
        ];
    }

    private function isSendMode(mixed $mode, SendMode $expected): bool
    {
        return $mode === $expected || $mode === $expected->value;
    }

    private function sequenceIssueId(SequenceIssue $issue): string
    {
        return match ($issue->code) {
            'empty' => 'sequence_empty',
            'first_step_not_immediate_new_thread' => 'first_step_not_immediate',
            'missing_body' => 'step_missing_body',
            'missing_subject' => 'new_thread_missing_subject',
            'reply_without_parent' => 'reply_without_parent',
            'unknown_variable' => 'unknown_variable',
            default => $issue->code,
        };
    }
}
