<?php

namespace App\Policies;

use App\Models\ManagedMailboxAccount;
use App\Models\User;

/** Model-policy adapter for managed account operations. */
class ManagedMailboxAccountPolicy extends ManagedMailPolicy
{
    public function view(User $user, ManagedMailboxAccount $account): bool
    {
        return $this->viewMailbox($user, $account);
    }

    public function create(User $user): bool
    {
        try {
            return $this->viewWorkspace($user, $user->currentWorkspace());
        } catch (\Throwable) {
            return false;
        }
    }

    public function update(User $user, ManagedMailboxAccount $account): bool
    {
        return $this->manageMailbox($user, $account);
    }

    public function manage(User $user, ManagedMailboxAccount $account): bool
    {
        return $this->manageMailbox($user, $account);
    }

    public function rotate(User $user, ManagedMailboxAccount $account): bool
    {
        return $this->manageMailbox($user, $account);
    }

    public function suspend(User $user, ManagedMailboxAccount $account): bool
    {
        return $this->manageMailbox($user, $account);
    }

    public function delete(User $user, ManagedMailboxAccount $account): bool
    {
        return $this->manageMailbox($user, $account);
    }
}
