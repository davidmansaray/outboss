<?php

namespace App\Domain\Mail\Contracts;

interface TlsEndpointChecker
{
    /** @return 'pass'|'missing'|'unknown' */
    public function check(string $host, int $port, int $timeoutSeconds): string;
}
