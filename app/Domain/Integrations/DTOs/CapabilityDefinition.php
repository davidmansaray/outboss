<?php

namespace App\Domain\Integrations\DTOs;

use App\Domain\Integrations\Enums\CapabilityRisk;

final readonly class CapabilityDefinition
{
    /**
     * @param  array<string, mixed>  $requestSchema
     * @param  array<string, mixed>  $resultSchema
     * @param  list<string>  $entityTypes
     */
    public function __construct(
        public string $name,
        public int $schemaVersion = 1,
        public array $requestSchema = [],
        public array $resultSchema = [],
        public bool $createsObservations = false,
        public string $risk = 'low',
        public string $approvalClass = 'none',
        public array $entityTypes = [],
        public int $maxInputItems = 1,
        public int $maxResultItems = 100,
        public bool $requiresCostEstimate = true,
        public string $ambiguousOperation = 'reconcile',
        public bool $enabled = true,
        public ?string $availabilityReason = null,
    ) {
        if ($name === '' || preg_match('/^[a-z][a-z0-9]*(?:\.[a-z0-9_]+)+$/', $name) !== 1) {
            throw new \InvalidArgumentException('Capability names must be stable dotted identifiers.');
        }
        if ($schemaVersion < 1 || $maxInputItems < 1 || $maxResultItems < 1) {
            throw new \InvalidArgumentException('Capability bounds are invalid.');
        }
        CapabilityRisk::tryFrom($risk) ?? throw new \InvalidArgumentException('Capability risk is invalid.');
    }

    /** @param array<string, mixed> $config */
    public static function fromConfig(string $name, array $config): self
    {
        return new self(
            name: $name,
            schemaVersion: (int) ($config['schema_version'] ?? 1),
            requestSchema: is_array($config['request_schema'] ?? null) ? $config['request_schema'] : [],
            resultSchema: is_array($config['result_schema'] ?? null) ? $config['result_schema'] : [],
            createsObservations: (bool) ($config['creates_observations'] ?? false),
            risk: (string) ($config['risk'] ?? 'low'),
            approvalClass: (string) ($config['approval_class'] ?? 'none'),
            entityTypes: array_values(array_filter($config['entity_types'] ?? [], 'is_string')),
            maxInputItems: max(1, (int) ($config['max_input_items'] ?? 1)),
            maxResultItems: max(1, (int) ($config['max_result_items'] ?? 100)),
            requiresCostEstimate: (bool) ($config['requires_cost_estimate'] ?? true),
            ambiguousOperation: (string) ($config['ambiguous_operation'] ?? 'reconcile'),
            enabled: (bool) ($config['enabled'] ?? true),
            availabilityReason: isset($config['availability_reason']) ? (string) $config['availability_reason'] : null,
        );
    }
}
