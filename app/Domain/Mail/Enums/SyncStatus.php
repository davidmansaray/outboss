<?php

namespace App\Domain\Mail\Enums;

enum SyncStatus: string
{
    case Idle = 'idle';
    case Syncing = 'syncing';
    case Failed = 'failed';
}
