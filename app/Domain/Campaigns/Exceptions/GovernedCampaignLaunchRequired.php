<?php

namespace App\Domain\Campaigns\Exceptions;

use RuntimeException;

/** Raised when a generic launch boundary targets an Objective-governed Campaign. */
final class GovernedCampaignLaunchRequired extends RuntimeException
{
    public const CODE = 'governed_launch_via_objective_required';

    public const USER_MESSAGE = 'Launch or resume this governed campaign through its Objective execution plan.';

    public function __construct()
    {
        parent::__construct(self::USER_MESSAGE);
    }
}
