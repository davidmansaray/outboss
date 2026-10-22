<?php

namespace App\Policies;

use App\Models\Offer;
use App\Models\User;
use App\Policies\Concerns\BelongsToWorkspace;

class OfferPolicy
{
    use BelongsToWorkspace;

    public function view(User $user, Offer $offer): bool
    {
        return $this->memberOfWorkspace($user, $offer);
    }

    public function history(User $user, Offer $offer): bool
    {
        return $this->view($user, $offer);
    }

    public function update(User $user, Offer $offer): bool
    {
        return $this->ownerOfWorkspace($user, $offer);
    }

    public function changeStatus(User $user, Offer $offer): bool
    {
        return $this->ownerOfWorkspace($user, $offer);
    }

    public function delete(User $user, Offer $offer): bool
    {
        return $this->ownerOfWorkspace($user, $offer);
    }

    private function ownerOfWorkspace(User $user, Offer $offer): bool
    {
        if (! $this->memberOfWorkspace($user, $offer)) {
            return false;
        }

        return $user->currentWorkspace()->isOwner($user);
    }
}
