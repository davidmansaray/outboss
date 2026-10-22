<?php

namespace Tests\Integration\ManagedMail;

use App\Domain\Mail\Contracts\ManagedMailboxProvisioner;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Exceptions\StalwartManagementException;
use App\Domain\Mail\Providers\FakeMailProvider;
use App\Domain\Mail\Providers\ProviderRegistry;
use App\Domain\Mail\Providers\StalwartJmapClient;
use App\Domain\Mail\Providers\StalwartManagedMailboxProvisioner;
use App\Domain\Mail\Providers\StalwartProvider;
use App\Domain\Mail\Services\ProvisionManagedDomain;
use App\Domain\Mail\Services\ProvisionManagedMailbox;
use App\Domain\Mail\Stalwart\StalwartManagementClient;
use App\Models\ManagedMailboxAccount;
use App\Models\ManagedMailDomain;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Disposable resources and application wiring for the real Stalwart suite.
 *
 * Integration tests run with APP_ENV=testing, where AppServiceProvider
 * deliberately installs fakes. This fixture replaces only the provider
 * registry in this process; it never changes production bindings or config
 * files. The remote records are deleted in cleanup(), including after a
 * failed assertion from the test's finally block.
 */
final class ManagedMailIntegrationFixture
{
    public readonly string $baseUrl;

    public readonly StalwartJmapClient $runtime;

    public readonly StalwartManagedMailboxProvisioner $provisioner;

    private readonly StalwartManagementClient $management;

    private readonly string $runToken;

    /** @var list<string> */
    private array $domainNames = [];

    /** @var list<array{domain_id:string,local_part:string,remote_id:?string}> */
    private array $accountReferences = [];

    private bool $cleaned = false;

    private bool $cleanupInProgress = false;

    public function __construct()
    {
        $baseUrl = rtrim((string) (getenv('STALWART_TEST_BASE_URL') ?: 'http://127.0.0.1:28080'), '/');
        $parts = parse_url($baseUrl);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $isLoopback = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);

        if (! $isLoopback && getenv('STALWART_TEST_ALLOW_REMOTE') !== '1') {
            throw new RuntimeException(
                'Managed-mail integration refuses non-loopback hosts. Set STALWART_TEST_ALLOW_REMOTE=1 only for an explicitly disposable host.',
            );
        }

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('STALWART_TEST_BASE_URL must be an absolute HTTP(S) URL.');
        }

        $recoveryAdmin = (string) (getenv('STALWART_TEST_RECOVERY_ADMIN') ?: 'admin:outboss-local-managed-mail-only');
        [$adminUsername, $adminPassword] = $this->splitCredential($recoveryAdmin);
        $apiKey = (string) (getenv('STALWART_TEST_MANAGEMENT_API_KEY') ?: '');
        $managementUrl = rtrim(
            // Stalwart 0.16 serves management JMAP on the same /jmap/ endpoint
            // as runtime mail. The disposable runner bootstraps that endpoint
            // before the test process starts; callers may still override it
            // when exercising a deliberately different fixture topology.
            (string) (getenv('STALWART_TEST_MANAGEMENT_API_URL') ?: $baseUrl.'/jmap/'),
            '/',
        );

        if ($apiKey === '' && ($adminUsername === '' || $adminPassword === '')) {
            throw new RuntimeException(
                'Set STALWART_TEST_MANAGEMENT_API_KEY or STALWART_TEST_RECOVERY_ADMIN=username:password for the disposable fixture.',
            );
        }

        $this->baseUrl = $baseUrl;
        $this->runToken = strtolower(bin2hex(random_bytes(5)));

        config()->set('outboss.managed_mail.default_instance', 'internal');
        $verifyTls = getenv('STALWART_TEST_VERIFY_TLS');
        $verifyTls = $verifyTls === false
            ? true
            : filter_var($verifyTls, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        config()->set('outboss.managed_mail.instances.internal', [
            'base_url' => $baseUrl,
            'management_api_url' => $managementUrl,
            'admin_api_key' => $apiKey,
            // The fixture uses Stalwart's documented recovery Basic auth when
            // no scoped API key was supplied. The app rejects this fallback
            // when APP_ENV=production.
            'admin_username' => $adminUsername,
            'admin_password' => $adminPassword,
            'verify_tls' => $verifyTls ?? true,
            'timeout_seconds' => max(1, (int) (getenv('STALWART_TEST_TIMEOUT_SECONDS') ?: 15)),
        ]);
        config()->set('outboss.managed_mail.session_cache_seconds', 1);

        $this->management = new StalwartManagementClient('internal');
        $this->runtime = new StalwartJmapClient;
        $this->provisioner = new StalwartManagedMailboxProvisioner('internal');

        // Do not let provisioning's InitialSyncMailbox job race the test's
        // explicit sync calls. Queue::fake affects only this test process.
        Queue::fake();

        $registry = new ProviderRegistry;
        foreach (MailProviderName::cases() as $provider) {
            if ($provider === MailProviderName::Stalwart) {
                $registry->register(
                    $provider,
                    new StalwartProvider($this->runtime),
                    null,
                    $this->provisioner,
                );

                continue;
            }

            $registry->register($provider, new FakeMailProvider($provider));
        }

        app()->instance(ProviderRegistry::class, $registry);
        app()->instance(ManagedMailboxProvisioner::class, $this->provisioner);
    }

    public function management(): StalwartManagementClient
    {
        return $this->management;
    }

    public function provider(): StalwartProvider
    {
        $provider = app(ProviderRegistry::class)->transport(MailProviderName::Stalwart);

        if (! $provider instanceof StalwartProvider) {
            throw new RuntimeException('The integration fixture did not install the Stalwart transport.');
        }

        return $provider;
    }

    public function domainName(string $label): string
    {
        $name = 'outboss-it-'.$this->runToken.'-'.strtolower($label).'.example.com';
        if (! in_array($name, $this->domainNames, true)) {
            $this->domainNames[] = $name;
        }

        return $name;
    }

    public function provisionDomain(Workspace $workspace, User $owner, string $domain): ManagedMailDomain
    {
        /** @var ProvisionManagedDomain $service */
        $service = app(ProvisionManagedDomain::class);
        $record = $service->handle(
            $workspace,
            $owner,
            $domain,
            'internal',
            ['correlation_id' => 'managed-mail-it-'.$this->runToken],
        );

        // The integration fixture has no DNS authority. Marking the remote
        // test domain verified is explicit test setup, not production DNS
        // verification, and exercises the same local state gate used by the
        // owner-only UI before account creation.
        $record->markVerified()->activate();

        return $record->fresh();
    }

    public function provisionMailbox(
        Workspace $workspace,
        User $owner,
        ManagedMailDomain $domain,
        string $localPart,
    ): ManagedMailboxAccount {
        $domainId = (string) $domain->remote_domain_id;
        $normalizedLocalPart = strtolower(trim($localPart));
        $referenceIndex = null;

        foreach ($this->accountReferences as $index => $reference) {
            if ($reference['domain_id'] === $domainId && $reference['local_part'] === $normalizedLocalPart) {
                $referenceIndex = $index;

                break;
            }
        }

        if ($referenceIndex === null) {
            $referenceIndex = count($this->accountReferences);
            $this->accountReferences[] = [
                'domain_id' => $domainId,
                'local_part' => $normalizedLocalPart,
                'remote_id' => null,
            ];
        }

        /** @var ProvisionManagedMailbox $service */
        $service = app(ProvisionManagedMailbox::class);
        $account = $service->handle(
            $workspace,
            $owner,
            $domain,
            $localPart,
            ucfirst($localPart).' Integration',
            ['correlation_id' => 'managed-mail-it-'.$this->runToken],
        );

        $this->accountReferences[$referenceIndex]['remote_id'] = $account->remote_account_id;

        return $account->fresh(['managedMailDomain', 'mailbox.connection']);
    }

    /**
     * Wait for eventual local delivery while keeping all failures safe.
     *
     * @param  callable():bool  $condition
     */
    public function waitUntil(callable $condition, int $timeoutSeconds = 30): void
    {
        $deadline = microtime(true) + max(1, $timeoutSeconds);

        do {
            if ($condition()) {
                return;
            }

            usleep(250_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Timed out waiting for the managed-mail fixture state.');
    }

    public function cleanup(): void
    {
        if ($this->cleaned || $this->cleanupInProgress) {
            return;
        }

        $this->cleanupInProgress = true;
        $failures = [];

        foreach (array_reverse($this->accountReferences) as $reference) {
            try {
                $remoteId = $reference['remote_id'];
                if ($remoteId === null || $remoteId === '') {
                    $remote = $this->management->findAccount($reference['domain_id'], $reference['local_part']);
                    $remoteId = is_array($remote) && is_string($remote['id'] ?? null) ? $remote['id'] : null;
                }

                if ($remoteId !== null && $remoteId !== '') {
                    $this->management->deleteAccount($remoteId);
                }
            } catch (Throwable $exception) {
                if (! $this->isAlreadyGone($exception)) {
                    $failures[] = $this->safeError($exception);
                }
            }
        }

        foreach (array_reverse($this->domainNames) as $domainName) {
            try {
                $remote = $this->management->findDomainByName($domainName);
                $remoteId = is_array($remote) && is_string($remote['id'] ?? null) ? $remote['id'] : null;

                if ($remoteId !== null && $remoteId !== '') {
                    // Automatic DKIM management creates two generated
                    // DkimSignature objects per domain. Stalwart rejects a
                    // Domain destroy while those linked objects remain.
                    $this->management->deleteDkimSignaturesForDomain($remoteId);
                    $this->management->deleteDomain($remoteId);
                }
            } catch (Throwable $exception) {
                if (! $this->isAlreadyGone($exception)) {
                    $failures[] = $this->safeError($exception);
                }
            }
        }

        $this->cleanupInProgress = false;

        if ($failures !== []) {
            throw new RuntimeException('Managed-mail fixture cleanup failed: '.implode(', ', $failures));
        }

        $this->cleaned = true;
    }

    /** @return array{0:string,1:string} */
    private function splitCredential(string $credential): array
    {
        $separator = strpos($credential, ':');

        if ($separator === false) {
            return ['', ''];
        }

        return [substr($credential, 0, $separator), substr($credential, $separator + 1)];
    }

    private function safeError(Throwable $exception): string
    {
        if ($exception instanceof StalwartManagementException) {
            return $exception->errorCode;
        }

        return Str::snake((new \ReflectionClass($exception))->getShortName());
    }

    private function isAlreadyGone(Throwable $exception): bool
    {
        if (! $exception instanceof StalwartManagementException) {
            return false;
        }

        return Str::endsWith($exception->errorCode, '_not_found');
    }
}
