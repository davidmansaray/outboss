<?php

use App\Domain\Mail\Contracts\DnsResolver;
use App\Domain\Mail\Contracts\DomainAuthEvidenceChecker;
use App\Domain\Mail\Contracts\ManagedMailboxProvisioner;
use App\Domain\Mail\Contracts\ManagedMailEndpointChecker;
use App\Domain\Mail\DTOs\ManagedDomainRequest;
use App\Domain\Mail\DTOs\ManagedMailboxRequest;
use App\Domain\Mail\DTOs\ProvisionedDomain;
use App\Domain\Mail\DTOs\ProvisionedMailbox;
use App\Domain\Mail\DTOs\RotatedCredential;
use App\Domain\Mail\Enums\ManagedMailDomainStatus;
use App\Domain\Mail\Services\ManagedMailCapability;
use App\Domain\Mail\Services\ManagedMailEventRecorder;
use App\Domain\Mail\Services\VerifyManagedMailDomain;
use App\Models\ManagedMailboxAccount;
use App\Models\ManagedMailDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use RuntimeException;

uses(RefreshDatabase::class);

it('does not combine a configured host with a different provider MX target', function () {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    config()->set('outboss.managed_mail.instances.internal.base_url', 'https://mx-configured.example.test');

    $domain = ManagedMailDomain::factory()->draft()->create([
        'workspace_id' => $workspace->id,
        'created_by_user_id' => $owner->id,
        'managed_instance_key' => 'internal',
        'domain' => 'example.com',
        'remote_domain_id' => 'remote-domain-1',
    ]);

    $provisioner = new class implements ManagedMailboxProvisioner
    {
        public function createDomain(ManagedDomainRequest $request): ProvisionedDomain
        {
            throw new RuntimeException('not used');
        }

        public function inspectDomain(string $remoteDomainId, ?string $managedInstanceKey = null): ProvisionedDomain
        {
            return new ProvisionedDomain(
                remoteDomainId: $remoteDomainId,
                domain: 'example.com',
                dnsRecords: [
                    'mx' => ['host' => 'example.com', 'value' => 'mx-provider.example.test'],
                ],
            );
        }

        public function createMailbox(ManagedMailboxRequest $request): ProvisionedMailbox
        {
            throw new RuntimeException('not used');
        }

        public function rotateMailboxCredential(ManagedMailboxAccount $account): RotatedCredential
        {
            throw new RuntimeException('not used');
        }

        public function suspendMailbox(ManagedMailboxAccount $account): void
        {
            throw new RuntimeException('not used');
        }

        public function deleteMailbox(ManagedMailboxAccount $account): void
        {
            throw new RuntimeException('not used');
        }

        public function deleteDomain(ManagedMailDomain $domain): void
        {
            throw new RuntimeException('not used');
        }
    };

    $auth = new class implements DomainAuthEvidenceChecker
    {
        public function check(string $domain): array
        {
            return ['spf' => 'pass', 'dmarc' => 'pass', 'dkim' => 'pass'];
        }

        public function checkWithExpectedRecords(string $domain, array $expectedRecords): array
        {
            return ['spf' => 'pass', 'dmarc' => 'pass', 'dkim' => 'pass'];
        }
    };

    $mx = new class implements DnsResolver
    {
        public function resolveMx(string $domain): array
        {
            return ['mx-provider.example.test'];
        }
    };

    $endpoint = new class implements ManagedMailEndpointChecker
    {
        /** @var list<string> */
        public array $hosts = [];

        public function check(string $domain, array $expectedRecords, array $expectedHosts): array
        {
            $this->hosts = $expectedHosts;

            return ['forward_dns' => 'pass', 'ptr' => 'pass', 'tls' => 'pass'];
        }
    };

    $verify = new VerifyManagedMailDomain(
        $provisioner,
        $auth,
        $mx,
        new ManagedMailCapability,
        new ManagedMailEventRecorder,
        $endpoint,
    );

    expect(fn () => $verify->handle($workspace, $owner, $domain))
        ->toThrow(ValidationException::class);

    expect($domain->fresh()->getAttribute('status'))->toBe(ManagedMailDomainStatus::Draft)
        ->and(data_get($domain->fresh()->getAttribute('dns_records'), 'verification.mx'))->toBe('missing')
        ->and($endpoint->hosts)->toBe([]);
});
