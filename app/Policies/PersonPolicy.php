<?php

namespace App\Policies;

use App\Models\Person;
use App\Models\User;
use App\Policies\Concerns\BelongsToWorkspace;

class PersonPolicy
{
    use BelongsToWorkspace;

    public function view(User $user, Person $person): bool
    {
        return $this->memberOfWorkspace($user, $person);
    }

    public function update(User $user, Person $person): bool
    {
        return $this->memberOfWorkspace($user, $person);
    }

    public function delete(User $user, Person $person): bool
    {
        return $this->memberOfWorkspace($user, $person);
    }
}
