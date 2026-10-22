<?php

namespace App\Domain\Mail\Contracts;

interface DomainAuthChecker
{
    /**
     * @return array{spf: 'pass'|'missing'|'unknown', dmarc: 'pass'|'missing'|'unknown', dkim: 'pass'|'missing'|'unknown'}
     */
    public function check(string $domain): array;
}
