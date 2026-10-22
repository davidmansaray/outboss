<?php

namespace App\Domain\Gtm\Support;

use App\Domain\Gtm\Enums\FulfilmentMode;
use App\Domain\Gtm\Exceptions\GtmPayloadValidationException;
use DateTimeImmutable;
use Throwable;

final class OfferPayloadValidator
{
    /**
     * @return array<string, mixed>
     */
    public function validate(mixed $payload): array
    {
        if (is_array($payload) && ($payload['schema_version'] ?? null) === 2) {
            return $this->validateV2($payload);
        }

        return $this->validateV1($payload);
    }

    /**
     * @param  array<string, mixed>|mixed  $payload
     * @return array<string, mixed>
     */
    private function validateV1(mixed $payload): array
    {
        $errors = [];
        $fields = [
            'name' => 'identity',
            'promise' => 'assertion',
            'target_audience' => 'assertion',
            'desired_outcome' => 'assertion',
            'call_to_action' => 'assertion',
            'notes' => 'notes',
        ];

        if (! is_array($payload) || array_is_list($payload)) {
            throw new GtmPayloadValidationException(['payload' => 'An Offer payload must be an object.']);
        }

        foreach ($fields as $field => $kind) {
            if (! array_key_exists($field, $payload)) {
                $errors[$field] = 'This field is required.';

                continue;
            }
            if (! is_string($payload[$field])) {
                $errors[$field] = 'Offer content must be plain text.';

                continue;
            }
            $limit = match ($kind) {
                'identity' => (int) config('gtm.limits.identity_max_length', 160),
                'notes' => (int) config('gtm.limits.notes_max_length', 2000),
                default => (int) config('gtm.limits.assertion_max_length', 5000),
            };
            $length = function_exists('mb_strlen') ? mb_strlen($payload[$field]) : strlen($payload[$field]);
            if ($length > $limit) {
                $errors[$field] = "Content may contain at most $limit characters.";
            }
        }

        foreach (array_keys($payload) as $field) {
            if (! array_key_exists($field, $fields)) {
                $errors[(string) $field] = 'Unknown field.';
            }
        }

        if ($errors !== []) {
            throw new GtmPayloadValidationException($errors);
        }

        return CanonicalOfferPayload::canonicalize($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateV2(array $payload): array
    {
        $errors = [];
        $allowed = [
            'schema_version', 'name', 'promise', 'target_audience',
            'desired_outcome', 'call_to_action', 'notes', 'proposition',
            'requested_commitment', 'cta', 'fulfilment', 'eligibility',
            'commercial_authority', 'approved_claims', 'prohibited_claims',
            'expires_at', 'agent_parameter_policy',
        ];

        foreach (array_keys($payload) as $field) {
            if (! in_array($field, $allowed, true)) {
                $errors[(string) $field] = 'Unknown field.';
            }
        }

        $this->stringField($payload, 'name', $errors, true, (int) config('gtm.limits.identity_max_length', 160));
        foreach (['promise', 'target_audience', 'desired_outcome', 'call_to_action'] as $field) {
            $this->stringField($payload, $field, $errors, true, (int) config('gtm.limits.assertion_max_length', 5000));
        }
        $this->stringField($payload, 'notes', $errors, false, (int) config('gtm.limits.notes_max_length', 2000));
        $this->stringField($payload, 'requested_commitment', $errors, true, 2000);

        $proposition = $this->objectField($payload, 'proposition', $errors);
        foreach (['problem_or_opportunity', 'value', 'proof'] as $field) {
            $this->stringField($proposition, $field, $errors, $field !== 'proof', 5000, "proposition.$field");
        }
        $this->rejectUnknown($proposition, ['problem_or_opportunity', 'value', 'proof'], 'proposition', $errors);

        $cta = $this->objectField($payload, 'cta', $errors);
        $this->stringField($cta, 'action', $errors, true, 80, 'cta.action');
        $this->stringField($cta, 'destination_url', $errors, false, 2048, 'cta.destination_url');
        $this->stringField($cta, 'response_route', $errors, false, 160, 'cta.response_route');
        $this->stringField($cta, 'acceptance_conversion_definition_version_id', $errors, true, 36, 'cta.acceptance_conversion_definition_version_id');
        $this->stringList($cta, 'required_inputs', $errors, 20, 160, 'cta.required_inputs');
        $destination = $cta['destination_url'] ?? null;
        if (is_string($destination) && $destination !== '' && (filter_var($destination, FILTER_VALIDATE_URL) === false || parse_url($destination, PHP_URL_SCHEME) !== 'https')) {
            $errors['cta.destination_url'] = 'The CTA destination must be an HTTPS URL.';
        }
        $this->uuidField($cta, 'acceptance_conversion_definition_version_id', $errors, 'cta.acceptance_conversion_definition_version_id');
        $this->rejectUnknown($cta, ['action', 'destination_url', 'response_route', 'required_inputs', 'acceptance_conversion_definition_version_id'], 'cta', $errors);

        $fulfilment = $this->objectField($payload, 'fulfilment', $errors);
        $mode = $fulfilment['mode'] ?? null;
        if (! is_string($mode) || FulfilmentMode::tryFrom($mode) === null) {
            $errors['fulfilment.mode'] = 'Choose a supported fulfilment mode.';
        }
        $this->stringField($fulfilment, 'capability_id', $errors, false, 36, 'fulfilment.capability_id');
        if (($fulfilment['capability_id'] ?? '') !== '') {
            $this->uuidField($fulfilment, 'capability_id', $errors, 'fulfilment.capability_id');
        }
        $this->stringList($fulfilment, 'asset_version_ids', $errors, 20, 36, 'fulfilment.asset_version_ids', true);
        foreach (($fulfilment['asset_version_ids'] ?? []) as $index => $id) {
            if (! is_string($id) || ! $this->isUuid($id)) {
                $errors["fulfilment.asset_version_ids.$index"] = 'Asset version IDs must be UUIDs.';
            }
        }
        $this->nullableInteger($fulfilment, 'owner_user_id', $errors, 1, null, 'fulfilment.owner_user_id');
        $this->nullableInteger($fulfilment, 'response_sla_hours', $errors, 1, 8760, 'fulfilment.response_sla_hours');
        $this->nullableInteger($fulfilment, 'capacity_units', $errors, 1, 100000, 'fulfilment.capacity_units');
        $this->rejectUnknown($fulfilment, ['mode', 'capability_id', 'asset_version_ids', 'owner_user_id', 'response_sla_hours', 'capacity_units'], 'fulfilment', $errors);

        $eligibility = $this->objectField($payload, 'eligibility', $errors);
        $this->stringList($eligibility, 'includes', $errors, 50, 500, 'eligibility.includes');
        $this->stringList($eligibility, 'excludes', $errors, 50, 500, 'eligibility.excludes');
        $this->rejectUnknown($eligibility, ['includes', 'excludes'], 'eligibility', $errors);

        $authority = $this->objectField($payload, 'commercial_authority', $errors);
        if (! is_bool($authority['approved'] ?? null)) {
            $errors['commercial_authority.approved'] = 'Commercial authority approval must be explicit.';
        }
        $this->stringField($authority, 'notes', $errors, false, 2000, 'commercial_authority.notes');
        $this->rejectUnknown($authority, ['approved', 'notes'], 'commercial_authority', $errors);

        $this->stringList($payload, 'approved_claims', $errors, 50, 500, 'approved_claims');
        $this->stringList($payload, 'prohibited_claims', $errors, 50, 500, 'prohibited_claims');
        $this->stringField($payload, 'expires_at', $errors, false, 64, 'expires_at');
        $expiry = $payload['expires_at'] ?? null;
        if (is_string($expiry) && $expiry !== '') {
            try {
                new DateTimeImmutable($expiry);
            } catch (Throwable) {
                $errors['expires_at'] = 'The Offer expiry must be an ISO-8601 date and time.';
            }
        }

        $parameters = $this->objectField($payload, 'agent_parameter_policy', $errors);
        $this->stringList($parameters, 'allowed_keys', $errors, 30, 120, 'agent_parameter_policy.allowed_keys');
        $this->rejectUnknown($parameters, ['allowed_keys'], 'agent_parameter_policy', $errors);

        if ($errors !== []) {
            throw new GtmPayloadValidationException($errors);
        }

        return CanonicalOfferPayload::canonicalize($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $errors
     */
    private function stringField(array $payload, string $field, array &$errors, bool $required, int $limit, ?string $errorKey = null): void
    {
        $key = $errorKey ?? $field;
        if (! array_key_exists($field, $payload) || $payload[$field] === null) {
            if ($required) {
                $errors[$key] = 'This field is required.';
            }

            return;
        }
        if (! is_string($payload[$field])) {
            $errors[$key] = 'This field must be plain text.';

            return;
        }
        $value = trim($payload[$field]);
        if ($required && $value === '') {
            $errors[$key] = 'This field is required.';
        } elseif (mb_strlen($value) > $limit) {
            $errors[$key] = "This field may contain at most $limit characters.";
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $errors
     * @return array<string, mixed>
     */
    private function objectField(array $payload, string $field, array &$errors): array
    {
        $value = $payload[$field] ?? null;
        if (! is_array($value) || array_is_list($value)) {
            $errors[$field] = 'This field must be an object.';

            return [];
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $errors
     */
    private function stringList(array $payload, string $field, array &$errors, int $maxItems, int $maxLength, string $errorKey, bool $optional = false): void
    {
        if ($optional && ! array_key_exists($field, $payload)) {
            return;
        }
        $value = $payload[$field] ?? [];
        if (! is_array($value) || ! array_is_list($value) || count($value) > $maxItems) {
            $errors[$errorKey] = "This field must be a list with at most $maxItems items.";

            return;
        }
        foreach ($value as $index => $item) {
            if (! is_string($item) || trim($item) === '' || mb_strlen(trim($item)) > $maxLength) {
                $errors["$errorKey.$index"] = "Each item must be non-empty text with at most $maxLength characters.";
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $errors
     */
    private function nullableInteger(array $payload, string $field, array &$errors, int $minimum, ?int $maximum, string $errorKey): void
    {
        if (! array_key_exists($field, $payload) || $payload[$field] === null) {
            return;
        }
        $value = $payload[$field];
        if (! is_int($value) || $value < $minimum || ($maximum !== null && $value > $maximum)) {
            $errors[$errorKey] = 'This field is outside the supported numeric range.';
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $errors
     */
    private function uuidField(array $payload, string $field, array &$errors, string $errorKey): void
    {
        if (! is_string($payload[$field] ?? null) || ! $this->isUuid($payload[$field])) {
            $errors[$errorKey] = 'This field must be a UUID.';
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $allowed
     * @param  array<string, string>  $errors
     */
    private function rejectUnknown(array $payload, array $allowed, string $prefix, array &$errors): void
    {
        foreach (array_keys($payload) as $field) {
            if (! in_array($field, $allowed, true)) {
                $errors[$prefix.'.'.(string) $field] = 'Unknown field.';
            }
        }
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    public static function assert(mixed $payload): array
    {
        return (new self)->validate($payload);
    }
}
