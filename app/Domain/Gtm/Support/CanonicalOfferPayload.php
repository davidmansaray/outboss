<?php

namespace App\Domain\Gtm\Support;

use App\Domain\Gtm\Enums\OfferStatus;
use InvalidArgumentException;
use JsonException;

final class CanonicalOfferPayload
{
    /**
     * @return array<string, string>
     */
    public static function empty(): array
    {
        return [
            'name' => '',
            'promise' => '',
            'target_audience' => '',
            'desired_outcome' => '',
            'call_to_action' => '',
            'notes' => '',
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function schemaVersion(array $payload): int
    {
        return ($payload['schema_version'] ?? null) === 2 ? 2 : 1;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function canonicalize(array $payload): array
    {
        /** @var array<string, mixed> $normalized */
        $normalized = self::normalizeNode($payload);

        return $normalized;
    }

    /**
     * Compare Offer payloads while ignoring only canonical formatting
     * differences. Offer payloads currently contain no server-maintained
     * fields, so this intentionally remains a canonicalized complete object.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function comparable(array $payload): array
    {
        return self::canonicalize($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function json(array $payload): string
    {
        try {
            return json_encode(
                self::canonicalize($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The Offer payload cannot be encoded.', previous: $exception);
        }
    }

    /**
     * Include status in the hash because status transitions are versioned
     * independently of content edits.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function hash(array $payload, OfferStatus|string $status = OfferStatus::Draft): string
    {
        $statusValue = $status instanceof OfferStatus ? $status->value : $status;

        return hash('sha256', self::json($payload).'|status_snapshot:'.$statusValue);
    }

    private static function normalizeNode(mixed $value): mixed
    {
        if (is_string($value)) {
            return trim(str_replace(["\r\n", "\r"], "\n", $value));
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::normalizeNode($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::normalizeNode($item);
        }

        return $value;
    }
}
