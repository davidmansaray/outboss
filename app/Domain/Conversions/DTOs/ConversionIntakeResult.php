<?php

namespace App\Domain\Conversions\DTOs;

use App\Models\ConversionEvent;
use App\Models\ConversionEventReceipt;

final readonly class ConversionIntakeResult
{
    public function __construct(
        public ConversionEventReceipt $receipt,
        public ?ConversionEvent $event,
        public bool $duplicate = false,
        public string $attributionState = 'not_evaluated',
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $eventState = $this->event?->getRawOriginal('state');
        $receiptState = $this->receipt->getRawOriginal('intake_state');

        return [
            'receipt_id' => (string) $this->receipt->id,
            'event_id' => $this->event?->id,
            'state' => $eventState ?? $receiptState,
            'duplicate' => $this->duplicate,
            'attribution_state' => $this->attributionState,
        ];
    }
}
