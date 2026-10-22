<?php

namespace App\Domain\Mail\Enums;

enum RecipientMailboxProvider: string
{
    case Google = 'google';
    case Microsoft = 'microsoft';
    case Yahoo = 'yahoo';
    case Other = 'other';
    case Unknown = 'unknown';
}
