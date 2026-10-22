<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;
use App\Policies\Concerns\BelongsToWorkspace;

class MessagePolicy
{
    use BelongsToWorkspace;

    public function view(User $user, Message $message): bool
    {
        return $this->memberOfWorkspace($user, $message);
    }

    public function create(User $user): bool
    {
        return true;
    }
}
