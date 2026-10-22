<?php

namespace App\Domain\Conversions\Support;

use App\Models\ConversionDefinitionVersion;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Validate and normalise an event against one immutable definition version. */
final class ConversionEventValidator
{
    /**
     * @param  array<mixed>  $input
     * @return array<string,mixed>
     */
    public function validate(ConversionDefinitionVersion $version, array $input): array
    {
        $errors = [];
        $properties = $input['properties'] ?? [];
        if (! is_array($properties) || ($properties !== [] && array_is_list($properties))) {
            $errors['properties'] = 'Event properties must be a JSON object.';
            $properties = [];
        }

        $schemaAttribute = $version->getAttribute('property_schema');
        $schema = is_array($schemaAttribute) ? $schemaAttribute : [];
        $definitions = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $unknown = array_diff(array_keys($properties), array_keys($definitions));
        if ($unknown !== []) {
            $errors['properties'] = 'Event properties contain an unsupported field.';
        }
        foreach ($required as $key) {
            if (! array_key_exists($key, $properties)) {
                $errors["properties.{$key}"] = 'This property is required.';
            }
        }
        foreach ($properties as $key => $value) {
            if (! isset($definitions[$key]) || ! is_array($definitions[$key])) {
                continue;
            }
            if (! $this->matchesType($value, $definitions[$key]['type'] ?? null)) {
                $errors["properties.{$key}"] = 'The property type is invalid.';
            }
            if (isset($definitions[$key]['enum']) && is_array($definitions[$key]['enum']) && ! in_array($value, $definitions[$key]['enum'], true)) {
                $errors["properties.{$key}"] = 'The property value is not allowed.';
            }
            if (is_string($value) && mb_strlen($value) > 2000) {
                $errors["properties.{$key}"] = 'String properties must be bounded.';
            }
        }

        $identities = $this->identities($input, $errors);
        $occurredAt = $input['occurred_at'] ?? $input['effective_at'] ?? null;
        if ($occurredAt === null) {
            $occurred = CarbonImmutable::now();
        } else {
            try {
                $occurred = CarbonImmutable::parse((string) $occurredAt);
                if ($occurred->isAfter(CarbonImmutable::now()->addMinutes(5))) {
                    $errors['occurred_at'] = 'An event cannot occur in the future.';
                }
            } catch (\Throwable) {
                $errors['occurred_at'] = 'Occurred time must be a valid timestamp.';
                $occurred = CarbonImmutable::now();
            }
        }

        $value = $input['value'] ?? null;
        $valuePolicyAttribute = $version->getAttribute('value_policy');
        $valuePolicy = is_array($valuePolicyAttribute) ? $valuePolicyAttribute : [];
        if ($value !== null && ! ($valuePolicy['allowed'] ?? false)) {
            $errors['value'] = 'This conversion definition does not accept event values.';
        }
        if ($value !== null && (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value)))) {
            $errors['value'] = 'Event value must be numeric.';
        }
        if ($value !== null) {
            $value = (string) $value;
        }
        $currency = $input['currency'] ?? null;
        if ($currency !== null) {
            $currency = strtoupper(trim((string) $currency));
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                $errors['currency'] = 'Currency must be an ISO-4217 code.';
            }
        }
        $policyCurrency = $valuePolicy['currency'] ?? null;
        if ($policyCurrency !== null && $value !== null && $currency !== $policyCurrency) {
            $errors['currency'] = 'The event currency does not match the definition policy.';
        }
        if ($value !== null && $currency === null) {
            $errors['currency'] = 'Currency is required when an event value is provided.';
        }

        $externalEventId = $input['external_event_id'] ?? ($input['event_id'] ?? null);
        if ($externalEventId !== null && (! is_string($externalEventId) && ! is_int($externalEventId) || mb_strlen((string) $externalEventId) > 255)) {
            $errors['external_event_id'] = 'External event IDs must be bounded strings.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'external_event_id' => $externalEventId === null ? null : (string) $externalEventId,
            'occurred_at' => $occurred,
            'properties' => CanonicalConversionPayload::canonicalize($properties),
            'identities' => $identities,
            'value' => $value,
            'currency' => $currency,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<array-key,mixed>  $errors
     * @return list<array<string,mixed>>
     */
    private function identities(array $input, array &$errors): array
    {
        $raw = $input['identities'] ?? null;
        if ($raw === null) {
            $raw = [];
            foreach (['account_id', 'domain', 'external_identity'] as $type) {
                if (array_key_exists($type, $input)) {
                    $candidate = is_array($input[$type]) ? $input[$type] : ['value' => $input[$type]];
                    $candidate['type'] = $type;
                    $raw[] = $candidate;
                }
            }
        }
        if (! is_array($raw) || ! array_is_list($raw) || count($raw) > 10) {
            $errors['identities'] = 'Event identities must be a bounded list.';

            return [];
        }
        $result = [];
        foreach ($raw as $identity) {
            if (! is_array($identity)) {
                $errors['identities'] = 'Event identities must be objects.';

                continue;
            }
            try {
                $result[] = ConversionIdentity::normalize($identity);
            } catch (ValidationException $exception) {
                $errors = array_replace($errors, $exception->errors());
            }
        }

        return $result;
    }

    private function matchesType(mixed $value, mixed $type): bool
    {
        if (is_array($type)) {
            foreach ($type as $candidate) {
                if ($this->matchesType($value, $candidate)) {
                    return true;
                }
            }

            return false;
        }

        return match ($type) {
            'string' => is_string($value),
            'number' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            'integer' => is_int($value) || (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ! array_is_list($value),
            'null' => $value === null,
            default => false,
        };
    }
}
