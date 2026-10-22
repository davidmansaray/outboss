<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\ThreadClassification;
use App\Domain\Objectives\Enums\ExecutionActionEventType;
use App\Domain\Objectives\Enums\GtmConversationEventType;
use App\Domain\Objectives\Enums\GtmConversationState;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Models\CampaignRecipient;
use App\Models\ExecutionPlan;
use App\Models\ExecutionPlanAction;
use App\Models\ExecutionPlanActionCompilation;
use App\Models\Exposure;
use App\Models\GtmConversation;
use App\Models\GtmConversationEvent;
use App\Models\Message;
use App\Models\ObjectiveVersion;
use App\Models\Thread;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Project a safely stopped human reply onto the Objective outcome timeline. */
final class ProjectGtmConversation
{
    public function __construct(private readonly RecordExecutionActionEvent $actionEvents) {}

    public function handle(int $workspaceId, int $threadId, int $messageId): ?GtmConversation
    {
        if ($workspaceId <= 0 || $threadId <= 0 || $messageId <= 0) {
            return null;
        }

        return DB::transaction(function () use ($workspaceId, $threadId, $messageId): ?GtmConversation {
            $thread = Thread::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($threadId)
                ->lockForUpdate()
                ->first();
            $message = Message::query()
                ->where('workspace_id', $workspaceId)
                ->where('thread_id', $threadId)
                ->whereKey($messageId)
                ->first();
            if (! $thread instanceof Thread
                || ! $message instanceof Message
                || $message->getRawOriginal('direction') !== MessageDirection::Inbound->value
                || $message->getRawOriginal('classification') !== ThreadClassification::Human->value) {
                return null;
            }

            $recipientId = $message->campaign_recipient_id ?? $thread->campaign_recipient_id;
            if (! is_numeric($recipientId)) {
                return null;
            }
            $recipient = CampaignRecipient::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey((int) $recipientId)
                ->first();
            if (! $recipient instanceof CampaignRecipient
                || $recipient->getRawOriginal('status') !== RecipientStatus::Replied->value
                || $recipient->stopped_at === null) {
                // The safety stop is deliberately a precondition, not work
                // performed by this downstream projection.
                return null;
            }

            $links = ExecutionPlanActionCompilation::query()
                ->where('workspace_id', $workspaceId)
                ->where('campaign_recipient_id', $recipient->id)
                ->get();
            if ($links->count() !== 1) {
                return null;
            }
            $link = $links->first();
            $action = ExecutionPlanAction::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($link->execution_plan_action_id)
                ->first();
            $exposure = $action instanceof ExecutionPlanAction
                ? Exposure::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('execution_plan_action_id', $action->id)
                    ->first()
                : null;
            $plan = $action instanceof ExecutionPlanAction
                ? ExecutionPlan::query()
                    ->where('workspace_id', $workspaceId)
                    ->whereKey($action->execution_plan_id)
                    ->first()
                : null;
            $objectiveVersion = $plan instanceof ExecutionPlan
                ? ObjectiveVersion::query()
                    ->where('workspace_id', $workspaceId)
                    ->whereKey($plan->objective_version_id)
                    ->first()
                : null;
            if (! $action instanceof ExecutionPlanAction
                || ! $exposure instanceof Exposure
                || ! $plan instanceof ExecutionPlan
                || ! $objectiveVersion instanceof ObjectiveVersion
                || (int) $exposure->thread_id !== $threadId) {
                return null;
            }

            $conversation = GtmConversation::query()->firstOrCreate([
                'workspace_id' => $workspaceId,
                'thread_id' => $threadId,
            ], [
                'id' => (string) Str::uuid(),
                'objective_id' => $objectiveVersion->objective_id,
                'objective_version_id' => $objectiveVersion->id,
                'objective_run_id' => $plan->objective_run_id,
                'execution_plan_action_id' => $action->id,
                'exposure_id' => $exposure->id,
                'account_id' => $action->account_id,
                'person_id' => $action->person_id,
                'offer_version_id' => $action->offer_version_id,
                'owner_user_id' => null,
                'fulfilment_request_id' => null,
                'state' => GtmConversationState::NeedsReview,
                'latest_classification' => 'ambiguous',
                'classification_confidence' => null,
                'next_action' => null,
                'next_action_due_at' => null,
                'lock_version' => 1,
            ]);
            if ((string) $conversation->execution_plan_action_id !== (string) $action->id
                || (string) $conversation->exposure_id !== (string) $exposure->id) {
                return null;
            }

            foreach ([GtmConversationEventType::ReplyObserved, GtmConversationEventType::Stopped] as $eventType) {
                $fingerprint = CanonicalObjectivePayload::hash([
                    'version' => 'gtm_conversation_event_v1',
                    'workspace_id' => $workspaceId,
                    'conversation_id' => (string) $conversation->id,
                    'thread_id' => $threadId,
                    'message_id' => $messageId,
                    'event' => $eventType->value,
                ]);
                GtmConversationEvent::query()->firstOrCreate([
                    'workspace_id' => $workspaceId,
                    'event_fingerprint' => $fingerprint,
                ], [
                    'id' => (string) Str::uuid(),
                    'gtm_conversation_id' => $conversation->id,
                    'thread_id' => $threadId,
                    'message_id' => $messageId,
                    'event' => $eventType,
                    'classification' => $eventType === GtmConversationEventType::ReplyObserved ? 'ambiguous' : null,
                    'confidence' => null,
                    'source' => 'deterministic',
                    'model_version' => null,
                    'prompt_version' => null,
                    'prior_event_id' => null,
                    'safe_rationale' => $eventType === GtmConversationEventType::Stopped
                        ? 'Existing mail safety stopped future sends before projection.'
                        : 'A human inbound message was observed.',
                    'actor_user_id' => null,
                    'occurred_at' => $message->received_at ?? now(),
                    'recorded_at' => now(),
                    'created_at' => now(),
                ]);
            }

            $this->actionEvents->handle($action, ExecutionActionEventType::ReplyObserved, $recipient, $message);
            $this->actionEvents->handle($action, ExecutionActionEventType::Stopped, $recipient, $message);

            return $conversation->fresh('events');
        }, 3);
    }
}
