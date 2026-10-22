<?php

namespace App\Domain\Objectives\Enums;

enum ApprovalGrantDecision: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
}
