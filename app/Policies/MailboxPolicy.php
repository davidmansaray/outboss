<?php

namespace App\Policies;

use App\Models\Mailbox;
use App\Models\User;
use App\Policies\Concerns\BelongsToWorkspace;

class MailboxPolicy
{
    use BelongsToWorkspace;

    public function view(User $user, Mailbox $mailbox): bool
    {
        return $this->consistent($user, $mailbox);
    }

    public function update(User $user, Mailbox $mailbox): bool
    {
        return $this->consistent($user, $mailbox);
    }

    public function delete(User $user, Mailbox $mailbox): bool
    {
        return $this->consistent($user, $mailbox);
    }

    private function consistent(User $user, Mailbox $mailbox): bool
    {
        if (! $this->memberOfWorkspace($user, $mailbox)) {
            return false;
        }

        $connection = $mailbox->connection;

        return $connection === null
            || (int) $connection->getAttribute('workspace_id') === (int) $mailbox->getAttribute('workspace_id');
    }
}
