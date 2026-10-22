<?php

use App\Domain\Mail\Contracts\DnsRecordResolver;
use App\Domain\Mail\Contracts\TlsEndpointChecker;
use App\Domain\Mail\Services\DnsManagedMailEndpointChecker;

it('requires live forward DNS and matching PTR/TLS evidence instead of stored status fields', function () {
    $resolver = new class implements DnsRecordResolver
    {
        /** @var array<string, list<array<string, mixed>>> */
        public array $answers = [
            'mail.example.com|A' => [['data' => '203.0.113.10']],
            'mail.example.com|AAAA' => [],
            '203.0.113.10|PTR' => [['data' => 'mail.example.com.']],
        ];

        /** @return list<array<string, mixed>> */
        public function lookup(string $host, string $type): array
        {
            return $this->answers[strtolower(trim($host, " .\t\n\r\0\x0B")).'|'.strtoupper($type)] ?? [];
        }
    };
    $tls = new class implements TlsEndpointChecker
    {
        /** @return 'pass'|'missing'|'unknown' */
        public function check(string $host, int $port, int $timeoutSeconds): string
        {
            return $host === 'mail.example.com' && $port === 443 ? 'pass' : 'missing';
        }
    };
    config()->set('outboss.managed_mail.instances.internal.outbound_ipv4', '203.0.113.10');
    $checker = new DnsManagedMailEndpointChecker($resolver, $tls);

    expect($checker->check('example.com', [
        'managed_instance_key' => 'internal',
        'forward_dns' => ['status' => 'pass'],
        'ptr' => ['status' => 'pass'],
        'tls' => ['status' => 'pass'],
    ], ['mail.example.com']))
        ->toBe(['forward_dns' => 'pass', 'ptr' => 'pass', 'tls' => 'pass']);

    $resolver->answers['203.0.113.10|PTR'] = [['data' => 'wrong.example.com.']];

    expect($checker->check('example.com', ['managed_instance_key' => 'internal'], ['mail.example.com'])['ptr'])->toBe('missing');
});

it('does not pass PTR without an explicit instance outbound address', function () {
    config()->set('outboss.managed_mail.instances.internal.outbound_ipv4', null);
    $resolver = new class implements DnsRecordResolver
    {
        /** @return list<array<string, mixed>> */
        public function lookup(string $host, string $type): array
        {
            return $type === 'A' ? [['data' => '203.0.113.10']] : [];
        }
    };
    $tls = new class implements TlsEndpointChecker
    {
        /** @return 'pass'|'missing'|'unknown' */
        public function check(string $host, int $port, int $timeoutSeconds): string
        {
            return 'pass';
        }
    };
    $checker = new DnsManagedMailEndpointChecker($resolver, $tls);

    expect($checker->check('example.com', [], ['mail.example.com']))
        ->toMatchArray(['forward_dns' => 'pass', 'ptr' => 'unknown', 'tls' => 'pass']);
});

it('does not combine forward, PTR, and TLS evidence from different hosts', function () {
    $resolver = new class implements DnsRecordResolver
    {
        /** @var array<string, list<array<string, mixed>>> */
        public array $answers = [
            'mail-a.example.com|A' => [['data' => '203.0.113.10']],
            'mail-a.example.com|AAAA' => [],
            'mail-b.example.com|A' => [['data' => '203.0.113.10']],
            'mail-b.example.com|AAAA' => [],
            '203.0.113.10|PTR' => [['data' => 'mail-a.example.com.']],
        ];

        /** @return list<array<string, mixed>> */
        public function lookup(string $host, string $type): array
        {
            return $this->answers[strtolower(trim($host, " .\t\n\r\0\x0B")).'|'.strtoupper($type)] ?? [];
        }
    };
    $tls = new class implements TlsEndpointChecker
    {
        /** @return 'pass'|'missing'|'unknown' */
        public function check(string $host, int $port, int $timeoutSeconds): string
        {
            return $host === 'mail-b.example.com' ? 'pass' : 'unknown';
        }
    };
    config()->set('outboss.managed_mail.instances.internal.outbound_ipv4', '203.0.113.10');
    $checker = new DnsManagedMailEndpointChecker($resolver, $tls);

    $result = $checker->check('example.com', [
        'managed_instance_key' => 'internal',
    ], ['mail-a.example.com', 'mail-b.example.com']);

    expect($result)->not->toBe([
        'forward_dns' => 'pass',
        'ptr' => 'pass',
        'tls' => 'pass',
    ])
        ->and($result)->toBe([
            'forward_dns' => 'pass',
            'ptr' => 'pass',
            'tls' => 'unknown',
        ]);
});
