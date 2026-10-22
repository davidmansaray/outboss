<?php

namespace App\Domain\Campaigns\Enums;

enum SendMode: string
{
    case Rotate = 'rotate';
    case Pin = 'pin';
}
