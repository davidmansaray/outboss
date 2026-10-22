<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\Contracts\DnsRecordResolver;
use App\Domain\Mail\Contracts\ManagedMailEndpointChecker;
use App\Domain\Mail\Contracts\TlsEndpointChecker;

/**
 * Observe the public mail host, reverse DNS, and its TLS endpoint.
 *
 * Only live observations can return pass. Any provider-supplied status field
 * is deliberately ignored here; it is retained for operator context only.
 */
final class DnsManagedMailEndpointChecker implements ManagedMailEndpointChecker
{
    public function __construct(
        private readonly ?DnsRecordResolver $records = null,
        private readonly ?TlsEndpointChecker $tls = null,
    ) {}

    /**
     * @param  array<string, mixed>  $expectedRecords
     * @param  list<string>  $expectedHosts
     * @return array{forward_dns: 'pass'|'missing'|'unknown', ptr: 'pass'|'missing'|'unknown', tls: 'pass'|'missing'|'unknown'}
     */
    public function check(string $domain, array $expectedRecords, array $expectedHosts): array
    {
        $hosts = $this->normaliseHosts($expectedHosts);
        if ($hosts === []) {
            return ['forward_dns' => 'unknown', 'ptr' => 'unknown', 'tls' => 'unknown'];
        }

        $outboundIp = $this->outboundIp($expectedRecords);
        $observations = [];
        foreach ($hosts as $host) {
            [$forwardStatus, $addresses] = $this->forwardStatus($host);
            $observations[] = [
                'forward_dns' => $forwardStatus,
                'ptr' => $this->ptrStatus($outboundIp, $host, $forwardStatus, $addresses),
                'tls' => $this->tlsStatus($host, $expectedRecords),
            ];
        }

        // Forward DNS, PTR, and TLS are one endpoint assertion. Never take
        // forward DNS from one host, PTR from another, and TLS from a third.
        // A complete chain is returned only when all three checks pass for
        // the same host. For an incomplete multi-host result, report the
        // strongest single-host observation in deterministic input order.
        foreach ($observations as $observation) {
            if ($observation['forward_dns'] === 'pass'
                && $observation['ptr'] === 'pass'
                && $observation['tls'] === 'pass') {
                return $observation;
            }
        }

        $best = $this->bestObservation($observations);

        return $best ?? ['forward_dns' => 'unknown', 'ptr' => 'unknown', 'tls' => 'unknown'];
    }

    /**
     * @return array{0: 'pass'|'missing'|'unknown', 1: list<string>}
     */
    private function forwardStatus(string $host): array
    {
        $resolverFailed = false;
        $sawAnswer = false;
        $addresses = [];

        foreach (['A', 'AAAA'] as $type) {
            $records = ($this->records ?? new DohDnsRecordResolver)->lookup($host, $type);
            if ($records === null) {
                $resolverFailed = true;

                continue;
            }

            $sawAnswer = true;
            foreach ($records as $record) {
                $address = $record['address'] ?? $record['ip'] ?? $record['data'] ?? null;
                if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                    $addresses[] = $address;
                }
            }
        }

        $addresses = array_values(array_unique($addresses));
        if ($addresses !== []) {
            return ['pass', $addresses];
        }

        return [$resolverFailed || ! $sawAnswer ? 'unknown' : 'missing', []];
    }

    /**
     * @param  'pass'|'missing'|'unknown'  $forwardStatus
     * @param  list<string>  $forwardAddresses
     * @return 'pass'|'missing'|'unknown'
     */
    private function ptrStatus(?string $outboundIp, string $host, string $forwardStatus, array $forwardAddresses): string
    {
        if ($outboundIp === null) {
            // PTR is an assertion about the configured outbound address. Do
            // not infer it from an arbitrary A record returned for the host.
            return 'unknown';
        }

        if ($forwardStatus === 'unknown') {
            return 'unknown';
        }

        // PTR only belongs to this host when the configured outbound address
        // is one of this host's forward addresses. An empty/failed forward
        // lookup can therefore never produce a PTR pass.
        if ($forwardAddresses === [] || ! in_array($outboundIp, $forwardAddresses, true)) {
            return 'missing';
        }

        $records = ($this->records ?? new DohDnsRecordResolver)->lookup($outboundIp, 'PTR');
        if ($records === null) {
            return 'unknown';
        }
        if ($records === []) {
            return 'missing';
        }

        foreach ($records as $record) {
            $ptr = $record['target'] ?? $record['data'] ?? $record['value'] ?? null;
            if (! is_string($ptr)) {
                continue;
            }

            $ptr = strtolower(trim($ptr, " .\t\n\r\0\x0B"));
            if ($ptr === $host) {
                return 'pass';
            }
        }

        return 'missing';
    }

    /**
     * @param  array<string, mixed>  $expectedRecords
     * @return 'pass'|'missing'|'unknown'
     */
    private function tlsStatus(string $host, array $expectedRecords): string
    {
        $port = $this->tlsPort($expectedRecords);
        $status = ($this->tls ?? new NativeTlsEndpointChecker)->check(
            $host,
            $port,
            $this->timeoutSeconds(),
        );
        if ($status === 'pass') {
            return 'pass';
        }

        // A refused/unreachable endpoint is not proof that its certificate is
        // invalid; keep it unknown so the operator can retry after recovery.
        return 'unknown';
    }

    /**
     * @param  list<array{forward_dns: 'pass'|'missing'|'unknown', ptr: 'pass'|'missing'|'unknown', tls: 'pass'|'missing'|'unknown'}>  $observations
     * @return array{forward_dns: 'pass'|'missing'|'unknown', ptr: 'pass'|'missing'|'unknown', tls: 'pass'|'missing'|'unknown'}|null
     */
    private function bestObservation(array $observations): ?array
    {
        $best = null;
        $bestScore = -1;
        foreach ($observations as $observation) {
            $score = 0;
            foreach ($observation as $status) {
                $score += $status === 'pass' ? 1 : 0;
            }

            if ($score > $bestScore) {
                $best = $observation;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /** @param array<string, mixed> $records */
    private function outboundIp(array $records): ?string
    {
        $instanceKey = trim((string) ($records['managed_instance_key'] ?? ''));
        $values = [];
        foreach ([
            $records['outbound_ipv4'] ?? null,
            $records['outbound_ip'] ?? null,
            is_array($records['ptr'] ?? null) ? ($records['ptr']['ip'] ?? null) : null,
            $instanceKey !== ''
                ? config('outboss.managed_mail.instances.'.$instanceKey.'.outbound_ipv4')
                : null,
        ] as $value) {
            if (is_string($value) && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $values[] = $value;
            }
        }

        return $values[0] ?? null;
    }

    /** @param array<string, mixed> $records */
    private function tlsPort(array $records): int
    {
        $value = is_array($records['tls'] ?? null) ? ($records['tls']['port'] ?? null) : null;
        $instanceKey = trim((string) ($records['managed_instance_key'] ?? ''));
        $value ??= $instanceKey !== ''
            ? config(
                'outboss.managed_mail.instances.'.$instanceKey.'.tls_port',
                config('outboss.managed_mail.tls_port', 443),
            )
            : config('outboss.managed_mail.tls_port', 443);
        $value = (int) $value;

        return $value >= 1 && $value <= 65535 ? $value : 443;
    }

    private function timeoutSeconds(): int
    {
        return max(1, min(10, (int) config('outboss.managed_mail.dns_timeout_seconds', 3)));
    }

    /**
     * @param  array<int, mixed>  $hosts
     * @return list<string>
     */
    private function normaliseHosts(array $hosts): array
    {
        $normalised = [];
        foreach ($hosts as $host) {
            if (! is_string($host) || trim($host) === '') {
                continue;
            }

            $host = strtolower(trim($host, " .\t\n\r\0\x0B[]"));
            if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false) {
                $normalised[] = $host;
            }
        }

        return array_values(array_unique($normalised));
    }
}
