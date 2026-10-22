<?php

namespace App\Domain\Integrations\Support;

use App\Domain\Integrations\DTOs\CapabilityDefinition;
use InvalidArgumentException;

final class CapabilityCatalogue
{
    /** @var array<string, CapabilityDefinition> */
    private array $definitions;

    /** @param array<string, CapabilityDefinition> $definitions */
    public function __construct(array $definitions = [])
    {
        if ($definitions === []) {
            $definitions = [];
            foreach (config('integrations.capabilities', []) as $name => $definition) {
                if (is_array($definition)) {
                    $definitions[(string) $name] = CapabilityDefinition::fromConfig((string) $name, $definition);
                }
            }
        }
        $this->definitions = $definitions;
    }

    /** @return list<CapabilityDefinition> */
    public function all(): array
    {
        $definitions = array_values($this->definitions);
        usort($definitions, fn (CapabilityDefinition $left, CapabilityDefinition $right): int => $left->name <=> $right->name);

        return $definitions;
    }

    public function get(string $name): CapabilityDefinition
    {
        return $this->definitions[$name]
            ?? throw new InvalidArgumentException("Unknown capability [{$name}].");
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    public function register(CapabilityDefinition $definition): void
    {
        if (isset($this->definitions[$definition->name])) {
            throw new InvalidArgumentException("Capability [{$definition->name}] is already registered.");
        }
        $this->definitions[$definition->name] = $definition;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(fn (CapabilityDefinition $definition): string => $definition->name, $this->all());
    }

    public function definitionHash(CapabilityDefinition|string $definition): string
    {
        $definition = $definition instanceof CapabilityDefinition ? $definition : $this->get($definition);
        $encoded = json_encode([
            'name' => $definition->name,
            'schema_version' => $definition->schemaVersion,
            'request_schema' => $definition->requestSchema,
            'result_schema' => $definition->resultSchema,
            'creates_observations' => $definition->createsObservations,
            'risk' => $definition->risk,
            'approval_class' => $definition->approvalClass,
            'entity_types' => $definition->entityTypes,
            'bounds' => [$definition->maxInputItems, $definition->maxResultItems],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $encoded);
    }
}
