<?php

namespace App\Domain\Mail\Contracts;

interface ManagedMailEndpointChecker
{
    /**
     * @param  array<string, mixed>  $expectedRecords
     * @param  list<string>  $expectedHosts
     * @return array{forward_dns: 'pass'|'missing'|'unknown', ptr: 'pass'|'missing'|'unknown', tls: 'pass'|'missing'|'unknown'}
     */
    public function check(string $domain, array $expectedRecords, array $expectedHosts): array;
}
