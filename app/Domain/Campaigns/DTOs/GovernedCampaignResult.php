<?php

namespace App\Domain\Campaigns\DTOs;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\SequenceStep;
use App\Models\SequenceVersion;

final readonly class GovernedCampaignResult
{
    /**
     * @param  array<string, CampaignRecipient>  $recipientsByActionId
     */
    public function __construct(
        public Campaign $campaign,
        public SequenceVersion $sequenceVersion,
        public SequenceStep $sequenceStep,
        public array $recipientsByActionId,
    ) {}
}
