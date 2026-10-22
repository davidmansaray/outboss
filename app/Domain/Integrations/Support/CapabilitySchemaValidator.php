<?php

namespace App\Domain\Integrations\Support;

use App\Domain\Integrations\DTOs\CapabilityDefinition;
use App\Domain\Integrations\Exceptions\CapabilitySchemaViolation;
use JsonException;

final class CapabilitySchemaValidator
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validateRequest(CapabilityDefinition|string $definition, array $payload): array
    {
        $definition = $this->definition($definition);

        return $this->validate($definition->requestSchema, $payload, 'request');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validateResult(CapabilityDefinition|string $definition, array $payload): array
    {
        $definition = $this->definition($definition);

        return $this->validate($definition->resultSchema, $payload, 'result');
    }

    /**
     * Assert that a canonical request envelope fits the configured transport
     * bound. The check is deliberately performed after schema normalisation so
     * the bytes admitted and fingerprinted are the same representation.
     *
     * @param  array<string, mixed>  $envelope
     */
    public function assertRequestEnvelopeSize(array $envelope): void
    {
        $this->assertByteLimit(
            $envelope,
            (int) config('integrations.max_request_bytes', 256 * 1024),
            'request',
        );
    }

    /**
     * Assert that a canonical result envelope fits the configured transport
     * bound before a result can settle an external operation.
     *
     * @param  array<string, mixed>  $envelope
     */
    public function assertResultEnvelopeSize(array $envelope): void
    {
        $this->assertByteLimit(
            $envelope,
            (int) config('integrations.max_result_bytes', 512 * 1024),
            'result',
        );
    }

    /**
     * Metadata has a separate bound because metadata is retained and exposed
     * independently of the request/result payloads.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function assertMetadataSize(array $metadata): void
    {
        $this->assertByteLimit(
            $metadata,
            (int) config('integrations.max_metadata_bytes', 16 * 1024),
            'metadata',
        );
    }

    /**
     * Validate a small JSON-schema subset shared by code-defined capability
     * contracts. The validator rejects unknown top-level fields and enforces
     * all byte/item bounds before an operation can reserve budget.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function validate(array $schema, mixed $payload, string $context = 'payload'): array
    {
        $errors = [];
        $normalized = $this->walk($schema, $payload, $context, $errors);
        if ($errors !== []) {
            throw new CapabilitySchemaViolation(array_values(array_unique($errors)));
        }

        return is_array($normalized) ? $normalized : [];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $errors
     */
    private function walk(array $schema, mixed $value, string $path, array &$errors): mixed
    {
        $type = $schema['type'] ?? null;
        if ($type !== null && ! $this->matchesType($type, $value)) {
            $errors[] = $path.'.type';

            return $value;
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = $path.'.enum';
        }

        if (is_string($value)) {
            $value = trim($value);
            $length = mb_strlen($value);
            if (isset($schema['minLength']) && $length < (int) $schema['minLength']) {
                $errors[] = $path.'.minLength';
            }
            if (isset($schema['maxLength']) && $length > (int) $schema['maxLength']) {
                $errors[] = $path.'.maxLength';
            }
        }

        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < (float) $schema['minimum']) {
                $errors[] = $path.'.minimum';
            }
            if (isset($schema['maximum']) && $value > (float) $schema['maximum']) {
                $errors[] = $path.'.maximum';
            }
        }

        if (is_array($value)) {
            if (isset($schema['minItems']) && array_is_list($value) && count($value) < (int) $schema['minItems']) {
                $errors[] = $path.'.minItems';
            }
            if (isset($schema['maxItems']) && array_is_list($value) && count($value) > (int) $schema['maxItems']) {
                $errors[] = $path.'.maxItems';
            }
            if (isset($schema['maxProperties']) && ! array_is_list($value) && count($value) > (int) $schema['maxProperties']) {
                $errors[] = $path.'.maxProperties';
            }

            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
            foreach ($required as $requiredKey) {
                if (is_string($requiredKey) && ! array_key_exists($requiredKey, $value)) {
                    $errors[] = $path.'.'.$requiredKey.'.required';
                }
            }
            if (! array_is_list($value)) {
                $additional = $schema['additionalProperties'] ?? true;
                foreach ($value as $key => $child) {
                    if (! is_string($key)) {
                        $errors[] = $path.'.properties';

                        continue;
                    }
                    if (! array_key_exists($key, $properties)) {
                        if ($additional === false) {
                            $errors[] = $path.'.'.$key.'.unsupported';
                        }

                        continue;
                    }
                    if (is_array($properties[$key])) {
                        $value[$key] = $this->walk($properties[$key], $child, $path.'.'.$key, $errors);
                    }
                }
            } elseif (is_array($schema['items'] ?? null)) {
                foreach ($value as $index => $child) {
                    $value[$index] = $this->walk($schema['items'], $child, $path.'.'.$index, $errors);
                }
            }
        }

        return $value;
    }

    /** @param string|list<string> $type */
    private function matchesType(string|array $type, mixed $value): bool
    {
        foreach ((array) $type as $candidate) {
            $matches = match ($candidate) {
                'object' => is_array($value) && ! array_is_list($value),
                'array' => is_array($value) && array_is_list($value),
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                default => false,
            };
            if ($matches) {
                return true;
            }
        }

        return false;
    }

    private function definition(CapabilityDefinition|string $definition): CapabilityDefinition
    {
        if ($definition instanceof CapabilityDefinition) {
            return $definition;
        }

        return app(CapabilityCatalogue::class)->get($definition);
    }

    /**
     * Stable, non-reversible request fingerprint for operation idempotency.
     *
     * @param  array<string, mixed>  $request
     */
    public function fingerprint(array $request, string $capability, int $schemaVersion = 1): string
    {
        $canonical = $this->canonicalize([
            'capability' => $capability,
            'schema_version' => $schemaVersion,
            'request' => $request,
        ]);

        try {
            $encoded = json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new \InvalidArgumentException('The capability request cannot be fingerprinted.', previous: $exception);
        }

        return hash_hmac('sha256', $encoded, (string) config('app.key'));
    }

    /**
     * Encode a value using the same canonical JSON representation used for
     * request fingerprints. Callers use the resulting byte count for transport
     * and persistence bounds; JSON failures become safe schema violations.
     */
    public function canonicalEncode(mixed $value, string $context = 'payload'): string
    {
        try {
            return json_encode(
                $this->canonicalize($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new CapabilitySchemaViolation(
                [$context.'.encoding'],
                previous: $exception,
            );
        }
    }

    private function assertByteLimit(mixed $value, int $limit, string $context): void
    {
        $encoded = $this->canonicalEncode($value, $context);
        if (strlen($encoded) > max(0, $limit)) {
            throw new CapabilitySchemaViolation([$context.'.max_bytes']);
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
