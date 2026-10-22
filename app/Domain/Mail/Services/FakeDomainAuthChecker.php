<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\Contracts\DomainAuthEvidenceChecker;

class FakeDomainAuthChecker implements DomainAuthEvidenceChecker
{
    /**
     * @var array<string, array{spf: 'pass'|'missing'|'unknown', dmarc: 'pass'|'missing'|'unknown', dkim?: 'pass'|'missing'|'unknown'}>
     */
    public static array $results = [];

    public static function reset(): void
    {
        self::$results = [];
    }

    public function check(string $domain): array
    {
        $result = self::$results[$domain] ?? [
            'spf' => 'missing',
            'dmarc' => 'missing',
            'dkim' => 'unknown',
        ];

        return [
            'spf' => $result['spf'],
            'dmarc' => $result['dmarc'],
            'dkim' => $result['dkim'] ?? 'unknown',
        ];
    }

    /**
     * The fake's configured result is the deterministic test evidence. The
     * production implementation never treats stored provider status fields as
     * an observation and instead performs the live DKIM lookup.
     *
     * @param  array<string, mixed>  $expectedRecords
     * @return array{spf: 'pass'|'missing'|'unknown', dmarc: 'pass'|'missing'|'unknown', dkim: 'pass'|'missing'|'unknown'}
     */
    public function checkWithExpectedRecords(string $domain, array $expectedRecords): array
    {
        return $this->check($domain);
    }
}
