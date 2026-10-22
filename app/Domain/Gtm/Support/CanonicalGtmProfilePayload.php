<?php

namespace App\Domain\Gtm\Support;

use JsonException;

final class CanonicalGtmProfilePayload
{
    /**
     * Return an empty complete payload for factories and callers that do not
     * have a workspace name yet.
     *
     * @return array<string, mixed>
     */
    public static function empty(): array
    {
        return self::seed('', null);
    }

    /**
     * Build the complete first version for a workspace.
     *
     * @return array<string, mixed>
     */
    public static function seed(string $workspaceName, ?string $websiteUrl = null): array
    {
        $empty = static fn (): array => [
            'value' => null,
            'provenance' => 'ai_inferred',
            'source_ids' => [],
            'owner_locked' => false,
            'updated_at' => null,
        ];
        $owner = static fn (mixed $value): array => [
            'value' => $value,
            'provenance' => 'owner_supplied',
            'source_ids' => [],
            'owner_locked' => true,
            'updated_at' => null,
        ];
        $dimensions = config('gtm.qualification.dimensions', [
            ['key' => 'segment_fit', 'label' => 'Segment fit', 'weight' => 25],
            ['key' => 'problem_relevance', 'label' => 'Problem relevance', 'weight' => 20],
            ['key' => 'timing_trigger', 'label' => 'Timing / trigger', 'weight' => 20],
            ['key' => 'buyer_committee_fit', 'label' => 'Buyer committee fit', 'weight' => 15],
            ['key' => 'commercial_feasibility', 'label' => 'Commercial feasibility', 'weight' => 10],
            ['key' => 'evidence_quality_freshness', 'label' => 'Evidence quality / freshness', 'weight' => 10],
        ]);

        return [
            'synthesis' => [
                'value' => null,
                'provenance' => 'ai_inferred',
                'source_ids' => [],
                'owner_locked' => false,
                'updated_at' => null,
            ],
            'company' => [
                'name' => $owner($workspaceName),
                'website_url' => $owner($websiteUrl),
                'category' => $empty(),
                'product_description' => $empty(),
                'target_problem' => $empty(),
                'primary_outcome' => $empty(),
            ],
            'market' => [
                'target_segment' => $empty(),
                'geographies' => [],
                'company_traits' => [],
                'excluded_segments' => [],
                'timing_thesis' => $empty(),
            ],
            'buyers' => [
                'target_roles' => [],
                'buying_triggers' => [],
                'blocking_roles' => [],
            ],
            'messaging' => [
                'proof_points' => [],
                'prohibited_claims' => [],
                'brand_voice' => $empty(),
                'message_constraints' => [],
            ],
            'qualification' => [
                'dimensions' => is_array($dimensions) ? array_values($dimensions) : [],
                'review_min' => (int) config('gtm.qualification.review_min', 50),
                'contact_min' => (int) config('gtm.qualification.contact_min', 75),
                'evidence_requirements' => [],
                'business_disqualifiers' => [],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function canonicalize(array $payload): array
    {
        /** @var array<string, mixed> $canonical */
        $canonical = self::sortValue($payload);

        return $canonical;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function hash(array $payload): string
    {
        $encoded = self::json($payload);

        return hash('sha256', $encoded);
    }

    /**
     * Encode the canonical payload for hashing or JSON persistence.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function json(array $payload): string
    {
        try {
            $encoded = json_encode(
                self::canonicalize($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new \InvalidArgumentException('The profile payload cannot be encoded.', previous: $exception);
        }

        if (strlen($encoded) > (int) config('gtm.limits.canonical_payload_bytes_max', 512 * 1024)) {
            throw new \InvalidArgumentException('The profile payload exceeds the configured size limit.');
        }

        return $encoded;
    }

    /**
     * Compare payloads without treating server-maintained timestamps as content.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function comparable(array $payload): array
    {
        /** @var array<string, mixed> $comparable */
        $comparable = self::removeTimestamps($payload);

        return self::canonicalize($comparable);
    }

    /**
     * Remove internal repeated-item tombstones from a profile read model.
     *
     * Tombstones remain in immutable versions so later research cannot
     * silently resurrect an owner-deleted item. They are not part of the
     * payload exposed to the UI, exports, or execution consumers.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function visible(array $payload): array
    {
        /** @var array<string, mixed> $visible */
        $visible = self::withoutTombstones($payload);

        return $visible;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sortValue(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(static function (mixed $item): mixed {
                if (is_array($item)) {
                    return self::sortValue($item);
                }
                if (is_string($item)) {
                    return trim(str_replace(["\r\n", "\r"], "\n", $item));
                }

                return $item;
            }, $value);
        }

        $sorted = [];
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $sorted[(string) $key] = self::sortValue($item);
            } elseif (is_string($item)) {
                $sorted[(string) $key] = trim(str_replace(["\r\n", "\r"], "\n", $item));
            } else {
                $sorted[(string) $key] = $item;
            }
        }

        return $sorted;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function removeTimestamps(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(static function (mixed $item): mixed {
                return is_array($item) ? self::removeTimestamps($item) : $item;
            }, $value);
        }

        $without = [];
        foreach ($value as $key => $item) {
            if ($key === 'updated_at') {
                continue;
            }
            $without[(string) $key] = is_array($item) ? self::removeTimestamps($item) : $item;
        }

        return $without;
    }

    private static function withoutTombstones(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $visible = [];
            foreach ($value as $item) {
                if (is_array($item) && ($item['tombstoned'] ?? false) === true) {
                    continue;
                }

                $visible[] = self::withoutTombstones($item);
            }

            return $visible;
        }

        $visible = [];
        foreach ($value as $key => $item) {
            $visible[(string) $key] = self::withoutTombstones($item);
        }

        return $visible;
    }
}
