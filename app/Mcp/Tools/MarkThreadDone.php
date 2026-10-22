<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Mail\Enums\ThreadLocalState;
use App\Models\Thread;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Marks a thread as done in the Unibox. Does not send email.')]
class MarkThreadDone extends OutbossTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'thread_id' => $schema->integer()
                ->required()
                ->description('The thread to mark as done.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::InboxSend->value)) {
            return $denied;
        }

        $thread = $this->authorised($request, Thread::class, $request->integer('thread_id'), 'update');
        $thread->forceFill(['local_state' => ThreadLocalState::Done, 'is_unread' => false])->save();
        $state = $thread->getAttribute('local_state');

        return $this->json([
            'id' => $thread->id,
            'state' => $state instanceof ThreadLocalState ? $state->value : (string) $state,
        ]);
    }
}
