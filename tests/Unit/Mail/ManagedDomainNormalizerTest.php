<?php

use App\Domain\Mail\Enums\ManagedMailDomainKind;
use App\Domain\Mail\Support\ManagedDomainNormalizer;

it('normalises managed domains and derives the organisational domain', function () {
    expect(ManagedDomainNormalizer::normalize(' HTTPS://Outreach.CanAIUseIt.com./path '))
        ->toBe('outreach.canaiuseit.com')
        ->and(ManagedDomainNormalizer::organisationalDomain('outreach.canaiuseit.com'))
        ->toBe('canaiuseit.com')
        ->and(ManagedDomainNormalizer::kind('outreach.canaiuseit.com'))
        ->toBe(ManagedMailDomainKind::Subdomain)
        ->and(ManagedDomainNormalizer::kind('canaiuseit.com'))
        ->toBe(ManagedMailDomainKind::Root);
});

it('converts IDN labels to their ASCII form', function () {
    expect(ManagedDomainNormalizer::normalize('BÜCHER.de'))->toBe('xn--bcher-kva.de');
});

it('rejects local, IP, reserved, and bare public suffix values', function (string $value) {
    expect(ManagedDomainNormalizer::normalize($value))->toBeNull();
})->with([
    'localhost',
    'mx.localhost',
    'mail.internal',
    '127.0.0.1',
    'co.uk',
    'example.test',
    'example.invalid',
    'com',
]);

it('normalises conservative managed mailbox local parts', function () {
    expect(ManagedDomainNormalizer::normalizeLocalPart(' Sales.Team '))->toBe('sales.team')
        ->and(ManagedDomainNormalizer::normalizeLocalPart('sales..team'))->toBeNull()
        ->and(ManagedDomainNormalizer::normalizeLocalPart('sales team'))->toBeNull()
        ->and(ManagedDomainNormalizer::normalizeLocalPart('sales@example.com'))->toBeNull();
});
