<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

class AiProviderSettingsPolicy
{
    public function view(User $user, Workspace $workspace): bool
    {
        return $workspace->membershipFor($user) !== null;
    }

    public function manage(User $user, Workspace $workspace): bool
    {
        return $workspace->isOwner($user);
    }

    public function viewUsage(User $user, Workspace $workspace): bool
    {
        return $this->view($user, $workspace);
    }

    public function viewAudit(User $user, Workspace $workspace): bool
    {
        return $workspace->isOwner($user);
    }
}
