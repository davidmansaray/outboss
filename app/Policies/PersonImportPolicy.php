<?php

namespace App\Policies;

use App\Models\PersonImport;
use App\Models\User;
use App\Policies\Concerns\BelongsToWorkspace;

class PersonImportPolicy
{
    use BelongsToWorkspace;

    public function view(User $user, PersonImport $import): bool
    {
        return $this->memberOfWorkspace($user, $import);
    }

    public function commit(User $user, PersonImport $import): bool
    {
        return $this->memberOfWorkspace($user, $import)
            && $user->currentWorkspace()->isOwner($user);
    }
}
