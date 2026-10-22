<?php

namespace App\Policies;

use App\Models\AiProviderCredential;
use App\Models\User;

class AiProviderCredentialPolicy
{
    public function view(User $user, AiProviderCredential $credential): bool
    {
        return $credential->workspace()->whereHas('memberships', fn ($query) => $query->where('user_id', $user->id))->exists();
    }

    public function manage(User $user, AiProviderCredential $credential): bool
    {
        return $credential->workspace?->isOwner($user) ?? false;
    }
}
