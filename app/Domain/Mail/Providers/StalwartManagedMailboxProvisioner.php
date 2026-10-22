<?php

namespace App\Domain\Mail\Providers;

use App\Domain\Mail\Contracts\ManagedMailboxProvisioner;
use App\Domain\Mail\DTOs\ManagedDomainRequest;
use App\Domain\Mail\DTOs\ManagedMailboxRequest;
use App\Domain\Mail\DTOs\ProvisionedDomain;
use App\Domain\Mail\DTOs\ProvisionedMailbox;
use App\Domain\Mail\DTOs\RotatedCredential;
use App\Domain\Mail\Exceptions\StalwartManagementException;
use App\Domain\Mail\Stalwart\StalwartManagementClient;
use App\Domain\Mail\Support\DnsZoneFileParser;
use App\Models\ManagedMailboxAccount;
use App\Models\ManagedMailDomain;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class StalwartManagedMailboxProvisioner implements ManagedMailboxProvisioner
{
    public function __construct(
        private readonly ?string $defaultInstanceKey = null,
        private readonly ?DnsZoneFileParser $zoneFiles = null,
    ) {}

    public function createDomain(ManagedDomainRequest $request): ProvisionedDomain
    {
        $remote = $this->client($request->managedInstanceKey)->createDomain(
            $request->domain,
            $this->correlationId($request->metadata),
        );

        return $this->domainResult($remote);
    }

    public function inspectDomain(string $remoteDomainId, ?string $managedInstanceKey = null): ProvisionedDomain
    {
        return $this->domainResult($this->client($managedInstanceKey)->inspectDomain($remoteDomainId));
    }

    public function createMailbox(ManagedMailboxRequest $request): ProvisionedMailbox
    {
        if ($request->password === null || $request->password === '') {
            throw new StalwartManagementException('missing_generated_credential');
        }

        $remote = $this->client($request->managedInstanceKey)->createAccount(
            $request->remoteDomainId,
            $request->localPart,
            $request->password,
            $request->displayName,
            $this->correlationId($request->metadata),
        );
        $email = (string) ($remote['emailAddress'] ?? $request->localPart.'@'.$request->domain);
        $created = ($remote['_outboss_created'] ?? false) === true;

        return new ProvisionedMailbox(
            remoteAccountId: (string) ($remote['id'] ?? ''),
            emailAddress: $email,
            username: $email,
            password: $created ? $request->password : null,
            metadata: $this->safeMetadata($remote),
        );
    }

    public function rotateMailboxCredential(ManagedMailboxAccount $account): RotatedCredential
    {
        $account->loadMissing('managedMailDomain');
        $domain = $account->managedMailDomain;
        if ($domain === null || ! is_string($account->remote_account_id) || $account->remote_account_id === '') {
            throw new StalwartManagementException('managed_mailbox_not_provisioned');
        }

        $password = Str::random(64);
        $this->client($domain->managed_instance_key)->rotateAccountPassword(
            $account->remote_account_id,
            $password,
        );

        return new RotatedCredential(
            username: $account->email_address,
            password: $password,
            version: ((int) $account->credential_version) + 1,
        );
    }

    public function suspendMailbox(ManagedMailboxAccount $account): void
    {
        $account->loadMissing('managedMailDomain');
        $domain = $account->managedMailDomain;
        if ($domain === null || ! is_string($account->remote_account_id) || $account->remote_account_id === '') {
            throw new StalwartManagementException('managed_mailbox_not_provisioned');
        }

        $this->client($domain->managed_instance_key)->suspendAccount($account->remote_account_id);
    }

    public function deleteMailbox(ManagedMailboxAccount $account): void
    {
        $account->loadMissing('managedMailDomain');
        $domain = $account->managedMailDomain;
        if ($domain === null || ! is_string($account->remote_account_id) || $account->remote_account_id === '') {
            throw new StalwartManagementException('managed_mailbox_not_provisioned');
        }

        $this->client($domain->managed_instance_key)->deleteAccount($account->remote_account_id);
    }

    public function deleteDomain(ManagedMailDomain $domain): void
    {
        if (! is_string($domain->remote_domain_id) || $domain->remote_domain_id === '') {
            throw new StalwartManagementException('managed_domain_not_provisioned');
        }

        $client = $this->client($domain->managed_instance_key);
        $client->deleteDkimSignaturesForDomain($domain->remote_domain_id);
        $client->deleteDomain($domain->remote_domain_id);
    }

    private function client(?string $instanceKey = null): StalwartManagementClient
    {
        return new StalwartManagementClient($instanceKey ?: $this->defaultInstanceKey);
    }

    /** @param array<string, mixed> $remote */
    private function domainResult(array $remote): ProvisionedDomain
    {
        $domain = (string) ($remote['name'] ?? '');
        $dnsRecords = [];
        $zoneFile = $remote['dnsZoneFile'] ?? null;
        if (is_string($zoneFile) && $zoneFile !== '') {
            $dnsRecords['zone_file'] = $zoneFile;
            $dnsRecords = [...$dnsRecords, ...$this->publicDnsRecordsFromZoneFile($domain, $zoneFile)];
        }

        return new ProvisionedDomain(
            remoteDomainId: (string) ($remote['id'] ?? ''),
            domain: $domain,
            dnsRecords: $dnsRecords,
            metadata: $this->safeMetadata($remote),
        );
    }

    /**
     * Extract only public DNS expectations needed by the readiness checker.
     * The raw zone file is retained for operator context, while the parsed
     * records make DKIM and MX matching independent of provider formatting.
     *
     * @return array<string, mixed>
     */
    private function publicDnsRecordsFromZoneFile(string $domain, string $zoneFile): array
    {
        if ($domain === '') {
            return [];
        }

        $records = [];
        $zoneFiles = $this->zoneFiles ?? new DnsZoneFileParser;
        foreach ($zoneFiles->records($zoneFile) as $record) {
            if ($record['type'] === 'MX'
                && preg_match('/^\d+\s+(\S+)$/', $record['value'], $matches) === 1) {
                $records['mx'] = [
                    'host' => $domain,
                    'value' => rtrim($matches[1], '.'),
                ];

                continue;
            }

            if ($record['type'] !== 'TXT') {
                continue;
            }

            $host = strtolower(trim($record['host'], " .\t\n\r\0\x0B"));
            if ($host === '@') {
                $host = strtolower($domain);
            } elseif (! str_contains($host, '.')) {
                $host .= '.'.strtolower($domain);
            }

            $value = $zoneFiles->txtValue($record['value']);
            if ($value === null) {
                continue;
            }

            if (str_contains($host, '._domainkey.'.strtolower($domain))
                && str_starts_with(strtolower($value), 'v=dkim1')) {
                $records['dkim'] ??= [];
                $records['dkim'][] = [
                    'host' => $host,
                    'value' => $value,
                ];
            } elseif ($host === strtolower($domain) && str_starts_with(strtolower($value), 'v=spf1')) {
                $records['spf'] = ['host' => $host, 'value' => $value];
            } elseif ($host === '_dmarc.'.strtolower($domain) && str_starts_with(strtolower($value), 'v=dmarc1')) {
                $records['dmarc'] = ['host' => $host, 'value' => $value];
            }
        }

        return $records;
    }

    /** @param array<string, mixed> $metadata */
    private function correlationId(array $metadata): ?string
    {
        $value = $metadata['correlation_id'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function safeMetadata(array $remote): array
    {
        return Arr::only(Arr::except($remote, ['credentials', '_outboss_created']), [
            'id',
            '@type',
            'name',
            'emailAddress',
            'domainId',
            'createdAt',
        ]);
    }
}
