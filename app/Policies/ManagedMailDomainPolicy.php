<?php

namespace App\Policies;

use App\Models\ManagedMailDomain;
use App\Models\User;

/** Model-policy adapter for route/controller authorization. */
class ManagedMailDomainPolicy extends ManagedMailPolicy
{
    public function view(User $user, ManagedMailDomain $domain): bool
    {
        return $this->viewDomain($user, $domain);
    }

    public function create(User $user): bool
    {
        try {
            return $this->viewWorkspace($user, $user->currentWorkspace());
        } catch (\Throwable) {
            return false;
        }
    }

    public function update(User $user, ManagedMailDomain $domain): bool
    {
        return $this->manageDomain($user, $domain);
    }

    public function manage(User $user, ManagedMailDomain $domain): bool
    {
        return $this->manageDomain($user, $domain);
    }

    public function verify(User $user, ManagedMailDomain $domain): bool
    {
        return $this->manageDomain($user, $domain);
    }

    public function delete(User $user, ManagedMailDomain $domain): bool
    {
        return $this->manageDomain($user, $domain);
    }
}
