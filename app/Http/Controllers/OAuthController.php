<?php

namespace App\Http\Controllers;

use App\Domain\Mail\DTOs\OAuthCallback;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Services\ConnectMailboxService;
use App\Domain\Mail\Services\RestoreBlockedRecipients;
use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class OAuthController extends Controller
{
    public function redirect(Request $request, ConnectMailboxService $connect): Response
    {
        $mailbox = $request->route('mailbox');
        $workspace = $request->user()->currentWorkspace();
        $mailbox = $mailbox instanceof Mailbox
            ? $mailbox
            : (is_numeric($mailbox)
                ? Mailbox::query()
                    ->where('workspace_id', $workspace->id)
                    ->findOrFail($mailbox)
                : null);

        $provider = $request->route('provider');

        try {
            $name = MailProviderName::from(
                is_string($provider) && $provider !== ''
                    ? $provider
                    : $this->providerName($mailbox),
            );

            if ($mailbox) {
                $this->authorize('update', $mailbox);
            }

            return Inertia::location($connect->start($request->user(), $name, $mailbox));
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable) {
            return redirect()->route('mailboxes.index')->with('error', 'Could not start that connection. Try again.');
        }
    }

    public function callback(Request $request, string $provider, ConnectMailboxService $connect, RestoreBlockedRecipients $restore): RedirectResponse
    {
        $name = MailProviderName::from($provider);

        try {
            $connection = $connect->complete(
                $request->user(),
                $name,
                new OAuthCallback(
                    code: (string) $request->query('code', ''),
                    state: (string) $request->query('state', ''),
                    error: $this->queryString($request, 'error'),
                    extras: [
                        'location' => $this->queryString($request, 'location'),
                        'accounts_server' => $this->queryString($request, 'accounts-server'),
                    ],
                ),
                (string) $request->query('state', ''),
            );

            $connection->mailboxes->each(fn (Mailbox $mailbox) => $restore->handle($mailbox));
            $request->session()->put(
                User::ACTIVE_WORKSPACE_SESSION_KEY,
                $connection->workspace_id,
            );

            return redirect()->route('mailboxes.index')->with('success', 'Mailbox connected.');
        } catch (RuntimeException $exception) {
            return redirect()->route('mailboxes.index')->with('error', $this->safeMessage($exception));
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('mailboxes.index')->with('error', 'Could not connect that mailbox. Try again.');
        }
    }

    private function safeMessage(RuntimeException $exception): string
    {
        return match ($exception->getMessage()) {
            'oauth_denied' => 'The provider denied access.',
            'oauth_state_invalid' => 'That connect attempt expired. Try again.',
            'oauth_identity_mismatch', 'oauth_mailbox_mismatch' => 'Reconnect must use the same account.',
            'oauth_no_refresh_token' => 'The provider did not grant offline access. Try connecting again.',
            default => 'Could not connect that mailbox. Try again.',
        };
    }

    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function providerName(?Mailbox $mailbox): string
    {
        $provider = $mailbox?->connection?->getAttribute('provider');

        return $provider instanceof MailProviderName ? $provider->value : (string) $provider;
    }
}
