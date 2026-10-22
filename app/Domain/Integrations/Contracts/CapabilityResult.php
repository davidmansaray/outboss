<?php

namespace App\Domain\Integrations\Contracts;

use App\Domain\Integrations\DTOs\ExternalCostEstimate;
use App\Domain\Integrations\DTOs\SafeExternalError;

/**
 * Neutral result envelope. The data member contains only schema-approved
 * values.
 */
class CapabilityResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $state,
        public readonly array $data = [],
        public readonly ?ExternalCostEstimate $cost = null,
        public readonly ?string $providerRequestId = null,
        public readonly ?string $providerSnapshotId = null,
        public readonly ?SafeExternalError $error = null,
        public readonly array $metadata = [],
    ) {
        if (! in_array($state, ['complete', 'partial', 'awaiting', 'ambiguous', 'failed'], true)) {
            throw new \InvalidArgumentException('The external result state is invalid.');
        }
    }

    public function successful(): bool
    {
        return in_array($this->state, ['complete', 'partial'], true);
    }

    /**
     * Return the provider result's neutral envelope for canonical size checks.
     *
     * The envelope intentionally contains only values that are already part of
     * the bounded capability contract. Provider response bodies and credentials
     * are never represented here.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'data' => $this->data,
            'cost' => $this->cost === null ? null : [
                'native_units' => $this->cost->nativeUnits,
                'native_unit' => $this->cost->nativeUnit,
                'cost_micros' => $this->cost->costMicros,
                'cost_basis' => $this->cost->costBasis,
                'upper_bound' => $this->cost->upperBound,
            ],
            'provider_request_id' => $this->providerRequestId,
            'provider_snapshot_id' => $this->providerSnapshotId,
            'error' => $this->error === null ? null : [
                'code' => $this->error->code,
                'message' => $this->error->message,
                'ambiguous' => $this->error->ambiguous,
                'retry_after' => $this->error->retryAfter?->toISOString(),
                'http_status' => $this->error->httpStatus,
            ],
            'metadata' => $this->metadata,
        ];
    }
}
