<?php

namespace App\Policies;

use App\Domain\Mail\Services\ManagedMailCapability;
use App\Models\MailExperiment;
use App\Models\User;
use App\Models\Workspace;

/** Owner-only policy for the internal experiment control surface. */
final class MailExperimentPolicy
{
    public function __construct(private readonly ManagedMailCapability $capability) {}

    public function view(User $user, MailExperiment $experiment): bool
    {
        return $this->allowed($user, $experiment->workspace);
    }

    public function manage(User $user, MailExperiment $experiment): bool
    {
        return $this->allowed($user, $experiment->workspace);
    }

    public function create(User $user): bool
    {
        try {
            return $this->capability->allows(
                $user,
                $user->currentWorkspace(),
                ManagedMailCapability::MAIL_EXPERIMENTS,
            );
        } catch (\Throwable) {
            return false;
        }
    }

    private function allowed(User $user, Workspace $workspace): bool
    {
        return $this->capability->allows(
            $user,
            $workspace,
            ManagedMailCapability::MAIL_EXPERIMENTS,
        );
    }
}
