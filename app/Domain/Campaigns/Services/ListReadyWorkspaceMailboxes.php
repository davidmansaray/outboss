<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Exceptions\SenderIdentityUnavailable;
use App\Domain\Mail\Services\MailboxSenderIdentityService;
use App\Models\Mailbox;
use Illuminate\Support\Collection;

/** Connected, unpaused workspace inboxes that already have a usable From identity. */
final class ListReadyWorkspaceMailboxes
{
    public function __construct(private MailboxSenderIdentityService $senderIdentities) {}

    /**
     * @return list<int>
     */
    public function idsForWorkspace(int $workspaceId): array
    {
        $ids = [];
        foreach ($this->forWorkspace($workspaceId) as $mailbox) {
            $ids[] = (int) $mailbox->id;
        }

        return $ids;
    }

    /**
     * @return Collection<int, Mailbox>
     */
    public function forWorkspace(int $workspaceId): Collection
    {
        $mailboxes = Mailbox::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', ConnectionStatus::Connected)
            ->whereNull('sending_paused_at')
            ->whereHas('connection', fn ($query) => $query->where('workspace_id', $workspaceId))
            ->orderBy('id')
            ->get();

        return $mailboxes
            ->filter(function (Mailbox $mailbox): bool {
                try {
                    $this->senderIdentities->effective($mailbox);

                    return true;
                } catch (SenderIdentityUnavailable) {
                    return false;
                }
            })
            ->values();
    }
}
