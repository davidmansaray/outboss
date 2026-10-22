<?php

namespace App\Domain\Gtm\Support;

use App\Domain\Gtm\Exceptions\GtmPayloadValidationException;
use Carbon\CarbonImmutable;

/**
 * Validate the deliberately small JSON contract emitted by the research model.
 *
 * The model is never allowed to submit profile envelopes, IDs, provenance, or
 * timestamps. Those values are stamped by the merge service after this class
 * has accepted the bounded plain-text output.
 */
final class GtmResearchOutputValidator
{
    /** @var array<string, list<string>> */
    private const SECTION_FIELDS = [
        'company' => ['name', 'category', 'product_description', 'target_problem', 'primary_outcome'],
        'market' => ['target_segment', 'timing_thesis'],
        'buyers' => [],
        'messaging' => ['brand_voice'],
        'qualification' => [],
    ];

    /** @var array<string, list<string>> */
    private const LIST_FIELDS = [
        'company' => [],
        'market' => ['geographies', 'company_traits', 'excluded_segments'],
        'buyers' => ['target_roles', 'buying_triggers', 'blocking_roles'],
        'messaging' => ['proof_points', 'prohibited_claims', 'message_constraints'],
        'qualification' => ['evidence_requirements', 'business_disqualifiers'],
    ];

    /** @var list<string> */
    private const CITABLE_FIELDS = [
        'synthesis',
        'company.name',
        'company.category',
        'company.product_description',
        'company.target_problem',
        'company.primary_outcome',
        'market.target_segment',
        'market.geographies',
        'market.company_traits',
        'market.excluded_segments',
        'market.timing_thesis',
        'buyers.target_roles',
        'buyers.buying_triggers',
        'buyers.blocking_roles',
        'messaging.proof_points',
        'messaging.prohibited_claims',
        'messaging.brand_voice',
        'messaging.message_constraints',
        'qualification.evidence_requirements',
        'qualification.business_disqualifiers',
    ];

    /**
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    public function validate(array $output): array
    {
        $allowed = ['synthesis', ...array_keys(self::SECTION_FIELDS), 'sources'];
        foreach (array_keys($output) as $key) {
            if (! in_array((string) $key, $allowed, true)) {
                $this->fail((string) $key, 'The research output contains an unsupported field.');
            }
        }

        $normalized = [];
        if (array_key_exists('synthesis', $output)) {
            $normalized['synthesis'] = $this->scalar('synthesis', $output['synthesis'], assertion: true);
        }

        foreach (array_keys(self::SECTION_FIELDS) as $section) {
            if (! array_key_exists($section, $output)) {
                continue;
            }
            if (! is_array($output[$section]) || array_is_list($output[$section])) {
                $this->fail($section, 'Each research section must be an object.');
            }

            /** @var array<string, mixed> $sectionOutput */
            $sectionOutput = $output[$section];
            $sectionNormalized = [];
            $sectionAllowed = [
                ...self::SECTION_FIELDS[$section],
                ...self::LIST_FIELDS[$section],
            ];
            foreach (array_keys($sectionOutput) as $field) {
                if (! in_array((string) $field, $sectionAllowed, true)) {
                    $this->fail($section.'.'.$field, 'The research field is not supported.');
                }
            }
            foreach (self::SECTION_FIELDS[$section] as $field) {
                if (array_key_exists($field, $sectionOutput)) {
                    $sectionNormalized[$field] = $this->scalar(
                        $section.'.'.$field,
                        $sectionOutput[$field],
                        assertion: ! in_array($field, ['name', 'category', 'brand_voice'], true),
                    );
                }
            }
            foreach (self::LIST_FIELDS[$section] as $field) {
                if (array_key_exists($field, $sectionOutput)) {
                    $sectionNormalized[$field] = $this->list($section.'.'.$field, $sectionOutput[$field]);
                }
            }
            $normalized[$section] = $sectionNormalized;
        }

        if (array_key_exists('sources', $output)) {
            $normalized['sources'] = $this->sources($output['sources']);
        } else {
            $normalized['sources'] = [];
        }

        $encoded = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false || strlen($encoded) > (int) config('gtm.research.max_checkpoint_bytes', 512 * 1024)) {
            $this->fail('output', 'The research output exceeds the bounded size limit.');
        }

        return $normalized;
    }

    /**
     * Validate and return the normalized source contract used by persistence
     * services. Keeping this as a separately typed boundary avoids leaking
     * the intentionally generic top-level output shape into source storage.
     *
     * @return list<array{url: string, title: string|null, excerpt: string|null, source_type: string, published_at: string|null}>
     */
    public function validateSources(mixed $sources): array
    {
        if (! is_array($sources)) {
            $this->fail('sources', 'Research sources must be a list.');
        }

        $validated = $this->validate(['sources' => $sources]);
        $value = $validated['sources'] ?? null;
        if (! is_array($value)) {
            throw new \RuntimeException('The normalized source contract is invalid.');
        }

        $result = [];
        foreach ($value as $source) {
            if (! is_array($source)) {
                throw new \RuntimeException('The normalized source contract is invalid.');
            }
            $url = $source['url'] ?? null;
            $title = $source['title'] ?? null;
            $excerpt = $source['excerpt'] ?? null;
            $sourceType = $source['source_type'] ?? null;
            $publishedAt = $source['published_at'] ?? null;
            if (! is_string($url)
                || ($title !== null && ! is_string($title))
                || ($excerpt !== null && ! is_string($excerpt))
                || ! is_string($sourceType)
                || ($publishedAt !== null && ! is_string($publishedAt))) {
                throw new \RuntimeException('The normalized source contract is invalid.');
            }
            $result[] = [
                'url' => $url,
                'title' => $title,
                'excerpt' => $excerpt,
                'source_type' => $sourceType,
                'published_at' => $publishedAt,
            ];
        }

        return $result;
    }

    /**
     * Validate model citations against opaque handles issued in this exact
     * prompt. Database source UUIDs are never exposed to or accepted from the
     * model.
     *
     * @param  array<string, int>  $allowedHandles
     * @param  array<string, mixed>  $normalizedOutput
     * @return array<string, list<int>>
     */
    public function validateCitations(
        mixed $citations,
        array $allowedHandles,
        array $normalizedOutput,
    ): array {
        if (! is_array($citations) || ($citations !== [] && array_is_list($citations))) {
            $this->fail('citations', 'Research citations must be an object keyed by field path.');
        }
        if (count($citations) > count(self::CITABLE_FIELDS)) {
            $this->fail('citations', 'The research output contains too many cited fields.');
        }

        $maximumPerField = max(1, (int) config('gtm.research.max_citations_per_field', 8));
        $result = [];
        foreach ($citations as $path => $handles) {
            if (! is_string($path)
                || ! in_array($path, self::CITABLE_FIELDS, true)
                || ! $this->outputHasFact($normalizedOutput, $path)) {
                $this->fail('citations.'.(string) $path, 'The citation field is not present in the research output.');
            }
            if (! is_array($handles)
                || ! array_is_list($handles)
                || $handles === []
                || count($handles) > $maximumPerField) {
                $this->fail('citations.'.$path, 'Each research fact needs a bounded list of source handles.');
            }

            $indexes = [];
            foreach ($handles as $handle) {
                if (! is_string($handle) || ! array_key_exists($handle, $allowedHandles)) {
                    $this->fail('citations.'.$path, 'The research output cited an unknown source handle.');
                }
                $indexes[] = $allowedHandles[$handle];
            }
            $result[$path] = array_values(array_unique($indexes));
        }

        foreach (self::CITABLE_FIELDS as $path) {
            if ($this->outputHasFact($normalizedOutput, $path) && ! array_key_exists($path, $result)) {
                $this->fail('citations.'.$path, 'Each research fact must cite at least one supplied source handle.');
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $output */
    private function outputHasFact(array $output, string $path): bool
    {
        $value = data_get($output, $path);

        return is_string($value)
            ? trim($value) !== ''
            : (is_array($value) && $value !== []);
    }

    private function scalar(string $path, mixed $value, bool $assertion): ?string
    {
        if ($value !== null && ! is_string($value)) {
            $this->fail($path, 'Research values must be strings or null.');
        }
        if ($value === null) {
            return null;
        }

        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
        $maximum = $assertion
            ? (int) config('gtm.limits.assertion_max_length', 5000)
            : (int) config('gtm.limits.identity_max_length', 160);
        if (mb_strlen($value) > $maximum) {
            $this->fail($path, 'The research value is too long.');
        }

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private function list(string $path, mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->fail($path, 'Research list fields must be arrays of strings.');
        }
        if (count($value) > (int) config('gtm.limits.repeated_items_max', 50)) {
            $this->fail($path, 'The research list contains too many items.');
        }

        $result = [];
        foreach ($value as $index => $item) {
            $normalized = $this->scalar($path.'.'.$index, $item, assertion: true);
            if ($normalized !== null && ! in_array($normalized, $result, true)) {
                $result[] = $normalized;
            }
        }

        return $result;
    }

    /**
     * @return list<array{url: string, title: string|null, excerpt: string|null, source_type: string, published_at: string|null}>
     */
    private function sources(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->fail('sources', 'Research sources must be a list.');
        }
        if (count($value) > (int) config('gtm.research.max_sources', 100)) {
            $this->fail('sources', 'The research output contains too many sources.');
        }

        $sources = [];
        $seen = [];
        foreach ($value as $index => $item) {
            if (! is_array($item) || array_is_list($item)) {
                $this->fail('sources.'.$index, 'Each research source must be an object.');
            }
            /** @var array<string, mixed> $source */
            $source = $item;
            foreach (array_keys($source) as $key) {
                if (! in_array((string) $key, ['url', 'title', 'excerpt', 'source_type', 'published_at'], true)) {
                    $this->fail('sources.'.$index.'.'.$key, 'Source metadata is not supported.');
                }
            }

            $rawUrl = $source['url'] ?? null;
            if (! is_string($rawUrl)) {
                $this->fail('sources.'.$index.'.url', 'Each research source needs a URL.');
            }
            try {
                $url = PublicWebsiteUrl::normalize($rawUrl);
            } catch (\InvalidArgumentException $exception) {
                $this->fail('sources.'.$index.'.url', $exception->getMessage());
            }
            if ($url === null) {
                $this->fail('sources.'.$index.'.url', 'Each research source needs a URL.');
            }

            $title = $source['title'] ?? null;
            if ($title !== null && ! is_string($title)) {
                $this->fail('sources.'.$index.'.title', 'Source titles must be strings or null.');
            }
            if (is_string($title)) {
                $title = trim(str_replace(["\r\n", "\r"], "\n", $title));
                if (mb_strlen($title) > (int) config('gtm.limits.identity_max_length', 160)) {
                    $this->fail('sources.'.$index.'.title', 'The source title is too long.');
                }
            }

            $excerpt = $source['excerpt'] ?? null;
            if ($excerpt !== null && ! is_string($excerpt)) {
                $this->fail('sources.'.$index.'.excerpt', 'Source excerpts must be strings or null.');
            }
            if (is_string($excerpt)) {
                $excerpt = trim(str_replace(["\r\n", "\r"], "\n", $excerpt));
                if (strlen($excerpt) > (int) config('gtm.limits.source_excerpt_bytes_max', 4096)) {
                    $this->fail('sources.'.$index.'.excerpt', 'The source excerpt is too long.');
                }
            }

            $sourceType = $source['source_type'] ?? 'website';
            if (! is_string($sourceType) || ! in_array($sourceType, ['website', 'extract', 'search'], true)) {
                $this->fail('sources.'.$index.'.source_type', 'The source type is not supported.');
            }

            $publishedAt = $source['published_at'] ?? null;
            if ($publishedAt !== null) {
                if (! is_string($publishedAt) || strlen($publishedAt) > 80) {
                    $this->fail('sources.'.$index.'.published_at', 'The source publication date is invalid.');
                }
                try {
                    $publishedAt = CarbonImmutable::parse($publishedAt)->toIso8601String();
                } catch (\Throwable) {
                    $this->fail('sources.'.$index.'.published_at', 'The source publication date is invalid.');
                }
            }

            $identity = $url.'|'.hash('sha256', (string) ($excerpt ?? ''));
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $sources[] = [
                'url' => $url,
                'title' => $title,
                'excerpt' => $excerpt,
                'source_type' => $sourceType,
                'published_at' => $publishedAt,
            ];
        }

        try {
            PublicWebsiteUrl::assertPublicNetworkBatch(array_column($sources, 'url'));
        } catch (\InvalidArgumentException $exception) {
            $this->fail('sources', $exception->getMessage());
        }

        return $sources;
    }

    private function fail(string $key, string $message): never
    {
        throw new GtmPayloadValidationException([$key => $message]);
    }
}
