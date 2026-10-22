<?php

namespace App\Mcp\Resources;

use App\Domain\Api\TokenScope;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Support\MailboxSyncFeedback;
use App\Mcp\Concerns\ChecksScopes;
use App\Models\Mailbox;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use JsonException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Description('Overview of mailbox connection status, sync health, and sending pause state for the authenticated workspace.')]
#[Uri('outboss://mailboxes/health')]
#[MimeType('application/json')]
class MailboxHealthResource extends Resource
{
    use ChecksScopes;

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::MailboxesRead->value)) {
            return $denied;
        }

        $workspace = $this->actor($request)->currentWorkspace();
        $mailboxes = Mailbox::query()
            ->where('workspace_id', $workspace->id)
            ->whereHas('connection', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->with(['connection', 'syncStates'])
            ->where('status', '!=', ConnectionStatus::Disconnected)
            ->orderBy('email_address')
            ->get();

        $payload = [
            'mailboxes' => $mailboxes->map(function (Mailbox $mailbox): array {
                $status = $mailbox->getAttribute('status');
                $pausedAt = $mailbox->getAttribute('sending_paused_at');

                return [
                    'id' => $mailbox->id,
                    'email' => $mailbox->email_address,
                    'status' => $status instanceof BackedEnum ? $status->value : (is_string($status) ? $status : null),
                    'sending_paused' => $pausedAt !== null,
                    'sending_paused_reason' => $mailbox->sending_paused_reason,
                    'daily_send_cap' => $mailbox->daily_send_cap,
                    'sync' => MailboxSyncFeedback::for($mailbox),
                    'last_synced_at' => $this->iso8601($mailbox->getAttribute('last_synced_at')),
                ];
            })->values()->all(),
        ];

        try {
            return Response::text(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (JsonException) {
            return Response::error('The resource could not encode its response.');
        }
    }

    private function iso8601(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::parse($value)->toIso8601String();
        }

        return null;
    }
}
