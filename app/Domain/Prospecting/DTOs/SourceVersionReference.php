<?php

namespace App\Domain\Prospecting\DTOs;

use App\Models\ProspectSourceVersion;

final readonly class SourceVersionReference
{
    public function __construct(
        public string $sourceId,
        public string $versionId,
        public int $version = 1,
        public int $schemaVersion = ProspectSourceVersion::SCHEMA_VERSION,
        public string $configurationHash = '',
        public string $policyHash = '',
    ) {}

    public static function fromModel(ProspectSourceVersion $version): self
    {
        return new self(
            sourceId: (string) $version->prospect_source_id,
            versionId: (string) $version->id,
            version: (int) $version->version,
            schemaVersion: (int) $version->schema_version,
            configurationHash: (string) $version->configuration_hash,
            policyHash: (string) $version->policy_hash,
        );
    }

    public function prospectSourceId(): string
    {
        return $this->sourceId;
    }

    public function prospectSourceVersionId(): string
    {
        return $this->versionId;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source_id' => $this->sourceId,
            'version_id' => $this->versionId,
            'version' => $this->version,
            'schema_version' => $this->schemaVersion,
            'configuration_hash' => $this->configurationHash,
            'policy_hash' => $this->policyHash,
        ];
    }
}
