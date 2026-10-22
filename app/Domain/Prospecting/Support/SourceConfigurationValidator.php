<?php

namespace App\Domain\Prospecting\Support;

use App\Domain\Prospecting\DTOs\SourcePolicySnapshot;
use App\Domain\Prospecting\Enums\ProspectSourceKind;
use App\Domain\Prospecting\Exceptions\SourcePolicyViolation;
use Illuminate\Validation\ValidationException;

final class SourceConfigurationValidator
{
    /** @return array<string, mixed> */
    public function validate(ProspectSourceKind|string $kind, mixed $configuration): array
    {
        if (! is_array($configuration) || ($configuration !== [] && array_is_list($configuration))) {
            throw ValidationException::withMessages(['configuration' => 'Source configuration must be an object.']);
        }

        try {
            return CanonicalSourceConfiguration::canonicalize($kind, $configuration);
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['configuration' => $exception->getMessage()]);
        }
    }

    /** @return array<string, mixed> */
    public function validatePolicy(mixed $policy): array
    {
        if (! is_array($policy) || array_is_list($policy)) {
            throw ValidationException::withMessages(['policy' => 'Source policy must be an object.']);
        }

        $defaults = [
            'provenance_class' => 'native',
            'provider' => null,
            'contract_version' => null,
            'allowed_purposes' => ['prospecting'],
            'prohibited_uses' => [],
            'geography' => null,
            'personal_data_classification' => 'standard',
            'display_fields' => [],
            'display_requirements' => [],
            'display_classification' => 'standard',
            'raw_retention_days' => 0,
            'normalized_retention_days' => 365,
            'export_allowed' => true,
            'export_fields' => [],
            'export_classification' => 'standard',
            'deletion_behavior' => 'delete_source_data',
            'contact_eligibility' => 'eligible',
            'policy_reviewed_at' => null,
            'policy_expires_at' => null,
            'approval_reference' => null,
        ];
        $unknown = array_diff(array_keys($policy), array_keys($defaults));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['policy' => 'Source policy contains an unsupported field.']);
        }
        $canonical = array_replace($defaults, $policy);
        if (! is_string($canonical['provenance_class']) || mb_strlen($canonical['provenance_class']) > 80) {
            throw ValidationException::withMessages(['policy.provenance_class' => 'Choose a bounded provenance class.']);
        }
        foreach (['allowed_purposes', 'prohibited_uses', 'display_fields', 'display_requirements', 'export_fields'] as $field) {
            if (! is_array($canonical[$field]) || ! array_is_list($canonical[$field]) || count($canonical[$field]) > 100) {
                throw ValidationException::withMessages(["policy.{$field}" => 'Policy lists must be bounded arrays.']);
            }
            foreach ($canonical[$field] as $item) {
                if (! is_string($item) || trim($item) === '' || mb_strlen($item) > 120) {
                    throw ValidationException::withMessages(["policy.{$field}" => 'Policy list values must be bounded strings.']);
                }
            }
        }
        foreach (['raw_retention_days', 'normalized_retention_days'] as $field) {
            if (! is_int($canonical[$field]) && ! (is_string($canonical[$field]) && preg_match('/^[0-9]+$/', $canonical[$field]) === 1)) {
                throw ValidationException::withMessages(["policy.{$field}" => 'Retention must be a bounded number of days.']);
            }
            $canonical[$field] = (int) $canonical[$field];
            if ($canonical[$field] < 0 || $canonical[$field] > 3650) {
                throw ValidationException::withMessages(["policy.{$field}" => 'Retention must be between 0 and 3650 days.']);
            }
        }
        if (! is_bool($canonical['export_allowed'])) {
            throw ValidationException::withMessages(['policy.export_allowed' => 'Export allowance must be boolean.']);
        }
        foreach (['display_classification', 'export_classification'] as $field) {
            if (! is_string($canonical[$field]) || ! in_array($canonical[$field], ['standard', 'restricted', 'prohibited'], true)) {
                throw ValidationException::withMessages(["policy.{$field}" => 'Policy classification is invalid.']);
            }
        }
        if (! in_array($canonical['deletion_behavior'], ['delete_source_data', 'retain'], true)) {
            throw ValidationException::withMessages(['policy.deletion_behavior' => 'Deletion behavior is invalid.']);
        }
        if (! in_array($canonical['contact_eligibility'], ['eligible', 'restricted', 'ineligible'], true)) {
            throw ValidationException::withMessages(['policy.contact_eligibility' => 'Contact eligibility is invalid.']);
        }
        foreach (['policy_reviewed_at', 'policy_expires_at'] as $field) {
            if ($canonical[$field] !== null && (! is_string($canonical[$field]) || trim($canonical[$field]) === '')) {
                throw ValidationException::withMessages(["policy.{$field}" => 'Policy dates must be ISO date strings.']);
            }
            if (is_string($canonical[$field])) {
                try {
                    new \DateTimeImmutable($canonical[$field]);
                } catch (\Throwable) {
                    throw ValidationException::withMessages(["policy.{$field}" => 'Policy dates must be ISO date strings.']);
                }
            }
        }

        $canonical['geography'] = $this->validateGeography($canonical['geography']);
        foreach (['provider', 'contract_version', 'personal_data_classification', 'approval_reference'] as $field) {
            if ($canonical[$field] !== null && (! is_string($canonical[$field]) || mb_strlen($canonical[$field]) > 160)) {
                throw ValidationException::withMessages(["policy.{$field}" => 'Policy values must be bounded strings.']);
            }
        }

        return SourcePolicySnapshot::canonicalize($canonical);
    }

    private function validateGeography(mixed $geography): mixed
    {
        if ($geography === null) {
            return null;
        }
        if (is_string($geography)) {
            if (trim($geography) === '' || mb_strlen($geography) > 160) {
                throw ValidationException::withMessages(['policy.geography' => 'Geography must be a bounded value.']);
            }

            return trim($geography);
        }
        if (! is_array($geography) || count($geography) > 20) {
            throw ValidationException::withMessages(['policy.geography' => 'Geography must be a bounded value.']);
        }
        foreach ($geography as $value) {
            $valueType = get_debug_type($value);
            if ($valueType !== 'string' && $valueType !== 'int') {
                throw ValidationException::withMessages(['policy.geography' => 'Geography values must be bounded strings.']);
            }
            if (mb_strlen((string) $value) > 160) {
                throw ValidationException::withMessages(['policy.geography' => 'Geography values must be bounded strings.']);
            }
        }

        return $geography;
    }

    /** @param array<string, mixed> $policy */
    public function assertProviderPolicyReady(array $policy): void
    {
        if (($policy['provenance_class'] ?? 'native') !== 'native' && trim((string) ($policy['approval_reference'] ?? '')) === '') {
            throw new SourcePolicyViolation('Provider source policy approval is required before a run can start.', 'approval_missing');
        }
        if (SourcePolicySnapshot::from($policy)->isExpired()) {
            throw new SourcePolicyViolation('Provider source policy has expired.', 'policy_expired');
        }
    }
}
