<?php

use App\Domain\Mail\Contracts\DnsRecordResolver;
use App\Domain\Mail\Services\DnsMxResolver;

it('does not cache a resolver failure and retries the next lookup', function () {
    $resolver = new class implements DnsRecordResolver
    {
        public int $lookups = 0;

        /** @return list<array<string, mixed>>|null */
        public function lookup(string $host, string $type): ?array
        {
            $this->lookups++;

            return $this->lookups === 1
                ? null
                : [['target' => 'Mail.Example.com.']];
        }
    };
    $mx = new DnsMxResolver($resolver);

    expect($mx->resolveMx('example.com'))->toBe([])
        ->and($mx->resolveMx('example.com'))->toBe(['mail.example.com'])
        ->and($mx->resolveMx('example.com'))->toBe(['mail.example.com'])
        ->and($resolver->lookups)->toBe(2);
});

it('caches an authoritative empty MX response', function () {
    $resolver = new class implements DnsRecordResolver
    {
        public int $lookups = 0;

        /** @return list<array<string, mixed>> */
        public function lookup(string $host, string $type): array
        {
            $this->lookups++;

            return [];
        }
    };
    $mx = new DnsMxResolver($resolver);

    expect($mx->resolveMx('no-mail.example.com'))->toBe([])
        ->and($mx->resolveMx('no-mail.example.com'))->toBe([])
        ->and($resolver->lookups)->toBe(1);
});
