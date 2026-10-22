<?php

namespace App\Domain\Objectives\Enums;

enum AttributionMethod: string
{
    case ExplicitCorrelation = 'explicit_correlation';
    case SingleEligibleTouch = 'single_eligible_touch';
    case HumanOverride = 'human_override';
    case Reversal = 'reversal';
}
