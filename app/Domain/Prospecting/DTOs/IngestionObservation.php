<?php

namespace App\Domain\Prospecting\DTOs;

use App\Domain\Prospecting\Enums\ObservationEntityType;

/** One normalized input row; raw uploaded rows are intentionally not stored. */
final readonly class IngestionObservation
{
    /**
     * @param  array<string, mixed>  $observedValues
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public ObservationEntityType|string $entityType,
        public array $observedValues,
        public ?int $rowNumber = null,
        public ?string $inputKey = null,
        public ?string $observationKey = null,
        public ?string $rawInputHash = null,
        public ?string $providerRecordId = null,
        public ?string $providerSnapshotId = null,
        public ?string $canonicalUrl = null,
        public ?string $providerObservedAt = null,
        public array $metadata = [],
    ) {}

    public function type(): ObservationEntityType
    {
        return $this->entityType instanceof ObservationEntityType
            ? $this->entityType
            : ObservationEntityType::from(strtolower(trim($this->entityType)));
    }

    public function stableKey(string $sourceRunId): string
    {
        if ($this->observationKey !== null && trim($this->observationKey) !== '') {
            return hash('sha256', $this->observationKey);
        }

        $payload = [
            'source_run_id' => $sourceRunId,
            'entity_type' => $this->type()->value,
            'input_key' => $this->inputKey,
            'row_number' => $this->rowNumber,
            'values' => $this->observedValues,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
