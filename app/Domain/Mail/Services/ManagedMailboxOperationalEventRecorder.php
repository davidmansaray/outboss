<?php

namespace App\Domain\Mail\Services;

use App\Models\Mailbox;
use App\Models\ManagedMailboxAccount;
use App\Models\ManagedMailEvent;
use App\Models\User;
use App\Models\Workspace;

/** Persist safe operational history only when the mailbox is managed mail. */
final class ManagedMailboxOperationalEventRecorder
{
    public function __construct(private readonly ManagedMailEventRecorder $events) {}

    /** @param array<string, mixed> $metadata */
    public function record(
        Mailbox $mailbox,
        string $event,
        ?User $actor = null,
        ?string $fromState = null,
        ?string $toState = null,
        array $metadata = [],
    ): ?ManagedMailEvent {
        $workspaceId = (int) $mailbox->getAttribute('workspace_id');
        if ($workspaceId <= 0) {
            return null;
        }

        $account = ManagedMailboxAccount::query()
            ->where('mailbox_id', $mailbox->getKey())
            ->with('managedMailDomain')
            ->first();
        $domain = $account?->managedMailDomain;
        if ($account === null || $domain === null || (int) $domain->workspace_id !== $workspaceId) {
            return null;
        }

        $workspace = Workspace::query()->whereKey($workspaceId)->first();
        if ($workspace === null) {
            return null;
        }

        return $this->events->record(
            workspace: $workspace,
            event: $event,
            actor: $actor,
            domain: $domain,
            account: $account,
            fromState: $fromState,
            toState: $toState,
            metadata: $metadata,
        );
    }
}
