<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Mail\Exceptions\SenderIdentityUnavailable;
use App\Domain\Mail\Services\MailboxSenderIdentityService;
use App\Domain\Mail\Support\MailboxSyncFeedback;
use App\Models\Mailbox;
use App\Models\ProviderConnection;
use Illuminate\Http\Request;

class MailboxResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $mailbox = $this->model(Mailbox::class);
        $connection = $mailbox->connection;
        $provider = $connection instanceof ProviderConnection
            ? $this->enumValue($connection->getAttribute('provider'))
            : null;

        return [
            'id' => $mailbox->id,
            'email' => $mailbox->email_address,
            'display_name' => $mailbox->display_name,
            'provider' => $provider,
            'status' => $this->enumValue($mailbox->getAttribute('status')),
            'sync' => $mailbox->relationLoaded('syncStates')
                ? MailboxSyncFeedback::for($mailbox)
                : null,
            'error' => $mailbox->last_sync_error,
            'effective_reply_identity' => $this->effectiveReplyIdentity($mailbox),
            'daily_send_cap' => $mailbox->daily_send_cap,
            'sending_paused_at' => $this->iso8601($mailbox->getAttribute('sending_paused_at')),
            'sending_paused_reason' => $mailbox->sending_paused_reason,
            'last_synced_at' => $this->iso8601($mailbox->getAttribute('last_synced_at')),
            'created_at' => $this->iso8601($mailbox->getAttribute('created_at')),
            'updated_at' => $this->iso8601($mailbox->getAttribute('updated_at')),
        ];
    }

    /**
     * The address replies and new messages are sent from, or null while the
     * mailbox's sender identity needs repair.
     *
     * @return array{key: string, email: string, name: string|null}|null
     */
    private function effectiveReplyIdentity(Mailbox $mailbox): ?array
    {
        try {
            return app(MailboxSenderIdentityService::class)->effective($mailbox);
        } catch (SenderIdentityUnavailable) {
            return null;
        }
    }
}
