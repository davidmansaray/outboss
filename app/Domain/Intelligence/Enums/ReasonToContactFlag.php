<?php

namespace App\Domain\Intelligence\Enums;

enum ReasonToContactFlag: string
{
    case SafeForMessage = 'safe_for_message';
    case SensitiveInference = 'sensitive_inference';
    case ProhibitedInference = 'prohibited_inference';
}
