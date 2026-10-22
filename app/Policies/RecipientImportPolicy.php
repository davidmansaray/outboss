<?php

namespace App\Policies;

use App\Models\RecipientImport;
use App\Models\User;
use App\Policies\Concerns\BelongsToWorkspace;

class RecipientImportPolicy
{
    use BelongsToWorkspace;

    public function view(User $user, RecipientImport $import): bool
    {
        return $this->memberOfWorkspace($user, $import);
    }

    public function update(User $user, RecipientImport $import): bool
    {
        return $this->memberOfWorkspace($user, $import)
            && $user->currentWorkspace()->isOwner($user);
    }
}
