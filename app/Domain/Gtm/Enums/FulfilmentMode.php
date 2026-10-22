<?php

namespace App\Domain\Gtm\Enums;

enum FulfilmentMode: string
{
    case ExternalDestination = 'external_destination';
    case ExistingAsset = 'existing_asset';
    case HumanAssisted = 'human_assisted';
}
