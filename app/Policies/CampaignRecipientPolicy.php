<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\User;
use App\Policies\Concerns\BelongsToWorkspace;

class CampaignRecipientPolicy
{
    use BelongsToWorkspace;

    public function view(User $user, CampaignRecipient $recipient): bool
    {
        $campaign = $recipient->campaign;

        if (! $campaign instanceof Campaign) {
            return false;
        }

        $mailbox = $recipient->mailbox;
        if ($mailbox !== null
            && $recipient->getAttribute('workspace_id') !== null
            && (int) $mailbox->getAttribute('workspace_id') !== (int) $recipient->getAttribute('workspace_id')) {
            return false;
        }

        // Campaign recipients are workspace-scoped in the current schema. The
        // campaign fallback keeps authorization correct for legacy records
        // while their denormalized workspace id is being backfilled.
        if ($recipient->getAttribute('workspace_id') !== null) {
            return (int) $campaign->getAttribute('workspace_id') === (int) $recipient->getAttribute('workspace_id')
                && $this->memberOfWorkspace($user, $recipient);
        }

        return $this->memberOfWorkspace($user, $campaign);
    }

    public function update(User $user, CampaignRecipient $recipient): bool
    {
        return $this->view($user, $recipient);
    }

    public function delete(User $user, CampaignRecipient $recipient): bool
    {
        return $this->view($user, $recipient);
    }
}
