<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Services\UniboxInbox;
use App\Models\Message;
use App\Models\Thread;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Returns one thread with a concise message list (text bodies only; HTML is omitted). Does not mark the thread as read. Does not mutate data.')]
#[IsReadOnly]
class GetThread extends OutbossTool
{
    public function __construct(private UniboxInbox $inbox) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'thread_id' => $schema->integer()
                ->required()
                ->description('The thread to load.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::InboxRead->value)) {
            return $denied;
        }

        $thread = $this->authorised($request, Thread::class, $request->integer('thread_id'));
        $workspaceId = (int) $thread->getAttribute('workspace_id');
        $thread->load([
            'mailbox',
            'recipient.campaign',
            'recipient.person' => fn ($query) => $query->where('workspace_id', $workspaceId),
            'recipient.account' => fn ($query) => $query->where('workspace_id', $workspaceId),
            'latestSentMessage' => fn ($query) => $query->where('workspace_id', $workspaceId),
            'messages' => fn ($query) => $query
                ->where('workspace_id', $workspaceId)
                ->where('mailbox_id', $thread->getAttribute('mailbox_id')),
        ]);
        $thread->loadExists(['messages as has_inbound' => fn (Builder $messages) => $messages
            ->where('direction', MessageDirection::Inbound)
            ->where('workspace_id', $workspaceId)
            ->where('mailbox_id', $thread->getAttribute('mailbox_id'))]);

        return $this->json([
            ...$this->inbox->threadSummary(
                $thread,
                $this->inbox->peopleByParticipantEmail($workspaceId, collect([$thread])),
            ),
            'messages' => $thread->messages
                ->map(function (Message $message): array {
                    $payload = $this->inbox->messagePayload($message);
                    unset($payload['html']);

                    return $payload;
                })
                ->values()
                ->all(),
        ]);
    }
}
