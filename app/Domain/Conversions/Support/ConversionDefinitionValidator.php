<?php

namespace App\Domain\Conversions\Support;

use App\Domain\Conversions\Enums\ConversionConnectionKind;
use App\Domain\Conversions\Enums\ConversionSourceType;
use App\Domain\Conversions\Enums\ConversionSubjectType;
use Illuminate\Validation\ValidationException;

/**
 * Canonical schema validator for Conversion Definition versions.
 *
 * Definitions are intentionally data contracts. Keeping this validator
 * independent from HTTP requests means manual, API and webhook intake all
 * validate against the same immutable version.
 */
final class ConversionDefinitionValidator
{
    /** @var list<string> */
    public const SOURCE_TYPES = ['manual', 'api', 'webhook', 'email_reply'];

    /** @var list<string> */
    public const IDENTITY_KEYS = ['account_id', 'external_identity', 'domain'];

    /** @var list<string> */
    private const DEDUPE_STRATEGIES = [
        'external_id',
        'source_and_external_id',
        'payload_hash',
        'idempotency_key',
    ];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input): array
    {
        $errors = [];
        $eventKey = $input['event_key'] ?? ($input['key'] ?? null);
        $displayName = $input['display_name'] ?? ($input['name'] ?? null);
        $description = $input['description'] ?? null;
        $subjectType = $input['subject_type'] ?? ($input['subject'] ?? ConversionSubjectType::Account->value);
        $sources = $input['allowed_sources'] ?? ($input['source_types'] ?? ($input['sources'] ?? self::SOURCE_TYPES));
        $identityKeys = $input['allowed_identity_keys'] ?? ($input['identity_keys'] ?? ($input['identities'] ?? self::IDENTITY_KEYS));
        $propertySchema = $input['property_schema'] ?? ($input['properties_schema'] ?? [
            'type' => 'object',
            'properties' => [],
            'required' => [],
            'additionalProperties' => false,
        ]);
        $dedupeStrategy = $input['dedupe_strategy'] ?? 'source_and_external_id';
        $dedupeConfiguration = $input['dedupe_configuration'] ?? ($input['dedupe'] ?? []);
        $attributionWindow = $input['attribution_window'] ?? ($input['attribution_window_metadata'] ?? ['days' => 30]);
        $valuePolicy = $input['value_policy'] ?? ($input['value'] ?? [
            'allowed' => false,
            'currency' => null,
        ]);
        $correctionPolicy = $input['correction_policy'] ?? ($input['corrections'] ?? [
            'allowed' => true,
        ]);
        $reversalPolicy = $input['reversal_policy'] ?? ($input['reversal'] ?? [
            'allowed' => true,
        ]);
        $retentionPolicy = $input['retention_policy'] ?? ($input['retention'] ?? [
            'unmatched_identity_days' => 30,
        ]);
        $exportPolicy = $input['export_policy'] ?? ($input['export'] ?? [
            'allowed' => true,
        ]);

        if (! is_string($eventKey) || preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/', $eventKey) !== 1 || mb_strlen($eventKey) > 100) {
            $errors['event_key'] = 'Event keys must use lower snake case and be at most 100 characters.';
        } else {
            $eventKey = strtolower(trim($eventKey));
        }

        if (! is_string($displayName) || trim($displayName) === '' || mb_strlen($displayName) > 160) {
            $errors['display_name'] = 'A bounded display name is required.';
        } else {
            $displayName = trim($displayName);
        }

        if ($description !== null && (! is_string($description) || mb_strlen($description) > 5000)) {
            $errors['description'] = 'Description must be bounded plain text.';
        }
        $description = $description === null ? null : trim($description);

        if ($subjectType instanceof ConversionSubjectType) {
            $subjectType = $subjectType->value;
        }
        if (! is_string($subjectType) || ConversionSubjectType::tryFrom($subjectType) === null) {
            $errors['subject_type'] = 'The conversion subject type is invalid.';
        } else {
            $subjectType = ConversionSubjectType::from($subjectType)->value;
        }

        $sources = $this->boundedStringList($sources, 'allowed_sources', $errors, self::SOURCE_TYPES);
        $identityKeys = $this->boundedStringList($identityKeys, 'allowed_identity_keys', $errors, self::IDENTITY_KEYS);

        if (! is_array($propertySchema) || array_is_list($propertySchema)) {
            $errors['property_schema'] = 'The property schema must be a JSON object.';
            $propertySchema = [
                'type' => 'object',
                'properties' => [],
                'required' => [],
                'additionalProperties' => false,
            ];
        } else {
            try {
                $propertySchema = $this->validatePropertySchema($propertySchema);
            } catch (ValidationException $exception) {
                $errors = array_replace($errors, $exception->errors());
            }
        }

        if (! is_string($dedupeStrategy) || ! in_array($dedupeStrategy, self::DEDUPE_STRATEGIES, true)) {
            $errors['dedupe_strategy'] = 'The deduplication strategy is invalid.';
            $dedupeStrategy = 'source_and_external_id';
        }
        if (! is_array($dedupeConfiguration) || ($dedupeConfiguration !== [] && array_is_list($dedupeConfiguration))) {
            $errors['dedupe_configuration'] = 'Deduplication configuration must be an object.';
            $dedupeConfiguration = [];
        }

        $attributionWindow = $this->validateAttributionWindow($attributionWindow, $errors);
        $valuePolicy = $this->validateValuePolicy($valuePolicy, $errors);
        $correctionPolicy = $this->validateBooleanPolicy($correctionPolicy, 'correction_policy', $errors, ['allowed' => true]);
        $reversalPolicy = $this->validateBooleanPolicy($reversalPolicy, 'reversal_policy', $errors, ['allowed' => true]);
        $retentionPolicy = $this->validateRetentionPolicy($retentionPolicy, $errors);
        $exportPolicy = $this->validateBooleanPolicy($exportPolicy, 'export_policy', $errors, ['allowed' => true]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'event_key' => $eventKey,
            'display_name' => $displayName,
            'description' => $description,
            'subject_type' => $subjectType,
            'allowed_sources' => array_values(array_unique($sources)),
            'property_schema' => $propertySchema,
            'allowed_identity_keys' => array_values(array_unique($identityKeys)),
            'dedupe_strategy' => $dedupeStrategy,
            'dedupe_configuration' => CanonicalConversionPayload::canonicalize($dedupeConfiguration),
            'attribution_window' => $attributionWindow,
            'value_policy' => $valuePolicy,
            'correction_policy' => $correctionPolicy,
            'reversal_policy' => $reversalPolicy,
            'retention_policy' => $retentionPolicy,
            'export_policy' => $exportPolicy,
        ];
    }

    /** @param array<string,mixed> $payload */
    public function hash(array $payload): string
    {
        return CanonicalConversionPayload::hash($payload);
    }

    /**
     * @param  array<string, string>  $errors
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function boundedStringList(mixed $values, string $field, array &$errors, array $allowed): array
    {
        if (! is_array($values) || ! array_is_list($values) || $values === [] || count($values) > 20) {
            $errors[$field] = 'This value must be a non-empty bounded list.';

            return [];
        }

        $result = [];
        foreach ($values as $value) {
            if (! is_string($value) || ! in_array($value, $allowed, true)) {
                $errors[$field] = 'The list contains an unsupported value.';

                continue;
            }
            $result[] = $value;
        }

        return $result;
    }

    /**
     * @param  array<string,mixed>  $schema
     * @return array<string,mixed>
     */
    private function validatePropertySchema(array $schema): array
    {
        $errors = [];
        if (($schema['type'] ?? 'object') !== 'object') {
            $errors['property_schema.type'] = 'Conversion properties must be a JSON object.';
        }
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];
        if (! is_array($properties) || ($properties !== [] && array_is_list($properties)) || count($properties) > 100) {
            $errors['property_schema.properties'] = 'Property definitions must be a bounded object.';
            $properties = [];
        }
        if (! is_array($required) || ! array_is_list($required) || count($required) > 100 || array_diff($required, array_keys($properties)) !== []) {
            $errors['property_schema.required'] = 'Required properties must name defined properties.';
            $required = [];
        }
        foreach ($properties as $name => $definition) {
            if (! is_string($name) || preg_match('/^[a-z][a-z0-9_]{0,79}$/', $name) !== 1 || ! is_array($definition) || array_is_list($definition)) {
                $errors['property_schema.properties'] = 'Property names and definitions are invalid.';

                continue;
            }
            $type = $definition['type'] ?? null;
            if (! is_string($type) || ! in_array($type, ['string', 'number', 'integer', 'boolean', 'array', 'object', 'null'], true)) {
                $errors["property_schema.properties.{$name}.type"] = 'Property types are invalid.';
            }
            if (isset($definition['enum']) && (! is_array($definition['enum']) || count($definition['enum']) > 50)) {
                $errors["property_schema.properties.{$name}.enum"] = 'Property enum values are invalid.';
            }
        }
        $unknown = array_diff(array_keys($schema), ['type', 'properties', 'required', 'additionalProperties', 'title', 'description']);
        if ($unknown !== []) {
            $errors['property_schema'] = 'The property schema contains an unsupported field.';
        }
        if (isset($schema['additionalProperties']) && $schema['additionalProperties'] !== false) {
            $errors['property_schema.additionalProperties'] = 'Unknown conversion properties must be rejected.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return CanonicalConversionPayload::canonicalize([
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
            ...array_intersect_key($schema, array_flip(['title', 'description'])),
        ]);
    }

    /**
     * @param  array<string,string>  $errors
     * @return array{days:int}
     */
    private function validateAttributionWindow(mixed $value, array &$errors): array
    {
        if (is_int($value)) {
            $value = ['days' => $value];
        }
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $errors['attribution_window'] = 'Attribution-window metadata must be an object.';

            return ['days' => 30];
        }
        $days = $value['days'] ?? 30;
        if ((is_string($days) && preg_match('/^[0-9]+$/', $days) === 1)) {
            $days = (int) $days;
        }
        if (! is_int($days) || $days < 0 || $days > 3650) {
            $errors['attribution_window.days'] = 'Attribution-window days must be between 0 and 3650.';
            $days = 30;
        }

        return ['days' => $days];
    }

    /**
     * @param  array<string,string>  $errors
     * @return array{allowed:bool,currency:?string}
     */
    private function validateValuePolicy(mixed $value, array &$errors): array
    {
        if (is_bool($value)) {
            $value = ['allowed' => $value, 'currency' => null];
        }
        if (! is_array($value) || array_is_list($value)) {
            $errors['value_policy'] = 'Value policy must be an object.';

            return ['allowed' => false, 'currency' => null];
        }
        $allowed = $value['allowed'] ?? ($value['accepts_value'] ?? false);
        $currency = $value['currency'] ?? ($value['currency_code'] ?? null);
        if (! is_bool($allowed)) {
            $errors['value_policy.allowed'] = 'Value allowance must be boolean.';
            $allowed = false;
        }
        if ($currency !== null && (! is_string($currency) || preg_match('/^[A-Z]{3}$/', strtoupper($currency)) !== 1)) {
            $errors['value_policy.currency'] = 'Currency must be an ISO-4217 code.';
            $currency = null;
        }
        if ($allowed && $currency === null) {
            // Currency may be supplied per event, but the policy still records
            // that no fixed currency is required.
            $currency = null;
        }

        return ['allowed' => $allowed, 'currency' => $currency === null ? null : strtoupper($currency)];
    }

    /**
     * @param  array<string,string>  $errors
     * @param  array{allowed:bool}  $defaults
     * @return array{allowed:bool}
     */
    private function validateBooleanPolicy(mixed $value, string $field, array &$errors, array $defaults): array
    {
        if (! is_array($value) || array_is_list($value)) {
            $errors[$field] = 'Policy must be an object.';

            return $defaults;
        }
        $allowed = $value['allowed'] ?? $defaults['allowed'];
        if (! is_bool($allowed)) {
            $errors["{$field}.allowed"] = 'Policy allowance must be boolean.';
            $allowed = $defaults['allowed'];
        }

        return ['allowed' => $allowed];
    }

    /**
     * @param  array<string,string>  $errors
     * @return array{unmatched_identity_days:int}
     */
    private function validateRetentionPolicy(mixed $value, array &$errors): array
    {
        if (! is_array($value) || array_is_list($value)) {
            $errors['retention_policy'] = 'Retention policy must be an object.';

            return ['unmatched_identity_days' => 30];
        }
        $days = $value['unmatched_identity_days'] ?? ($value['identity_days'] ?? 30);
        if (is_string($days) && preg_match('/^[0-9]+$/', $days) === 1) {
            $days = (int) $days;
        }
        if (! is_int($days) || $days < 0 || $days > 30) {
            $errors['retention_policy.unmatched_identity_days'] = 'Unmatched identity retention must be between 0 and 30 days.';
            $days = 30;
        }

        return ['unmatched_identity_days' => $days];
    }

    /** @return list<string> */
    public static function sourceValues(): array
    {
        return array_map(static fn (ConversionSourceType $source): string => $source->value, ConversionSourceType::cases());
    }

    /** @return list<string> */
    public static function connectionKinds(): array
    {
        return array_map(static fn (ConversionConnectionKind $kind): string => $kind->value, ConversionConnectionKind::cases());
    }
}
