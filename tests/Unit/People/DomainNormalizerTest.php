<?php

use App\Domain\People\Support\DomainNormalizer;

it('normalises websites and domains', function (?string $input, ?string $expected) {
    expect(DomainNormalizer::normalize($input))->toBe($expected);
})->with([
    ['https://www.Acme.co.uk/about', 'acme.co.uk'],
    ['HTTP://WWW.EXAMPLE.COM:8080/path?q=1#hash', 'example.com'],
    ['  www.example.com  ', 'example.com'],
    ['example.com.', 'example.com'],
    ['//example.com/about', 'example.com'],
    ['https://user:pass@www.example.com/x', 'example.com'],
    ['app.acme.com', 'app.acme.com'],
    ['https://app.acme.com/dashboard', 'app.acme.com'],
    ['acme', null],
    ['https://acme', null],
    ['localhost', null],
    ['', null],
    ['   ', null],
    [null, null],
]);

it('rejects values without a dot after stripping', function () {
    expect(DomainNormalizer::normalize('https://intranet'))->toBeNull()
        ->and(DomainNormalizer::normalize('www.internal'))->toBeNull();
});

it('detects free-mail domains', function (string $domain, bool $expected) {
    expect(DomainNormalizer::isFreeMailDomain($domain))->toBe($expected);
})->with([
    ['gmail.com', true],
    ['GMAIL.COM', true],
    ['https://www.googlemail.com', true],
    ['outlook.com', true],
    ['hotmail.com', true],
    ['live.com', true],
    ['yahoo.com', true],
    ['icloud.com', true],
    ['me.com', true],
    ['aol.com', true],
    ['proton.me', true],
    ['protonmail.com', true],
    ['gmx.com', true],
    ['mail.com', true],
    ['zoho.com', true],
    ['yandex.com', true],
    ['acme.com', false],
    ['mail.acme.com', false],
    ['gmail.com.evil.test', false],
]);

it('extracts a domain from an email address', function (?string $email, ?string $expected) {
    expect(DomainNormalizer::fromEmail($email))->toBe($expected);
})->with([
    ['maya@northstar.studio', 'northstar.studio'],
    ['MAYA@GMAIL.COM', 'gmail.com'],
    ['maya@app.acme.com', 'app.acme.com'],
    ['not-an-email', null],
    ['maya@', null],
    ['@acme.com', 'acme.com'],
    ['', null],
    [null, null],
]);
