<?php

namespace App\Domain\Gtm\Support;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class GtmProfilePayloadValidator
{
    /** @var array<string, list<string>> */
    private const SECTION_FIELDS = [
        'company' => ['name', 'website_url', 'category', 'product_description', 'target_problem', 'primary_outcome'],
        'market' => ['target_segment', 'geographies', 'company_traits', 'excluded_segments', 'timing_thesis'],
        'buyers' => ['target_roles', 'buying_triggers', 'blocking_roles'],
        'messaging' => ['proof_points', 'prohibited_claims', 'brand_voice', 'message_constraints'],
        'qualification' => ['dimensions', 'review_min', 'contact_min', 'evidence_requirements', 'business_disqualifiers'],
    ];

    /** @var list<string> */
    private const REPEATED_FIELDS = [
        'geographies',
        'company_traits',
        'excluded_segments',
        'target_roles',
        'buying_triggers',
        'blocking_roles',
        'proof_points',
        'prohibited_claims',
        'message_constraints',
        'evidence_requirements',
        'business_disqualifiers',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validate(array $payload): array
    {
        $allowed = ['synthesis', ...array_keys(self::SECTION_FIELDS)];
        foreach (array_keys($payload) as $key) {
            if (! in_array($key, $allowed, true)) {
                $this->fail($key, 'This profile field is not supported.');
            }
        }

        if (! array_key_exists('synthesis', $payload)) {
            $this->fail('synthesis', 'This profile field is required.');
        }
        $payload['synthesis'] = $this->validateScalar('synthesis', $payload['synthesis']);
        $seenRepeatedIds = [];
        foreach (array_keys(self::SECTION_FIELDS) as $section) {
            if (! array_key_exists($section, $payload)) {
                $this->fail($section, 'This profile section is required.');
            }
            if (! is_array($payload[$section])) {
                $this->fail($section, 'This profile section must be an object.');
            }
            $payload[$section] = $this->validateSection(
                (string) $section,
                $payload[$section],
                seenRepeatedIds: $seenRepeatedIds,
            );
        }

        return CanonicalGtmProfilePayload::canonicalize($payload);
    }

    /**
     * Validate an owner section patch. Server-owned provenance metadata is
     * accepted for replay compatibility but ignored by the mutation service.
     *
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    public function validateSectionPatch(string $section, array $patch): array
    {
        if (! isset(self::SECTION_FIELDS[$section])) {
            $this->fail('section', 'This profile section is not supported.');
        }

        if (array_key_exists($section, $patch) && count($patch) === 1 && is_array($patch[$section])) {
            $patch = $patch[$section];
        }

        $seenRepeatedIds = [];

        return $this->validateSection(
            $section,
            $patch,
            complete: false,
            seenRepeatedIds: $seenRepeatedIds,
        );
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, true>  $seenRepeatedIds
     * @return array<string, mixed>
     */
    private function validateSection(
        string $section,
        array $values,
        bool $complete = true,
        array &$seenRepeatedIds = [],
    ): array {
        $fields = self::SECTION_FIELDS[$section] ?? null;
        if ($fields === null) {
            $this->fail('section', 'This profile section is not supported.');
        }

        foreach (array_keys($values) as $key) {
            if (! in_array($key, $fields, true)) {
                $this->fail($section.'.'.$key, 'This profile field is not supported.');
            }
        }

        if ($complete) {
            foreach ($fields as $field) {
                if (! array_key_exists($field, $values)) {
                    $this->fail($section.'.'.$field, 'This profile field is required.');
                }
            }
        }

        foreach ($values as $field => $value) {
            $path = $section.'.'.$field;
            if (in_array($field, self::REPEATED_FIELDS, true)) {
                $values[$field] = $this->validateRepeated($path, $value, $seenRepeatedIds);
            } elseif ($field === 'dimensions') {
                $values[$field] = $this->validateDimensions($path, $value);
            } elseif (in_array($field, ['review_min', 'contact_min'], true)) {
                if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                    $this->fail($path, 'Use a whole-number threshold.');
                }
                $number = (int) $value;
                if ($number < 0 || $number > 100) {
                    $this->fail($path, 'Thresholds must be between 0 and 100.');
                }
                $values[$field] = $number;
            } else {
                $values[$field] = $this->validateScalar($path, $value);
            }
        }

        return CanonicalGtmProfilePayload::canonicalize($values);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateScalar(string $path, mixed $value): array
    {
        if (is_array($value)) {
            $allowed = ['value', 'provenance', 'source_ids', 'owner_locked', 'updated_at'];
            foreach (array_keys($value) as $key) {
                if (! in_array($key, $allowed, true)) {
                    $this->fail($path, 'This field contains unsupported metadata.');
                }
            }
            $raw = $value['value'] ?? null;
            $provenance = $value['provenance'] ?? 'ai_inferred';
            $sourceIds = $value['source_ids'] ?? [];
            $ownerLocked = $value['owner_locked'] ?? false;
            $updatedAt = $value['updated_at'] ?? null;
        } else {
            $raw = $value;
            $provenance = 'ai_inferred';
            $sourceIds = [];
            $ownerLocked = false;
            $updatedAt = null;
        }

        if ($raw !== null && ! is_scalar($raw)) {
            $this->fail($path, 'Profile values must be plain text or numbers.');
        }
        if (is_string($raw)) {
            $raw = trim($raw);
            $maximum = $this->maximumFor($path);
            if (mb_strlen($raw) > $maximum) {
                $this->fail($path, 'This value is too long.');
            }
            if (str_ends_with($path, 'website_url')) {
                try {
                    $raw = PublicWebsiteUrl::normalize($raw);
                } catch (InvalidArgumentException $exception) {
                    $this->fail($path, $exception->getMessage());
                }
            }
        }

        if (! is_string($provenance) || ! in_array($provenance, ['direct_source', 'ai_inferred', 'owner_supplied'], true)) {
            $this->fail($path, 'This provenance value is not supported.');
        }
        if (! is_array($sourceIds) || count($sourceIds) > (int) config('gtm.limits.current_version_sources_max', 100)) {
            $this->fail($path, 'This field contains too many sources.');
        }
        foreach ($sourceIds as $sourceId) {
            if (! is_string($sourceId) || preg_match('/^[0-9a-fA-F-]{36}$/', $sourceId) !== 1) {
                $this->fail($path, 'Source references must be UUIDs.');
            }
        }
        if (! is_bool($ownerLocked) || ($ownerLocked && $provenance !== 'owner_supplied')) {
            $this->fail($path, 'Owner lock metadata is invalid.');
        }
        if ($updatedAt !== null && ! is_string($updatedAt)) {
            $this->fail($path, 'The update timestamp is invalid.');
        }

        return [
            'value' => $raw,
            'provenance' => $provenance,
            'source_ids' => array_values(array_unique($sourceIds)),
            'owner_locked' => $ownerLocked,
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * @param  array<string, true>  $seenRepeatedIds
     * @return list<array<string, mixed>>
     */
    private function validateRepeated(string $path, mixed $value, array &$seenRepeatedIds): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->fail($path, 'This field must be a list.');
        }
        if (count($value) > (int) config('gtm.limits.repeated_items_max', 50)) {
            $this->fail($path, 'This list contains too many items.');
        }

        $items = [];
        foreach ($value as $index => $item) {
            $itemPath = $path.'.'.$index;
            // Item identity/tombstone metadata is validated separately from
            // the common value envelope. It is never trusted as provenance.
            $scalar = is_array($item)
                ? [...$item, 'id' => null, 'tombstoned' => null]
                : $item;
            if (is_array($scalar)) {
                unset($scalar['id'], $scalar['tombstoned']);
            }
            $envelope = $this->validateScalar($itemPath, $scalar);
            if (is_array($item) && array_key_exists('id', $item)) {
                if (! is_string($item['id']) || preg_match('/^[0-9a-fA-F-]{36}$/', $item['id']) !== 1) {
                    $this->fail($itemPath, 'Repeated item IDs must be UUIDs.');
                }
                $envelope['id'] = strtolower($item['id']);
            } else {
                $envelope['id'] = self::stableItemId($path, $envelope['value']);
            }
            if (isset($seenRepeatedIds[$envelope['id']])) {
                $this->fail(
                    $itemPath.'.id',
                    'Repeated item IDs must be unique across the profile.',
                );
            }
            $seenRepeatedIds[$envelope['id']] = true;
            if (is_array($item) && array_key_exists('tombstoned', $item) && ! is_bool($item['tombstoned'])) {
                $this->fail($itemPath, 'Repeated item tombstones must be boolean.');
            }
            $envelope['tombstoned'] = is_array($item) && ($item['tombstoned'] ?? false) === true;
            $items[] = $envelope;
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateDimensions(string $path, mixed $value): array
    {
        if (! is_array($value)) {
            $this->fail($path, 'Qualification dimensions must be a list.');
        }
        /** @var array<int, array{key: string, label: string, weight: int}> $configuredValues */
        $configuredValues = is_array(config('gtm.qualification.dimensions', []))
            ? config('gtm.qualification.dimensions', [])
            : [];
        $configured = [];
        foreach ($configuredValues as $configuredValue) {
            $configured[$configuredValue['key']] = $configuredValue;
        }
        $seen = [];
        $dimensions = [];
        foreach (array_values($value) as $index => $dimension) {
            $dimensionPath = $path.'.'.$index;
            if (! is_array($dimension)) {
                $this->fail($dimensionPath, 'Qualification dimensions must be objects.');
            }
            foreach (array_keys($dimension) as $dimensionField) {
                if (! in_array($dimensionField, ['key', 'label', 'weight'], true)) {
                    $this->fail($dimensionPath, 'Qualification dimension metadata is not supported.');
                }
            }
            $key = $dimension['key'] ?? null;
            if (! is_string($key) || ! isset($configured[$key]) || isset($seen[$key])) {
                $this->fail($dimensionPath, 'This qualification dimension is not supported.');
            }
            $seen[$key] = true;
            if (array_key_exists('label', $dimension)
                && $dimension['label'] !== $configured[$key]['label']) {
                $this->fail($dimensionPath, 'Qualification labels are fixed.');
            }
            if (array_key_exists('weight', $dimension)
                && $dimension['weight'] !== $configured[$key]['weight']) {
                $this->fail($dimensionPath, 'Qualification weights are fixed.');
            }
            $dimensions[] = [
                'key' => $key,
                'label' => is_string($dimension['label'] ?? null) ? trim($dimension['label']) : $configured[$key]['label'],
                'weight' => (int) ($dimension['weight'] ?? $configured[$key]['weight']),
            ];
        }
        if (count($configured) !== count($dimensions)) {
            $this->fail($path, 'All fixed qualification dimensions are required.');
        }

        return $dimensions;
    }

    private static function stableItemId(string $path, mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $hex = substr(hash('sha256', 'gtm-profile-item|'.$path.'|'.($encoded === false ? '' : $encoded)), 0, 32);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }

    private function maximumFor(string $path): int
    {
        if (str_ends_with($path, 'website_url')) {
            return (int) config('gtm.limits.url_max_length', 2048);
        }

        foreach (['name', 'category', 'brand_voice'] as $field) {
            if (str_ends_with($path, '.'.$field)) {
                return (int) config('gtm.limits.identity_max_length', 160);
            }
        }

        return (int) config('gtm.limits.assertion_max_length', 5000);
    }
}
