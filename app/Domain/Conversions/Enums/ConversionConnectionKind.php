<?php

namespace App\Domain\Conversions\Enums;

enum ConversionConnectionKind: string
{
    case Api = 'api';
    case Webhook = 'webhook';
}
