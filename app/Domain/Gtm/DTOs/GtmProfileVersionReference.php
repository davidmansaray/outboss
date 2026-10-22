<?php

namespace App\Domain\Gtm\DTOs;

final readonly class GtmProfileVersionReference
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $profileId,
        public string $versionId,
        public int $version,
        public int $schemaVersion,
        public string $contentHash,
        public array $payload,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'profile_id' => $this->profileId,
            'version_id' => $this->versionId,
            'version' => $this->version,
            'schema_version' => $this->schemaVersion,
            'content_hash' => $this->contentHash,
            'payload' => $this->payload,
        ];
    }
}
