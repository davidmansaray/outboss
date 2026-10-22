<?php

namespace App\Domain\Campaigns\Enums;

enum ImportSource: string
{
    case Paste = 'paste';
    case Csv = 'csv';
}
