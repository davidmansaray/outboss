<?php

namespace App\Domain\Workspaces\Services;

use App\Domain\Gtm\Support\PublicWebsiteUrl;
use App\Models\Workspace;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

final class WorkspaceFaviconFetcher
{
    public function __construct(
        private readonly PublicWebsiteAddressResolver $addresses,
    ) {}

    /**
     * Refresh a workspace only when its canonical website still matches the
     * hash carried by the queued job.
     */
    public function refresh(int $workspaceId, string $expectedWebsiteHash): void
    {
        $workspace = Workspace::query()
            ->with('gtmProfile.currentVersion')
            ->find($workspaceId);
        if ($workspace === null) {
            return;
        }

        $website = $this->website($workspace);
        if ($website === null || ! hash_equals($expectedWebsiteHash, hash('sha256', $website))) {
            return;
        }

        $icon = null;
        try {
            $icon = $this->discover($website);
        } catch (ConnectionException|InvalidArgumentException|RuntimeException) {
            // A missing or temporarily unavailable favicon is an ordinary
            // fallback state. Operators can retry it with the refresh command.
        }

        $newPath = null;
        if ($icon !== null) {
            $contentHash = hash('sha256', $icon['contents']);
            $newPath = "workspace-avatars/{$workspaceId}/{$contentHash}.{$icon['extension']}";
            if (! Storage::disk('public')->put($newPath, $icon['contents'], ['visibility' => 'public'])) {
                throw new RuntimeException('The workspace avatar could not be stored.');
            }
        }

        $previousPath = null;
        $accepted = DB::transaction(function () use (
            $workspaceId,
            $expectedWebsiteHash,
            $icon,
            $newPath,
            &$previousPath,
        ): bool {
            $locked = Workspace::query()->whereKey($workspaceId)->lockForUpdate()->first();
            if ($locked === null) {
                return false;
            }
            $locked->load('gtmProfile.currentVersion');
            $currentWebsite = $this->website($locked);
            if ($currentWebsite === null
                || ! hash_equals($expectedWebsiteHash, hash('sha256', $currentWebsite))) {
                return false;
            }

            $previousPath = is_string($locked->avatar_path) ? $locked->avatar_path : null;
            $attemptedAt = now();
            $attributes = [
                'avatar_website_hash' => $expectedWebsiteHash,
                'avatar_refreshed_at' => $attemptedAt,
            ];

            if ($icon !== null) {
                $attributes['avatar_path'] = $newPath;
                $attributes['avatar_source_url'] = $icon['source_url'];
                $attributes['avatar_missing_since'] = null;
            } elseif ($previousPath === null) {
                $attributes['avatar_path'] = null;
                $attributes['avatar_source_url'] = null;
                $attributes['avatar_missing_since'] = $locked->avatar_missing_since ?? $attemptedAt;
            } elseif ($locked->avatar_website_hash !== $expectedWebsiteHash) {
                $attributes['avatar_path'] = null;
                $attributes['avatar_source_url'] = null;
                $attributes['avatar_missing_since'] = $attemptedAt;
            } else {
                // Keep a previously valid image during a temporary failed
                // refresh rather than replacing it with a less useful initial.
                $attributes['avatar_missing_since'] = null;
            }

            $locked->forceFill($attributes)->save();

            return true;
        }, 3);

        if (! $accepted) {
            app(WorkspaceAvatarManager::class)->deleteCachedPath($workspaceId, $newPath);

            return;
        }

        if ($previousPath !== $newPath) {
            app(WorkspaceAvatarManager::class)->deleteCachedPath($workspaceId, $previousPath);
        }
    }

    /**
     * @return array{contents: string, extension: string, source_url: string}|null
     */
    private function discover(string $website): ?array
    {
        try {
            $page = $this->requestFollowingRedirects(
                $website,
                (int) config('workspaces.avatars.max_html_bytes', 262_144),
                'text/html,application/xhtml+xml;q=0.9,*/*;q=0.1',
            );
        } catch (ConnectionException|InvalidArgumentException|RuntimeException) {
            $page = null;
        }

        $candidates = [];
        $baseUrl = $website;
        if ($page !== null) {
            $baseUrl = $page['url'];
            $contentType = strtolower($page['response']->header('Content-Type'));
            if ($contentType === '' || str_contains($contentType, 'html')) {
                $candidates = $this->htmlCandidates($baseUrl, $page['response']->body());
            }
        }

        $origin = $this->origin($baseUrl);
        if ($origin !== null) {
            $candidates[] = $origin.'/favicon.ico';
        }

        $maximum = max(1, (int) config('workspaces.avatars.max_candidates', 8));
        $candidates = array_slice(array_values(array_unique($candidates)), 0, $maximum);
        foreach ($candidates as $candidate) {
            try {
                $image = $this->requestFollowingRedirects(
                    $candidate,
                    (int) config('workspaces.avatars.max_image_bytes', 524_288),
                    'image/avif,image/webp,image/png,image/jpeg,image/gif,image/x-icon,*/*;q=0.1',
                );
            } catch (ConnectionException|InvalidArgumentException|RuntimeException) {
                continue;
            }
            if ($image === null) {
                continue;
            }

            $validated = $this->validatedImage($image['response']->body());
            if ($validated !== null) {
                return [
                    'contents' => $validated['contents'],
                    'extension' => $validated['extension'],
                    'source_url' => $image['url'],
                ];
            }
        }

        return null;
    }

    /**
     * @return array{url: string, response: Response}|null
     */
    private function requestFollowingRedirects(string $url, int $maximumBytes, string $accept): ?array
    {
        $maximumRedirects = max(0, (int) config('workspaces.avatars.max_redirects', 3));
        $current = PublicWebsiteUrl::normalize($url);
        if ($current === null) {
            return null;
        }

        for ($redirects = 0; $redirects <= $maximumRedirects; $redirects++) {
            $response = $this->request($current, $maximumBytes, $accept);
            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                $location = $response->header('Location');
                if ($location === '' || $redirects === $maximumRedirects) {
                    return null;
                }
                $current = $this->resolve($current, $location);

                continue;
            }

            if (! $response->successful()) {
                return null;
            }

            return ['url' => $current, 'response' => $response];
        }

        return null;
    }

    private function request(string $url, int $maximumBytes, string $accept): Response
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            throw new InvalidArgumentException('The favicon host is invalid.');
        }

        $addresses = $this->addresses->resolve($url);
        $address = collect($addresses)
            ->first(fn (string $candidate): bool => filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)
            ?? $addresses[0];
        $pinnedAddress = str_contains($address, ':') ? "[{$address}]" : $address;
        $options = [
            'allow_redirects' => false,
            'progress' => static function (
                int $downloadTotal,
                int $downloadedBytes,
            ) use ($maximumBytes): void {
                if ($downloadTotal > $maximumBytes || $downloadedBytes > $maximumBytes) {
                    throw new RuntimeException('The favicon response is too large.');
                }
            },
        ];
        if (defined('CURLOPT_RESOLVE')) {
            $options['curl'] = [CURLOPT_RESOLVE => ["{$host}:443:{$pinnedAddress}"]];
        } else {
            throw new RuntimeException('Favicon fetching requires cURL DNS pinning.');
        }

        $response = Http::withOptions($options)
            ->connectTimeout((int) config('workspaces.avatars.connect_timeout_seconds', 3))
            ->timeout((int) config('workspaces.avatars.timeout_seconds', 8))
            ->withHeaders([
                'Accept' => $accept,
                'User-Agent' => (string) config('workspaces.avatars.user_agent'),
            ])
            ->get($url);

        $declaredLength = $response->header('Content-Length');
        if (($declaredLength !== '' && (int) $declaredLength > $maximumBytes)
            || strlen($response->body()) > $maximumBytes) {
            throw new RuntimeException('The favicon response is too large.');
        }

        return $response;
    }

    /** @return list<string> */
    private function htmlCandidates(string $baseUrl, string $html): array
    {
        if ($html === '' || ! class_exists(\DOMDocument::class)) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $loaded = $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return [];
        }

        $ranked = [];
        foreach ($document->getElementsByTagName('link') as $link) {
            $rel = strtolower(trim($link->getAttribute('rel')));
            $tokens = preg_split('/\s+/', $rel) ?: [];
            if (! in_array('icon', $tokens, true) && ! str_contains($rel, 'apple-touch-icon')) {
                continue;
            }
            $href = trim($link->getAttribute('href'));
            if ($href === '') {
                continue;
            }
            try {
                $candidate = $this->resolve($baseUrl, $href);
            } catch (InvalidArgumentException) {
                continue;
            }
            $sizes = strtolower($link->getAttribute('sizes'));
            preg_match_all('/(\d+)x(\d+)/', $sizes, $matches, PREG_SET_ORDER);
            $score = 0;
            foreach ($matches as $match) {
                $score = max($score, min(1024, (int) $match[1]) * min(1024, (int) $match[2]));
            }
            $ranked[] = ['url' => $candidate, 'score' => $score];
        }

        usort($ranked, fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return array_values(array_unique(array_column($ranked, 'url')));
    }

    private function resolve(string $baseUrl, string $location): string
    {
        try {
            $resolved = (string) UriResolver::resolve(new Uri($baseUrl), new Uri(html_entity_decode($location)));
        } catch (\Throwable) {
            throw new InvalidArgumentException('The favicon URL is invalid.');
        }

        $normalized = PublicWebsiteUrl::normalize($resolved);
        if ($normalized === null) {
            throw new InvalidArgumentException('The favicon URL is invalid.');
        }

        return $normalized;
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! is_string($parts['host'] ?? null)) {
            return null;
        }

        return 'https://'.$parts['host'];
    }

    /** @return array{contents: string, extension: string}|null */
    private function validatedImage(string $contents): ?array
    {
        if (strlen($contents) >= 6 && substr($contents, 0, 4) === "\x00\x00\x01\x00") {
            return ['contents' => $contents, 'extension' => 'ico'];
        }

        $image = @getimagesizefromstring($contents);
        if (is_array($image)) {
            if ($image[0] > 2048 || $image[1] > 2048) {
                return null;
            }

            $extension = match (strtolower($image['mime'])) {
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                default => null,
            };

            if ($extension !== null) {
                return ['contents' => $contents, 'extension' => $extension];
            }
        }

        $svg = $this->sanitizedSvg($contents);

        return $svg === null ? null : ['contents' => $svg, 'extension' => 'svg'];
    }

    private function sanitizedSvg(string $contents): ?string
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $contents) === 1) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $loaded = $document->loadXML($contents, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $document->documentElement;
        if (! $loaded
            || $root === null
            || strtolower($root->localName) !== 'svg'
            || $root->namespaceURI !== 'http://www.w3.org/2000/svg') {
            return null;
        }

        $forbiddenElements = [
            'script', 'style', 'foreignobject', 'iframe', 'object', 'embed',
            'image', 'audio', 'video', 'animate', 'animatemotion',
            'animatetransform', 'set',
        ];
        foreach ($document->getElementsByTagName('*') as $element) {
            if (in_array(strtolower($element->localName), $forbiddenElements, true)) {
                return null;
            }
            foreach ($element->attributes as $attribute) {
                $name = strtolower($attribute->localName);
                $value = trim(html_entity_decode($attribute->value));
                if (str_starts_with($name, 'on')
                    || preg_match('/(?:javascript:|data:|@import|expression\s*\()/i', $value) === 1) {
                    return null;
                }
                if (($name === 'href' || $attribute->namespaceURI === 'http://www.w3.org/1999/xlink')
                    && $value !== '' && ! str_starts_with($value, '#')) {
                    return null;
                }
                if (preg_match('/url\s*\(\s*["\']?(?!#)/i', $value) === 1) {
                    return null;
                }
            }
        }

        $sanitized = $document->saveXML($root);

        return is_string($sanitized) && $sanitized !== '' ? $sanitized : null;
    }

    private function website(Workspace $workspace): ?string
    {
        $website = data_get($workspace, 'gtmProfile.currentVersion.payload.company.website_url.value');

        return is_string($website) && $website !== '' ? $website : null;
    }
}
