<?php

namespace App\Domain\Objectives\Enums;

enum ExecutionPlanCompilationState: string
{
    case Started = 'started';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Superseded = 'superseded';
}
