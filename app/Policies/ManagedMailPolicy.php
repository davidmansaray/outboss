<?php

namespace App\Policies;

use App\Domain\Mail\Services\ManagedMailCapability;
use App\Models\ManagedMailboxAccount;
use App\Models\ManagedMailDomain;
use App\Models\User;
use App\Models\Workspace;

/** Shared owner/workspace policy for internal managed-mail records. */
class ManagedMailPolicy
{
    private readonly ManagedMailCapability $capability;

    public function __construct(?ManagedMailCapability $capability = null)
    {
        $this->capability = $capability ?? new ManagedMailCapability;
    }

    public function viewWorkspace(User $user, Workspace $workspace): bool
    {
        return $this->capability->allows($user, $workspace);
    }

    public function manageWorkspace(User $user, Workspace $workspace): bool
    {
        return $this->capability->allows($user, $workspace, ManagedMailCapability::MANAGED_MAIL, true);
    }

    public function viewDomain(User $user, ManagedMailDomain $domain): bool
    {
        return $this->domainAllowed($user, $domain);
    }

    public function manageDomain(User $user, ManagedMailDomain $domain): bool
    {
        return $this->domainAllowed($user, $domain);
    }

    public function viewMailbox(User $user, ManagedMailboxAccount $account): bool
    {
        return $this->accountAllowed($user, $account);
    }

    public function manageMailbox(User $user, ManagedMailboxAccount $account): bool
    {
        return $this->accountAllowed($user, $account);
    }

    private function domainAllowed(User $user, ManagedMailDomain $domain): bool
    {
        return $this->capability->allows($user, $domain->workspace);
    }

    private function accountAllowed(User $user, ManagedMailboxAccount $account): bool
    {
        $domain = $account->managedMailDomain;

        return $domain !== null && $this->domainAllowed($user, $domain);
    }
}
