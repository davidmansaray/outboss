<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\ThreadClassification;
use App\Domain\Objectives\Enums\GtmConversationEventType;
use App\Models\Message;

/** Bounded replay of human replies whose mail stop already committed. */
final class ReconcileObjectiveConversations
{
    public function __construct(private readonly ProjectGtmConversation $project) {}

    public function handle(?int $workspaceId = null, int $limit = 100): int
    {
        $messages = Message::query()
            ->when($workspaceId !== null, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->where('direction', MessageDirection::Inbound->value)
            ->where('classification', ThreadClassification::Human->value)
            ->whereHas('recipient', fn ($recipient) => $recipient
                ->where('status', RecipientStatus::Replied->value)
                ->whereNotNull('stopped_at')
                ->whereExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('execution_plan_action_compilations')
                        ->whereColumn('execution_plan_action_compilations.workspace_id', 'campaign_recipients.workspace_id')
                        ->whereColumn('execution_plan_action_compilations.campaign_recipient_id', 'campaign_recipients.id');
                }))
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('gtm_conversation_events')
                    ->whereColumn('gtm_conversation_events.workspace_id', 'messages.workspace_id')
                    ->whereColumn('gtm_conversation_events.message_id', 'messages.id')
                    ->where('gtm_conversation_events.event', GtmConversationEventType::ReplyObserved->value);
            })
            ->orderBy('id')
            ->limit(max(1, min($limit, 500)))
            ->get(['id', 'workspace_id', 'thread_id']);

        $repaired = 0;
        foreach ($messages as $message) {
            $conversation = $this->project->handle(
                (int) $message->getAttribute('workspace_id'),
                (int) $message->getAttribute('thread_id'),
                (int) $message->id,
            );
            if ($conversation !== null) {
                $repaired++;
            }
        }

        return $repaired;
    }
}
