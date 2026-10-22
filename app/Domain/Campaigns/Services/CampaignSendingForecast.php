<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\Support\SendWindowCalculator;
use App\Domain\Objectives\Services\ExecutionAllowanceLedger;
use App\Models\Campaign;
use App\Models\CampaignComposition;
use App\Models\CampaignRecipient;
use App\Models\ExecutionAuthorityScope;
use App\Models\ExecutionPlan;
use App\Models\SendAttempt;
use Carbon\CarbonImmutable;

/** Read-only, conservative estimate. Runtime safety gates remain authoritative. */
final class CampaignSendingForecast
{
    public const MAX_FORECAST_WORK_ITEMS = 1000;

    /** @return array<string, mixed> */
    public function handle(Campaign $campaign, ?CampaignComposition $composition): array
    {
        $workspaceId = (int) $campaign->getAttribute('workspace_id');
        $recipientQuery = $campaign->recipients()->where('workspace_id', $workspaceId)->humanReviewAudience();
        $recipientCount = (clone $recipientQuery)->count();
        $recipients = $recipientQuery->orderBy('id')->limit(self::MAX_FORECAST_WORK_ITEMS)->get();
        $steps = ($campaign->draftVersion ?? $campaign->publishedVersion)?->activeSteps;
        $mailboxes = $campaign->mailboxes()->where('mailboxes.workspace_id', $workspaceId)
            ->where('mailboxes.status', 'connected')->whereNull('mailboxes.sending_paused_at')
            ->whereHas('connection', fn ($query) => $query->where('workspace_id', $workspaceId)->where('status', 'connected'))->get()->keyBy('id');
        $scope = $composition?->execution_authority_scope_id === null ? null : ExecutionAuthorityScope::query()
            ->where('workspace_id', $workspaceId)->whereKey($composition->execution_authority_scope_id)->first();
        $budget = app(MailboxSendBudget::class);
        $inboxCapacity = $mailboxes->sum(fn ($mailbox) => $budget->dailyCap($mailbox));
        $target = $campaign->getAttribute('daily_send_limit');
        $result = ['status' => 'incomplete', 'messages' => $recipientCount * ($steps?->count() ?? 0),
            'estimated_finish_at' => null, 'deadline_at' => $composition?->sending_ends_at?->toAtomString(),
            'messages_by_deadline' => 0, 'shortfall' => 0, 'mailboxes' => $mailboxes->count(),
            'daily_capacity' => $target === null ? $inboxCapacity : min((int) $target, $inboxCapacity), 'competing_messages' => 0,
            'inbox_daily_capacity' => $inboxCapacity, 'requested_daily_limit' => $target,
            'message' => 'Save an allowance, prospects, messages and sending inboxes to estimate completion.'];
        if ($campaign->launched_at !== null) {
            return [...$result, 'status' => 'launched', 'message' => 'This campaign has launched. Use exact review and recipient delivery status to inspect remaining work.'];
        }
        if ($result['messages'] > self::MAX_FORECAST_WORK_ITEMS) {
            return $this->tooLarge($result);
        }
        if ($target !== null && (int) $target > $inboxCapacity) {
            return [...$result, 'status' => 'daily_limit_exceeded',
                'message' => "Your target is {$target} messages/day, but the selected ready inboxes support {$inboxCapacity}. Select more inboxes, explicitly increase inbox limits, or lower the campaign target."];
        }
        if ($scope !== null) {
            $committed = app(ExecutionAllowanceLedger::class)->committed($workspaceId, $scope->id);
            $currentPlan = ExecutionPlan::query()->where('workspace_id', $workspaceId)->where('origin', 'human')
                ->whereNotIn('status', ['cancelled', 'superseded'])
                ->whereHas('objectiveRun', fn ($query) => $query->where('workspace_id', $workspaceId)->where('campaign_composition_id', $composition->id))
                ->latest('created_at')->first();
            $own = $currentPlan === null ? 0 : $committed->where('execution_plan_id', $currentPlan->id)->count();
            $limits = $scope->authority;
            $usage = [
                'messages' => $committed->count(),
                'people' => $committed->pluck('person_id')->unique()->count(),
                'accounts' => $committed->pluck('account_id')->unique()->count(),
            ];
            $projected = [
                'messages' => $committed->count() + max(0, $result['messages'] - $own),
                'people' => $committed->pluck('person_id')->merge($recipients->map(fn ($r) => $r->person_id ?? 'unlinked:'.$r->id))->unique()->count(),
                'accounts' => $committed->pluck('account_id')->merge($recipients->map(fn ($r) => $r->account_id ?? 'unlinked:'.$r->id))->unique()->count(),
            ];
            $exceeded = false;
            $quantities = [];
            foreach (['messages', 'people', 'accounts'] as $kind) {
                $maximum = (int) ($limits['maximum_'.$kind] ?? 0);
                $quantities[$kind] = ['maximum' => $maximum, 'committed' => $usage[$kind], 'remaining' => max(0, $maximum - $usage[$kind]), 'projected' => $projected[$kind]];
                $exceeded = $exceeded || $projected[$kind] > $maximum;
            }
            $result['allowance'] = ['name' => $scope->name, 'quantities' => $quantities, 'exceeded' => $exceeded];
            if ($exceeded) {
                return [...$result, 'status' => 'allowance_exceeded', 'message' => 'This campaign exceeds the remaining shared sending allowance. Choose an adequate allowance or reduce the audience. Expired approvals still count as committed.'];
            }
        }
        if ($scope === null || $recipients->isEmpty() || $steps === null || $steps->isEmpty() || $mailboxes->isEmpty()) {
            return $result;
        }
        // The current exact human compiler accepts one immediate email only.
        // Do not imply that a multi-step forecast makes that sequence launchable.
        if ($steps->count() !== 1 || (int) $steps->sole()->delay_minutes !== 0) {
            return [...$result, 'status' => 'unsupported', 'message' => 'Exact human review currently supports one immediate email per prospect. Follow-up sequences cannot be approved yet.'];
        }
        if ($recipients->contains(fn ($recipient) => ! $mailboxes->has($recipient->mailbox_id))) {
            return [...$result, 'message' => 'Assign every prospect to a connected selected inbox in Sending settings.'];
        }
        $budget = app(MailboxSendBudget::class);
        $windows = app(SendWindowCalculator::class);
        $start = CarbonImmutable::now()->max(CarbonImmutable::parse($scope->authority['starts_at']));
        if ($composition->sending_starts_at !== null) {
            $start = $start->max($composition->sending_starts_at);
        }
        $limit = CarbonImmutable::parse($scope->authority['ends_at']);
        $deadline = $composition->sending_ends_at === null ? null : CarbonImmutable::parse($composition->sending_ends_at);
        $spacing = max(1, (int) config('outboss.sending.spacing_max_seconds', 180),
            (int) config('outboss.sending.spacing_min_seconds', 45),
            (int) ceil(60 / max(1, (int) config('outboss.send_rate_per_minute', 10))));
        $domainLimit = max(1, (int) config('outboss.sending.domain_hourly_limit', 6));
        $used = [];
        $cursors = [];
        $caps = [];
        foreach ($mailboxes as $mailbox) {
            $caps[$mailbox->id] = $budget->dailyCap($mailbox);
            $used[$mailbox->id][CarbonImmutable::now()->utc()->toDateString()] =
                $budget->consumedToday($mailbox, includeReserved: true);
            $cursors[$mailbox->id] = $mailbox->next_send_slot_at === null ? $start : $start->max($mailbox->next_send_slot_at);
        }
        $campaignUsed = [];
        // Reserve outstanding work from active campaigns before this draft.
        // This deliberately does not promise capacity that another campaign owns.
        $competingQuery = CampaignRecipient::query()->where('workspace_id', $workspaceId)
            ->where('campaign_id', '!=', $campaign->id)->whereIn('mailbox_id', $mailboxes->keys())
            ->whereIn('status', ['queued', 'active'])
            ->whereHas('campaign', fn ($query) => $query->where('workspace_id', $workspaceId)->where('status', 'active'));
        if ((clone $competingQuery)->count() + $result['messages'] > self::MAX_FORECAST_WORK_ITEMS) {
            return $this->tooLarge($result);
        }
        $competing = $competingQuery->with(['sequenceVersion.activeSteps', 'nextStep'])->get();
        // Move matching reservations from the seed into their one simulated
        // work item, preserving its due time. Unrelated reservations stay seeded.
        $reservations = SendAttempt::query()->where('workspace_id', $workspaceId)
            ->where('kind', 'campaign')->whereIn('status', MailboxSendBudget::reservedStatuses())
            ->whereIn('campaign_recipient_id', $competing->modelKeys())
            ->orderBy('id')->limit(self::MAX_FORECAST_WORK_ITEMS + 1)
            ->get(['id', 'campaign_recipient_id', 'sequence_step_id', 'mailbox_id', 'due_at']);
        if ($reservations->count() > self::MAX_FORECAST_WORK_ITEMS) {
            return $this->tooLarge($result);
        }
        $reservedSteps = $reservations->groupBy(fn ($attempt) => $attempt->campaign_recipient_id.':'.$attempt->sequence_step_id.':'.$attempt->mailbox_id);
        $representedReservations = [];
        $work = [];
        foreach ($competing as $recipient) {
            $remaining = $recipient->sequenceVersion?->activeSteps
                ->filter(fn ($step) => (int) $step->position >= (int) ($recipient->nextStep->position ?? 1))->count() ?? 0;
            if (count($work) + $remaining + $result['messages'] > self::MAX_FORECAST_WORK_ITEMS) {
                return $this->tooLarge($result);
            }
            for ($i = 0; $i < $remaining; $i++) {
                $reservation = $i === 0 ? $reservedSteps->get($recipient->id.':'.$recipient->next_sequence_step_id.':'.$recipient->mailbox_id)?->first() : null;
                if ($reservation !== null) {
                    $representedReservations[$reservation->id] = true;
                    $used[$recipient->mailbox_id][CarbonImmutable::now()->utc()->toDateString()]--;
                }
                $work[] = ['recipient' => $recipient, 'ours' => false, 'not_before' => $reservation?->due_at];
            }
        }
        $result['competing_messages'] = count($work);
        foreach ($recipients as $recipient) {
            $work[] = ['recipient' => $recipient, 'ours' => true];
        }
        $domains = [];
        $recentQuery = SendAttempt::query()->where('workspace_id', $workspaceId)->where('kind', 'campaign')
            ->where(function ($query): void {
                $query->whereIn('status', MailboxSendBudget::reservedStatuses())
                    ->orWhere(fn ($used) => $used->whereIn('status', MailboxSendBudget::providerReachingStatuses())
                        ->where('lease_acquired_at', '>=', now()->subHour()));
            });
        if ((clone $recentQuery)->count() + count($work) > self::MAX_FORECAST_WORK_ITEMS) {
            return $this->tooLarge($result);
        }
        $recentAttempts = $recentQuery->with('recipient')->get();
        foreach ($recentAttempts as $attempt) {
            if (isset($representedReservations[$attempt->id])) {
                continue;
            }
            $domain = strtolower((string) $attempt->recipient?->getAttribute('email_domain'));
            $domains[$domain][] = $attempt->lease_acquired_at === null ? CarbonImmutable::now() : CarbonImmutable::parse($attempt->lease_acquired_at);
        }
        $finish = null;
        $scheduled = 0;
        foreach ($work as $item) {
            $recipient = $item['recipient'];
            $id = $recipient->mailbox_id;
            $domain = strtolower((string) ($recipient->email_domain ?: substr(strrchr($recipient->email_normalized, '@') ?: '', 1)));
            $at = $cursors[$id];
            if (($item['not_before'] ?? null) !== null) {
                $at = $at->max($item['not_before']);
            }
            // Bounded by the existing finite allowance; never loop indefinitely.
            while ($at->lessThan($limit)) {
                $at = $windows->nextAllowed($at, $campaign->timezone, (string) $campaign->send_window_start,
                    (string) $campaign->send_window_end, $campaign->business_days_only);
                if (! $at->lessThan($limit)) {
                    break;
                }
                $day = $at->utc()->toDateString();
                if ($item['ours'] && $target !== null && ($campaignUsed[$day] ?? 0) >= (int) $target) {
                    $at = $at->utc()->addDay()->startOfDay();

                    continue;
                }
                if (($used[$id][$day] ?? 0) >= $caps[$id]) {
                    $at = $at->utc()->addDay()->startOfDay();

                    continue;
                }
                $domainSlot = $this->domainSlot($domains[$domain] ?? [], $at, $domainLimit);
                if ($domainSlot->greaterThan($at)) {
                    $at = $domainSlot;

                    continue;
                }
                $domains[$domain][] = $at;
                $used[$id][$day] = ($used[$id][$day] ?? 0) + 1;
                $cursors[$id] = $at->addSeconds($spacing);
                if ($item['ours']) {
                    $campaignUsed[$day] = ($campaignUsed[$day] ?? 0) + 1;
                    $scheduled++;
                    $finish = $finish === null ? $at : $finish->max($at);
                    if ($deadline === null || $at->lessThan($deadline)) {
                        $result['messages_by_deadline']++;
                    }
                }
                break;
            }
        }
        $fits = $scheduled === $result['messages'] && ($deadline === null || $result['messages_by_deadline'] === $result['messages']);
        $noSendingHours = $deadline !== null && ! $windows->nextAllowed($start, $campaign->timezone,
            (string) $campaign->send_window_start, (string) $campaign->send_window_end, $campaign->business_days_only)->lessThan($deadline);

        return [...$result, 'status' => $fits ? 'fits' : 'over_capacity',
            'estimated_finish_at' => $scheduled === $result['messages'] ? $finish?->toAtomString() : null,
            'shortfall' => $result['messages'] - $result['messages_by_deadline'],
            'message' => $fits ? 'The saved campaign fits the estimated available capacity.'
                : ($noSendingHours ? 'No sending hours fall before this deadline. Adjust sending hours or extend/remove the deadline; extra inboxes or higher limits cannot solve this timing conflict.'
                    : 'The saved campaign does not fit. Add inboxes and redistribute prospects, increase inbox limits explicitly, or extend/remove the deadline. The sending allowance may also need extending.')];
    }

    /** @param list<CarbonImmutable> $history */
    private function domainSlot(array $history, CarbonImmutable $at, int $limit): CarbonImmutable
    {
        // Different inboxes can be forecast out of timestamp order. Retain the
        // whole bounded history and check every rolling window containing this
        // candidate, including already planned sends later than the candidate.
        foreach ([...$history, $at] as $windowStart) {
            if ($windowStart->greaterThan($at) || $windowStart->addHour()->lessThan($at)) {
                continue;
            }
            $inWindow = array_filter($history, fn ($time) => $time->greaterThanOrEqualTo($windowStart)
                && $time->lessThanOrEqualTo($windowStart->addHour()));
            if (count($inWindow) >= $limit) {
                // Match the scheduler's one-hour domain retry backoff rather
                // than promise a send immediately after the oldest lease ages out.
                return $at->addHour()->max($windowStart->addHour()->addSecond());
            }
        }

        return $at;
    }

    /** @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function tooLarge(array $result): array
    {
        return [...$result, 'status' => 'unavailable',
            'message' => 'The current shared workload is too large for an interactive estimate. No deadline fit has been verified. Reduce the draft audience or retry when active work has cleared.'];
    }
}
