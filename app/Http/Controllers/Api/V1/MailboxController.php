<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Services\CachedDomainAuthChecker;
use App\Domain\Mail\Services\ManagedMailboxOperationalEventRecorder;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MailboxResource;
use App\Jobs\Mail\SyncMailbox;
use App\Models\Mailbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MailboxController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $workspace = $request->user()->currentWorkspace();
        $mailboxes = Mailbox::query()
            ->where('workspace_id', $workspace->id)
            ->whereHas('connection', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->with(['connection', 'syncStates'])
            ->where('status', '!=', ConnectionStatus::Disconnected)
            ->orderBy('email_address')
            ->paginate(min(100, max(1, $request->integer('per_page', 15))))
            ->withQueryString();

        return MailboxResource::collection($mailboxes);
    }

    public function show(Mailbox $mailbox): MailboxResource
    {
        $this->authorize('view', $mailbox);
        $mailbox->load(['connection', 'syncStates']);

        return new MailboxResource($mailbox);
    }

    public function authCheck(Mailbox $mailbox, CachedDomainAuthChecker $checker): JsonResponse
    {
        $this->authorize('view', $mailbox);
        $domain = EmailNormalizer::domain($mailbox->email_address) ?? '';

        return response()->json([
            'domain' => $domain,
            ...$checker->check($domain),
        ]);
    }

    public function sync(Mailbox $mailbox): JsonResponse
    {
        $this->authorize('update', $mailbox);

        $mailbox->forceFill(['sync_requested_at' => now()])->save();
        SyncMailbox::dispatch($mailbox->id);

        return response()->json([
            'queued' => true,
            'data' => (new MailboxResource($mailbox->fresh(['connection', 'syncStates'])))->resolve(),
        ]);
    }

    public function updateSending(Request $request, Mailbox $mailbox): MailboxResource
    {
        $this->authorize('update', $mailbox);
        $data = $request->validate([
            'daily_send_cap' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $mailbox->forceFill([
            'daily_send_cap' => $data['daily_send_cap'] ?? null,
        ])->save();

        return new MailboxResource($mailbox->fresh(['connection', 'syncStates']));
    }

    public function resumeSending(Mailbox $mailbox, ManagedMailboxOperationalEventRecorder $events): MailboxResource
    {
        $this->authorize('update', $mailbox);
        $reason = $mailbox->getAttribute('sending_paused_reason');
        $mailbox->forceFill([
            'sending_paused_at' => null,
            'sending_paused_reason' => null,
        ])->save();
        $events->record(
            $mailbox,
            'mailbox_sending_resumed',
            actor: request()->user(),
            fromState: 'paused',
            toState: 'active',
            metadata: ['previous_reason' => is_string($reason) ? $reason : null],
        );

        return new MailboxResource($mailbox->fresh(['connection', 'syncStates']));
    }
}
