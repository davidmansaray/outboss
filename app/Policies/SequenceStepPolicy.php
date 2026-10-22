<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\SequenceStep;
use App\Models\User;
use App\Policies\Concerns\BelongsToWorkspace;

class SequenceStepPolicy
{
    use BelongsToWorkspace;

    public function view(User $user, SequenceStep $step): bool
    {
        $campaign = $step->version?->campaign;

        return $campaign instanceof Campaign && $this->memberOfWorkspace($user, $campaign);
    }

    public function update(User $user, SequenceStep $step): bool
    {
        return $this->view($user, $step);
    }

    public function delete(User $user, SequenceStep $step): bool
    {
        return $this->update($user, $step);
    }
}
