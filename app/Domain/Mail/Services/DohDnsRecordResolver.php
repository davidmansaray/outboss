<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\Contracts\DnsRecordResolver;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Resolve public DNS through DNS-over-HTTPS with an explicit request bound.
 *
 * PHP's dns_get_record() does not expose a timeout. A readiness check must
 * not let a broken recursive resolver hold an operator request indefinitely,
 * so production uses a trusted DoH endpoint and a short bounded HTTP call.
 */
final class DohDnsRecordResolver implements DnsRecordResolver
{
    /** @var array<string, int> */
    private const TYPE_CODES = [
        'A' => 1,
        'AAAA' => 28,
        'MX' => 15,
        'PTR' => 12,
        'TXT' => 16,
    ];

    public function __construct(
        private readonly ?string $endpoint = null,
        private readonly ?int $timeoutSeconds = null,
    ) {}

    /**
     * @param  'A'|'AAAA'|'MX'|'PTR'|'TXT'  $type
     * @return list<array<string, mixed>>|null
     */
    public function lookup(string $host, string $type): ?array
    {
        $type = strtoupper(trim($type));
        if (! isset(self::TYPE_CODES[$type])) {
            return null;
        }

        $queryName = $this->queryName($host, $type);
        if ($queryName === null) {
            return [];
        }

        $endpoint = trim((string) ($this->endpoint ?? config(
            'outboss.managed_mail.dns_resolver_url',
            'https://cloudflare-dns.com/dns-query',
        )));
        if ($endpoint === '' || filter_var($endpoint, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($endpoint, PHP_URL_SCHEME)) !== 'https') {
            return null;
        }

        $timeout = max(1, min(10, (int) ($this->timeoutSeconds ?? config(
            'outboss.managed_mail.dns_timeout_seconds',
            3,
        ))));

        try {
            // Cloudflare's JSON DNS endpoint requires this exact media type.
            // acceptJson() first sets application/json and Laravel appends the
            // later header, producing a malformed combined Accept value that
            // Cloudflare rejects with HTTP 400.
            $response = Http::withHeaders(['Accept' => 'application/dns-json'])
                ->connectTimeout($timeout)
                ->timeout($timeout)
                ->get($endpoint, [
                    'name' => $queryName,
                    'type' => self::TYPE_CODES[$type],
                    'cd' => 0,
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return null;
        }

        $rcode = $payload['Status'] ?? $payload['status'] ?? null;
        if (is_int($rcode) || (is_string($rcode) && is_numeric($rcode))) {
            $rcode = (int) $rcode;
            // NXDOMAIN and a successful empty answer are both a real
            // no-record result. Other resolver errors remain unknown.
            if ($rcode !== 0 && $rcode !== 3) {
                return null;
            }
        }

        $answers = $payload['Answer'] ?? $payload['answer'] ?? [];
        if (! is_array($answers)) {
            return [];
        }

        $records = [];
        foreach (array_slice($answers, 0, 64) as $answer) {
            if (! is_array($answer)) {
                continue;
            }

            $answerType = $answer['type'] ?? $answer['Type'] ?? null;
            if ((is_int($answerType) || (is_string($answerType) && is_numeric($answerType)))
                && (int) $answerType !== self::TYPE_CODES[$type]) {
                continue;
            }

            $data = $answer['data'] ?? $answer['Data'] ?? null;
            if (! is_string($data) || trim($data) === '') {
                continue;
            }

            $records[] = [
                'name' => is_string($answer['name'] ?? $answer['Name'] ?? null)
                    ? (string) ($answer['name'] ?? $answer['Name'])
                    : $queryName,
                'type' => $type,
                'data' => $this->normaliseData($data, $type),
            ];
        }

        return $records;
    }

    private function queryName(string $host, string $type): ?string
    {
        $host = strtolower(trim($host, " .\t\n\r\0\x0B"));
        if ($host === '') {
            return null;
        }

        if ($type === 'PTR') {
            if (filter_var($host, FILTER_VALIDATE_IP) === false) {
                return null;
            }

            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return implode('.', array_reverse(explode('.', $host))).'.in-addr.arpa';
            }

            $packed = inet_pton($host);
            if ($packed === false) {
                return null;
            }

            $unpacked = unpack('H*', $packed);
            if ($unpacked === false) {
                return null;
            }
            $hex = $unpacked[1];
            if ($hex === '') {
                return null;
            }

            return implode('.', array_reverse(str_split($hex))).'.ip6.arpa';
        }

        return $this->isDnsName($host)
            ? $host
            : null;
    }

    private function isDnsName(string $host): bool
    {
        $labels = explode('.', $host);
        if (count($labels) > 127) {
            return false;
        }

        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63
                || preg_match('/\A[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\z/i', $label) !== 1) {
                return false;
            }
        }

        return strlen($host) <= 253;
    }

    private function normaliseData(string $data, string $type): string
    {
        $data = trim($data);

        if ($type === 'TXT') {
            preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $data, $matches);
            if ($matches[1] !== []) {
                return implode('', array_map(
                    static fn (string $part): string => stripcslashes($part),
                    $matches[1],
                ));
            }

            return trim($data, '"');
        }

        if ($type === 'MX') {
            return preg_match('/^\s*\d+\s+(\S+)\s*$/', $data, $matches) === 1
                ? rtrim($matches[1], '.')
                : $data;
        }

        return rtrim($data, '.');
    }
}
