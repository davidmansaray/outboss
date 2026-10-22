<?php

namespace App\Domain\Objectives\Enums;

enum ObjectiveRunPhaseName: string
{
    case ValidateInputs = 'validate_inputs';
    case RecheckExclusions = 'recheck_exclusions';
    case FreezeAssignments = 'freeze_assignments';
    case DraftMessages = 'draft_messages';
    case ValidatePlan = 'validate_plan';
    case AwaitApproval = 'await_approval';
    case CompileCampaign = 'compile_campaign';
    case Launch = 'launch';
    case Execute = 'execute';
    case Observe = 'observe';
    case Attribute = 'attribute';
}
