<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Objectives\Enums\ExecutionActionEventType;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Models\CampaignRecipient;
use App\Models\ExecutionActionEvent;
use App\Models\ExecutionPlanAction;
use App\Models\ExecutionPlanActionCompilation;
use App\Models\Message;
use App\Models\SendAttempt;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Idempotently append a safe event for an exact action and send attempt. */
final class RecordExecutionActionEvent
{
    public function forAttempt(
        SendAttempt $attempt,
        ExecutionActionEventType $event,
        ?string $reasonCode = null,
    ): ?ExecutionActionEvent {
        $recipientId = $attempt->getAttribute('campaign_recipient_id');
        $links = ExecutionPlanActionCompilation::query()
            ->where('workspace_id', $attempt->getAttribute('workspace_id'))
            ->where('campaign_recipient_id', (int) $recipientId)
            ->where('sequence_step_id', $attempt->sequence_step_id)
            ->get();
        if ($links->count() !== 1) {
            return null;
        }
        $action = ExecutionPlanAction::query()
            ->where('workspace_id', $attempt->getAttribute('workspace_id'))
            ->whereKey($links->first()->execution_plan_action_id)
            ->first();
        if (! $action instanceof ExecutionPlanAction) {
            return null;
        }
        $recipient = CampaignRecipient::query()->where('workspace_id', $action->workspace_id)->whereKey((int) $recipientId)->first();
        $message = Message::query()->where('workspace_id', $action->workspace_id)->whereKey($attempt->getAttribute('message_id'))->first();

        return $this->handle($action, $event, $recipient, $message, $attempt, $reasonCode);
    }

    public function handle(
        ExecutionPlanAction $action,
        ExecutionActionEventType $event,
        ?CampaignRecipient $recipient = null,
        ?Message $message = null,
        ?SendAttempt $attempt = null,
        ?string $reasonCode = null,
    ): ExecutionActionEvent {
        foreach ([$recipient, $message, $attempt] as $reference) {
            if ($reference !== null && (int) $reference->getAttribute('workspace_id') !== (int) $action->workspace_id) {
                throw new InvalidArgumentException('Execution event references must belong to the action workspace.');
            }
        }

        $attemptFingerprint = $attempt instanceof SendAttempt
            ? CanonicalObjectivePayload::hash([
                'version' => 'execution_action_attempt_v1',
                'action_fingerprint' => (string) $action->action_fingerprint,
                'send_attempt_id' => (int) $attempt->id,
                'send_idempotency_key' => (string) $attempt->idempotency_key,
                'event' => $event->value,
            ])
            : null;
        $sourceFingerprint = CanonicalObjectivePayload::hash([
            'version' => 'execution_action_event_v1',
            'workspace_id' => (int) $action->workspace_id,
            'action_fingerprint' => (string) $action->action_fingerprint,
            'event' => $event->value,
            'attempt_fingerprint' => $attemptFingerprint,
            'reason_code' => $reasonCode,
        ]);

        // A caller may already hold an older MySQL repeatable-read snapshot.
        // Read the current event so an idempotent retry sees a committed peer.
        return ExecutionActionEvent::query()->lockForUpdate()->firstOrCreate([
            'workspace_id' => $action->workspace_id,
            'source_event_fingerprint' => $sourceFingerprint,
        ], [
            'id' => (string) Str::uuid(),
            'execution_plan_action_id' => $action->id,
            'campaign_recipient_id' => $recipient?->id,
            'message_id' => $message?->id,
            'send_attempt_id' => $attempt?->id,
            'event' => $event,
            'provider_request_id' => $attempt?->provider_request_id,
            'provider_message_id' => $message?->remote_message_id,
            'attempt_fingerprint' => $attemptFingerprint,
            'safe_reason_code' => $reasonCode,
            'occurred_at' => now(),
            'recorded_at' => now(),
            'created_at' => now(),
        ]);
    }
}
