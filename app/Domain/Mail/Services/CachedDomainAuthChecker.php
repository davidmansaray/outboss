<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\Contracts\DomainAuthChecker;
use Illuminate\Support\Facades\Cache;

class CachedDomainAuthChecker
{
    public function __construct(private DomainAuthChecker $checker) {}

    /**
     * @return array{spf: 'pass'|'missing'|'unknown', dmarc: 'pass'|'missing'|'unknown', dkim: 'pass'|'missing'|'unknown'}
     */
    public function check(string $domain): array
    {
        $key = 'domain-auth:'.strtolower(trim($domain));

        return Cache::remember($key, 3600, fn () => $this->checker->check($domain));
    }
}
