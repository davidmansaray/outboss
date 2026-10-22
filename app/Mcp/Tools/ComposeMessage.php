<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Mail\Exceptions\ClientOperationConflict;
use App\Domain\Mail\Exceptions\SenderIdentityUnavailable;
use App\Domain\Mail\Exceptions\SendOperationIncomplete;
use App\Domain\Mail\Services\ComposeMessageService;
use App\Models\Mailbox;
use App\Models\Message;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Description('Sends real email: composes a new message from a workspace mailbox. Confirm with the user before calling. client_operation_id must be a UUID used for idempotency.')]
#[IsDestructive]
#[IsOpenWorld]
class ComposeMessage extends OutbossTool
{
    public function __construct(private ComposeMessageService $compose) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'mailbox_id' => $schema->integer()
                ->required()
                ->description('Workspace mailbox that will send the message.'),
            'to' => $schema->string()
                ->required()
                ->description('Recipient email address.'),
            'subject' => $schema->string()
                ->required()
                ->description('Message subject.'),
            'body' => $schema->string()
                ->required()
                ->description('Message body that will be sent.'),
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
            'mailbox_id' => ['required', 'integer'],
            'to' => ['required', 'email'],
            'subject' => ['required', 'string'],
            'body' => ['required', 'string'],
            'client_operation_id' => ['required', 'uuid'],
        ]);
        $mailbox = $this->authorised($request, Mailbox::class, (int) $data['mailbox_id'], 'update');

        try {
            $attempt = $this->compose->handle(
                $this->actor($request),
                $mailbox,
                $data['to'],
                $data['subject'],
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

        $message = $attempt->message;

        return $this->json([
            'sent' => true,
            'thread_id' => $message instanceof Message ? $message->getAttribute('thread_id') : null,
            'message_id' => $attempt->message_id,
            'send_attempt_id' => $attempt->id,
        ]);
    }
}
