<?php

namespace App\Domain\Campaigns\Enums;

enum ImportStatus: string
{
    case Previewed = 'previewed';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';
}
