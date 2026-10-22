<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\DTOs\GovernedCampaignLaunchContext;
use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Enums\SequenceVersionStatus;
use App\Domain\Campaigns\Support\SendWindowCalculator;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Services\FrozenMailExperimentInputGuard;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\ExecutionPlanActionCompilation;
use App\Models\SendAttempt;
use App\Models\SequenceVersion;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class LaunchCampaignService
{
    public function __construct(
        private SendWindowCalculator $windows,
        private PublishSequenceVersion $versions,
        private CampaignLaunchReadiness $readiness,
        private FrozenMailExperimentInputGuard $frozenInputs,
        private GovernedCampaignInputGuard $governedInputs,
    ) {}

    public function handle(Campaign $campaign, User $actor): Campaign
    {
        $this->governedInputs->assertGenericLaunchAllowed($campaign);
        $this->assertActor($campaign, $actor);

        return DB::transaction(function () use ($campaign): Campaign {
            $campaign = Campaign::query()
                ->where('workspace_id', $campaign->getAttribute('workspace_id'))
                ->whereKey($campaign->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->governedInputs->assertGenericLaunchAllowed($campaign);
            $this->frozenInputs->assertCampaignMutable($campaign);

            return $this->launchLocked($campaign);
        }, 3);
    }

    /** Internal boundary for the existing operational MailExperiment lifecycle. */
    public function handleTrustedLifecycle(Campaign $campaign): Campaign
    {
        if (! FrozenMailExperimentInputGuard::lifecycleMutationIsTrusted()) {
            throw new LogicException('Trusted Campaign lifecycle launch is not active.');
        }
        $this->governedInputs->assertGenericLaunchAllowed($campaign);

        return DB::transaction(function () use ($campaign): Campaign {
            $locked = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $this->governedInputs->assertGenericLaunchAllowed($locked);

            return $this->launchLocked($locked);
        }, 3);
    }

    /** Called only with the callback-scoped capability issued by LaunchObjectiveExecution. */
    public function handleGoverned(
        Campaign $campaign,
        string $planId,
        GovernedCampaignLaunchContext $context,
    ): Campaign {
        if (! $context->authorizes($campaign, $planId)) {
            throw new LogicException('The governed Objective launch context is not active for this Campaign.');
        }

        return $this->governedInputs->runCampaignLaunch(
            $campaign,
            function () use ($campaign, $planId): Campaign {
                $launched = ($this->isCampaignStatus($campaign->getAttribute('status'), CampaignStatus::Paused)
                    || $this->isCampaignStatus($campaign->getAttribute('status'), CampaignStatus::Completed))
                    ? $this->resumeLocked($campaign, false)
                    : $this->launchLocked($campaign, false);
                $links = ExecutionPlanActionCompilation::query()
                    ->where('workspace_id', $campaign->getAttribute('workspace_id'))
                    ->where('execution_plan_id', $planId)
                    ->where('campaign_id', $campaign->id)
                    ->with('action')
                    ->get();
                foreach ($links as $link) {
                    $action = $link->action;
                    if ($action === null || (int) $action->workspace_id !== (int) $campaign->getAttribute('workspace_id')) {
                        throw new LogicException('The governed Campaign action window is unavailable.');
                    }
                    $campaign->recipients()
                        ->where('workspace_id', $campaign->getAttribute('workspace_id'))
                        ->whereKey($link->campaign_recipient_id)
                        ->where('status', RecipientStatus::Active)
                        ->where('next_send_at', '<', $action->scheduled_not_before)
                        ->update(['next_send_at' => $action->scheduled_not_before]);
                }

                return $launched;
            },
        );
    }

    private function launchLocked(Campaign $campaign, bool $checkReadiness = true): Campaign
    {
        if ($checkReadiness) {
            $this->assertReady($campaign);
        }

        $version = $campaign->draftVersion ?? $campaign->publishedVersion;
        if (! $version instanceof SequenceVersion) {
            throw ValidationException::withMessages(['sequence' => 'A sequence version is required.']);
        }
        $steps = $version->activeSteps;
        $previousPublishedId = $campaign->getAttribute('published_sequence_version_id');
        $versionId = $version->getKey();

        if ($previousPublishedId && $versionId && (int) $previousPublishedId !== (int) $versionId) {
            SequenceVersion::query()->whereKey($previousPublishedId)->update([
                'status' => SequenceVersionStatus::Retired->value,
            ]);
        }

        if ($version->getAttribute('status') !== SequenceVersionStatus::Published) {
            $version->forceFill([
                'status' => SequenceVersionStatus::Published,
                'published_at' => now(),
            ])->save();
        }

        $campaign->recipients()
            ->where('workspace_id', $campaign->getAttribute('workspace_id'))
            ->where('status', RecipientStatus::Queued)
            ->update([
                'status' => RecipientStatus::Active,
                'sequence_version_id' => $version->id,
                'next_sequence_step_id' => $steps->first()->id,
                'next_send_at' => now(),
            ]);

        $campaign->forceFill([
            'status' => CampaignStatus::Active,
            'published_sequence_version_id' => $version->id,
            'launched_at' => $campaign->launched_at ?? now(),
            'paused_at' => null,
            'completed_at' => null,
        ])->save();

        return $campaign->fresh();
    }

    public function pause(Campaign $campaign): Campaign
    {
        return $this->governedInputs->runCampaignPause($campaign, function () use ($campaign): Campaign {
            $this->snapshotAndDeferOpenWork($campaign, 'campaign_paused');

            $campaign->forceFill([
                'status' => CampaignStatus::Paused,
                'paused_at' => now(),
            ])->save();

            return $campaign->fresh();
        });
    }

    public function complete(Campaign $campaign): Campaign
    {
        $status = $campaign->getAttribute('status');

        if ($this->isCampaignStatus($status, CampaignStatus::Draft)) {
            throw ValidationException::withMessages([
                'status' => 'Draft campaigns have nothing to complete.',
            ]);
        }

        if ($this->isCampaignStatus($status, CampaignStatus::Completed)) {
            throw ValidationException::withMessages([
                'status' => 'This campaign is already completed.',
            ]);
        }

        return $this->governedInputs->runCampaignComplete($campaign, function () use ($campaign): Campaign {
            if (! FrozenMailExperimentInputGuard::lifecycleMutationIsTrusted()) {
                $this->frozenInputs->assertCampaignMutable($campaign);
            }

            $this->snapshotAndDeferOpenWork($campaign, 'campaign_completed');

            $campaign->forceFill([
                'status' => CampaignStatus::Completed,
                'completed_at' => now(),
                'paused_at' => null,
            ])->save();

            return $campaign->fresh();
        });
    }

    public function resume(Campaign $campaign, User $actor): Campaign
    {
        $this->governedInputs->assertGenericLaunchAllowed($campaign);
        $this->assertActor($campaign, $actor);

        return DB::transaction(function () use ($campaign): Campaign {
            $campaign = Campaign::query()
                ->where('workspace_id', $campaign->getAttribute('workspace_id'))
                ->whereKey($campaign->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->governedInputs->assertGenericLaunchAllowed($campaign);
            $this->frozenInputs->assertCampaignMutable($campaign);

            return $this->resumeLocked($campaign);
        }, 3);
    }

    public function resumeTrustedLifecycle(Campaign $campaign): Campaign
    {
        if (! FrozenMailExperimentInputGuard::lifecycleMutationIsTrusted()) {
            throw new LogicException('Trusted Campaign lifecycle resume is not active.');
        }
        $this->governedInputs->assertGenericLaunchAllowed($campaign);

        return DB::transaction(function () use ($campaign): Campaign {
            $locked = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $this->governedInputs->assertGenericLaunchAllowed($locked);

            return $this->resumeLocked($locked);
        }, 3);
    }

    private function resumeLocked(Campaign $campaign, bool $checkReadiness = true): Campaign
    {
        $previousPublishedId = $campaign->published_sequence_version_id;
        $this->launchLocked($campaign, $checkReadiness);
        $campaign->refresh();

        if ($previousPublishedId && $campaign->published_sequence_version_id !== $previousPublishedId) {
            $this->versions->migrateUnstarted($campaign, $campaign->publishedVersion);
        }

        $campaign->recipients()
            ->where('workspace_id', $campaign->getAttribute('workspace_id'))
            ->whereNotNull('remaining_delay_seconds')
            ->whereIn('status', [RecipientStatus::Active, RecipientStatus::Queued, RecipientStatus::Paused])
            ->each(function (CampaignRecipient $recipient) use ($campaign): void {
                $due = $this->windows->nextAllowed(
                    now()->addSeconds((int) $recipient->remaining_delay_seconds),
                    $campaign->timezone,
                    (string) $campaign->send_window_start,
                    (string) $campaign->send_window_end,
                    $campaign->business_days_only,
                );

                $recipient->forceFill([
                    'next_send_at' => $due,
                    'remaining_delay_seconds' => null,
                ])->save();
            });

        return $campaign->fresh();
    }

    private function assertActor(Campaign $campaign, User $actor): void
    {
        $workspaceId = (int) $campaign->getAttribute('workspace_id');
        if ($workspaceId < 1
            || (int) $actor->currentWorkspace()->id !== $workspaceId
            || ! WorkspaceMembership::query()
                ->where('workspace_id', $workspaceId)
                ->where('user_id', $actor->id)
                ->exists()) {
            throw new AuthorizationException('The Campaign is not available in the actor workspace.');
        }
    }

    private function assertReady(Campaign $campaign): void
    {
        $blockers = $this->readiness->blockers($campaign);

        if ($blockers === []) {
            return;
        }

        $errors = [];

        foreach ($blockers as $blocker) {
            $key = match ($blocker['section']) {
                'audience' => 'people',
                'sequence' => 'sequence',
                'sending' => 'mailboxes',
            };
            $errors[$key] = $blocker['message'];
        }

        throw ValidationException::withMessages($errors);
    }

    private function snapshotAndDeferOpenWork(Campaign $campaign, string $deferredReason): void
    {
        $campaign->recipients()
            ->where('workspace_id', $campaign->getAttribute('workspace_id'))
            ->whereNotIn('status', array_map(fn (RecipientStatus $status) => $status->value, [
                RecipientStatus::Completed,
                RecipientStatus::Replied,
                RecipientStatus::Interested,
                RecipientStatus::NotNow,
                RecipientStatus::WrongPerson,
                RecipientStatus::Bounced,
                RecipientStatus::Unsubscribed,
                RecipientStatus::Removed,
            ]))
            ->each(function (CampaignRecipient $recipient): void {
                $remaining = $recipient->next_send_at
                    ? max(0, now()->diffInSeconds($recipient->next_send_at, false))
                    : 0;

                $recipient->forceFill([
                    'remaining_delay_seconds' => $remaining,
                    'blocked_from_status' => $recipient->blocked_from_status ?? $recipient->status->value,
                ])->save();
            });

        SendAttempt::query()
            ->whereIn('campaign_recipient_id', $campaign->recipients()->pluck('id'))
            ->where('workspace_id', $campaign->getAttribute('workspace_id'))
            ->whereIn('status', [SendAttemptStatus::Scheduled, SendAttemptStatus::Queued])
            ->update(['status' => SendAttemptStatus::Deferred, 'deferred_reason' => $deferredReason]);
    }

    private function isCampaignStatus(mixed $status, CampaignStatus $expected): bool
    {
        return $status === $expected || $status === $expected->value;
    }
}
