<?php

namespace App\Domain\Intelligence\DTOs;

/** One bounded immutable source observation to persist. */
final readonly class ResearchSnapshotInput
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public string $sourceType,
        public ?string $canonicalUrl,
        public ?string $registrableDomain,
        public ?string $content,
        public ?string $mimeType = null,
        public ?string $language = null,
        public ?string $providerOperationId = null,
        public ?string $providerRequestId = null,
        public ?int $providerItemRank = null,
        public string $state = 'available',
        public array $metadata = [],
        public ?string $resultIdentity = null,
        public ?string $observedAt = null,
        public ?string $retrievedAt = null,
        public ?string $expiresAt = null,
    ) {}
}
