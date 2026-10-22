<?php

namespace App\Domain\Objectives\Enums;

enum ObjectiveRunPhaseState: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Waiting = 'waiting';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Skipped = 'skipped';
}
