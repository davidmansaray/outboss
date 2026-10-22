<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\Contracts\DnsRecordResolver;
use App\Domain\Mail\Contracts\DnsResolver;
use Illuminate\Support\Facades\Cache;

class DnsMxResolver implements DnsResolver
{
    public function __construct(private readonly ?DnsRecordResolver $records = null) {}

    public function resolveMx(string $domain): array
    {
        $domain = strtolower(trim($domain, " .\t\n\r\0\x0B"));

        if ($domain === '' || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return [];
        }

        $ttl = max(60, (int) config('outboss.experiments.dns_cache_seconds', 3600));
        // Version the key so deployments do not inherit empty results cached by
        // the former implementation when its upstream resolver was unavailable.
        $key = 'outboss:recipient-mx:v2:'.$domain;
        $cached = Cache::get($key);
        if (is_array($cached)) {
            $cachedHosts = [];
            $valid = true;
            foreach ($cached as $host) {
                if (! is_string($host)) {
                    $valid = false;

                    break;
                }

                $cachedHosts[] = $host;
            }

            if ($valid) {
                return $cachedHosts;
            }
        }

        $records = ($this->records ?? new DohDnsRecordResolver)->lookup($domain, 'MX');
        if ($records === null) {
            // A resolver failure is not an authoritative no-answer response and
            // must remain retryable rather than poisoning the cache for an hour.
            return [];
        }

        $hosts = [];
        foreach ($records as $record) {
            $host = $record['target'] ?? $record['data'] ?? null;

            if (! is_string($host) || trim($host) === '') {
                continue;
            }

            $hosts[] = strtolower(trim($host, " .\t\n\r\0\x0B"));
        }

        $hosts = array_values(array_unique($hosts));
        Cache::put($key, $hosts, $ttl);

        return $hosts;
    }
}
