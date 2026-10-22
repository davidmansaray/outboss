<?php

namespace App\Http\Controllers;

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\ThreadLocalState;
use App\Domain\Mail\Exceptions\ClientOperationConflict;
use App\Domain\Mail\Exceptions\SenderIdentityUnavailable;
use App\Domain\Mail\Exceptions\SendOperationIncomplete;
use App\Domain\Mail\Services\ApplyInboundCampaignEffects;
use App\Domain\Mail\Services\ComposeMessageService;
use App\Domain\Mail\Services\MailboxSenderIdentityService;
use App\Domain\Mail\Services\ReplyToThreadService;
use App\Domain\Mail\Services\UniboxInbox;
use App\Http\Requests\ComposeMessageRequest;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Thread;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UniboxController extends Controller
{
    public function __construct(private UniboxInbox $inbox) {}

    public function index(Request $request, MailboxSenderIdentityService $senderIdentities): Response
    {
        $workspace = $request->user()->currentWorkspace();
        $folder = $this->inbox->normalizeFolder($request->query('folder'));
        $mailboxId = $request->integer('mailbox') ?: null;
        $campaign = $this->inbox->campaignFilter($request->query('campaign'), $workspace);

        $threads = $this->inbox->listQuery($workspace)
            ->tap(fn (Builder $query) => $this->inbox->applyFolder($query, $folder))
            ->tap(fn (Builder $query) => $this->inbox->applyCampaignAndMailbox($query, $mailboxId, $campaign))
            ->orderByDesc('last_message_at')
            ->paginate(50)
            ->withQueryString();

        $fallbackPeople = $this->inbox->peopleByParticipantEmail($workspace->id, $threads->getCollection());
        $threads->through(fn (Thread $thread) => $this->inbox->threadSummary($thread, $fallbackPeople));

        $mailboxes = [];

        foreach (
            Mailbox::query()
                ->where('workspace_id', $workspace->id)
                ->whereHas('connection', fn ($query) => $query->where('workspace_id', $workspace->id))
                ->where('status', '!=', ConnectionStatus::Disconnected)
                ->get() as $mailbox
        ) {
            $effective = null;

            try {
                $effective = $senderIdentities->effective($mailbox);
            } catch (SenderIdentityUnavailable) {
                // Keep inbound Unibox usable while outbound identity repair is pending.
            }

            $status = $mailbox->getAttribute('status');
            $mailboxes[] = [
                'id' => $mailbox->id,
                'email_address' => $mailbox->email_address,
                'status' => $status instanceof ConnectionStatus ? $status->value : (string) $status,
                'effective_reply_identity' => $effective,
            ];
        }

        return Inertia::render('unibox/Index', [
            'folder' => $folder,
            'mailbox' => $mailboxId,
            'campaign' => $campaign,
            'threads' => $threads,
            'counts' => $this->inbox->counts($workspace, $mailboxId, $campaign),
            'campaigns' => $workspace->campaigns()->orderBy('name')->get(['id', 'name']),
            'mailboxes' => $mailboxes,
            'replyPreview' => app()->isLocal(),
        ]);
    }

    public function show(Request $request, Thread $thread): JsonResponse
    {
        $this->authorize('view', $thread);
        $workspaceId = (int) $thread->getAttribute('workspace_id');
        $thread->forceFill(['is_unread' => false])->save();
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
            'thread' => [
                ...$this->inbox->threadSummary(
                    $thread,
                    $this->inbox->peopleByParticipantEmail($workspaceId, collect([$thread])),
                ),
                'messages' => $thread->messages
                    ->map(fn (Message $message): array => $this->inbox->messagePayload($message))
                    ->values(),
            ],
        ]);
    }

    public function reply(Request $request, Thread $thread, ReplyToThreadService $reply): RedirectResponse
    {
        $this->authorize('update', $thread);
        $data = $request->validate([
            'body' => ['required', 'string'],
            'client_operation_id' => ['required', 'uuid'],
        ]);

        try {
            $reply->handle($request->user(), $thread, $data['body'], $data['client_operation_id']);
        } catch (SenderIdentityUnavailable) {
            return back()->withErrors(['reply' => SenderIdentityUnavailable::USER_MESSAGE]);
        } catch (ClientOperationConflict) {
            return back()->withErrors(['reply' => ClientOperationConflict::USER_MESSAGE]);
        } catch (SendOperationIncomplete) {
            return back()->withErrors(['reply' => SendOperationIncomplete::USER_MESSAGE]);
        }

        return back()->with('success', 'Reply sent.');
    }

    public function done(Request $request, Thread $thread): RedirectResponse
    {
        $this->authorize('update', $thread);
        $thread->forceFill(['local_state' => ThreadLocalState::Done, 'is_unread' => false])->save();

        return back();
    }

    public function classify(Request $request, Thread $thread, ApplyInboundCampaignEffects $effects): RedirectResponse
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
            default => abort(422),
        };

        if ($previousStatus === RecipientStatus::Unsubscribed
            && in_array($action, ['interested', 'replied', 'treat_as_reply', 'not_now', 'wrong_person'], true)) {
            return back()->with(
                'success',
                'This person stays suppressed from the earlier unsubscribe — manage suppressions in Settings.',
            );
        }

        return back();
    }

    public function compose(ComposeMessageRequest $request, ComposeMessageService $compose): RedirectResponse
    {
        $data = $request->validated();

        $mailbox = Mailbox::query()->where('workspace_id', $request->user()->currentWorkspace()->id)->findOrFail((int) $data['mailbox_id']);
        $this->authorize('update', $mailbox);
        try {
            $compose->handle($request->user(), $mailbox, $data['to'], $data['subject'], $data['body'], $data['client_operation_id']);
        } catch (SenderIdentityUnavailable) {
            return back()->withErrors(['mailbox_id' => SenderIdentityUnavailable::USER_MESSAGE]);
        } catch (ClientOperationConflict) {
            return back()->withErrors(['client_operation_id' => ClientOperationConflict::USER_MESSAGE]);
        } catch (SendOperationIncomplete) {
            return back()->withErrors(['client_operation_id' => SendOperationIncomplete::USER_MESSAGE]);
        }

        return back()->with('success', 'Message sent.');
    }
}
