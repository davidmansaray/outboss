<?php

namespace App\Domain\Gtm\DTOs;

use App\Domain\Gtm\Enums\OfferStatus;

final readonly class OfferVersionReference
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $offerId,
        public string $versionId,
        public int $version,
        public OfferStatus $status,
        public string $contentHash,
        public array $payload,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'offer_id' => $this->offerId,
            'version_id' => $this->versionId,
            'version' => $this->version,
            'status' => $this->status->value,
            'content_hash' => $this->contentHash,
            'payload' => $this->payload,
        ];
    }
}
