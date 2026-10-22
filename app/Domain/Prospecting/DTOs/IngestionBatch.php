<?php

namespace App\Domain\Prospecting\DTOs;

use App\Models\ProspectSource;
use App\Models\ProspectSourceVersion;
use App\Models\User;
use App\Models\Workspace;

/**
 * The public input boundary for ingestion. Callers can pass source/version
 * models or UUIDs; resolution always happens server-side in the service.
 *
 * @phpstan-type ObservationInput IngestionObservation|array<string,mixed>
 */
final readonly class IngestionBatch
{
    /**
     * @param  list<ObservationInput>  $observations
     * @param  array<string, mixed>|null  $inputReference
     */
    public function __construct(
        public Workspace $workspace,
        public ProspectSource|string $source,
        public ProspectSourceVersion|string|null $sourceVersion,
        public string $idempotencyKey,
        array $observations = [],
        public ?array $inputReference = null,
        public ?User $actor = null,
        public string $initiatorType = 'system',
        public ?int $personImportId = null,
        public ?int $recipientImportId = null,
        public ?string $requestFingerprint = null,
    ) {
        $this->observations = array_map(
            static fn (IngestionObservation|array $observation): IngestionObservation => $observation instanceof IngestionObservation
                ? $observation
                : new IngestionObservation(
                    entityType: (string) ($observation['entity_type'] ?? $observation['entityType'] ?? 'person'),
                    observedValues: is_array($observation['observed_values'] ?? $observation['observedValues'] ?? null)
                        ? ($observation['observed_values'] ?? $observation['observedValues'])
                        : $observation,
                    rowNumber: isset($observation['row_number']) ? (int) $observation['row_number'] : (isset($observation['rowNumber']) ? (int) $observation['rowNumber'] : null),
                    inputKey: isset($observation['input_key']) ? (string) $observation['input_key'] : (isset($observation['inputKey']) ? (string) $observation['inputKey'] : null),
                    observationKey: isset($observation['observation_key']) ? (string) $observation['observation_key'] : (isset($observation['observationKey']) ? (string) $observation['observationKey'] : null),
                    rawInputHash: isset($observation['raw_input_hash']) ? (string) $observation['raw_input_hash'] : null,
                    providerRecordId: isset($observation['provider_record_id']) ? (string) $observation['provider_record_id'] : null,
                    providerSnapshotId: isset($observation['provider_snapshot_id']) ? (string) $observation['provider_snapshot_id'] : null,
                    canonicalUrl: isset($observation['canonical_url']) ? (string) $observation['canonical_url'] : null,
                    providerObservedAt: isset($observation['provider_observed_at']) ? (string) $observation['provider_observed_at'] : null,
                    metadata: is_array($observation['metadata'] ?? null) ? $observation['metadata'] : [],
                ),
            $observations,
        );
    }

    /** @var list<IngestionObservation> */
    public array $observations;

    /** @param list<ObservationInput> $observations */
    public static function make(
        Workspace $workspace,
        ProspectSource|string $source,
        ProspectSourceVersion|string|null $sourceVersion,
        string $idempotencyKey,
        array $observations,
        ?User $actor = null,
    ): self {
        return new self($workspace, $source, $sourceVersion, $idempotencyKey, $observations, actor: $actor);
    }
}
