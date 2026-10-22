<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Enums\ThreadLocalState;
use App\Domain\Mail\Exceptions\SenderIdentityUnavailable;
use App\Domain\Mail\Services\ApplyInboundCampaignEffects;
use App\Domain\Mail\Services\ReplyToThreadService;
use App\Domain\Mail\Services\UniboxInbox;
use App\Http\Controllers\Api\V1\Concerns\MapsDomainExceptions;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Thread;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ThreadController extends Controller
{
    use MapsDomainExceptions;

    public function __construct(private UniboxInbox $inbox) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'unread' => ['nullable', 'boolean'],
            'pagination' => ['nullable', 'in:page,cursor'],
            'cursor' => ['nullable', 'string', 'max:500'],
        ]);
        $folder = $this->inbox->normalizeFolder($request->query('folder'));
        $mailboxId = $request->integer('mailbox') ?: null;
        $campaign = $this->inbox->campaignFilter($request->query('campaign'), $workspace);
        $perPage = min(100, max(1, $request->integer('per_page', 50)));

        $query = $this->inbox->listQuery($workspace)
            ->tap(fn (Builder $query) => $this->inbox->applyFolder($query, $folder))
            ->tap(fn (Builder $query) => $this->inbox->applyCampaignAndMailbox($query, $mailboxId, $campaign))
            ->tap(fn (Builder $query) => $this->inbox->applySearch($query, $filters['q'] ?? null))
            ->tap(fn (Builder $query) => $this->inbox->applyUnread($query, $request->boolean('unread')));

        if (($filters['pagination'] ?? 'page') === 'cursor') {
            $page = $this->inbox->cursorPage($query, $filters['cursor'] ?? null, $perPage);

            return response()->json([
                'data' => $this->summaries($workspace->id, $page['items']),
                'meta' => [
                    'per_page' => $perPage,
                    'next_cursor' => $page['next_cursor'],
                    'prev_cursor' => null,
                ],
            ]);
        }

        $threads = $query
            ->orderByDesc('last_message_at')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'data' => $this->summaries($workspace->id, $threads->getCollection()),
            'links' => [
                'first' => $threads->url(1),
                'last' => $threads->url($threads->lastPage()),
                'prev' => $threads->previousPageUrl(),
                'next' => $threads->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $threads->currentPage(),
                'from' => $threads->firstItem(),
                'last_page' => $threads->lastPage(),
                'per_page' => $threads->perPage(),
                'to' => $threads->lastItem(),
                'total' => $threads->total(),
            ],
        ]);
    }

    public function changes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'since' => ['nullable', 'string', 'max:500'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        $changes = $this->inbox->changes(
            $request->user()->currentWorkspace(),
            $data['since'] ?? null,
            (int) ($data['limit'] ?? 200),
        );

        return response()->json([
            'data' => $changes['rows'],
            'meta' => [
                'next_since' => $changes['next_since'],
                'has_more' => $changes['has_more'],
            ],
        ]);
    }

    public function read(Thread $thread): JsonResponse
    {
        return $this->setUnread($thread, false);
    }

    public function unread(Thread $thread): JsonResponse
    {
        return $this->setUnread($thread, true);
    }

    public function show(Thread $thread): JsonResponse
    {
        $this->authorize('view', $thread);
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

        return response()->json([
            'data' => [
                ...$this->inbox->threadSummary(
                    $thread,
                    $this->inbox->peopleByParticipantEmail($workspaceId, collect([$thread])),
                ),
                'messages' => $thread->messages
                    ->map(fn (Message $message) => $this->inbox->messagePayload($message))
                    ->values()
                    ->all(),
            ],
        ]);
    }

    public function reply(Request $request, Thread $thread, ReplyToThreadService $reply): JsonResponse
    {
        $this->authorize('update', $thread);
        $data = $request->validate([
            'body' => ['required', 'string'],
            'client_operation_id' => ['required', 'uuid'],
        ]);

        try {
            $attempt = $reply->handle($request->user(), $thread, $data['body'], $data['client_operation_id']);
        } catch (SenderIdentityUnavailable) {
            $this->failSenderIdentity('body');
        }

        return response()->json([
            'sent' => true,
            'thread_id' => $thread->id,
            'send_attempt_id' => $attempt->id,
            'data' => [
                'thread' => $this->summary($thread),
                'send_attempt' => [
                    'id' => $attempt->id,
                    'status' => $this->attemptStatus($attempt->getAttribute('status')),
                ],
            ],
        ]);
    }

    public function done(Thread $thread): JsonResponse
    {
        $this->authorize('update', $thread);
        $thread->forceFill(['local_state' => ThreadLocalState::Done, 'is_unread' => false])->save();
        $state = $thread->getAttribute('local_state');

        return response()->json([
            'data' => [
                'id' => $thread->id,
                'state' => $state instanceof ThreadLocalState ? $state->value : (string) $state,
            ],
        ]);
    }

    public function classify(Request $request, Thread $thread, ApplyInboundCampaignEffects $effects): JsonResponse
    {
        $this->authorize('update', $thread);
        $action = $request->validate(['action' => ['required', 'string']])['action'];
        $previous = $this->inbox->workspaceRecipient($thread)?->getAttribute('status');
        $previousStatus = $previous instanceof RecipientStatus
            ? $previous
            : RecipientStatus::tryFrom((string) $previous);

        match ($action) {
            'interested' => $effects->triage($thread, RecipientStatus::Interested),
            'replied', 'treat_as_reply' => $effects->triage($thread, RecipientStatus::Replied),
            'not_now' => $effects->triage($thread, RecipientStatus::NotNow),
            'wrong_person' => $effects->triage($thread, RecipientStatus::WrongPerson),
            'remove_bad_address' => $effects->removeBadAddress($thread),
            'remove_from_campaign' => $effects->removeFromCampaign($thread),
            default => abort(422, 'Unknown classification action.'),
        };

        $notice = null;

        if ($previousStatus === RecipientStatus::Unsubscribed
            && in_array($action, ['interested', 'replied', 'treat_as_reply', 'not_now', 'wrong_person'], true)) {
            $notice = 'This person stays suppressed from the earlier unsubscribe — manage suppressions in Settings.';
        }

        return response()->json([
            'data' => [
                'id' => $thread->id,
                'action' => $action,
            ],
            'notice' => $notice,
        ]);
    }

    private function setUnread(Thread $thread, bool $unread): JsonResponse
    {
        $this->authorize('update', $thread);
        $thread->forceFill(['is_unread' => $unread])->save();

        return response()->json(['data' => $this->summary($thread)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Thread $thread): array
    {
        $workspaceId = (int) $thread->getAttribute('workspace_id');
        $fresh = $this->inbox->listQuery(Workspace::query()->findOrFail($workspaceId))
            ->whereKey($thread->id)
            ->firstOrFail();

        return $this->inbox->threadSummary(
            $fresh,
            $this->inbox->peopleByParticipantEmail($workspaceId, collect([$fresh])),
        );
    }

    /**
     * @param  Collection<int, Thread>  $threads
     * @return list<array<string, mixed>>
     */
    private function summaries(int $workspaceId, Collection $threads): array
    {
        $fallbackPeople = $this->inbox->peopleByParticipantEmail($workspaceId, $threads);

        return array_values($threads
            ->map(fn (Thread $thread) => $this->inbox->threadSummary($thread, $fallbackPeople))
            ->all());
    }

    private function attemptStatus(mixed $status): string
    {
        return $status instanceof SendAttemptStatus ? $status->value : (string) $status;
    }
}
