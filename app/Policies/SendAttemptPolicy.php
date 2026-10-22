<?php

namespace App\Policies;

use App\Models\SendAttempt;
use App\Models\User;
use App\Policies\Concerns\BelongsToWorkspace;

class SendAttemptPolicy
{
    use BelongsToWorkspace;

    public function view(User $user, SendAttempt $attempt): bool
    {
        return $this->memberOfWorkspace($user, $attempt);
    }
}
