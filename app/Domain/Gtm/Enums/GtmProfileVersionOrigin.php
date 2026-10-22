<?php

namespace App\Domain\Gtm\Enums;

enum GtmProfileVersionOrigin: string
{
    case WorkspaceSeed = 'workspace_seed';
    case OwnerEdit = 'owner_edit';
    case ResearchMerge = 'research_merge';
    case ReviewResolution = 'review_resolution';
}
