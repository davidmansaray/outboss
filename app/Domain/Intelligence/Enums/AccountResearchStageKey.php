<?php

namespace App\Domain\Intelligence\Enums;

enum AccountResearchStageKey: string
{
    case Eligibility = 'eligibility';
    case DomainAvailability = 'domain_availability';
    case WebsiteRetrieval = 'website_retrieval';
    case TargetedSearch = 'targeted_search';
    case ClaimSynthesis = 'claim_synthesis';
    case Qualification = 'qualification';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $stage): string => $stage->value, self::cases());
    }
}
