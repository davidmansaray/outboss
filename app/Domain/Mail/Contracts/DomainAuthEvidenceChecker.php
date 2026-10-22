<?php

namespace App\Domain\Mail\Contracts;

/**
 * Optional extension used by managed-domain verification.
 *
 * The mailbox-auth UI intentionally keeps the provider-neutral one-argument
 * DomainAuthChecker contract. Managed verification additionally supplies the
 * provisioner's public DNS evidence so DKIM can be checked against the exact
 * selector and key that was generated for this domain.
 */
interface DomainAuthEvidenceChecker extends DomainAuthChecker
{
    /**
     * @param  array<string, mixed>  $expectedRecords
     * @return array{spf: 'pass'|'missing'|'unknown', dmarc: 'pass'|'missing'|'unknown', dkim: 'pass'|'missing'|'unknown'}
     */
    public function checkWithExpectedRecords(string $domain, array $expectedRecords): array;
}
