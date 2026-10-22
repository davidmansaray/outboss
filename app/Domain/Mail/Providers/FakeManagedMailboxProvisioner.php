<?php

namespace App\Domain\Mail\Providers;

use App\Domain\Mail\Contracts\ManagedMailboxProvisioner;
use App\Domain\Mail\DTOs\ManagedDomainRequest;
use App\Domain\Mail\DTOs\ManagedMailboxRequest;
use App\Domain\Mail\DTOs\ProvisionedDomain;
use App\Domain\Mail\DTOs\ProvisionedMailbox;
use App\Domain\Mail\DTOs\RotatedCredential;
use App\Models\ManagedMailboxAccount;
use App\Models\ManagedMailDomain;
use Closure;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Deterministic in-memory managed-mail instance for feature tests.
 *
 * This fake models idempotent remote principals and credential rotation. It
 * deliberately does not implement JMAP runtime transport; transport tests
 * should use their own fake or the provider contract fixture.
 */
final class FakeManagedMailboxProvisioner implements ManagedMailboxProvisioner
{
    /** @var array{domains: array<string, array<string, mixed>>, accounts: array<string, array<string, mixed>>} */
    public static array $store = [
        'domains' => [],
        'accounts' => [],
    ];

    public static bool $failDomainCreate = false;

    public static bool $failMailboxCreate = false;

    public static bool $failRotation = false;

    public static ?Closure $onMailboxCreate = null;

    public static function reset(): void
    {
        self::$store = ['domains' => [], 'accounts' => []];
        self::$failDomainCreate = false;
        self::$failMailboxCreate = false;
        self::$failRotation = false;
        self::$onMailboxCreate = null;
    }

    public function createDomain(ManagedDomainRequest $request): ProvisionedDomain
    {
        if (self::$failDomainCreate) {
            throw new RuntimeException('managed_domain_create_failed');
        }

        $key = $this->domainKey($request->managedInstanceKey, $request->domain);
        $existing = self::$store['domains'][$key] ?? null;

        if ($existing !== null) {
            return $this->domainResult($existing);
        }

        $record = [
            'remote_domain_id' => 'fake-domain-'.substr(hash('sha256', $key), 0, 24),
            'managed_instance_key' => $request->managedInstanceKey,
            'domain' => $request->domain,
            'status' => 'pending_dns',
            'dns_records' => [
                'mx' => ['host' => $request->domain, 'value' => 'mx.fake-managed.test'],
                'spf' => ['host' => $request->domain, 'value' => 'v=spf1 a -all'],
                // The fake represents a fully configured test instance. The
                // production verifier still requires these signals to be
                // observed; leaving any of them out intentionally exercises
                // the pending/blocked path.
                'forward_dns' => ['host' => $request->domain, 'status' => 'pass'],
                'ptr' => ['host' => $request->domain, 'status' => 'pass'],
                'tls' => ['host' => $request->domain, 'status' => 'pass'],
                'dmarc' => ['host' => '_dmarc.'.$request->domain, 'value' => 'v=DMARC1; p=none'],
                'dkim' => ['host' => 'default._domainkey.'.$request->domain, 'value' => 'v=DKIM1; k=rsa; p=fake'],
            ],
            'metadata' => ['fake' => true],
        ];

        self::$store['domains'][$key] = $record;

        return $this->domainResult($record);
    }

    public function inspectDomain(string $remoteDomainId, ?string $managedInstanceKey = null): ProvisionedDomain
    {
        foreach (self::$store['domains'] as $record) {
            if ($record['remote_domain_id'] === $remoteDomainId
                && ($managedInstanceKey === null
                    || strtolower(trim((string) $record['managed_instance_key'])) === strtolower(trim($managedInstanceKey)))) {
                return $this->domainResult($record);
            }
        }

        throw new RuntimeException('managed_domain_not_found');
    }

    public function createMailbox(ManagedMailboxRequest $request): ProvisionedMailbox
    {
        if (self::$onMailboxCreate !== null) {
            (self::$onMailboxCreate)($request);
        }

        if (self::$failMailboxCreate) {
            throw new RuntimeException('managed_mailbox_create_failed');
        }

        $domainExists = collect(self::$store['domains'])
            ->contains(fn (array $domain): bool => $domain['remote_domain_id'] === $request->remoteDomainId
                && strtolower(trim((string) $domain['managed_instance_key']))
                    === strtolower(trim($request->managedInstanceKey)));

        if (! $domainExists) {
            throw new RuntimeException('managed_domain_not_found');
        }

        $emailAddress = strtolower($request->localPart.'@'.$request->domain);
        $key = $this->accountKey($request->managedInstanceKey, $emailAddress);
        $existing = self::$store['accounts'][$key] ?? null;

        if ($existing !== null) {
            return $this->mailboxResult($existing, includeCredential: false);
        }

        $record = [
            'remote_account_id' => 'fake-account-'.substr(hash('sha256', $key), 0, 24),
            'managed_instance_key' => $request->managedInstanceKey,
            'remote_domain_id' => $request->remoteDomainId,
            'domain' => $request->domain,
            'local_part' => $request->localPart,
            'email_address' => $emailAddress,
            'display_name' => $request->displayName,
            'status' => 'active',
            'credential_version' => 1,
            // Credentials stay in the fake's private test state and are only
            // returned on the one-time create/rotate response.
            'username' => $emailAddress,
            'password' => $request->password ?? '',
            'metadata' => ['fake' => true],
        ];

        self::$store['accounts'][$key] = $record;

        return $this->mailboxResult($record, includeCredential: true);
    }

    public function rotateMailboxCredential(ManagedMailboxAccount $account): RotatedCredential
    {
        if (self::$failRotation) {
            throw new RuntimeException('managed_mailbox_rotation_failed');
        }

        $domain = $account->managedMailDomain;
        if ($domain === null || $account->remote_account_id === null) {
            throw new RuntimeException('managed_mailbox_not_provisioned');
        }

        foreach (self::$store['accounts'] as $key => $record) {
            if ($record['remote_account_id'] !== $account->remote_account_id
                || strtolower(trim((string) $record['managed_instance_key'])) !== strtolower(trim((string) $domain->managed_instance_key))) {
                continue;
            }

            $password = Str::random(64);
            $version = ((int) $record['credential_version']) + 1;
            self::$store['accounts'][$key]['password'] = $password;
            self::$store['accounts'][$key]['credential_version'] = $version;

            return new RotatedCredential(
                username: (string) $record['username'],
                password: $password,
                version: $version,
                metadata: ['fake' => true],
            );
        }

        throw new RuntimeException('managed_mailbox_not_found');
    }

    public function suspendMailbox(ManagedMailboxAccount $account): void
    {
        $this->setMailboxStatus($account, 'suspended');
    }

    public function deleteMailbox(ManagedMailboxAccount $account): void
    {
        $account->loadMissing('managedMailDomain');
        $instanceKey = $account->managedMailDomain?->managed_instance_key;

        foreach (self::$store['accounts'] as $key => $record) {
            if ($record['remote_account_id'] === $account->remote_account_id
                && strtolower(trim((string) $record['managed_instance_key'])) === strtolower(trim((string) $instanceKey))) {
                unset(self::$store['accounts'][$key]);
            }
        }
    }

    public function deleteDomain(ManagedMailDomain $domain): void
    {
        foreach (self::$store['domains'] as $key => $record) {
            if ($record['remote_domain_id'] === $domain->remote_domain_id
                && strtolower(trim((string) $record['managed_instance_key'])) === strtolower(trim((string) $domain->managed_instance_key))) {
                unset(self::$store['domains'][$key]);
            }
        }
    }

    /** @param array<string, mixed> $record */
    private function domainResult(array $record): ProvisionedDomain
    {
        return new ProvisionedDomain(
            remoteDomainId: (string) $record['remote_domain_id'],
            domain: (string) $record['domain'],
            dnsRecords: is_array($record['dns_records'] ?? null) ? $record['dns_records'] : [],
            metadata: is_array($record['metadata'] ?? null) ? $record['metadata'] : [],
        );
    }

    /** @param array<string, mixed> $record */
    private function mailboxResult(array $record, bool $includeCredential): ProvisionedMailbox
    {
        return new ProvisionedMailbox(
            remoteAccountId: (string) $record['remote_account_id'],
            emailAddress: (string) $record['email_address'],
            username: $includeCredential ? (string) $record['username'] : null,
            password: $includeCredential ? (string) $record['password'] : null,
            metadata: [
                ...(is_array($record['metadata'] ?? null) ? $record['metadata'] : []),
                'local_part' => (string) $record['local_part'],
                'status' => (string) $record['status'],
                'credential_version' => (int) $record['credential_version'],
            ],
        );
    }

    private function setMailboxStatus(ManagedMailboxAccount $account, string $status): void
    {
        $account->loadMissing('managedMailDomain');
        $instanceKey = $account->managedMailDomain?->managed_instance_key;

        foreach (self::$store['accounts'] as $key => $record) {
            if ($record['remote_account_id'] === $account->remote_account_id
                && strtolower(trim((string) $record['managed_instance_key'])) === strtolower(trim((string) $instanceKey))) {
                self::$store['accounts'][$key]['status'] = $status;

                return;
            }
        }

        throw new RuntimeException('managed_mailbox_not_found');
    }

    private function domainKey(string $instance, string $domain): string
    {
        return strtolower(trim($instance)).'|'.strtolower(trim($domain));
    }

    private function accountKey(string $instance, string $email): string
    {
        return strtolower(trim($instance)).'|'.strtolower(trim($email));
    }
}
