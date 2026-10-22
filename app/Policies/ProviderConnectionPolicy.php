<?php

namespace App\Policies;

use App\Models\ProviderConnection;
use App\Models\User;
use App\Policies\Concerns\BelongsToWorkspace;

class ProviderConnectionPolicy
{
    use BelongsToWorkspace;

    public function view(User $user, ProviderConnection $connection): bool
    {
        return $this->memberOfWorkspace($user, $connection);
    }

    public function update(User $user, ProviderConnection $connection): bool
    {
        return $this->memberOfWorkspace($user, $connection);
    }

    public function delete(User $user, ProviderConnection $connection): bool
    {
        return $this->memberOfWorkspace($user, $connection);
    }
}
