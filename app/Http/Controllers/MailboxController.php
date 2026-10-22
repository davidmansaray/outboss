<?php

namespace App\Http\Controllers;

use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Exceptions\ManagedMailProvisioningException;
use App\Domain\Mail\Exceptions\SenderIdentityUnavailable;
use App\Domain\Mail\Services\CachedDomainAuthChecker;
use App\Domain\Mail\Services\DisconnectMailboxService;
use App\Domain\Mail\Services\MailboxSenderIdentityService;
use App\Domain\Mail\Services\ManagedMailboxOperationalEventRecorder;
use App\Domain\Mail\Services\ManagedMailCapability;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Domain\Mail\Support\MailboxSyncFeedback;
use App\Http\Requests\UpdateMailboxReplyIdentityRequest;
use App\Jobs\Mail\SyncMailbox;
use App\Models\Campaign;
use App\Models\Mailbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class MailboxController extends Controller
{
    public function index(
        Request $request,
        MailboxSenderIdentityService $senderIdentities,
        ManagedMailCapability $managedMail,
    ): Response {
        $workspace = $request->user()->currentWorkspace();
        $mailboxes = [];
        $models = Mailbox::query()
            ->where('workspace_id', $workspace->id)
            ->whereHas('connection', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->with(['connection', 'syncStates'])
            ->where('status', '!=', ConnectionStatus::Disconnected)
            ->get();

        foreach ($models as $mailbox) {
            $effective = null;
            $identityError = null;

            try {
                $effective = $senderIdentities->effective($mailbox);
            } catch (SenderIdentityUnavailable) {
                $identityError = SenderIdentityUnavailable::USER_MESSAGE;
            }

            $provider = $mailbox->connection?->getAttribute('provider');
            $status = $mailbox->getAttribute('status');

            $mailboxes[] = [
                'id' => $mailbox->id,
                'email' => $mailbox->email_address,
                'provider' => $provider instanceof \BackedEnum ? (string) $provider->value : (string) $provider,
                'status' => $status instanceof \BackedEnum ? (string) $status->value : (string) $status,
                'sync' => MailboxSyncFeedback::for($mailbox),
                'error' => $mailbox->last_sync_error,
                'reply_identities' => $senderIdentities->clientOptions($mailbox),
                'selected_reply_identity_key' => $mailbox->selected_sender_identity_key,
                'effective_reply_identity' => $effective,
                'reply_identity_error' => $identityError,
                'replies_today' => $mailbox->threads()
                    ->whereHas('messages', fn ($query) => $query
                        ->where('direction', 'inbound')
                        ->where('classification', 'human')
                        ->where('received_at', '>=', now($request->user()->timezone)->startOfDay()))
                    ->count(),
                'daily_send_cap' => $mailbox->daily_send_cap,
                'default_daily_cap' => (int) config('outboss.sending.default_daily_cap', 50),
                'sending_paused_at' => $mailbox->getAttribute('sending_paused_at')
                    ? Carbon::parse($mailbox->getAttribute('sending_paused_at'))->toIso8601String()
                    : null,
                'sending_paused_reason' => $mailbox->sending_paused_reason,
            ];
        }

        return Inertia::render('mailboxes/Index', [
            'returnCampaign' => $request->integer('from_campaign') > 0
                ? Campaign::query()->where('workspace_id', $workspace->id)->whereKey($request->integer('from_campaign'))->first(['id', 'name'])
                : null,
            'mailboxes' => $mailboxes,
            'canManageInternal' => $managedMail->allows($request->user(), $workspace),
        ]);
    }

    public function updateReplyIdentity(
        UpdateMailboxReplyIdentityRequest $request,
        Mailbox $mailbox,
        MailboxSenderIdentityService $senderIdentities,
    ): RedirectResponse {
        $this->authorize('update', $mailbox);
        $senderIdentities->select($mailbox, $request->string('identity_key')->toString());

        return back()->with('success', 'Reply identity saved.');
    }

    public function sync(Request $request, Mailbox $mailbox): RedirectResponse
    {
        $this->authorize('update', $mailbox);

        $mailbox->forceFill(['sync_requested_at' => now()])->save();
        SyncMailbox::dispatch($mailbox->id);

        return back();
    }

    public function updateSending(Request $request, Mailbox $mailbox): RedirectResponse
    {
        $this->authorize('update', $mailbox);
        $data = $request->validate([
            'daily_send_cap' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $mailbox->forceFill([
            'daily_send_cap' => $data['daily_send_cap'] ?? null,
        ])->save();

        return back()->with('success', 'Daily send cap saved.');
    }

    public function resumeSending(Mailbox $mailbox, ManagedMailboxOperationalEventRecorder $events): RedirectResponse
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

        return back()->with('success', 'Sending resumed.');
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

    public function destroy(Request $request, Mailbox $mailbox, DisconnectMailboxService $disconnect): RedirectResponse
    {
        $this->authorize('delete', $mailbox);

        try {
            $disconnect->handle($mailbox);
        } catch (ManagedMailProvisioningException $exception) {
            return redirect()->route('mailboxes.index')->with('error', $exception->getMessage());
        }

        return redirect()->route('mailboxes.index')->with('success', 'Inbox disconnected.');
    }
}
