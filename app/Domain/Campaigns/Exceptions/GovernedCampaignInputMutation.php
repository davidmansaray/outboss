<?php

namespace App\Domain\Campaigns\Exceptions;

use RuntimeException;

/** Raised when compiled Campaign input would diverge from its approved plan. */
final class GovernedCampaignInputMutation extends RuntimeException
{
    public const CODE = 'governed_campaign_frozen';

    public const USER_MESSAGE = 'This governed campaign is frozen. Supersede its execution plan to change executable inputs.';

    public function __construct()
    {
        parent::__construct(self::USER_MESSAGE);
    }
}
