<?php

namespace App\Domain\Objectives\Enums;

enum PolicyEvaluationStage: string
{
    case Draft = 'draft';
    case Approval = 'approval';
    case Launch = 'launch';
    case Schedule = 'schedule';
    case Lease = 'lease';
    case PreSend = 'pre_send';
}
