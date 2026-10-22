<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\Contracts\DnsResolver;

class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, list<string>> */
    private static array $records = [];

    public static function reset(): void
    {
        self::$records = [];
    }

    /**
     * @param  list<string>  $records
     */
    public static function set(string $domain, array $records): void
    {
        self::$records[strtolower(trim($domain))] = $records;
    }

    /**
     * @param  array<string, list<string>>  $records
     */
    public static function setMany(array $records): void
    {
        foreach ($records as $domain => $mx) {
            self::set($domain, $mx);
        }
    }

    public function resolveMx(string $domain): array
    {
        return self::$records[strtolower(trim($domain))] ?? [];
    }
}
