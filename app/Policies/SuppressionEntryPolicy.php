<?php

namespace App\Policies;

use App\Models\SuppressionEntry;
use App\Models\User;
use App\Policies\Concerns\BelongsToWorkspace;

class SuppressionEntryPolicy
{
    use BelongsToWorkspace;

    public function view(User $user, SuppressionEntry $entry): bool
    {
        return $this->memberOfWorkspace($user, $entry);
    }

    public function delete(User $user, SuppressionEntry $entry): bool
    {
        return $this->memberOfWorkspace($user, $entry);
    }
}
