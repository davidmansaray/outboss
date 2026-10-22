<?php

namespace App\Domain\Prospecting\Support;

use App\Domain\Prospecting\Enums\ProspectSourceKind;

final class CanonicalSourceConfiguration
{
    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    public static function canonicalize(ProspectSourceKind|string $kind, array $configuration): array
    {
        $kind = self::kind($kind);
        $configuration = self::trimRecursive($configuration);

        $canonical = match ($kind) {
            ProspectSourceKind::Manual => [
                'ingress' => self::boundedString($configuration['ingress'] ?? 'web', 24),
                'system_managed' => (bool) ($configuration['system_managed'] ?? true),
            ],
            ProspectSourceKind::ApiImport => [
                'ingress' => self::boundedEnum($configuration['ingress'] ?? 'rest', ['rest', 'mcp']),
                'client_label' => self::nullableString($configuration['client_label'] ?? null, 120),
                'principal_fingerprint' => self::nullableString($configuration['principal_fingerprint'] ?? null, 128),
                'system_managed' => (bool) ($configuration['system_managed'] ?? true),
            ],
            ProspectSourceKind::CsvImport => [
                'filename' => self::nullableString($configuration['filename'] ?? null, 255),
                'input_sha256' => self::nullableHash($configuration['input_sha256'] ?? null),
                'headers' => self::boundedList($configuration['headers'] ?? [], 100, 120),
                'mapping' => self::boundedMap($configuration['mapping'] ?? [], 50, 120),
                'import_motion' => self::boundedEnum($configuration['import_motion'] ?? 'people', ['people', 'campaign_recipients']),
            ],
            ProspectSourceKind::ExistingAccounts => [
                'selection_type' => self::boundedEnum($configuration['selection_type'] ?? 'accounts', ['accounts', 'people', 'mixed']),
                'selection_hash' => self::nullableHash($configuration['selection_hash'] ?? null),
                'selection_count' => self::boundedInt($configuration['selection_count'] ?? 0, 0, 1_000_000),
            ],
            ProspectSourceKind::LocalBusiness, ProspectSourceKind::Provider => self::providerConfiguration($configuration),
        };

        ksort($canonical);

        return $canonical;
    }

    /** @param array<string, mixed> $configuration */
    /** @param array<string, mixed> $configuration */
    public static function hash(array $configuration): string
    {
        return hash('sha256', self::json($configuration));
    }

    /** @param array<string, mixed> $configuration */
    public static function json(array $configuration): string
    {
        return json_encode(self::sortRecursive($configuration), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public static function kind(ProspectSourceKind|string $kind): ProspectSourceKind
    {
        return $kind instanceof ProspectSourceKind ? $kind : ProspectSourceKind::from(strtolower(trim($kind)));
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private static function providerConfiguration(array $configuration): array
    {
        $allowed = ['capability', 'discovery_motion', 'source_family', 'provider', 'schema_version'];
        $result = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $configuration)) {
                $result[$key] = is_int($configuration[$key])
                    ? self::boundedInt($configuration[$key], 1, 1000)
                    : self::boundedString($configuration[$key], 120);
            }
        }

        return $result;
    }

    private static function boundedString(mixed $value, int $max): string
    {
        if (! is_string($value)) {
            throw new \InvalidArgumentException('Source configuration strings must be strings.');
        }

        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max) {
            throw new \InvalidArgumentException("Source configuration values must be 1-{$max} characters.");
        }

        return $value;
    }

    private static function nullableString(mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::boundedString($value, $max);
    }

    /** @param list<string> $allowed */
    private static function boundedEnum(mixed $value, array $allowed): string
    {
        $value = self::boundedString($value, 48);
        if (! in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException('Source configuration contains an unsupported value.');
        }

        return $value;
    }

    private static function boundedInt(mixed $value, int $min, int $max): int
    {
        if (! is_int($value) && ! (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1)) {
            throw new \InvalidArgumentException('Source configuration numbers must be bounded integers.');
        }
        $value = (int) $value;
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException('Source configuration number is outside its allowed bound.');
        }

        return $value;
    }

    private static function nullableHash(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || preg_match('/^[a-f0-9]{64}$/i', $value) !== 1) {
            throw new \InvalidArgumentException('Source configuration hashes must be SHA-256 values.');
        }

        return strtolower($value);
    }

    /** @return list<string> */
    private static function boundedList(mixed $value, int $maxItems, int $itemMax): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $maxItems) {
            throw new \InvalidArgumentException('Source configuration list is invalid or too large.');
        }
        $result = [];
        foreach ($value as $item) {
            $result[] = self::boundedString($item, $itemMax);
        }

        return array_values(array_unique($result));
    }

    /** @return array<string, string> */
    private static function boundedMap(mixed $value, int $maxItems, int $itemMax): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value)) || count($value) > $maxItems) {
            throw new \InvalidArgumentException('Source configuration mapping is invalid or too large.');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new \InvalidArgumentException('Source configuration mapping keys must be strings.');
            }
            $result[self::boundedString($key, $itemMax)] = self::boundedString($item, $itemMax);
        }

        return $result;
    }

    private static function trimRecursive(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map([self::class, 'trimRecursive'], $value);
        }

        return is_string($value) ? trim($value) : $value;
    }

    private static function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'sortRecursive'], $value);
        }
        ksort($value);

        return array_map([self::class, 'sortRecursive'], $value);
    }
}
