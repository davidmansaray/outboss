<?php

namespace App\Domain\Integrations\Enums;

enum CapabilityRisk: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Restricted = 'restricted';
}
