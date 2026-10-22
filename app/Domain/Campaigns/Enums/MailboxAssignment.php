<?php

namespace App\Domain\Campaigns\Enums;

enum MailboxAssignment: string
{
    case Rotation = 'rotation';
    case CampaignPin = 'campaign_pin';
    case ManualPin = 'manual_pin';
}
