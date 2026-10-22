<?php

namespace App\Domain\Objectives\Enums;

enum GtmExperimentStatus: string
{
    case Draft = 'draft';
    case Assigned = 'assigned';
    case Approved = 'approved';
    case Running = 'running';
    case Paused = 'paused';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Superseded = 'superseded';
}
