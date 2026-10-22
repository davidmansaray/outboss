<?php

namespace App\Domain\Campaigns\Enums;

enum CampaignStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';

    public function allowsEditing(): bool
    {
        return in_array($this, [self::Draft, self::Paused], true);
    }
}
