<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\DTOs\BudgetDecision;
use App\Domain\Mail\Enums\SendAttemptKind;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\SendAttempt;
use Carbon\CarbonImmutable;

class MailboxSendBudget
{
    public const REASON_DAILY_CAP = 'daily_cap_reached';

    public const REASON_CAMPAIGN_DAILY_LIMIT = 'campaign_daily_limit_reached';

    public const REASON_SPACING = 'send_spacing';

    public const REASON_DOMAIN = 'domain_throttled';

    /**
     * @return list<SendAttemptStatus>
     */
    public static function providerReachingStatuses(): array
    {
        return [
            SendAttemptStatus::Sending,
            SendAttemptStatus::ProviderAccepted,
            SendAttemptStatus::Sent,
            SendAttemptStatus::Unknown,
            SendAttemptStatus::ManualReview,
        ];
    }

    /**
     * @return list<SendAttemptStatus>
     */
    public static function reservedStatuses(): array
    {
        return [
            SendAttemptStatus::Scheduled,
            SendAttemptStatus::Queued,
            SendAttemptStatus::LeaseAcquired,
        ];
    }

    public function dailyCap(Mailbox $mailbox): int
    {
        $override = $mailbox->getAttribute('daily_send_cap');

        if (is_numeric($override) && (int) $override > 0) {
            return (int) $override;
        }

        return max(1, (int) config('outboss.sending.default_daily_cap', 50));
    }

    public function remainingDailyBudget(Mailbox $mailbox, bool $includeReserved = false): int
    {
        return max(0, $this->dailyCap($mailbox) - $this->consumedToday($mailbox, $includeReserved));
    }

    public function campaignDailyLimit(Campaign $campaign): ?int
    {
        $limit = $campaign->getAttribute('daily_send_limit');

        return is_numeric($limit) && (int) $limit > 0 ? (int) $limit : null;
    }

    public function remainingCampaignDailyBudget(
        Campaign $campaign,
        bool $includeReserved = false,
        ?int $excludeAttemptId = null,
    ): ?int {
        $limit = $this->campaignDailyLimit($campaign);

        if ($limit === null) {
            return null;
        }

        return max(0, $limit - $this->consumedCampaignToday($campaign, $includeReserved, $excludeAttemptId));
    }

    public function domainThrottled(int $workspaceId, string $domain, bool $includeReserved = false): bool
    {
        if ($workspaceId <= 0 || $domain === '') {
            return false;
        }

        $limit = max(1, (int) config('outboss.sending.domain_hourly_limit', 6));

        return $this->domainUsage($workspaceId, $domain, $includeReserved) >= $limit;
    }

    public function nextSpacingSlot(Mailbox $mailbox): CarbonImmutable
    {
        $min = max(0, (int) config('outboss.sending.spacing_min_seconds', 45));
        $max = max($min, (int) config('outboss.sending.spacing_max_seconds', 180));
        $jitter = $max === $min ? $min : random_int($min, $max);
        $cursor = $mailbox->getAttribute('next_send_slot_at');
        $from = $cursor instanceof \DateTimeInterface
            ? CarbonImmutable::parse($cursor)
            : now();
        $from = $from->greaterThan(now()) ? $from : now();

        return CarbonImmutable::parse($from)->addSeconds($jitter);
    }

    public function spacingTooSoon(Mailbox $mailbox): bool
    {
        $min = max(0, (int) config('outboss.sending.spacing_min_seconds', 45));

        if ($min === 0) {
            return false;
        }

        $latest = SendAttempt::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('kind', SendAttemptKind::Campaign)
            ->whereNotNull('lease_acquired_at')
            ->whereIn('status', self::providerReachingStatuses())
            ->orderByDesc('lease_acquired_at')
            ->value('lease_acquired_at');

        if ($latest === null) {
            return false;
        }

        return CarbonImmutable::parse($latest)->addSeconds($min)->isFuture();
    }

    public function gate(
        Mailbox $mailbox,
        CampaignRecipient $recipient,
        ?Campaign $campaign = null,
        bool $includeReservedCampaign = false,
        ?int $excludeCampaignAttemptId = null,
    ): BudgetDecision {
        if ($this->remainingDailyBudget($mailbox) <= 0) {
            return new BudgetDecision(
                allowed: false,
                deferredReason: self::REASON_DAILY_CAP,
                retryAt: now()->utc()->addDay()->startOfDay(),
            );
        }

        if ($campaign !== null
            && $this->remainingCampaignDailyBudget(
                $campaign,
                includeReserved: $includeReservedCampaign,
                excludeAttemptId: $excludeCampaignAttemptId,
            ) === 0) {
            return new BudgetDecision(
                allowed: false,
                deferredReason: self::REASON_CAMPAIGN_DAILY_LIMIT,
                retryAt: now()->utc()->addDay()->startOfDay(),
            );
        }

        $domain = (string) ($recipient->getAttribute('email_domain') ?: '');

        if ($domain !== '' && $this->domainThrottled((int) $recipient->getAttribute('workspace_id'), $domain)) {
            return new BudgetDecision(
                allowed: false,
                deferredReason: self::REASON_DOMAIN,
                retryAt: now()->addHour(),
            );
        }

        if ($this->spacingTooSoon($mailbox)) {
            return new BudgetDecision(
                allowed: false,
                deferredReason: self::REASON_SPACING,
                retryAt: now()->addSeconds(max(1, (int) config('outboss.sending.spacing_min_seconds', 45))),
            );
        }

        return new BudgetDecision(allowed: true);
    }

    public function canSchedule(Mailbox $mailbox, CampaignRecipient $recipient, ?Campaign $campaign = null): BudgetDecision
    {
        if ($this->remainingDailyBudget($mailbox, includeReserved: true) <= 0) {
            return new BudgetDecision(
                allowed: false,
                deferredReason: self::REASON_DAILY_CAP,
                retryAt: now()->utc()->addDay()->startOfDay(),
            );
        }

        if ($campaign !== null && $this->remainingCampaignDailyBudget($campaign, includeReserved: true) === 0) {
            return new BudgetDecision(
                allowed: false,
                deferredReason: self::REASON_CAMPAIGN_DAILY_LIMIT,
                retryAt: now()->utc()->addDay()->startOfDay(),
            );
        }

        $domain = (string) ($recipient->getAttribute('email_domain') ?: '');

        if ($domain !== '' && $this->domainThrottled(
            (int) $recipient->getAttribute('workspace_id'),
            $domain,
            includeReserved: true,
        )) {
            return new BudgetDecision(
                allowed: false,
                deferredReason: self::REASON_DOMAIN,
                retryAt: now()->addHour(),
            );
        }

        return new BudgetDecision(allowed: true);
    }

    public function consumedToday(Mailbox $mailbox, bool $includeReserved): int
    {
        $query = SendAttempt::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('kind', SendAttemptKind::Campaign);

        if ($includeReserved) {
            $query->where(function ($inner) {
                $inner->where(function ($consumed) {
                    $consumed->whereNotNull('lease_acquired_at')
                        ->where('lease_acquired_at', '>=', now()->utc()->startOfDay())
                        ->whereIn('status', self::providerReachingStatuses());
                })->orWhereIn('status', self::reservedStatuses());
            });
        } else {
            $query->whereNotNull('lease_acquired_at')
                ->where('lease_acquired_at', '>=', now()->utc()->startOfDay())
                ->whereIn('status', self::providerReachingStatuses());
        }

        return $query->count();
    }

    public function consumedCampaignToday(
        Campaign $campaign,
        bool $includeReserved,
        ?int $excludeAttemptId = null,
    ): int {
        $query = SendAttempt::query()
            ->where('workspace_id', $campaign->getAttribute('workspace_id'))
            ->where('kind', SendAttemptKind::Campaign)
            ->whereHas('recipient', fn ($recipient) => $recipient
                ->where('workspace_id', $campaign->getAttribute('workspace_id'))
                ->where('campaign_id', $campaign->getKey()));

        if ($excludeAttemptId !== null) {
            $query->where('send_attempts.id', '<>', $excludeAttemptId);
        }

        if ($includeReserved) {
            $query->where(function ($inner) {
                $inner->where(function ($consumed) {
                    $consumed->whereNotNull('lease_acquired_at')
                        ->where('lease_acquired_at', '>=', now()->utc()->startOfDay())
                        ->whereIn('status', self::providerReachingStatuses());
                })->orWhereIn('status', self::reservedStatuses());
            });
        } else {
            $query->whereNotNull('lease_acquired_at')
                ->where('lease_acquired_at', '>=', now()->utc()->startOfDay())
                ->whereIn('status', self::providerReachingStatuses());
        }

        return $query->count();
    }

    private function domainUsage(int $workspaceId, string $domain, bool $includeReserved): int
    {
        $query = SendAttempt::query()
            ->where('kind', SendAttemptKind::Campaign)
            ->whereHas('recipient', fn ($recipient) => $recipient
                ->where('workspace_id', $workspaceId)
                ->where('email_domain', $domain));

        if ($includeReserved) {
            $query->where(function ($inner) {
                $inner->where(function ($consumed) {
                    $consumed->whereNotNull('lease_acquired_at')
                        ->where('lease_acquired_at', '>=', now()->subHour())
                        ->whereIn('status', self::providerReachingStatuses());
                })->orWhereIn('status', self::reservedStatuses());
            });
        } else {
            $query->whereNotNull('lease_acquired_at')
                ->where('lease_acquired_at', '>=', now()->subHour())
                ->whereIn('status', self::providerReachingStatuses());
        }

        return $query->count();
    }
}
