<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Mail\Exceptions\ClientOperationConflict;
use App\Domain\Mail\Exceptions\SenderIdentityUnavailable;
use App\Domain\Mail\Exceptions\SendOperationIncomplete;
use App\Domain\Mail\Services\ReplyToThreadService;
use App\Models\Thread;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Description('Sends real email: replies on an existing thread from its mailbox. Confirm with the user before calling. client_operation_id must be a UUID used for idempotency.')]
#[IsDestructive]
#[IsOpenWorld]
class ReplyToThread extends OutbossTool
{
    public function __construct(private ReplyToThreadService $reply) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'thread_id' => $schema->integer()
                ->required()
                ->description('The thread to reply to. Confirm with the user first.'),
            'body' => $schema->string()
                ->required()
                ->description('Reply body that will be sent to the participant.'),
            'client_operation_id' => $schema->string()
                ->required()
                ->description('UUID used to make the send idempotent. Reuse the same value to avoid a duplicate send.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::InboxSend->value)) {
            return $denied;
        }

        $data = $request->validate([
            'thread_id' => ['required', 'integer'],
            'body' => ['required', 'string'],
            'client_operation_id' => ['required', 'uuid'],
        ]);
        $thread = $this->authorised($request, Thread::class, (int) $data['thread_id'], 'update');

        try {
            $attempt = $this->reply->handle(
                $this->actor($request),
                $thread,
                $data['body'],
                $data['client_operation_id'],
            );
        } catch (SenderIdentityUnavailable) {
            return $this->senderIdentityError();
        } catch (ClientOperationConflict) {
            return Response::error(ClientOperationConflict::USER_MESSAGE);
        } catch (SendOperationIncomplete) {
            return Response::error(SendOperationIncomplete::USER_MESSAGE);
        }

        return $this->json([
            'sent' => true,
            'thread_id' => $thread->id,
            'send_attempt_id' => $attempt->id,
        ]);
    }
}
