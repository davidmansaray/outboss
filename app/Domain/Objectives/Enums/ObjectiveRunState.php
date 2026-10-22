<?php

namespace App\Domain\Objectives\Enums;

enum ObjectiveRunState: string
{
    case Planned = 'planned';
    case Running = 'running';
    case Waiting = 'waiting';
    case NeedsReview = 'needs_review';
    case Paused = 'paused';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Superseded = 'superseded';
}
