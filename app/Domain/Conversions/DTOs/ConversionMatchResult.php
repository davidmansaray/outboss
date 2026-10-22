<?php

namespace App\Domain\Conversions\DTOs;

use App\Models\ConversionEvent;
use App\Models\ConversionEventMatch;

final readonly class ConversionMatchResult
{
    public function __construct(
        public ConversionEvent $event,
        public ConversionEventMatch $match,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'event_id' => (string) $this->event->id,
            'match_id' => (string) $this->match->id,
            'state' => $this->event->getRawOriginal('state'),
            'matched_account_id' => $this->event->matched_account_id,
            'candidate_count' => (int) $this->match->candidate_count,
            'resolution_path' => $this->match->resolution_path,
            'attribution_state' => 'not_evaluated',
        ];
    }
}
