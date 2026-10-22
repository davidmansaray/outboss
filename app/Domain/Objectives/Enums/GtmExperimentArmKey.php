<?php

namespace App\Domain\Objectives\Enums;

enum GtmExperimentArmKey: string
{
    case Control = 'control';
    case Treatment = 'treatment';
}
