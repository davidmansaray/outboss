<?php

use App\Domain\Mail\Support\EmailNormalizer;
use App\Domain\Mail\Support\HtmlSanitizer;

it('keeps stylesheets, images, and inline styles while stripping scripts', function () {
    $html = <<<'HTML'
        <html><head><style>.outer > p { font-family: "Helvetica Neue", sans-serif; color: #111; }</style></head>
        <body>
        <div class="outer" style="padding: 12px; background: url(https://cdn.example.test/bg.png);">
        <p>Hi <a href="http://example.test/page">link</a></p>
        <img src="http://example.test/logo.png" alt="logo">
        <script>alert(1)</script>
        <iframe src="https://evil.test"></iframe>
        <form action="https://evil.test"><input type="text"></form>
        <p onclick="alert(1)">click</p>
        </div>
        </body></html>
        HTML;

    $sanitized = app(HtmlSanitizer::class)->sanitize($html);

    expect($sanitized)->toContain('.outer > p { font-family: "Helvetica Neue", sans-serif; color: #111; }')
        ->and($sanitized)->toContain('src="https://example.test/logo.png"')
        ->and($sanitized)->toContain('<a href="https://example.test/page"')
        ->and($sanitized)->toContain('style="padding: 12px;')
        ->and($sanitized)->toContain('class="outer"')
        ->and($sanitized)->not->toContain('<script')
        ->and($sanitized)->not->toContain('<iframe')
        ->and($sanitized)->not->toContain('<form')
        ->and($sanitized)->not->toContain('onclick');
});

it('does not truncate large emails', function () {
    $html = '<p>'.str_repeat('word ', 10_000).'</p>';

    $sanitized = app(HtmlSanitizer::class)->sanitize($html);

    expect(mb_strlen((string) $sanitized))->toBeGreaterThan(20_000);
});

it('passes through null and empty bodies', function () {
    expect(app(HtmlSanitizer::class)->sanitize(null))->toBeNull()
        ->and(app(HtmlSanitizer::class)->sanitize('   '))->toBe('   ');
});

it('derives plain text without style or script contents for snippets', function () {
    $html = '<style>div.x { padding: 0 }</style><p>Hello world</p><script>alert(1)</script>More';

    expect(EmailNormalizer::htmlToText($html))->toBe('Hello world More')
        ->and(EmailNormalizer::htmlToText(null))->toBe('')
        ->and(EmailNormalizer::htmlToText('<b>Bold</b> text'))->toBe('Bold text');
});
