<?php

namespace App\Domain\Mail\Contracts;

interface DnsResolver
{
    /**
     * Return the public MX hostnames observed for a domain in resolver order.
     * An empty list means the lookup failed or no MX record was present.
     *
     * @return list<string>
     */
    public function resolveMx(string $domain): array;
}
