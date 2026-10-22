<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\Contracts\DnsResolver;
use App\Domain\Mail\Enums\RecipientMailboxProvider;
use App\Domain\Mail\Support\EmailNormalizer;

class RecipientMailboxProviderClassifier
{
    public function __construct(private DnsResolver $resolver) {}

    /**
     * Classify the MX snapshot for an email address or a bare domain.
     */
    public function classify(string $emailOrDomain): RecipientMailboxProvider
    {
        return $this->inspect($emailOrDomain)['provider'];
    }

    public function handle(string $emailOrDomain): RecipientMailboxProvider
    {
        return $this->classify($emailOrDomain);
    }

    /**
     * Keep the exact MX observation alongside the classification. This is
     * intentionally a small public-DNS snapshot, not a message-routing hint.
     *
     * @return array{provider: RecipientMailboxProvider, mx: list<string>, domain: string}
     */
    public function inspect(string $emailOrDomain): array
    {
        $domain = str_contains($emailOrDomain, '@')
            ? EmailNormalizer::domain($emailOrDomain)
            : strtolower(trim($emailOrDomain, " .\t\n\r\0\x0B"));

        if (! is_string($domain) || $domain === '') {
            return [
                'provider' => RecipientMailboxProvider::Unknown,
                'mx' => [],
                'domain' => '',
            ];
        }

        $mx = $this->resolver->resolveMx($domain);

        return [
            'provider' => $this->classifyHosts($mx),
            'mx' => $mx,
            'domain' => $domain,
        ];
    }

    /**
     * @param  list<string>  $hosts
     */
    private function classifyHosts(array $hosts): RecipientMailboxProvider
    {
        if ($hosts === []) {
            return RecipientMailboxProvider::Unknown;
        }

        foreach ($hosts as $host) {
            $host = strtolower(trim((string) $host, " .\t\n\r\0\x0B"));

            if ($this->matches($host, [
                'google.com',
                'googlemail.com',
                'aspmx.l.google.com',
            ])) {
                return RecipientMailboxProvider::Google;
            }
        }

        foreach ($hosts as $host) {
            $host = strtolower(trim((string) $host, " .\t\n\r\0\x0B"));

            if ($this->matches($host, [
                'outlook.com',
                'hotmail.com',
                'live.com',
                'protection.outlook.com',
                'mail.protection.outlook.com',
                'microsoft.com',
            ])) {
                return RecipientMailboxProvider::Microsoft;
            }
        }

        foreach ($hosts as $host) {
            $host = strtolower(trim((string) $host, " .\t\n\r\0\x0B"));

            if ($this->matches($host, [
                'yahoo.com',
                'yahoo.net',
                'yahoodns.net',
                'ymail.com',
            ])) {
                return RecipientMailboxProvider::Yahoo;
            }
        }

        return RecipientMailboxProvider::Other;
    }

    /**
     * Match an exact host or a subdomain of one of the provider suffixes.
     *
     * @param  list<string>  $suffixes
     */
    private function matches(string $host, array $suffixes): bool
    {
        foreach ($suffixes as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }
}
