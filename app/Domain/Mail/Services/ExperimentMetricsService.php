<?php

namespace App\Domain\Mail\Services;

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Enums\ThreadClassification;
use App\Domain\Mail\Support\ConfidenceInterval;
use App\Domain\Mail\Support\MessageIdNormalizer;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\MailboxSyncState;
use App\Models\MailExperiment;
use App\Models\MailExperimentAssignment;
use App\Models\ManagedMailboxAccount;
use App\Models\ManagedMailEvent;
use App\Models\Message;
use App\Models\SendAttempt;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use LogicException;

class ExperimentMetricsService
{
    /** @var list<string> */
    private const CONFIRMED_PROVIDER_STATUSES = [
        'sent',
        'provider_accepted',
    ];

    /** @var list<string> */
    private const HUMAN_REPLY_STATUSES = [
        'replied',
        'interested',
        'not_now',
        'wrong_person',
    ];

    /**
     * Build a recipient-level report from durable assignment, send, message,
     * and campaign-recipient records. The first provider-reaching attempt is
     * the only first-attempt outcome; follow-ups never add another exposure.
     * Hard-bounce outcomes are correlated back to that attempt's outbound
     * message instead of inferred from the recipient's terminal status.
     *
     * @param  array<string, scalar|list<string>|null>  $filters
     * @return array<string, mixed>
     */
    public function report(MailExperiment $experiment, array $filters = []): array
    {
        $workspaceId = (int) $experiment->getAttribute('workspace_id');
        if ($workspaceId <= 0) {
            throw new LogicException('Mail experiment has no workspace.');
        }

        $experiment = MailExperiment::query()
            ->whereKey($experiment->getKey())
            ->where('workspace_id', $workspaceId)
            ->firstOrFail();

        $assignments = MailExperimentAssignment::query()
            ->where('mail_experiment_id', $experiment->id)
            ->where('workspace_id', $workspaceId)
            ->with([
                'cohort',
                'campaignRecipient.mailbox.syncStates',
                'campaignRecipient.campaign',
            ])
            ->orderBy('id')
            ->get();

        $assignments = $assignments->filter(fn (MailExperimentAssignment $assignment): bool => $this->assignmentBelongsToExperiment($assignment, $experiment));
        $recipientIds = [];
        foreach ($assignments as $assignment) {
            $recipientId = $assignment->getAttribute('campaign_recipient_id');
            if (is_numeric($recipientId)) {
                $recipientIds[] = (int) $recipientId;
            }
        }
        $attempts = $this->attemptsByRecipient($recipientIds, $workspaceId);
        $messages = $this->messagesByRecipient($recipientIds, $workspaceId, $attempts);

        $buckets = [
            'cohorts' => [],
            'domains' => [],
            'recipient_domains' => [],
            'mailboxes' => [],
            'recipient_providers' => [],
        ];
        $evidence = [];
        $summary = $this->emptyBucket('all');

        foreach ($assignments as $assignment) {
            $observation = $this->observe($assignment, $attempts, $messages, $workspaceId);

            if (! $this->passesFilters($observation, $filters)) {
                continue;
            }

            $evidence[] = $observation;
            $this->addObservation($summary, $observation);

            $cohortKey = $observation['cohort_key'] ?? 'excluded';
            $provider = $observation['recipient_provider'];
            $recipientDomain = $observation['recipient_domain'];
            $senderDomain = $observation['sender_domain'];
            $mailboxKey = $observation['mailbox_key'];

            $this->addToBucket($buckets['cohorts'], (string) $cohortKey, $observation);
            $this->addToBucket($buckets['recipient_providers'], $provider, $observation);

            if ($senderDomain !== null) {
                $this->addToBucket($buckets['domains'], $senderDomain, $observation);
            }

            if ($recipientDomain !== '') {
                // Keep recipient domains as a separate dimension. In
                // particular, an assignment that never reached a provider
                // must not be attributed to a sending domain merely because
                // its recipient domain is known.
                $this->addToBucket($buckets['recipient_domains'], $recipientDomain, $observation);
            }

            if ($mailboxKey !== null) {
                $this->addToBucket($buckets['mailboxes'], $mailboxKey, $observation);
            }
        }

        $cohortMetrics = $this->finaliseBuckets($buckets['cohorts']);
        $providerMetrics = $this->finaliseBuckets($buckets['recipient_providers']);
        $domainMetrics = $this->finaliseBuckets($buckets['domains']);
        $recipientDomainMetrics = $this->finaliseBuckets($buckets['recipient_domains']);
        $mailboxMetrics = $this->finaliseBuckets($buckets['mailboxes']);
        $summary = $this->finaliseBucket($summary);
        $operationalEvidence = $this->operationalEvidence($experiment, $assignments, $attempts);
        [$summary, $mailboxMetrics] = $this->applyOperationalHistory(
            $summary,
            $mailboxMetrics,
            $operationalEvidence['managed_mail_events'],
        );
        $cohortComparison = $this->cohortComparison($cohortMetrics, $experiment);
        $operator = $this->operatorReport($experiment, $summary, $mailboxMetrics, $operationalEvidence['incidents']);
        $warnings = $this->warnings($summary, $cohortComparison, $experiment);

        return [
            'experiment' => [
                'id' => $experiment->id,
                'workspace_id' => $workspaceId,
                'name' => $experiment->name,
                'hypothesis' => $experiment->hypothesis,
                'status' => $this->enumValue($experiment->status),
                'primary_metric' => $experiment->primary_metric,
                'target_assignments_per_cohort' => $experiment->target_assignments_per_cohort,
                'randomisation_seed' => '[stored encrypted]',
                'frozen_at' => $this->iso8601($experiment->frozen_at),
                'started_at' => $this->iso8601($experiment->started_at),
                'completed_at' => $this->iso8601($experiment->completed_at),
                'aborted_at' => $this->iso8601($experiment->aborted_at),
            ],
            'summary' => $summary,
            'metrics' => $summary,
            'cohorts' => $cohortMetrics,
            'cohort_breakdown' => $cohortMetrics,
            'domains' => $domainMetrics,
            'domain_breakdown' => $domainMetrics,
            'recipient_domains' => $recipientDomainMetrics,
            'recipient_domain_breakdown' => $recipientDomainMetrics,
            'mailboxes' => $mailboxMetrics,
            'mailbox_breakdown' => $mailboxMetrics,
            'recipient_providers' => $providerMetrics,
            'recipient_provider_breakdown' => $providerMetrics,
            'primary_effect' => $cohortComparison,
            'operator' => $operator,
            'operational_evidence' => $operationalEvidence,
            'warnings' => $warnings,
            'evidence' => $evidence,
        ];
    }

    /**
     * Return the first-attempt unknown/manual-review guardrail inputs without
     * loading messages, mailboxes, or any DNS-backed service. This is used by
     * the send worker while the experiment row is transactionally locked.
     *
     * @return array{attempted_count:int,unknown_manual_review_count:int,rate:float|null,threshold:float,breached:bool}
     */
    public function runtimeUnknownManualReview(MailExperiment $experiment): array
    {
        $workspaceId = (int) $experiment->getAttribute('workspace_id');
        if ($workspaceId <= 0) {
            return [
                'attempted_count' => 0,
                'unknown_manual_review_count' => 0,
                'rate' => null,
                'threshold' => $this->unknownManualReviewThreshold($experiment),
                'breached' => false,
            ];
        }

        $assignmentRows = MailExperimentAssignment::query()
            ->where('mail_experiment_id', $experiment->getKey())
            ->where('workspace_id', $workspaceId)
            ->whereNull('exclusion_reason')
            ->whereNotNull('campaign_recipient_id')
            ->get(['campaign_recipient_id']);
        $recipientIds = $assignmentRows
            ->pluck('campaign_recipient_id')
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($recipientIds === []) {
            return [
                'attempted_count' => 0,
                'unknown_manual_review_count' => 0,
                'rate' => null,
                'threshold' => $this->unknownManualReviewThreshold($experiment),
                'breached' => false,
            ];
        }

        $attempts = SendAttempt::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('campaign_recipient_id', $recipientIds)
            ->where('kind', 'campaign')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $grouped = $attempts->groupBy(fn (SendAttempt $attempt): int => (int) $attempt->getAttribute('campaign_recipient_id'));
        $attemptedCount = 0;
        $unknownManualCount = 0;

        foreach ($recipientIds as $recipientId) {
            /** @var Collection<int, SendAttempt> $recipientAttempts */
            $recipientAttempts = $grouped->get($recipientId, collect());
            $providerAttempts = $recipientAttempts->filter(fn (SendAttempt $attempt): bool => $this->isProviderAttempt($attempt));
            if ($providerAttempts->isEmpty()) {
                continue;
            }

            $attemptedCount++;
            $first = $providerAttempts->first();
            if ($this->attemptHadUnknown($first) || $this->attemptHadManualReview($first)) {
                $unknownManualCount++;
            }
        }

        $threshold = $this->unknownManualReviewThreshold($experiment);
        $rate = $attemptedCount > 0 ? (float) $unknownManualCount / $attemptedCount : null;

        return [
            'attempted_count' => $attemptedCount,
            'unknown_manual_review_count' => $unknownManualCount,
            'rate' => $rate,
            'threshold' => $threshold,
            'breached' => $rate !== null && $rate > $threshold,
        ];
    }

    /**
     * The configured value is a fraction in the inclusive [0, 1] range.
     * Experiment-local configuration wins over the deployment default.
     */
    public function unknownManualReviewThreshold(MailExperiment $experiment): float
    {
        $configuration = $experiment->getAttribute('configuration');
        $configured = is_array($configuration)
            ? ($configuration['guardrails']['unknown_manual_review_rate_threshold']
                ?? $configuration['analysis']['unknown_manual_review_rate_threshold']
                ?? null)
            : null;
        $configured ??= config('outboss.experiments.unknown_manual_review_rate_threshold', 0.10);

        if (! is_numeric($configured)) {
            return 0.10;
        }

        return min(1.0, max(0.0, (float) $configured));
    }

    /**
     * @param  array<string, scalar|list<string>|null>  $filters
     * @return array<string, mixed>
     */
    public function handle(MailExperiment $experiment, array $filters = []): array
    {
        return $this->report($experiment, $filters);
    }

    /**
     * @param  list<int>  $recipientIds
     * @return array<int, Collection<int, SendAttempt>>
     */
    private function attemptsByRecipient(array $recipientIds, int $workspaceId): array
    {
        if ($recipientIds === []) {
            return [];
        }

        /** @var array<int, Collection<int, SendAttempt>> $grouped */
        $grouped = [];
        SendAttempt::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('campaign_recipient_id', $recipientIds)
            ->where('kind', 'campaign')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->each(function (SendAttempt $attempt) use (&$grouped): void {
                $recipientId = (int) $attempt->getAttribute('campaign_recipient_id');
                $grouped[$recipientId] ??= collect();
                $grouped[$recipientId]->push($attempt);
            });

        return $grouped;
    }

    /**
     * @param  list<int>  $recipientIds
     * @param  array<int, Collection<int, SendAttempt>>  $attempts
     * @return array<int, Collection<int, Message>>
     */
    private function messagesByRecipient(array $recipientIds, int $workspaceId, array $attempts = []): array
    {
        if ($recipientIds === []) {
            return [];
        }

        $attemptMessageRecipients = [];
        foreach ($attempts as $recipientId => $recipientAttempts) {
            foreach ($recipientAttempts as $attempt) {
                $messageId = $attempt->getAttribute('message_id');
                if (is_numeric($messageId)) {
                    $messageId = (int) $messageId;
                    if (array_key_exists($messageId, $attemptMessageRecipients)
                        && $attemptMessageRecipients[$messageId] !== (int) $recipientId) {
                        // A message linked to attempts for two recipients is
                        // ambiguous evidence and must not be attributed to
                        // either one.
                        $attemptMessageRecipients[$messageId] = null;
                    } else {
                        $attemptMessageRecipients[$messageId] = (int) $recipientId;
                    }
                }
            }
        }
        $attemptMessageIds = array_keys($attemptMessageRecipients);

        /** @var array<int, Collection<int, Message>> $grouped */
        $grouped = [];
        $query = Message::query()
            ->where('workspace_id', $workspaceId)
            ->where(function ($query) use ($recipientIds, $attemptMessageIds): void {
                $query->whereIn('campaign_recipient_id', $recipientIds);
                if ($attemptMessageIds !== []) {
                    $query->orWhereIn('id', $attemptMessageIds);
                }
            })
            ->orderBy('id');

        $query->get()->each(function (Message $message) use (&$grouped, $attemptMessageRecipients): void {
            $messageId = (int) $message->getAttribute('id');
            $storedRecipientId = $message->getAttribute('campaign_recipient_id');
            $attemptRecipientId = $attemptMessageRecipients[$messageId] ?? null;
            $recipientId = is_numeric($storedRecipientId)
                ? (int) $storedRecipientId
                : $attemptRecipientId;
            if ($recipientId === null) {
                return;
            }

            $grouped[$recipientId] ??= collect();
            $grouped[$recipientId]->push($message);
        });

        return $grouped;
    }

    private function assignmentBelongsToExperiment(MailExperimentAssignment $assignment, MailExperiment $experiment): bool
    {
        if ((int) $assignment->mail_experiment_id !== (int) $experiment->id
            || (int) $assignment->workspace_id !== (int) $experiment->workspace_id) {
            return false;
        }

        $cohort = $assignment->cohort;

        return $cohort === null || (int) $cohort->mail_experiment_id === (int) $experiment->id;
    }

    /**
     * @param  array<int, Collection<int, SendAttempt>>  $attempts
     * @param  array<int, Collection<int, Message>>  $messages
     * @return array<string, mixed>
     */
    private function observe(
        MailExperimentAssignment $assignment,
        array $attempts,
        array $messages,
        int $workspaceId,
    ): array {
        $recipient = $assignment->campaignRecipient;
        $recipientCampaign = $recipient instanceof CampaignRecipient ? $recipient->campaign : null;
        $recipientIsScoped = $recipient instanceof CampaignRecipient
            && (int) $recipient->getAttribute('workspace_id') === $workspaceId
            && ($recipientCampaign === null
                || (int) $recipientCampaign->getAttribute('workspace_id') === $workspaceId)
            && ($assignment->getAttribute('campaign_id') === null
                || (int) $recipient->getAttribute('campaign_id') === (int) $assignment->getAttribute('campaign_id'));
        if (! $recipientIsScoped) {
            $recipient = null;
        }

        $recipientId = $recipient?->getKey();
        $recipientAttempts = $recipientId === null ? collect() : ($attempts[(int) $recipientId] ?? collect());
        $providerAttempts = $recipientAttempts->filter(fn (SendAttempt $attempt): bool => $this->isProviderAttempt($attempt));
        $firstAttempt = $providerAttempts->first();
        $firstStatus = $firstAttempt instanceof SendAttempt ? $this->enumValue($firstAttempt->status) : null;
        $firstConfirmed = in_array($firstStatus, self::CONFIRMED_PROVIDER_STATUSES, true);
        $recipientStatus = $recipient ? $this->enumValue($recipient->getAttribute('status')) : null;
        $humanReply = $firstConfirmed && $recipient !== null
            && (($recipient->getAttribute('replied_at') !== null) || in_array($recipientStatus, self::HUMAN_REPLY_STATUSES, true));
        $interested = $firstConfirmed && $recipientStatus === RecipientStatus::Interested->value;
        $unsubscribed = $firstConfirmed && $recipientStatus === RecipientStatus::Unsubscribed->value;
        $replyAt = $recipient?->getAttribute('replied_at');
        $firstSentAt = $recipient?->getAttribute('first_sent_at');
        $replySeconds = $humanReply && $replyAt !== null && $firstSentAt !== null
            ? max(0, $this->timestamp($replyAt) - $this->timestamp($firstSentAt))
            : null;
        $manual = $firstAttempt instanceof SendAttempt && $this->attemptHadManualReview($firstAttempt);
        $unknown = $firstAttempt instanceof SendAttempt && $this->attemptHadUnknown($firstAttempt);
        $providerErrors = $providerAttempts->sum(fn (SendAttempt $attempt): int => $this->providerErrorCount($attempt));
        $unknownManualAttempts = $providerAttempts->filter(
            fn (SendAttempt $attempt): bool => $this->attemptHadUnknown($attempt) || $this->attemptHadManualReview($attempt),
        )->count();
        $providerCallCount = $providerAttempts->sum(fn (SendAttempt $attempt): int => $this->providerCallCount($attempt));
        $providerUnknownCount = $providerAttempts->sum(
            fn (SendAttempt $attempt): int => max(0, (int) $attempt->getAttribute('provider_unknown_count')),
        );
        $providerManualReviewCount = $providerAttempts->sum(
            fn (SendAttempt $attempt): int => max(0, (int) $attempt->getAttribute('provider_manual_review_count')),
        );
        $followUpSentAfterReply = $replyAt !== null && $recipientAttempts->contains(function (SendAttempt $attempt) use ($replyAt, $firstAttempt): bool {
            if ($firstAttempt !== null && $attempt->id === $firstAttempt->id) {
                return false;
            }

            $at = $attempt->sent_at ?? $attempt->provider_accepted_at ?? $attempt->created_at;

            return $at instanceof DateTimeInterface && $this->timestamp($at) > $this->timestamp($replyAt)
                && in_array($this->enumValue($attempt->status), self::CONFIRMED_PROVIDER_STATUSES, true);
        });
        /** @var Collection<int, Message> $recipientMessages */
        $recipientMessages = $recipientId === null ? collect() : ($messages[(int) $recipientId] ?? collect());
        $firstMessage = $this->firstAttemptMessage($firstAttempt, $recipientMessages);
        $firstMessageHardBounce = $firstConfirmed
            && $this->hasHardBounceForFirstMessage($firstAttempt, $firstMessage, $providerAttempts, $recipientMessages);
        $softBounce = $firstConfirmed && $recipientMessages->contains(fn (Message $message): bool => $this->enumValue($message->classification) === ThreadClassification::Bounce->value
            && $this->enumValue($message->bounce_subtype) === 'soft');
        $mailbox = $recipient?->mailbox;
        $mailboxScoped = $mailbox instanceof Mailbox && (int) $mailbox->getAttribute('workspace_id') === $workspaceId;
        $mailboxKey = $mailboxScoped ? (string) $mailbox->id : null;
        $senderDomain = $mailboxScoped ? $this->senderDomain($mailbox->email_address) : null;
        $mailboxHealthPaused = $mailboxScoped && $mailbox->getAttribute('sending_paused_at') !== null;
        $syncFailureCount = 0;
        /** @var array<string, int|null> $syncFailureStates */
        $syncFailureStates = [];
        if ($mailboxScoped) {
            foreach ($mailbox->syncStates as $syncState) {
                if ($this->enumValue($syncState->getAttribute('status')) === 'failed'
                    || $syncState->getAttribute('last_error') !== null) {
                    $syncFailureCount++;
                    $stateKey = $this->syncFailureStateKey($mailbox, $syncState);
                    $syncFailureStates[$stateKey] = $this->syncFailureDurationSeconds($syncState);
                }
            }
        }

        return [
            'assignment_id' => $assignment->id,
            'email_normalized' => $assignment->email_normalized,
            'recipient_domain' => $assignment->recipient_domain,
            'recipient_provider' => $this->enumValue($assignment->recipient_provider) ?: 'unknown',
            'stratum_key' => $assignment->stratum_key,
            'assignment_key' => $assignment->assignment_key,
            'cohort_key' => $assignment->cohort?->key,
            'cohort_label' => $assignment->cohort?->label,
            'campaign_id' => $assignment->getAttribute('campaign_id'),
            'campaign_recipient_id' => $recipientId,
            'mailbox_key' => $mailboxKey,
            'mailbox' => $mailboxScoped ? $mailbox->email_address : null,
            'sender_domain' => $senderDomain,
            'first_attempt_status' => $firstStatus,
            'provider_attempted_at' => $firstAttempt instanceof SendAttempt
                ? $this->iso8601($firstAttempt->getAttribute('provider_attempted_at'))
                : null,
            'provider_submitted_first_message' => $firstConfirmed,
            'exposed' => $firstConfirmed,
            'attempted' => $providerAttempts->isNotEmpty(),
            'attempt_count' => $providerCallCount,
            'excluded' => $assignment->exclusion_reason !== null,
            'exclusion_reason' => $assignment->exclusion_reason,
            'human_reply' => $humanReply,
            'interested' => $interested,
            // A terminal recipient state can be caused by a later follow-up
            // bounce. Keep this outcome tied to the first provider-reaching
            // campaign message instead of treating the recipient state as
            // proof of a first-message hard bounce.
            'bounced' => $firstMessageHardBounce,
            'first_message_hard_bounce' => $firstMessageHardBounce,
            'unsubscribed' => $unsubscribed,
            'unknown_first_attempt' => $unknown,
            'manual_review_first_attempt' => $manual,
            'unknown_or_manual_review_first_attempt' => $unknown || $manual,
            'provider_unknown_count' => $providerUnknownCount,
            'provider_manual_review_count' => $providerManualReviewCount,
            'provider_error_count' => $providerErrors,
            'provider_error_attempts' => $providerErrors,
            'unknown_manual_review_attempts' => $unknownManualAttempts,
            'mailbox_health_paused' => $mailboxHealthPaused,
            'sync_failure_count' => $syncFailureCount,
            'sync_failure_states' => $syncFailureStates,
            'soft_bounce' => $softBounce,
            'first_sent_at' => $this->iso8601($firstSentAt),
            'replied_at' => $this->iso8601($replyAt),
            'reply_seconds' => $replySeconds,
            'follow_up_stop_correct' => $humanReply ? ! $followUpSentAfterReply : null,
            'campaign_status' => $recipientCampaign instanceof Campaign
                ? $this->enumValue($recipientCampaign->getAttribute('status'))
                : null,
            'recipient_status' => $recipientStatus,
        ];
    }

    /** @param Collection<int, Message> $recipientMessages */
    private function firstAttemptMessage(?SendAttempt $firstAttempt, Collection $recipientMessages): ?Message
    {
        if (! $firstAttempt) {
            return null;
        }

        $messageId = $firstAttempt->getAttribute('message_id');
        if (! is_numeric($messageId)) {
            return null;
        }

        $attemptMailboxId = $firstAttempt->getAttribute('mailbox_id');
        $attemptRecipientId = $firstAttempt->getAttribute('campaign_recipient_id');

        $message = $recipientMessages->first(
            fn (Message $candidate): bool => (int) $candidate->getAttribute('id') === (int) $messageId
                && (! is_numeric($attemptMailboxId)
                    || (int) $candidate->getAttribute('mailbox_id') === (int) $attemptMailboxId)
                && (! is_numeric($attemptRecipientId)
                    || ! is_numeric($candidate->getAttribute('campaign_recipient_id'))
                    || (int) $candidate->getAttribute('campaign_recipient_id') === (int) $attemptRecipientId),
        );

        return $message instanceof Message ? $message : null;
    }

    /**
     * A recipient can be left in a bounced terminal state by a later
     * sequence step. A first-message hard bounce therefore needs a durable
     * relationship to the first outbound message (or, when providers omit
     * message headers, an otherwise unambiguous first-message thread).
     *
     * @param  Collection<int, SendAttempt>  $providerAttempts
     * @param  Collection<int, Message>  $recipientMessages
     */
    private function hasHardBounceForFirstMessage(
        ?SendAttempt $firstAttempt,
        ?Message $firstMessage,
        Collection $providerAttempts,
        Collection $recipientMessages,
    ): bool {
        if (! $firstAttempt || ! $firstMessage
            || $this->enumValue($firstMessage->getAttribute('direction')) !== 'outbound') {
            return false;
        }

        $firstMessageId = (int) $firstMessage->getAttribute('id');
        $firstWorkspaceId = (int) $firstMessage->getAttribute('workspace_id');
        $firstMailboxId = (int) $firstMessage->getAttribute('mailbox_id');
        if ($firstWorkspaceId <= 0 || $firstMailboxId <= 0) {
            return false;
        }

        $firstThreadId = $firstMessage->getAttribute('thread_id');
        $firstInternetMessageId = MessageIdNormalizer::normalize(
            is_string($firstMessage->getAttribute('internet_message_id'))
                ? $firstMessage->getAttribute('internet_message_id')
                : null,
        );
        $firstSentAt = $firstAttempt->getAttribute('sent_at')
            ?? $firstAttempt->getAttribute('provider_accepted_at')
            ?? $firstMessage->getAttribute('sent_at')
            ?? $firstAttempt->getAttribute('created_at');

        $providerMessageIds = $providerAttempts
            ->map(fn (SendAttempt $attempt): mixed => $attempt->getAttribute('message_id'))
            ->filter(fn (mixed $messageId): bool => is_numeric($messageId))
            ->map(fn (mixed $messageId): int => (int) $messageId)
            ->unique()
            ->values();
        $hasLaterProviderMessage = $providerMessageIds->contains(
            fn (int $messageId): bool => $messageId !== $firstMessageId,
        );

        foreach ($recipientMessages as $candidate) {
            if ((int) $candidate->getAttribute('workspace_id') !== $firstWorkspaceId
                || (int) $candidate->getAttribute('mailbox_id') !== $firstMailboxId
                || $this->enumValue($candidate->getAttribute('direction')) !== 'inbound'
                || $this->enumValue($candidate->getAttribute('classification')) !== ThreadClassification::Bounce->value
                || $this->enumValue($candidate->getAttribute('bounce_subtype')) !== 'hard') {
                continue;
            }

            $bounceAt = $candidate->getAttribute('received_at') ?? $candidate->getAttribute('created_at');
            if ($firstSentAt instanceof DateTimeInterface
                && $bounceAt instanceof DateTimeInterface
                && $this->timestamp($bounceAt) < $this->timestamp($firstSentAt)) {
                continue;
            }

            if ((int) $candidate->getAttribute('reply_to_message_id') === $firstMessageId) {
                return true;
            }

            if ($firstInternetMessageId !== null) {
                $inReplyTo = MessageIdNormalizer::normalize(
                    is_string($candidate->getAttribute('in_reply_to'))
                        ? $candidate->getAttribute('in_reply_to')
                        : null,
                );
                if ($inReplyTo === $firstInternetMessageId) {
                    return true;
                }

                $references = $candidate->getAttribute('references');
                if (is_array($references)) {
                    foreach ($references as $reference) {
                        if (is_string($reference)
                            && MessageIdNormalizer::normalize($reference) === $firstInternetMessageId) {
                            return true;
                        }
                    }
                }
            }

            // Some providers preserve the thread but omit In-Reply-To and
            // References on delivery-status messages. Only use that fallback
            // when no later provider message exists, otherwise the bounce
            // cannot safely be assigned to the first message.
            if (! $hasLaterProviderMessage
                && is_numeric($firstThreadId)
                && (int) $candidate->getAttribute('thread_id') === (int) $firstThreadId) {
                return true;
            }
        }

        return false;
    }

    private function syncFailureStateKey(Mailbox $mailbox, MailboxSyncState $syncState): string
    {
        $scope = $this->enumValue($syncState->getAttribute('scope'));
        if ($scope === null || $scope === '') {
            $scope = 'state-'.(string) $syncState->getKey();
        }

        return (string) $mailbox->getKey().':'.$scope;
    }

    private function syncFailureDurationSeconds(MailboxSyncState $syncState): ?int
    {
        $startedAt = $syncState->getAttribute('last_started_at');
        if (! $startedAt instanceof DateTimeInterface) {
            return null;
        }

        $status = $this->enumValue($syncState->getAttribute('status'));
        $completedAt = $syncState->getAttribute('last_completed_at');
        $isOngoingFailure = $status === 'failed'
            || ($syncState->getAttribute('last_error') !== null && ! $completedAt instanceof DateTimeInterface);
        $endedAt = $isOngoingFailure ? now() : $completedAt;
        if (! $endedAt instanceof DateTimeInterface) {
            return null;
        }

        return max(0, $this->timestamp($endedAt) - $this->timestamp($startedAt));
    }

    /**
     * @param  array<string, mixed>  $observation
     * @param  array<string, scalar|list<string>|null>  $filters
     */
    private function passesFilters(array $observation, array $filters): bool
    {
        foreach ($filters as $key => $expected) {
            $actual = match ($key) {
                'cohort', 'cohort_key' => $observation['cohort_key'],
                'domain', 'sender_domain' => $observation['sender_domain'],
                'recipient_domain' => $observation['recipient_domain'],
                'mailbox', 'mailbox_id' => $observation['mailbox_key'],
                'recipient_provider', 'provider' => $observation['recipient_provider'],
                default => null,
            };

            if ($actual === null) {
                return false;
            }

            $allowed = is_array($expected) ? $expected : [$expected];
            if (! in_array($actual, array_map('strval', $allowed), true)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function emptyBucket(string $key): array
    {
        return [
            'key' => $key,
            'assignment_count' => 0,
            'eligible_count' => 0,
            'excluded_count' => 0,
            'attempted_count' => 0,
            'provider_attempt_count' => 0,
            'exposed_count' => 0,
            'human_reply_count' => 0,
            'interested_count' => 0,
            'bounced_count' => 0,
            'unsubscribed_count' => 0,
            'unknown_first_attempt_count' => 0,
            'manual_review_first_attempt_count' => 0,
            'unknown_or_manual_review_first_attempt_count' => 0,
            'unknown_manual_review_attempt_count' => 0,
            'provider_error_attempt_count' => 0,
            'soft_bounce_count' => 0,
            'follow_up_stop_correct_count' => 0,
            'follow_up_stop_observation_count' => 0,
            'reply_seconds' => [],
            'mailbox_health_pause_count' => 0,
            'mailbox_health_pause_keys' => [],
            'sync_failure_count' => 0,
            'sync_failure_event_count' => 0,
            'sync_failure_mailbox_keys' => [],
            'sync_failure_state_keys' => [],
            'sync_failure_durations' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  &$bucket
     * @param  array<string, mixed>  $observation
     */
    private function addObservation(array &$bucket, array $observation): void
    {
        $bucket['assignment_count']++;
        if ($observation['excluded']) {
            $bucket['excluded_count']++;

            return;
        }

        $bucket['eligible_count']++;
        if ($observation['attempted']) {
            $bucket['attempted_count']++;
        }
        $bucket['provider_attempt_count'] += $observation['attempt_count'];
        if ($observation['exposed']) {
            $bucket['exposed_count']++;
        }
        if ($observation['human_reply']) {
            $bucket['human_reply_count']++;
        }
        if ($observation['interested']) {
            $bucket['interested_count']++;
        }
        if ($observation['bounced']) {
            $bucket['bounced_count']++;
        }
        if ($observation['unsubscribed']) {
            $bucket['unsubscribed_count']++;
        }
        if ($observation['unknown_first_attempt']) {
            $bucket['unknown_first_attempt_count']++;
        }
        if ($observation['manual_review_first_attempt']) {
            $bucket['manual_review_first_attempt_count']++;
        }
        if ($observation['unknown_or_manual_review_first_attempt']) {
            $bucket['unknown_or_manual_review_first_attempt_count']++;
        }
        $bucket['unknown_manual_review_attempt_count'] += $observation['unknown_manual_review_attempts'];
        $bucket['provider_error_attempt_count'] += $observation['provider_error_attempts'];
        if ($observation['mailbox_health_paused']) {
            $mailboxKey = $observation['mailbox_key'];
            if ($mailboxKey !== null) {
                $bucket['mailbox_health_pause_keys'][$mailboxKey] = true;
            }
        }
        if ($observation['sync_failure_count'] > 0) {
            $mailboxKey = $observation['mailbox_key'];
            if ($mailboxKey !== null) {
                $bucket['sync_failure_mailbox_keys'][$mailboxKey] = true;
            }
            foreach ($observation['sync_failure_states'] as $stateKey => $duration) {
                $bucket['sync_failure_state_keys'][$stateKey] = true;
                if ($duration !== null) {
                    $bucket['sync_failure_durations'][$stateKey] = $duration;
                }
            }
        }
        if ($observation['soft_bounce']) {
            $bucket['soft_bounce_count']++;
        }
        if ($observation['follow_up_stop_correct'] !== null) {
            $bucket['follow_up_stop_observation_count']++;
            if ($observation['follow_up_stop_correct']) {
                $bucket['follow_up_stop_correct_count']++;
            }
        }
        if ($observation['reply_seconds'] !== null && $observation['exposed']) {
            $bucket['reply_seconds'][] = $observation['reply_seconds'];
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  &$buckets
     * @param  array<string, mixed>  $observation
     */
    private function addToBucket(array &$buckets, string $key, array $observation): void
    {
        $buckets[$key] ??= $this->emptyBucket($key);
        $this->addObservation($buckets[$key], $observation);
    }

    /**
     * @param  array<string, array<string, mixed>>  $buckets
     * @return array<string, array<string, mixed>>
     */
    private function finaliseBuckets(array $buckets): array
    {
        ksort($buckets, SORT_STRING);
        foreach ($buckets as $key => $bucket) {
            $buckets[$key] = $this->finaliseBucket($bucket);
        }

        return $buckets;
    }

    /**
     * @param  array<string, mixed>  $bucket
     * @return array<string, mixed>
     */
    private function finaliseBucket(array $bucket): array
    {
        $exposed = (int) $bucket['exposed_count'];
        $attempted = (int) $bucket['attempted_count'];
        $providerAttempts = (int) $bucket['provider_attempt_count'];
        $replies = (int) $bucket['human_reply_count'];
        $bucket['human_reply_rate'] = $exposed > 0 ? (float) $replies / $exposed : null;
        $bucket['interested_rate'] = $exposed > 0 ? (float) $bucket['interested_count'] / $exposed : null;
        $bucket['bounce_rate'] = $exposed > 0 ? (float) $bucket['bounced_count'] / $exposed : null;
        $bucket['unsubscribe_rate'] = $exposed > 0 ? (float) $bucket['unsubscribed_count'] / $exposed : null;
        // Unknown/manual first-attempt outcomes are recipient-level metrics;
        // only eligible recipients with a provider-reaching attempt belong in
        // their denominator. Excluded or never-attempted assignments must not
        // dilute the rate.
        $bucket['unknown_or_manual_review_rate'] = $attempted > 0
            ? (float) $bucket['unknown_or_manual_review_first_attempt_count'] / $attempted
            : null;
        $bucket['unknown_manual_review_attempt_rate'] = $providerAttempts > 0
            ? (float) $bucket['unknown_manual_review_attempt_count'] / $providerAttempts
            : null;
        $bucket['follow_up_stop_correct_rate'] = $bucket['follow_up_stop_observation_count'] > 0
            ? (float) $bucket['follow_up_stop_correct_count'] / $bucket['follow_up_stop_observation_count']
            : null;
        sort($bucket['reply_seconds'], SORT_NUMERIC);
        $bucket['median_time_to_reply_seconds'] = $this->median($bucket['reply_seconds']);
        $bucket['mailbox_health_pause_count'] = count($bucket['mailbox_health_pause_keys']);
        $bucket['sync_failure_count'] = count($bucket['sync_failure_mailbox_keys']);
        $bucket['sync_failure_event_count'] = count($bucket['sync_failure_state_keys']);
        $bucket['sync_failure_duration_seconds'] = array_sum($bucket['sync_failure_durations']);
        // Provider errors are counted per provider-reaching attempt, so a
        // follow-up cannot make the numerator exceed its denominator.
        $bucket['provider_error_rate'] = $providerAttempts > 0
            ? min(1.0, (float) $bucket['provider_error_attempt_count'] / $providerAttempts)
            : null;
        $bucket['soft_bounce_rate'] = $exposed > 0
            ? (float) $bucket['soft_bounce_count'] / $exposed
            : null;
        $bucket['unique_human_reply_rate'] = $bucket['human_reply_rate'];
        $bucket['any_reply_rate'] = $bucket['human_reply_rate'];
        $bucket['positive_reply_rate'] = $bucket['interested_rate'];
        $bucket['first_message_hard_bounce_rate'] = $bucket['bounce_rate'];
        $bucket['unknown_manual_review_rate'] = $bucket['unknown_or_manual_review_rate'];
        unset(
            $bucket['mailbox_health_pause_keys'],
            $bucket['sync_failure_mailbox_keys'],
            $bucket['sync_failure_state_keys'],
            $bucket['sync_failure_durations'],
        );
        unset($bucket['reply_seconds']);

        return $bucket;
    }

    /**
     * @param  array<string, array<string, mixed>>  $cohorts
     * @return array<string, mixed>
     */
    private function cohortComparison(array $cohorts, MailExperiment $experiment): array
    {
        $keys = array_values(array_filter(array_keys($cohorts), fn (string $key): bool => $key !== 'excluded'));
        sort($keys, SORT_STRING);
        $aKey = $keys[0] ?? null;
        $bKey = $keys[1] ?? null;
        $a = $aKey === null ? null : $cohorts[$aKey];
        $b = $bKey === null ? null : $cohorts[$bKey];
        $aSuccesses = (int) ($a['human_reply_count'] ?? 0);
        $aTrials = (int) ($a['exposed_count'] ?? 0);
        $bSuccesses = (int) ($b['human_reply_count'] ?? 0);
        $bTrials = (int) ($b['exposed_count'] ?? 0);

        return [
            'cohort_a' => $aKey,
            'cohort_b' => $bKey,
            'cohort_a_counts' => ['successes' => $aSuccesses, 'trials' => $aTrials],
            'cohort_b_counts' => ['successes' => $bSuccesses, 'trials' => $bTrials],
            'absolute_difference' => ConfidenceInterval::effectSize($aSuccesses, $aTrials, $bSuccesses, $bTrials)['absolute_difference'],
            'relative_difference' => ConfidenceInterval::effectSize($aSuccesses, $aTrials, $bSuccesses, $bTrials)['relative_difference'],
            'odds_ratio' => ConfidenceInterval::effectSize($aSuccesses, $aTrials, $bSuccesses, $bTrials)['odds_ratio'],
            'confidence_interval_95' => ConfidenceInterval::difference($aSuccesses, $aTrials, $bSuccesses, $bTrials),
            'low_sample_warning' => $aTrials < $this->lowSampleThreshold($experiment)
                || $bTrials < $this->lowSampleThreshold($experiment)
                || $aSuccesses < 5
                || ($aTrials - $aSuccesses) < 5
                || $bSuccesses < 5
                || ($bTrials - $bSuccesses) < 5,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<int|string, array<string, mixed>>  $mailboxes
     * @param  list<mixed>  $incidents
     * @return array<string, mixed>
     */
    private function operatorReport(MailExperiment $experiment, array $summary, array $mailboxes, array $incidents): array
    {
        $mailboxCount = count($mailboxes);
        $infraMicros = $experiment->infrastructure_cost_micros;
        $operatorMicros = $experiment->operator_cost_micros;
        $exposed = (int) $summary['exposed_count'];

        return [
            'operator_minutes' => $experiment->operator_minutes,
            'operator_time_minutes' => $experiment->operator_minutes,
            'operator_hours' => $experiment->operator_minutes === null ? null : $experiment->operator_minutes / 60,
            'operator_time_hours' => $experiment->operator_minutes === null ? null : $experiment->operator_minutes / 60,
            'operator_cost_micros' => $operatorMicros,
            'infrastructure_cost_micros' => $infraMicros,
            'active_mailbox_count' => $mailboxCount,
            'incident_count' => max((int) $experiment->incident_count, count($incidents)),
            'incidents' => $incidents,
            'cost_per_active_mailbox_micros' => $infraMicros === null || $mailboxCount === 0
                ? null
                : $infraMicros / $mailboxCount,
            'infrastructure_cost_per_active_mailbox_micros' => $infraMicros === null || $mailboxCount === 0
                ? null
                : $infraMicros / $mailboxCount,
            'cost_per_1000_provider_submitted_messages_micros' => $infraMicros === null || $exposed === 0
                ? null
                : ($infraMicros / $exposed) * 1000,
            'infrastructure_cost_per_1000_provider_submitted_messages_micros' => $infraMicros === null || $exposed === 0
                ? null
                : ($infraMicros / $exposed) * 1000,
        ];
    }

    /**
     * Keep the complete operational record beside the recipient table. The
     * event stream is append-only, so a recovered sync failure or resumed
     * health pause remains visible in the final gate artifact.
     *
     * @param  Collection<int, MailExperimentAssignment>  $assignments
     * @param  array<int, Collection<int, SendAttempt>>  $attempts
     * @return array{incidents:list<mixed>,managed_mail_events:list<array<string,mixed>>,mailbox_daily_sends:list<array<string,mixed>>,approved_ramp:array<string,mixed>}
     */
    private function operationalEvidence(MailExperiment $experiment, Collection $assignments, array $attempts): array
    {
        $incidents = $experiment->getAttribute('incidents');
        $incidents = is_array($incidents) ? array_values($incidents) : [];
        $snapshot = $experiment->getAttribute('assignment_snapshot');
        $configuration = $experiment->getAttribute('configuration');
        $approvedRamp = is_array($snapshot) && is_array($snapshot['ramp'] ?? null)
            ? $snapshot['ramp']
            : (is_array($configuration) && is_array($configuration['ramp'] ?? null) ? $configuration['ramp'] : []);
        $approvedRamp = $this->stringKeyedArray($approvedRamp);

        $mailboxes = $assignments
            ->map(fn (MailExperimentAssignment $assignment): mixed => $assignment->campaignRecipient?->mailbox)
            ->filter(fn (mixed $mailbox): bool => $mailbox instanceof Mailbox
                && (int) $mailbox->getAttribute('workspace_id') === (int) $experiment->workspace_id)
            ->keyBy(fn (Mailbox $mailbox): int => (int) $mailbox->getKey());
        $mailboxIds = $mailboxes->keys()->map(fn (mixed $id): int => (int) $id)->values()->all();

        $accounts = $mailboxIds === []
            ? collect()
            : ManagedMailboxAccount::query()
                ->whereIn('mailbox_id', $mailboxIds)
                ->with('managedMailDomain')
                ->get()
                ->filter(fn (ManagedMailboxAccount $account): bool => $account->workspaceId() === (int) $experiment->workspace_id);
        $accountIds = $accounts->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $domainIds = $accounts->pluck('managed_mail_domain_id')->map(fn (mixed $id): int => (int) $id)->unique()->all();
        $accountMailboxIds = $accounts->mapWithKeys(
            fn (ManagedMailboxAccount $account): array => [(int) $account->id => (int) $account->mailbox_id],
        );

        /** @var list<array<string, mixed>> $events */
        $events = [];
        if ($accountIds !== [] || $domainIds !== []) {
            $eventRows = ManagedMailEvent::query()
                ->where('workspace_id', $experiment->workspace_id)
                ->where(function ($query) use ($accountIds, $domainIds): void {
                    if ($accountIds !== []) {
                        $query->whereIn('managed_mailbox_account_id', $accountIds);
                    }
                    if ($domainIds !== []) {
                        $method = $accountIds === [] ? 'whereIn' : 'orWhereIn';
                        $query->{$method}('managed_mail_domain_id', $domainIds);
                    }
                })
                ->where('created_at', '>=', $this->experimentWindowStart($experiment))
                ->where('created_at', '<=', $this->experimentWindowEnd($experiment))
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();
            foreach ($eventRows as $event) {
                $accountId = $event->getAttribute('managed_mailbox_account_id');
                $mailboxId = is_numeric($accountId) ? $accountMailboxIds->get((int) $accountId) : null;
                $metadata = $event->getAttribute('safe_metadata');
                $events[] = [
                    'id' => (int) $event->getKey(),
                    'at' => $this->iso8601($event->getAttribute('created_at')),
                    'event' => (string) $event->getAttribute('event'),
                    'mailbox_id' => is_numeric($mailboxId) ? (int) $mailboxId : null,
                    'managed_mail_domain_id' => $event->getAttribute('managed_mail_domain_id'),
                    'managed_mailbox_account_id' => $accountId,
                    'actor_type' => (string) $event->getAttribute('actor_type'),
                    'actor_user_id' => $event->getAttribute('actor_user_id'),
                    'from_state' => $event->getAttribute('from_state'),
                    'to_state' => $event->getAttribute('to_state'),
                    'safe_metadata' => is_array($metadata) ? $metadata : [],
                ];
            }
        }

        /** @var array<string, array{mailbox_id:int,day_utc:string,count:int}> $daily */
        $daily = [];
        foreach ($attempts as $recipientAttempts) {
            foreach ($recipientAttempts as $attempt) {
                $status = $this->enumValue($attempt->getAttribute('status'));
                $mailboxId = $attempt->getAttribute('mailbox_id');
                $acceptedAt = $attempt->getAttribute('provider_accepted_at') ?? $attempt->getAttribute('sent_at');
                if (! in_array($status, self::CONFIRMED_PROVIDER_STATUSES, true)
                    || ! is_numeric($mailboxId)
                    || ! $acceptedAt instanceof DateTimeInterface
                    || ! $mailboxes->has((int) $mailboxId)) {
                    continue;
                }

                $day = CarbonImmutable::instance($acceptedAt)->utc()->toDateString();
                $key = (int) $mailboxId.':'.$day;
                $daily[$key] ??= ['mailbox_id' => (int) $mailboxId, 'day_utc' => $day, 'count' => 0];
                $daily[$key]['count']++;
            }
        }

        ksort($daily, SORT_STRING);
        $dailyRows = [];
        foreach ($daily as $row) {
            $mailbox = $mailboxes->get($row['mailbox_id']);
            if (! $mailbox instanceof Mailbox) {
                continue;
            }
            $configuredCap = is_numeric($mailbox->getAttribute('daily_send_cap'))
                ? max(1, (int) $mailbox->getAttribute('daily_send_cap'))
                : max(1, (int) config('outboss.sending.default_daily_cap', 50));
            $approvedCap = $this->approvedRampDailyCap($approvedRamp);
            $effectiveCap = $approvedCap === null ? $configuredCap : min($configuredCap, $approvedCap);

            $dailyRows[] = [
                ...$row,
                'mailbox' => $mailbox->getAttribute('email_address'),
                'sender_domain' => $this->senderDomain($mailbox->getAttribute('email_address')),
                'configured_daily_cap' => $configuredCap,
                'approved_ramp_daily_cap' => $approvedCap,
                'effective_daily_cap' => $effectiveCap,
                'cap_exceeded' => $row['count'] > $effectiveCap,
            ];
        }

        return [
            'incidents' => $incidents,
            'managed_mail_events' => $events,
            'mailbox_daily_sends' => $dailyRows,
            'approved_ramp' => $approvedRamp,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<int|string, array<string, mixed>>  $mailboxes
     * @param  list<array<string, mixed>>  $events
     * @return array{array<string,mixed>,array<int|string,array<string,mixed>>}
     */
    private function applyOperationalHistory(array $summary, array $mailboxes, array $events): array
    {
        $pausedMailboxKeys = [];
        $failedMailboxKeys = [];
        $mailboxIndexes = [];
        $pauseEvents = 0;
        $failureEvents = 0;
        $recoveredDuration = 0;
        $currentPauseCount = (int) $summary['mailbox_health_pause_count'];
        $currentFailureEventCount = (int) $summary['sync_failure_event_count'];
        $currentFailureDuration = (int) $summary['sync_failure_duration_seconds'];

        foreach ($mailboxes as $mailboxKey => $bucket) {
            $mailboxIndexes[(string) $mailboxKey] = $mailboxKey;
            if ((int) $bucket['mailbox_health_pause_count'] > 0) {
                $pausedMailboxKeys[(string) $mailboxKey] = true;
            }
            if ((int) $bucket['sync_failure_count'] > 0) {
                $failedMailboxKeys[(string) $mailboxKey] = true;
            }
            $bucket['mailbox_health_pause_event_count'] = 0;
            $bucket['sync_recovered_duration_seconds'] = 0;
            $mailboxes[$mailboxKey] = $bucket;
        }

        foreach ($events as $event) {
            $mailboxId = $event['mailbox_id'] ?? null;
            $mailboxKey = is_numeric($mailboxId) ? (string) (int) $mailboxId : null;
            $eventName = $event['event'] ?? null;
            $metadata = is_array($event['safe_metadata'] ?? null) ? $event['safe_metadata'] : [];

            if ($eventName === 'mailbox_sending_paused') {
                $pauseEvents++;
                if ($mailboxKey !== null) {
                    $pausedMailboxKeys[$mailboxKey] = true;
                    if (array_key_exists($mailboxKey, $mailboxIndexes)) {
                        $index = $mailboxIndexes[$mailboxKey];
                        $bucket = $mailboxes[$index];
                        $bucket['mailbox_health_pause_event_count'] = (int) $bucket['mailbox_health_pause_event_count'] + 1;
                        $bucket['mailbox_health_pause_count'] = max(1, (int) $bucket['mailbox_health_pause_count']);
                        $mailboxes[$index] = $bucket;
                    }
                }
            }

            if ($eventName === 'mailbox_sync_failed') {
                $failureEvents++;
                if ($mailboxKey !== null) {
                    $failedMailboxKeys[$mailboxKey] = true;
                    if (array_key_exists($mailboxKey, $mailboxIndexes)) {
                        $index = $mailboxIndexes[$mailboxKey];
                        $bucket = $mailboxes[$index];
                        $bucket['sync_failure_event_count'] = (int) $bucket['sync_failure_event_count'] + 1;
                        $bucket['sync_failure_count'] = max(1, (int) $bucket['sync_failure_count']);
                        $mailboxes[$index] = $bucket;
                    }
                }
            }

            if ($eventName === 'mailbox_sync_recovered') {
                $duration = is_numeric($metadata['duration_seconds'] ?? null)
                    ? max(0, (int) $metadata['duration_seconds'])
                    : 0;
                $recoveredDuration += $duration;
                if ($mailboxKey !== null && array_key_exists($mailboxKey, $mailboxIndexes)) {
                    $index = $mailboxIndexes[$mailboxKey];
                    $bucket = $mailboxes[$index];
                    $bucket['sync_recovered_duration_seconds'] = (int) $bucket['sync_recovered_duration_seconds'] + $duration;
                    $bucket['sync_failure_duration_seconds'] = (int) $bucket['sync_failure_duration_seconds'] + $duration;
                    $mailboxes[$index] = $bucket;
                }
            }
        }

        $summary['mailbox_health_pause_count'] = count($pausedMailboxKeys);
        $summary['mailbox_health_pause_event_count'] = max($currentPauseCount, $pauseEvents);
        $summary['sync_failure_count'] = count($failedMailboxKeys);
        $summary['sync_failure_event_count'] = max($currentFailureEventCount, $failureEvents);
        $summary['sync_recovered_duration_seconds'] = $recoveredDuration;
        $summary['sync_failure_duration_seconds'] = $currentFailureDuration + $recoveredDuration;

        return [$summary, $mailboxes];
    }

    private function experimentWindowStart(MailExperiment $experiment): DateTimeInterface
    {
        foreach (['started_at', 'frozen_at', 'created_at'] as $attribute) {
            $value = $experiment->getAttribute($attribute);
            if ($value instanceof DateTimeInterface) {
                return $value;
            }
        }

        return now()->startOfDay();
    }

    private function experimentWindowEnd(MailExperiment $experiment): DateTimeInterface
    {
        foreach (['completed_at', 'aborted_at'] as $attribute) {
            $value = $experiment->getAttribute($attribute);
            if ($value instanceof DateTimeInterface) {
                return $value;
            }
        }

        return now();
    }

    /** @param array<string, mixed> $ramp */
    private function approvedRampDailyCap(array $ramp): ?int
    {
        foreach (['daily_cap', 'per_mailbox_daily_cap', 'initial_daily_cap', 'initial_per_mailbox'] as $key) {
            $value = $ramp[$key] ?? null;
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * JSON objects should have string keys, but normalise defensively before
     * including operator-supplied configuration in the evidence artifact.
     *
     * @param  array<mixed>  $values
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $values): array
    {
        $normalised = [];
        foreach ($values as $key => $value) {
            $normalised[(string) $key] = $value;
        }

        return $normalised;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $comparison
     * @return list<string>
     */
    private function warnings(array $summary, array $comparison, MailExperiment $experiment): array
    {
        $warnings = [];
        if (($comparison['low_sample_warning'] ?? false) === true) {
            $warnings[] = 'Small sample: confidence intervals are imprecise and no winner should be declared.';
        }
        if (($summary['unknown_first_attempt_count'] + $summary['manual_review_first_attempt_count']) > 0) {
            $warnings[] = 'Unknown or manual-review first attempts are excluded from confirmed exposure.';
        }
        if ((int) $summary['exposed_count'] === 0) {
            $warnings[] = 'No provider-submitted first messages were observed.';
        }
        if ((int) $experiment->incident_count > 0) {
            $warnings[] = 'The experiment has recorded operational incidents; review the gate report before interpreting outcomes.';
        }

        return $warnings;
    }

    private function lowSampleThreshold(MailExperiment $experiment): int
    {
        $configuration = $experiment->getAttribute('configuration');
        $configured = is_array($configuration) ? ($configuration['analysis']['low_sample_threshold'] ?? null) : null;

        return max(1, is_numeric($configured) ? (int) $configured : (int) config('outboss.experiments.low_sample_threshold', 30));
    }

    /** @param list<int|float> $values */
    private function median(array $values): ?float
    {
        $count = count($values);
        if ($count === 0) {
            return null;
        }

        $middle = intdiv($count, 2);

        return $count % 2 === 0
            ? ((float) $values[$middle - 1] + (float) $values[$middle]) / 2
            : (float) $values[$middle];
    }

    private function senderDomain(?string $email): ?string
    {
        return $email === null ? null : (str_contains($email, '@') ? strtolower((string) substr($email, strrpos($email, '@') + 1)) : null);
    }

    private function isProviderAttempt(SendAttempt $attempt): bool
    {
        if ((int) $attempt->getAttribute('attempt_count') > 0
            || $attempt->getAttribute('provider_attempted_at') !== null
            || $attempt->getAttribute('provider_accepted_at') !== null
            || $attempt->getAttribute('sent_at') !== null) {
            return true;
        }

        $status = $this->enumValue($attempt->getAttribute('status'));
        if (in_array($status, [
            SendAttemptStatus::Sending->value,
            SendAttemptStatus::ProviderAccepted->value,
            SendAttemptStatus::Sent->value,
            SendAttemptStatus::Unknown->value,
            SendAttemptStatus::ManualReview->value,
        ], true)) {
            return true;
        }

        // A failed provider call may be represented by a hand-created or
        // legacy row without attempt_count. Restrict this fallback to an
        // explicitly provider-prefixed error so workspace/preflight failures
        // do not become exposure attempts.
        $errorCode = $attempt->getAttribute('error_code');

        return $status === SendAttemptStatus::Failed->value
            && is_string($errorCode)
            && str_starts_with($errorCode, 'provider');
    }

    private function providerCallCount(SendAttempt $attempt): int
    {
        return $this->isProviderAttempt($attempt)
            ? max(1, (int) $attempt->getAttribute('attempt_count'))
            : 0;
    }

    private function attemptHadUnknown(SendAttempt $attempt): bool
    {
        return (int) $attempt->getAttribute('provider_unknown_count') > 0
            || $this->enumValue($attempt->getAttribute('status')) === SendAttemptStatus::Unknown->value;
    }

    private function attemptHadManualReview(SendAttempt $attempt): bool
    {
        return (int) $attempt->getAttribute('provider_manual_review_count') > 0
            || (int) $attempt->getAttribute('manual_review_cycle') > 0
            || $this->enumValue($attempt->getAttribute('status')) === SendAttemptStatus::ManualReview->value;
    }

    private function providerErrorCount(SendAttempt $attempt): int
    {
        $recorded = max(0, (int) $attempt->getAttribute('provider_error_count'));
        if ($recorded > 0) {
            return min($recorded, $this->providerCallCount($attempt));
        }

        $status = $this->enumValue($attempt->getAttribute('status'));
        $errorCode = $attempt->getAttribute('error_code');

        return in_array($status, [
            SendAttemptStatus::Failed->value,
            SendAttemptStatus::Unknown->value,
            SendAttemptStatus::ManualReview->value,
        ], true) || (is_string($errorCode) && str_starts_with($errorCode, 'provider'))
            ? 1
            : 0;
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) ? $value : null;
    }

    private function iso8601(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format(DateTimeInterface::ATOM) : null;
    }

    private function timestamp(mixed $value): int
    {
        return $value instanceof DateTimeInterface ? $value->getTimestamp() : 0;
    }
}
