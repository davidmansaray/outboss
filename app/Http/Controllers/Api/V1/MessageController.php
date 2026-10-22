<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Exceptions\SenderIdentityUnavailable;
use App\Domain\Mail\Services\ComposeMessageService;
use App\Domain\Mail\Services\UniboxInbox;
use App\Http\Controllers\Api\V1\Concerns\MapsDomainExceptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\ComposeMessageRequest;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Thread;
use Illuminate\Http\JsonResponse;

class MessageController extends Controller
{
    use MapsDomainExceptions;

    public function store(ComposeMessageRequest $request, ComposeMessageService $compose, UniboxInbox $inbox): JsonResponse
    {
        $data = $request->validated();
        $mailbox = Mailbox::query()
            ->where('workspace_id', $request->user()->currentWorkspace()->id)
            ->whereKey((int) $data['mailbox_id'])
            ->firstOrFail();
        $this->authorize('update', $mailbox);

        try {
            $attempt = $compose->handle(
                $request->user(),
                $mailbox,
                $data['to'],
                $data['subject'],
                $data['body'],
                $data['client_operation_id'],
            );
        } catch (SenderIdentityUnavailable) {
            $this->failSenderIdentity();
        }

        $message = $attempt->message;
        $threadId = $message instanceof Message ? $message->getAttribute('thread_id') : null;
        $workspace = $request->user()->currentWorkspace();
        $thread = $threadId === null
            ? null
            : $inbox->listQuery($workspace)->whereKey((int) $threadId)->first();

        return response()->json([
            'sent' => true,
            'thread_id' => $threadId,
            'message_id' => $attempt->message_id,
            'send_attempt_id' => $attempt->id,
            'data' => [
                'thread' => $thread instanceof Thread
                    ? $inbox->threadSummary($thread, $inbox->peopleByParticipantEmail($workspace->id, collect([$thread])))
                    : null,
                'send_attempt' => [
                    'id' => $attempt->id,
                    'status' => $this->attemptStatus($attempt->getAttribute('status')),
                ],
            ],
        ], 201);
    }

    private function attemptStatus(mixed $status): string
    {
        return $status instanceof SendAttemptStatus ? $status->value : (string) $status;
    }
}
