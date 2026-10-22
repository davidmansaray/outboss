<?php

use App\Domain\Mail\Contracts\DnsRecordResolver;
use App\Domain\Mail\Services\DnsDomainAuthChecker;
use App\Domain\Mail\Services\DohDnsRecordResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('treats v=spf1 and v=dmarc1 txt records as a pass', function () {
    $checker = new DnsDomainAuthChecker;

    expect($checker->statusForRecords([['txt' => 'v=spf1 include:_spf.google.com ~all']], 'v=spf1'))->toBe('pass')
        ->and($checker->statusForRecords([['txt' => 'v=DMARC1; p=none']], 'v=dmarc1'))->toBe('pass')
        ->and($checker->statusForRecords([['txt' => 'something-else']], 'v=spf1'))->toBe('missing')
        ->and($checker->statusForRecords([], 'v=spf1'))->toBe('missing');
});

it('requires a valid DMARC policy tag', function (string $record, string $expected) {
    $checker = new DnsDomainAuthChecker;

    expect($checker->statusForRecords([['txt' => $record]], 'v=dmarc1'))->toBe($expected);
})->with([
    'none policy' => ['v=DMARC1; p=none', 'pass'],
    'quarantine policy with mixed case' => ['v=dmarc1; P=Quarantine; rua=mailto:dmarc@example.com', 'pass'],
    'reject policy' => ['v=DMARC1; p=reject; pct=100', 'pass'],
    'missing policy' => ['v=DMARC1; rua=mailto:dmarc@example.com', 'missing'],
    'empty policy' => ['v=DMARC1; p=', 'missing'],
    'invalid policy' => ['v=DMARC1; p=monitor', 'missing'],
    'duplicate policy' => ['v=DMARC1; p=none; p=reject', 'missing'],
    'version is not first' => ['p=reject; v=DMARC1', 'missing'],
    'malformed tag' => ['v=DMARC1; p=reject; broken', 'missing'],
]);

it('checks the provisioner selector and exact DKIM public key', function () {
    $resolver = new class implements DnsRecordResolver
    {
        /** @var array<string, list<array<string, mixed>>> */
        public array $answers = [
            'example.com|TXT' => [['data' => 'v=spf1 include:_spf.example.test ~all']],
            '_dmarc.example.com|TXT' => [['data' => 'v=DMARC1; p=none']],
            's1._domainkey.example.com|TXT' => [['data' => 'v=DKIM1; k=rsa; p=PUBLIC-KEY']],
        ];

        /** @return list<array<string, mixed>> */
        public function lookup(string $host, string $type): array
        {
            return $this->answers[strtolower(trim($host)).'|'.strtoupper($type)] ?? [];
        }
    };
    $checker = new DnsDomainAuthChecker($resolver);
    $expected = [
        'spf' => [
            'host' => 'example.com',
            'value' => 'v=spf1 include:_spf.example.test ~all',
        ],
        'dkim' => [
            'host' => 's1._domainkey.example.com',
            'value' => 'v=DKIM1; k=rsa; p=PUBLIC-KEY',
        ],
    ];

    expect($checker->checkWithExpectedRecords('example.com', $expected))
        ->toMatchArray(['spf' => 'pass', 'dmarc' => 'pass', 'dkim' => 'pass']);

    $resolver->answers['s1._domainkey.example.com|TXT'] = [
        ['data' => 'v=DKIM1; k=rsa; p=ANOTHER-KEY'],
    ];

    expect($checker->checkWithExpectedRecords('example.com', $expected)['dkim'])->toBe('missing');
});

it('requires every provider-advertised DKIM key to be published', function () {
    $resolver = new class implements DnsRecordResolver
    {
        /** @var array<string, list<array<string, mixed>>> */
        public array $answers = [
            'ed._domainkey.example.com|TXT' => [['data' => 'v=DKIM1; k=ed25519; p=ED-KEY']],
        ];

        /** @return list<array<string, mixed>> */
        public function lookup(string $host, string $type): array
        {
            return $this->answers[strtolower(trim($host)).'|'.strtoupper($type)] ?? [];
        }
    };
    $checker = new DnsDomainAuthChecker($resolver);
    $expected = [
        'dkim' => [
            ['host' => 'ed._domainkey.example.com', 'value' => 'v=DKIM1; k=ed25519; p=ED-KEY'],
            ['host' => 'rsa._domainkey.example.com', 'value' => 'v=DKIM1; k=rsa; p=RSA-KEY'],
        ],
    ];

    expect($checker->checkWithExpectedRecords('example.com', $expected)['dkim'])->toBe('missing');

    $resolver->answers['rsa._domainkey.example.com|TXT'] = [
        ['data' => 'v=DKIM1; k=rsa; p=RSA-KEY'],
    ];

    expect($checker->checkWithExpectedRecords('example.com', $expected)['dkim'])->toBe('pass');
});

it('discovers every DKIM key in a flattened zone-file fallback', function () {
    $resolver = new class implements DnsRecordResolver
    {
        /** @return list<array<string, mixed>> */
        public function lookup(string $host, string $type): array
        {
            return match (strtolower($host)) {
                'ed._domainkey.example.com' => [['data' => 'v=DKIM1; k=ed25519; p=ED-KEY']],
                'rsa._domainkey.example.com' => [['data' => 'v=DKIM1; k=rsa; p=RSA-KEY']],
                default => [],
            };
        }
    };
    $expected = [
        'zone_file' => 'ed._domainkey.example.com. IN TXT "v=DKIM1; k=ed25519; p=ED-KEY" '
            .'rsa._domainkey.example.com. IN TXT ( "v=DKIM1; k=rsa; p=RSA-" "KEY" ) '
            .'example.com. IN TXT "v=spf1 mx -all"',
    ];

    expect((new DnsDomainAuthChecker($resolver))->checkWithExpectedRecords('example.com', $expected)['dkim'])
        ->toBe('pass');
});

it('keeps DKIM unknown when the provider did not return a selector or DNS failed', function () {
    $resolver = new class implements DnsRecordResolver
    {
        /** @return list<array<string, mixed>>|null */
        public function lookup(string $host, string $type): ?array
        {
            return null;
        }
    };
    $checker = new DnsDomainAuthChecker($resolver);

    expect($checker->checkWithExpectedRecords('example.com', [])['dkim'])->toBe('unknown')
        ->and($checker->check('example.com')['spf'])->toBe('unknown');
});

it('does not treat an SPF deny-all record as managed-server authorization', function () {
    $resolver = new class implements DnsRecordResolver
    {
        /** @var array<string, list<array<string, mixed>>> */
        public array $answers = [
            'example.com|TXT' => [['data' => 'v=spf1 -all']],
            '_dmarc.example.com|TXT' => [['data' => 'v=DMARC1; p=none']],
        ];

        /** @return list<array<string, mixed>> */
        public function lookup(string $host, string $type): array
        {
            return $this->answers[strtolower(trim($host)).'|'.strtoupper($type)] ?? [];
        }
    };
    $checker = new DnsDomainAuthChecker($resolver);

    expect($checker->checkWithExpectedRecords('example.com', [
        'outbound_ipv4' => '203.0.113.10',
        'spf' => ['value' => 'v=spf1 ip4:203.0.113.10 -all'],
    ]))
        ->toMatchArray(['spf' => 'missing', 'dmarc' => 'pass']);
});

it('accepts an exact mx-based SPF record when the managed endpoint has an outbound IP', function () {
    $resolver = new class implements DnsRecordResolver
    {
        /** @return list<array<string, mixed>> */
        public function lookup(string $host, string $type): array
        {
            return match (strtolower($host).'|'.strtoupper($type)) {
                'example.com|TXT' => [['data' => 'v=spf1 mx -all']],
                '_dmarc.example.com|TXT' => [['data' => 'v=DMARC1; p=reject']],
                default => [],
            };
        }
    };

    expect((new DnsDomainAuthChecker($resolver))->checkWithExpectedRecords('example.com', [
        'outbound_ipv4' => '203.0.113.10',
        'spf' => ['host' => 'example.com', 'value' => 'v=spf1 mx -all'],
    ]))
        ->toMatchArray(['spf' => 'pass', 'dmarc' => 'pass']);
});

it('prefers structured DKIM records over a flattened zone-file fallback', function () {
    $resolver = new class implements DnsRecordResolver
    {
        /** @return list<array<string, mixed>> */
        public function lookup(string $host, string $type): array
        {
            return strtolower($host) === 's1._domainkey.example.com'
                ? [['data' => 'v=DKIM1; k=rsa; p=RIGHT-KEY']]
                : [];
        }
    };

    $expected = [
        'dkim' => [[
            'host' => 's1._domainkey.example.com',
            'value' => 'v=DKIM1; k=rsa; p=RIGHT-KEY',
        ]],
        'zone_file' => 's1._domainkey.example.com. IN TXT "v=DKIM1; k=rsa; p=RIGHT-KEY" example.com. IN TXT "v=spf1 mx -all"',
    ];

    expect((new DnsDomainAuthChecker($resolver))->checkWithExpectedRecords('example.com', $expected)['dkim'])
        ->toBe('pass');
});

it('requires a selector and parseable expected DKIM key before observing DNS', function () {
    $resolver = new class implements DnsRecordResolver
    {
        /** @return list<array<string, mixed>> */
        public function lookup(string $host, string $type): array
        {
            return [['data' => 'v=DKIM1; k=rsa; p=ANY-OBSERVED-KEY']];
        }
    };
    $checker = new DnsDomainAuthChecker($resolver);

    expect($checker->checkWithExpectedRecords('example.com', [
        'dkim' => ['host' => 's1._domainkey.example.com', 'value' => 'v=DKIM1; k=rsa'],
    ])['dkim'])->toBe('unknown');
});

it('normalises a provisioner selector and public-key pair before matching DKIM', function () {
    $resolver = new class implements DnsRecordResolver
    {
        /** @return list<array<string, mixed>> */
        public function lookup(string $host, string $type): array
        {
            return strtolower($host) === 's1._domainkey.example.com'
                ? [['data' => 'v=DKIM1; k=rsa; p=PUBLIC-KEY']]
                : [];
        }
    };
    $checker = new DnsDomainAuthChecker($resolver);

    expect($checker->checkWithExpectedRecords('example.com', [
        'dkim' => ['selector' => 's1', 'public_key' => 'PUBLIC-KEY'],
    ])['dkim'])->toBe('pass');
});

it('uses a bounded HTTPS DNS resolver and normalises TXT and MX answers', function () {
    Http::fake(function (Request $request) {
        $type = $request->data()['type'] ?? null;

        return Http::response([
            'Status' => 0,
            'Answer' => $type === 15
                ? [['name' => 'example.com.', 'type' => 15, 'data' => '10 mx.example.com.']]
                : [['name' => 'example.com.', 'type' => 16, 'data' => '"v=spf1" " include:example.test ~all"']],
        ]);
    });
    $resolver = new DohDnsRecordResolver('https://dns.example.test/resolve', 1);

    expect($resolver->lookup('Example.com', 'TXT'))
        ->toBe([['name' => 'example.com.', 'type' => 'TXT', 'data' => 'v=spf1 include:example.test ~all']])
        ->and($resolver->lookup('example.com', 'MX'))
        ->toBe([['name' => 'example.com.', 'type' => 'MX', 'data' => 'mx.example.com']]);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/resolve'
        && str_contains($request->url(), 'name=example.com')
        && str_contains($request->url(), 'type=')
        && $request->hasHeader('Accept', 'application/dns-json'));
});
