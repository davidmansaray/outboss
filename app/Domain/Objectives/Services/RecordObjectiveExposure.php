<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Objectives\Enums\ExecutionActionEventType;
use App\Domain\Objectives\Enums\ExecutionPlanActionState;
use App\Domain\Objectives\Enums\ExposureBoundary;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Models\CampaignRecipient;
use App\Models\ExecutionPlan;
use App\Models\ExecutionPlanAction;
use App\Models\ExecutionPlanActionCompilation;
use App\Models\Exposure;
use App\Models\Message;
use App\Models\SendAttempt;
use App\Models\Thread;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Project one proven provider-accepted send into append-only commercial lineage. */
final class RecordObjectiveExposure
{
    public function __construct(private readonly RecordExecutionActionEvent $actionEvents) {}

    public function handle(int $workspaceId, int $sendAttemptId): ?Exposure
    {
        if ($workspaceId <= 0 || $sendAttemptId <= 0) {
            return null;
        }

        return DB::transaction(function () use ($workspaceId, $sendAttemptId): ?Exposure {
            $attempt = SendAttempt::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($sendAttemptId)
                ->lockForUpdate()
                ->first();
            if (! $attempt instanceof SendAttempt) {
                return null;
            }

            $status = SendAttemptStatus::tryFrom((string) $attempt->getRawOriginal('status'));
            if (! in_array($status, [SendAttemptStatus::ProviderAccepted, SendAttemptStatus::Sent], true)) {
                return null;
            }

            $recipientId = $attempt->getAttribute('campaign_recipient_id');
            $messageId = $attempt->getAttribute('message_id');
            if (! is_numeric($recipientId) || ! is_numeric($messageId)) {
                return null;
            }

            $links = ExecutionPlanActionCompilation::query()
                ->where('workspace_id', $workspaceId)
                ->where('campaign_recipient_id', (int) $recipientId)
                ->where('sequence_step_id', $attempt->getAttribute('sequence_step_id'))
                ->get();
            if ($links->count() !== 1) {
                return null;
            }
            $link = $links->first();
            $action = ExecutionPlanAction::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($link->execution_plan_action_id)
                ->lockForUpdate()
                ->first();
            $plan = $action instanceof ExecutionPlanAction
                ? ExecutionPlan::query()
                    ->where('workspace_id', $workspaceId)
                    ->whereKey($action->execution_plan_id)
                    ->first()
                : null;
            $recipient = CampaignRecipient::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey((int) $recipientId)
                ->first();
            $message = Message::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey((int) $messageId)
                ->first();
            $thread = $message instanceof Message && is_numeric($message->thread_id)
                ? Thread::query()->where('workspace_id', $workspaceId)->whereKey((int) $message->thread_id)->first()
                : null;

            if (! $action instanceof ExecutionPlanAction
                || ! $plan instanceof ExecutionPlan
                || ! $recipient instanceof CampaignRecipient
                || ! $message instanceof Message
                || ! $thread instanceof Thread
                || (string) $link->execution_plan_id !== (string) $plan->id
                || (int) $link->campaign_recipient_id !== (int) $recipient->id
                || (int) $message->campaign_recipient_id !== (int) $recipient->id
                || (int) $attempt->message_id !== (int) $message->id
                || (int) $attempt->campaign_recipient_id !== (int) $recipient->id
                || (string) $action->objective_run_arm_draft_id === '') {
                return null;
            }

            $eventType = $status === SendAttemptStatus::Sent
                ? ExecutionActionEventType::ConfirmedSent
                : ExecutionActionEventType::ProviderAccepted;
            $sourceEvent = $this->actionEvents->handle(
                $action,
                $eventType,
                $recipient,
                $message,
                $attempt,
            );

            $occurredAt = $this->firstTimestamp(
                $attempt->getAttribute('provider_accepted_at'),
                $attempt->getAttribute('sent_at'),
                $message->getAttribute('sent_at'),
            );
            if (! $occurredAt instanceof CarbonImmutable) {
                return null;
            }

            $boundary = $status === SendAttemptStatus::Sent
                ? ExposureBoundary::Sent
                : ExposureBoundary::ProviderAccepted;
            $fingerprint = CanonicalObjectivePayload::hash([
                'version' => 'objective_exposure_v1',
                'workspace_id' => $workspaceId,
                'action_id' => (string) $action->id,
                'action_fingerprint' => (string) $action->action_fingerprint,
                'send_attempt_id' => (int) $attempt->id,
                'message_id' => (int) $message->id,
                'campaign_recipient_id' => (int) $recipient->id,
            ]);

            $existing = Exposure::query()
                ->where('workspace_id', $workspaceId)
                ->where(function ($query) use ($action, $attempt): void {
                    $query->where('execution_plan_action_id', $action->id)
                        ->orWhere('send_attempt_id', $attempt->id);
                })
                ->first();
            if ($existing instanceof Exposure) {
                if ((string) $existing->execution_plan_action_id !== (string) $action->id
                    || (int) $existing->send_attempt_id !== (int) $attempt->id) {
                    throw new RuntimeException('objective_exposure_identity_conflict');
                }

                $this->projectActionState($action, $status);

                return $existing;
            }

            $exposure = Exposure::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'execution_plan_action_id' => $action->id,
                'gtm_experiment_assignment_id' => $action->gtm_experiment_assignment_id,
                'gtm_experiment_arm_id' => $action->gtm_experiment_arm_id,
                'strategy_version_id' => $action->strategy_version_id,
                'offer_version_id' => $action->offer_version_id,
                'objective_version_id' => $plan->objective_version_id,
                'objective_run_id' => $plan->objective_run_id,
                'message_id' => $message->id,
                'send_attempt_id' => $attempt->id,
                'campaign_recipient_id' => $recipient->id,
                'thread_id' => $thread->id,
                'boundary' => $boundary,
                'occurred_at' => $occurredAt,
                'recorded_at' => now(),
                'source_event_id' => $sourceEvent->id,
                'exposure_fingerprint' => $fingerprint,
                'created_at' => now(),
            ]);
            $this->projectActionState($action, $status);

            return $exposure;
        }, 3);
    }

    private function projectActionState(ExecutionPlanAction $action, SendAttemptStatus $status): void
    {
        $target = $status === SendAttemptStatus::Sent
            ? ExecutionPlanActionState::Sent
            : ExecutionPlanActionState::ProviderAccepted;
        $query = DB::table('execution_plan_actions')
            ->where('workspace_id', $action->workspace_id)
            ->where('id', $action->id);
        if ($target === ExecutionPlanActionState::ProviderAccepted) {
            $query->whereNotIn('state', [
                ExecutionPlanActionState::Sent->value,
                ExecutionPlanActionState::Failed->value,
                ExecutionPlanActionState::Cancelled->value,
            ]);
        }
        $query->update(['state' => $target->value, 'updated_at' => now()]);
    }

    private function firstTimestamp(mixed ...$values): ?CarbonImmutable
    {
        foreach ($values as $value) {
            if ($value instanceof DateTimeInterface) {
                return CarbonImmutable::instance($value);
            }
            if (is_string($value) && trim($value) !== '') {
                return CarbonImmutable::parse($value);
            }
        }

        return null;
    }
}
