<?php

namespace App\Domain\Mail\Contracts;

/**
 * Bounded public DNS lookup used by managed-mail readiness checks.
 *
 * A null result means the resolver could not complete the lookup. An empty
 * list is an authoritative no-answer result and is therefore distinguishable
 * from an unavailable resolver.
 */
interface DnsRecordResolver
{
    /**
     * @param  'A'|'AAAA'|'MX'|'PTR'|'TXT'  $type
     * @return list<array<string, mixed>>|null
     */
    public function lookup(string $host, string $type): ?array;
}
