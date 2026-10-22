<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\User;
use App\Policies\Concerns\BelongsToWorkspace;

class AssetPolicy
{
    use BelongsToWorkspace;

    public function view(User $user, Asset $asset): bool
    {
        return $this->memberOfWorkspace($user, $asset);
    }

    public function update(User $user, Asset $asset): bool
    {
        return $this->memberOfWorkspace($user, $asset)
            && $user->currentWorkspace()->isOwner($user);
    }
}
