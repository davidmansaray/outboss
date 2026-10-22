<?php

namespace App\Domain\Mail\Services;

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Mail\DTOs\MailExperimentRuntimeDecision;
use App\Domain\Mail\Enums\ConnectionKind;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\MailExperimentDomainStrategy;
use App\Domain\Mail\Enums\MailExperimentStatus;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\ManagedMailboxAccountStatus;
use App\Domain\Mail\Enums\ManagedMailDomainKind;
use App\Domain\Mail\Enums\ManagedMailDomainStatus;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\MailExperiment;
use App\Models\MailExperimentAssignment;
use App\Models\MailExperimentCohort;
use App\Models\ManagedMailboxAccount;
use App\Models\ManagedMailDomain;
use App\Models\ProviderConnection;
use App\Models\SendAttempt;
use Illuminate\Support\Facades\DB;

/**
 * Runtime-only managed experiment checks.
 *
 * Preparation and launch validate the same topology, but a worker must read
 * the current rows again. In particular, a domain can be suspended after an
 * experiment is launched. This service deliberately uses persisted evidence;
 * DNS is verified by the separate domain-verification flow and is never
 * resolved in the send hot path.
 */
final class MailExperimentRuntimeGuard
{
    public function __construct(
        private ExperimentMetricsService $metrics,
    ) {}

    /**
     * Check a campaign before creating or acquiring managed experiment work.
     * The method owns a transaction when called by a worker and is also safe
     * inside an existing transaction (Laravel uses a savepoint for nesting).
     */
    public function check(
        Campaign $campaign,
        ?Mailbox $mailbox = null,
        ?CampaignRecipient $recipient = null,
    ): MailExperimentRuntimeDecision {
        return DB::transaction(function () use ($campaign, $mailbox, $recipient): MailExperimentRuntimeDecision {
            $resolved = $this->resolveExperiment($campaign);
            if ($resolved === null) {
                return MailExperimentRuntimeDecision::allowed();
            }

            if ($resolved instanceof MailExperimentRuntimeDecision) {
                return $resolved;
            }

            [$experiment, $cohort] = $resolved;
            $status = $this->status($experiment);

            if ($status !== MailExperimentStatus::Running) {
                return MailExperimentRuntimeDecision::blocked('experiment_not_running');
            }

            if ($mailbox !== null) {
                $domainDecision = $this->checkManagedMailbox($experiment, $cohort, $campaign, $mailbox);
                if (! $domainDecision->allowed) {
                    return $domainDecision;
                }
            }

            $guardrail = $this->metrics->runtimeUnknownManualReview($experiment);
            if ($guardrail['breached'] === true) {
                $this->pauseExperiment(
                    $experiment,
                    'unknown_manual_review_threshold',
                    [
                        'rate' => $guardrail['rate'],
                        'threshold' => $guardrail['threshold'],
                        'attempted_count' => $guardrail['attempted_count'],
                        'unknown_manual_review_count' => $guardrail['unknown_manual_review_count'],
                    ],
                );

                return MailExperimentRuntimeDecision::blocked('unknown_manual_review_threshold', true);
            }

            if ($recipient !== null && ! $this->recipientHasFrozenAssignment($experiment, $cohort, $campaign, $recipient)) {
                // A cohort recipient without the immutable assignment row is
                // an unsafe state, but it is not evidence that the whole
                // experiment should be paused. Keep the recipient out of the
                // scheduler/send pipeline and leave the operator an explicit
                // integrity failure to investigate.
                return MailExperimentRuntimeDecision::blocked('recipient_not_in_frozen_assignment');
            }

            return MailExperimentRuntimeDecision::allowed();
        });
    }

    private function recipientHasFrozenAssignment(
        MailExperiment $experiment,
        MailExperimentCohort $cohort,
        Campaign $campaign,
        CampaignRecipient $recipient,
    ): bool {
        $workspaceId = (int) $experiment->getAttribute('workspace_id');

        if ((int) $recipient->getAttribute('workspace_id') !== $workspaceId
            || (int) $recipient->getAttribute('campaign_id') !== (int) $campaign->getKey()) {
            return false;
        }

        return MailExperimentAssignment::query()
            ->where('mail_experiment_id', $experiment->getKey())
            ->where('mail_experiment_cohort_id', $cohort->getKey())
            ->where('workspace_id', $workspaceId)
            ->where('campaign_id', $campaign->getKey())
            ->where('campaign_recipient_id', $recipient->getKey())
            ->where('email_normalized', $recipient->getAttribute('email_normalized'))
            ->whereNull('exclusion_reason')
            ->exists();
    }

    /**
     * @return array{0: MailExperiment, 1: MailExperimentCohort}|MailExperimentRuntimeDecision|null
     */
    private function resolveExperiment(Campaign $campaign): array|MailExperimentRuntimeDecision|null
    {
        $cohortId = $campaign->getAttribute('mail_experiment_cohort_id');
        $cohorts = MailExperimentCohort::query()
            ->where(function ($query) use ($campaign, $cohortId): void {
                $query->where('campaign_id', $campaign->getKey());
                if (is_numeric($cohortId)) {
                    $query->orWhere('id', (int) $cohortId);
                }
            })
            ->get();
        if ($cohorts->isEmpty()) {
            return $cohortId === null
                ? null
                : MailExperimentRuntimeDecision::blocked('experiment_link_invalid');
        }

        if ($cohorts->count() !== 1) {
            return MailExperimentRuntimeDecision::blocked('experiment_link_ambiguous');
        }

        $cohort = $cohorts->first();
        if ((int) $cohort->getAttribute('campaign_id') !== (int) $campaign->getKey()
            || ($cohortId !== null && (int) $cohortId !== (int) $cohort->getKey())) {
            return MailExperimentRuntimeDecision::blocked('experiment_link_invalid');
        }

        $experimentId = $cohort->getAttribute('mail_experiment_id');
        $experiment = is_numeric($experimentId)
            ? MailExperiment::query()
                ->whereKey((int) $experimentId)
                ->where('workspace_id', $campaign->getAttribute('workspace_id'))
                ->lockForUpdate()
                ->first()
            : null;

        if (! $experiment instanceof MailExperiment
            || (int) $experiment->getAttribute('workspace_id') !== (int) $campaign->getAttribute('workspace_id')) {
            return MailExperimentRuntimeDecision::blocked('experiment_link_invalid');
        }

        return [$experiment, $cohort];
    }

    private function checkManagedMailbox(
        MailExperiment $experiment,
        MailExperimentCohort $cohort,
        Campaign $campaign,
        Mailbox $mailbox,
    ): MailExperimentRuntimeDecision {
        $workspaceId = (int) $experiment->getAttribute('workspace_id');
        if ((int) $campaign->getAttribute('workspace_id') !== $workspaceId
            || (int) $mailbox->getAttribute('workspace_id') !== $workspaceId) {
            $this->pauseExperiment($experiment, 'workspace_mismatch');

            return MailExperimentRuntimeDecision::blocked('workspace_mismatch', true);
        }

        $attached = DB::table('mail_experiment_cohort_mailbox')
            ->where('mail_experiment_cohort_id', $cohort->getKey())
            ->where('mail_experiment_id', $experiment->getKey())
            ->where('mailbox_id', $mailbox->getKey())
            ->exists();

        $connection = ProviderConnection::query()
            ->whereKey($mailbox->getAttribute('provider_connection_id'))
            ->where('workspace_id', $workspaceId)
            ->first();
        $account = ManagedMailboxAccount::query()
            ->where('mailbox_id', $mailbox->getKey())
            ->lockForUpdate()
            ->first();

        $domain = $account?->managedMailDomain()
            ->where('workspace_id', $workspaceId)
            ->lockForUpdate()
            ->first();

        $domainKind = $this->domainKindFor($cohort);
        $valid = $attached
            && $account instanceof ManagedMailboxAccount
            && $account->getAttribute('status') === ManagedMailboxAccountStatus::Active
            && (int) $account->getAttribute('mailbox_id') === (int) $mailbox->getKey()
            && $mailbox->getAttribute('status') === ConnectionStatus::Connected
            && $connection instanceof ProviderConnection
            && (int) $connection->getAttribute('workspace_id') === $workspaceId
            && $connection->getAttribute('provider') === MailProviderName::Stalwart
            && $connection->getAttribute('connection_kind') === ConnectionKind::Managed
            && $connection->getAttribute('status') === ConnectionStatus::Connected
            && $domain instanceof ManagedMailDomain
            && $domain->getAttribute('status') === ManagedMailDomainStatus::Active
            && $domain->getAttribute('verified_at') !== null
            && (int) $domain->getAttribute('workspace_id') === $workspaceId
            && $domain->getAttribute('provider') === MailProviderName::Stalwart
            && $domainKind !== null
            && $domain->getAttribute('domain_kind') === $domainKind;

        if (! $valid) {
            $this->pauseExperiment($experiment, 'managed_domain_unavailable', [
                'mailbox_id' => (int) $mailbox->getKey(),
                'managed_mailbox_account_id' => $account?->getKey(),
                'managed_mail_domain_id' => $domain?->getKey(),
            ]);

            return MailExperimentRuntimeDecision::blocked('managed_domain_unavailable', true);
        }

        return MailExperimentRuntimeDecision::allowed();
    }

    /**
     * Pause all cohort campaigns and defer pre-provider attempts in the same
     * transaction that observed the breached guardrail. This closes the gap
     * where a scheduler has already queued several outbox events.
     *
     * @param  array<string, scalar|null>  $metadata
     */
    private function pauseExperiment(MailExperiment $experiment, string $reason, array $metadata = []): void
    {
        if ($this->status($experiment) !== MailExperimentStatus::Running) {
            return;
        }

        $workspaceId = (int) $experiment->getAttribute('workspace_id');
        $campaignIds = MailExperimentCohort::query()
            ->where('mail_experiment_id', $experiment->getKey())
            ->whereNotNull('campaign_id')
            ->pluck('campaign_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($campaignIds !== []) {
            $terminal = [
                RecipientStatus::Completed->value,
                RecipientStatus::Replied->value,
                RecipientStatus::Interested->value,
                RecipientStatus::NotNow->value,
                RecipientStatus::WrongPerson->value,
                RecipientStatus::Bounced->value,
                RecipientStatus::Unsubscribed->value,
                RecipientStatus::Removed->value,
            ];

            CampaignRecipient::query()
                ->whereIn('campaign_id', $campaignIds)
                ->where('workspace_id', $workspaceId)
                ->whereNotIn('status', $terminal)
                ->lockForUpdate()
                ->get()
                ->each(function (CampaignRecipient $recipient): void {
                    $remaining = $recipient->getAttribute('next_send_at') !== null
                        ? max(0, now()->diffInSeconds($recipient->getAttribute('next_send_at'), false))
                        : 0;

                    $recipient->forceFill([
                        'remaining_delay_seconds' => $remaining,
                        'blocked_from_status' => $recipient->getAttribute('blocked_from_status')
                            ?? $this->statusValue($recipient->getAttribute('status')),
                    ])->save();
                });

            SendAttempt::query()
                ->whereIn('campaign_recipient_id', CampaignRecipient::query()
                    ->whereIn('campaign_id', $campaignIds)
                    ->where('workspace_id', $workspaceId)
                    ->select('id'))
                ->where('workspace_id', $workspaceId)
                ->whereIn('status', [SendAttemptStatus::Scheduled->value, SendAttemptStatus::Queued->value])
                ->update([
                    'status' => SendAttemptStatus::Deferred->value,
                    'deferred_reason' => $reason,
                ]);

            Campaign::query()
                ->whereIn('id', $campaignIds)
                ->where('workspace_id', $workspaceId)
                ->where('status', CampaignStatus::Active->value)
                ->update([
                    'status' => CampaignStatus::Paused->value,
                    'paused_at' => now(),
                ]);
        }

        $incidents = $experiment->getAttribute('incidents');
        $incidents = is_array($incidents) ? $incidents : [];
        $incident = [
            'type' => 'experiment_guardrail',
            'reason' => $reason,
            'at' => now()->toIso8601String(),
            ...$metadata,
        ];
        $incidents[] = $incident;

        $experiment->forceFill([
            'status' => MailExperimentStatus::Paused,
            'incidents' => $incidents,
            'incident_count' => max((int) $experiment->getAttribute('incident_count'), count($incidents)),
        ])->save();
    }

    private function status(MailExperiment $experiment): MailExperimentStatus
    {
        $status = $experiment->getAttribute('status');

        return $status instanceof MailExperimentStatus
            ? $status
            : (MailExperimentStatus::tryFrom((string) $status) ?? MailExperimentStatus::Draft);
    }

    private function domainKindFor(MailExperimentCohort $cohort): ?ManagedMailDomainKind
    {
        $strategy = $cohort->getAttribute('domain_strategy');
        $strategy = $strategy instanceof MailExperimentDomainStrategy
            ? $strategy
            : MailExperimentDomainStrategy::tryFrom((string) $strategy);

        return $strategy === null
            ? null
            : ($strategy === MailExperimentDomainStrategy::Subdomain
            ? ManagedMailDomainKind::Subdomain
            : ManagedMailDomainKind::Root);
    }

    private function statusValue(mixed $status): string
    {
        return $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    }
}
