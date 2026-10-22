<?php

namespace App\Domain\Mail\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer as SymfonyHtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class HtmlSanitizer
{
    private const MAX_INPUT_LENGTH = 2_000_000;

    public function sanitize(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return $html;
        }

        $styles = $this->extractStylesheets($html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;

        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowAttribute('style', '*')
            ->allowAttribute('class', '*')
            ->allowAttribute('bgcolor', '*')
            ->allowAttribute('border', '*')
            ->allowAttribute('background', '*')
            ->dropElement('script')
            ->dropElement('iframe')
            ->dropElement('form')
            ->dropElement('object')
            ->dropElement('embed')
            ->dropElement('link')
            ->dropElement('video')
            ->dropElement('audio')
            ->dropElement('source')
            ->forceHttpsUrls()
            ->withMaxInputLength(self::MAX_INPUT_LENGTH);

        $sanitized = (new SymfonyHtmlSanitizer($config))->sanitize($html);

        if ($styles !== []) {
            $sanitized = implode("\n", $styles)."\n".$sanitized;
        }

        return $sanitized;
    }

    /**
     * Style blocks are extracted before sanitization because the sanitizer
     * entity-encodes text nodes, which would corrupt CSS. The extracted CSS is
     * re-appended verbatim; it is rendered inside a script-less sandboxed
     * iframe, so stylesheet content cannot execute.
     *
     * @return list<string>
     */
    private function extractStylesheets(string $html): array
    {
        if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $matches) !== 1) {
            return [];
        }

        $styles = [];

        foreach ($matches[1] as $css) {
            $css = preg_replace('/<!--|-->/u', '', trim($css)) ?? '';

            if (trim($css) !== '') {
                $styles[] = '<style>'.$css.'</style>';
            }
        }

        return $styles;
    }
}
