<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\Contracts\ManagedMailEndpointChecker;

/** Deterministic readiness evidence for the managed-mail test surface. */
final class FakeManagedMailEndpointChecker implements ManagedMailEndpointChecker
{
    /**
     * @param  array<string, mixed>  $expectedRecords
     * @param  list<string>  $expectedHosts
     * @return array{forward_dns: 'pass'|'missing'|'unknown', ptr: 'pass'|'missing'|'unknown', tls: 'pass'|'missing'|'unknown'}
     */
    public function check(string $domain, array $expectedRecords, array $expectedHosts): array
    {
        return [
            'forward_dns' => $this->recordStatus($expectedRecords, 'forward_dns'),
            'ptr' => $this->recordStatus($expectedRecords, 'ptr'),
            'tls' => $this->recordStatus($expectedRecords, 'tls'),
        ];
    }

    /**
     * @param  array<string, mixed>  $records
     * @return 'pass'|'missing'|'unknown'
     */
    private function recordStatus(array $records, string $key): string
    {
        $value = $records[$key] ?? null;
        if (is_array($value)) {
            $value = $value['status'] ?? null;
        }

        $value = strtolower(trim((string) $value));

        return in_array($value, ['pass', 'missing', 'unknown'], true) ? $value : 'unknown';
    }
}
