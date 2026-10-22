<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\Thread;
use App\Models\User;
use App\Policies\Concerns\BelongsToWorkspace;

class ThreadPolicy
{
    use BelongsToWorkspace;

    public function view(User $user, Thread $thread): bool
    {
        return $this->consistent($user, $thread);
    }

    public function update(User $user, Thread $thread): bool
    {
        return $this->consistent($user, $thread);
    }

    private function consistent(User $user, Thread $thread): bool
    {
        if (! $this->memberOfWorkspace($user, $thread)) {
            return false;
        }

        $workspaceId = (int) $thread->getAttribute('workspace_id');
        $mailbox = Mailbox::query()->whereKey((int) $thread->getAttribute('mailbox_id'))->first();

        if ($mailbox === null
            || (int) $mailbox->getAttribute('workspace_id') !== $workspaceId) {
            return false;
        }

        $recipientId = $thread->getAttribute('campaign_recipient_id');
        $recipient = $recipientId === null
            ? null
            : CampaignRecipient::query()->whereKey((int) $recipientId)->first();
        if ($recipient === null) {
            return true;
        }

        $campaign = $recipient->campaign;

        return (int) $recipient->getAttribute('workspace_id') === $workspaceId
            && (int) $recipient->getAttribute('mailbox_id') === (int) $mailbox->getKey()
            && $campaign instanceof Campaign
            && (int) $campaign->getAttribute('workspace_id') === $workspaceId;
    }
}
