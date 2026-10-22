<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;
use App\Policies\Concerns\BelongsToWorkspace;

class AccountPolicy
{
    use BelongsToWorkspace;

    public function view(User $user, Account $account): bool
    {
        return $this->memberOfWorkspace($user, $account);
    }

    public function update(User $user, Account $account): bool
    {
        return $this->memberOfWorkspace($user, $account);
    }

    public function delete(User $user, Account $account): bool
    {
        return $this->memberOfWorkspace($user, $account);
    }
}
