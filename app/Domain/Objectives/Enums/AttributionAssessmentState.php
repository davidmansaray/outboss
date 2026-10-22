<?php

namespace App\Domain\Objectives\Enums;

enum AttributionAssessmentState: string
{
    case Attributed = 'attributed';
    case Unattributed = 'unattributed';
    case Ambiguous = 'ambiguous';
    case Ineligible = 'ineligible';
    case ManuallyOverridden = 'manually_overridden';
    case Reversed = 'reversed';
}
