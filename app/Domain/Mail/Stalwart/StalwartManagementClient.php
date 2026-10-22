<?php

namespace App\Domain\Mail\Stalwart;

use App\Domain\Mail\Exceptions\StalwartManagementException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Narrow client for Stalwart's management JMAP capability.
 *
 * Stalwart 0.16+ exposes managed objects through x:Domain and x:Account JMAP
 * methods. This class intentionally does not expose an arbitrary request API.
 */
final class StalwartManagementClient
{
    private const CORE_CAPABILITY = 'urn:ietf:params:jmap:core';

    private const MANAGEMENT_CAPABILITY = 'urn:stalwart:jmap';

    /**
     * Permissions required by Outboss' JMAP transport.
     *
     * Managed accounts intentionally do not inherit Stalwart's broad default
     * User role. A restricted management API key may only grant permissions
     * it holds itself, so keeping this list aligned with StalwartJmapClient
     * avoids requiring an administrator-equivalent provisioning credential.
     *
     * @var array<string, true>
     */
    private const MANAGED_ACCOUNT_PERMISSIONS = [
        'authenticate' => true,
        'authenticateWithAlias' => true,
        'emailSend' => true,
        'emailReceive' => true,
        'jmapMailboxGet' => true,
        'jmapIdentityGet' => true,
        'jmapEmailGet' => true,
        'jmapEmailQuery' => true,
        'jmapEmailQueryChanges' => true,
        'jmapEmailCreate' => true,
        'jmapEmailSubmissionCreate' => true,
    ];

    /** @var array{base_url:string,management_api_url:string,admin_api_key:string,admin_username:string,admin_password:string,verify_tls:bool,timeout_seconds:int} */
    private array $instance;

    private ?string $discoveredApiUrl = null;

    /**
     * @param  array{base_url?:string,management_api_url?:string,admin_api_key?:string,admin_username?:string,admin_password?:string,verify_tls?:bool,timeout_seconds?:int}|null  $instance
     */
    public function __construct(?string $instanceKey = null, ?array $instance = null)
    {
        $instanceKey ??= (string) config('outboss.managed_mail.default_instance', 'internal');
        $configured = $instance ?? config("outboss.managed_mail.instances.{$instanceKey}", []);

        $this->instance = [
            'base_url' => rtrim((string) ($configured['base_url'] ?? ''), '/'),
            'management_api_url' => (string) ($configured['management_api_url'] ?? ''),
            'admin_api_key' => (string) ($configured['admin_api_key'] ?? ''),
            // Basic auth is intentionally limited to non-production fixture
            // and bootstrap use. Production automation must use a scoped
            // bearer key, which is the only credential persisted in the
            // application's configuration contract.
            'admin_username' => (string) ($configured['admin_username'] ?? ''),
            'admin_password' => (string) ($configured['admin_password'] ?? ''),
            'verify_tls' => (bool) ($configured['verify_tls'] ?? true),
            'timeout_seconds' => max(1, (int) ($configured['timeout_seconds'] ?? 15)),
        ];
    }

    /** @return array<string, mixed> */
    public function createDomain(string $domain, ?string $correlationId = null): array
    {
        $domain = Str::lower(trim($domain));
        if ($domain === '') {
            throw new StalwartManagementException('invalid_domain', 'The managed mail domain is invalid.');
        }

        if (($existing = $this->findDomainByName($domain, $correlationId)) !== null) {
            return $existing;
        }

        $clientId = 'outboss-domain';
        $arguments = [
            'create' => [
                $clientId => [
                    'name' => $domain,
                    'aliases' => (object) [],
                    'certificateManagement' => ['@type' => 'Manual'],
                    'dkimManagement' => ['@type' => 'Automatic'],
                    'dnsManagement' => ['@type' => 'Manual'],
                    'subAddressing' => ['@type' => 'Enabled'],
                ],
            ],
        ];

        try {
            $result = $this->write('x:Domain/set', $arguments, $correlationId);
            $id = data_get($result, "created.{$clientId}.id");
            if (! is_string($id) || $id === '') {
                $this->throwSetFailure($result, $clientId, 'domain_create_failed');
            }

            return $this->inspectDomain($id, $correlationId);
        } catch (Throwable $exception) {
            // A response may be lost after Stalwart commits the create. Recover
            // by the unique domain name instead of blindly submitting again.
            $recovered = $this->recoverDomain($domain, $correlationId);
            if ($recovered !== null) {
                return $recovered;
            }

            throw $this->safeException($exception, 'domain_create_failed');
        }
    }

    /** @return array<string, mixed> */
    public function inspectDomain(string $remoteDomainId, ?string $correlationId = null): array
    {
        // Request the public zone file explicitly. The managed verifier uses
        // its generated DKIM selector/key as the expected value; without this
        // property it would have to guess a selector and remain unknown.
        $result = $this->read('x:Domain/get', [
            'ids' => [$remoteDomainId],
            'properties' => ['id', 'name', 'dnsZoneFile'],
        ], $correlationId);
        $domain = data_get($result, 'list.0');

        if (! is_array($domain)) {
            throw new StalwartManagementException('domain_not_found', 'The managed mail domain was not found on the configured server.');
        }

        return $domain;
    }

    /** @return array<string, mixed>|null */
    public function findDomainByName(string $domain, ?string $correlationId = null): ?array
    {
        $domain = Str::lower(trim($domain));
        $result = $this->read('x:Domain/query', ['filter' => ['name' => $domain]], $correlationId);
        $ids = data_get($result, 'ids', []);

        if (! is_array($ids)) {
            throw new StalwartManagementException('invalid_provider_response');
        }

        foreach ($ids as $id) {
            if (! is_string($id)) {
                continue;
            }
            $candidate = $this->inspectDomain($id, $correlationId);
            if (Str::lower((string) ($candidate['name'] ?? '')) === $domain) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function createAccount(
        string $remoteDomainId,
        string $localPart,
        string $password,
        ?string $displayName = null,
        ?string $correlationId = null,
    ): array {
        $localPart = Str::lower(trim($localPart));
        if ($localPart === '' || $password === '') {
            throw new StalwartManagementException('invalid_account', 'The managed mailbox account is invalid.');
        }

        if (($existing = $this->findAccount($remoteDomainId, $localPart, $correlationId)) !== null) {
            return [...$existing, '_outboss_created' => false];
        }

        $clientId = 'outboss-account';
        $arguments = [
            'create' => [
                $clientId => [
                    '@type' => 'User',
                    'name' => $localPart,
                    'domainId' => $remoteDomainId,
                    'description' => $displayName ?: 'Managed by Outboss',
                    'credentials' => (object) [
                        '0' => [
                            '@type' => 'Password',
                            'secret' => $password,
                            'allowedIps' => (object) [],
                        ],
                    ],
                    'memberGroupIds' => (object) [],
                    'roles' => [
                        '@type' => 'Custom',
                        'roleIds' => (object) [],
                    ],
                    'permissions' => [
                        '@type' => 'Replace',
                        'enabledPermissions' => self::MANAGED_ACCOUNT_PERMISSIONS,
                        'disabledPermissions' => (object) [],
                    ],
                    'quotas' => (object) [],
                    'aliases' => (object) [],
                    'encryptionAtRest' => ['@type' => 'Disabled'],
                ],
            ],
        ];

        try {
            $result = $this->write('x:Account/set', $arguments, $correlationId);
            $id = data_get($result, "created.{$clientId}.id");
            if (! is_string($id) || $id === '') {
                $this->throwSetFailure($result, $clientId, 'account_create_failed');
            }

            return [...$this->inspectAccount($id, $correlationId), '_outboss_created' => true];
        } catch (Throwable $exception) {
            $recovered = $this->recoverAccount($remoteDomainId, $localPart, $correlationId);
            if ($recovered !== null) {
                return [...$recovered, '_outboss_created' => false];
            }

            throw $this->safeException($exception, 'account_create_failed');
        }
    }

    /** @return array<string, mixed> */
    public function inspectAccount(string $remoteAccountId, ?string $correlationId = null): array
    {
        $result = $this->read('x:Account/get', ['ids' => [$remoteAccountId]], $correlationId);
        $account = data_get($result, 'list.0');

        if (! is_array($account)) {
            throw new StalwartManagementException('account_not_found', 'The managed mailbox account was not found on the configured server.');
        }

        return $account;
    }

    /** @return array<string, mixed>|null */
    public function findAccount(
        string $remoteDomainId,
        string $localPart,
        ?string $correlationId = null,
    ): ?array {
        $localPart = Str::lower(trim($localPart));
        $result = $this->read('x:Account/query', [
            'filter' => [
                'name' => $localPart,
                'domainId' => $remoteDomainId,
            ],
        ], $correlationId);
        $ids = data_get($result, 'ids', []);

        if (! is_array($ids)) {
            throw new StalwartManagementException('invalid_provider_response');
        }

        foreach ($ids as $id) {
            if (! is_string($id)) {
                continue;
            }
            $candidate = $this->inspectAccount($id, $correlationId);
            if ((string) ($candidate['domainId'] ?? '') === $remoteDomainId
                && Str::lower((string) ($candidate['name'] ?? '')) === $localPart) {
                return $candidate;
            }
        }

        return null;
    }

    public function rotateAccountPassword(
        string $remoteAccountId,
        string $password,
        ?string $correlationId = null,
    ): void {
        if ($password === '') {
            throw new StalwartManagementException('invalid_credential', 'The generated mailbox credential is invalid.');
        }

        $result = $this->write('x:Account/set', [
            'update' => [
                $remoteAccountId => [
                    'credentials' => (object) [
                        '0' => [
                            '@type' => 'Password',
                            'secret' => $password,
                            'allowedIps' => (object) [],
                        ],
                    ],
                ],
            ],
        ], $correlationId);

        $this->assertUpdated($result, $remoteAccountId, 'credential_rotation_failed');
    }

    public function suspendAccount(string $remoteAccountId, ?string $correlationId = null): void
    {
        // Account has no isEnabled property in the current Stalwart schema.
        // Removing its credentials blocks authentication while preserving mail.
        $result = $this->write('x:Account/set', [
            'update' => [
                $remoteAccountId => ['credentials' => (object) []],
            ],
        ], $correlationId);

        $this->assertUpdated($result, $remoteAccountId, 'account_suspend_failed');
    }

    public function deleteAccount(string $remoteAccountId, ?string $correlationId = null): void
    {
        try {
            $result = $this->write('x:Account/set', ['destroy' => [$remoteAccountId]], $correlationId);
            $destroyed = data_get($result, 'destroyed', []);
            if (! is_array($destroyed) || ! in_array($remoteAccountId, $destroyed, true)) {
                $this->throwSetFailure($result, $remoteAccountId, 'account_delete_failed', 'notDestroyed');
            }
        } catch (Throwable $exception) {
            // The destroy may have committed before its response was lost,
            // or this may be a retry after the remote delete succeeded but
            // the local transaction failed. Only an authoritative not-found
            // result makes the operation successful; every other provider
            // error retains its original safe error code.
            if ($this->accountIsAbsent($remoteAccountId, $correlationId)) {
                return;
            }

            throw $this->safeException($exception, 'account_delete_failed');
        }
    }

    public function deleteDomain(string $remoteDomainId, ?string $correlationId = null): void
    {
        $result = $this->write('x:Domain/set', ['destroy' => [$remoteDomainId]], $correlationId);
        $destroyed = data_get($result, 'destroyed', []);
        if (! is_array($destroyed) || ! in_array($remoteDomainId, $destroyed, true)) {
            $this->throwSetFailure($result, $remoteDomainId, 'domain_delete_failed', 'notDestroyed');
        }
    }

    /**
     * Remove generated DKIM records before deleting a disposable domain.
     *
     * Stalwart intentionally rejects Domain/set destroy while a domain still
     * owns DkimSignature objects. The production provisioning path keeps
     * those keys for the domain lifetime; this narrow helper exists for the
     * integration fixture's teardown of generated test domains.
     */
    public function deleteDkimSignaturesForDomain(string $remoteDomainId, ?string $correlationId = null): void
    {
        $result = $this->read('x:DkimSignature/query', [
            'filter' => ['domainId' => $remoteDomainId],
        ], $correlationId);
        $ids = data_get($result, 'ids', []);

        if (! is_array($ids) || $ids === []) {
            return;
        }

        $destroyIds = array_values(array_filter($ids, static fn ($id): bool => is_string($id) && $id !== ''));
        if ($destroyIds === []) {
            return;
        }

        $destroyed = $this->write('x:DkimSignature/set', ['destroy' => $destroyIds], $correlationId);
        $destroyedIds = data_get($destroyed, 'destroyed', []);
        if (! is_array($destroyedIds) || count(array_intersect($destroyIds, $destroyedIds)) !== count($destroyIds)) {
            throw new StalwartManagementException('dkim_signature_delete_failed');
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function read(string $method, array $arguments, ?string $correlationId): array
    {
        return $this->call($method, $arguments, $correlationId, true);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function write(string $method, array $arguments, ?string $correlationId): array
    {
        return $this->call($method, $arguments, $correlationId, false);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function call(
        string $method,
        array $arguments,
        ?string $correlationId,
        bool $retryRead,
    ): array {
        $callId = 'outboss-1';
        $payload = [
            'using' => [self::CORE_CAPABILITY, self::MANAGEMENT_CAPABILITY],
            'methodCalls' => [[$method, $arguments, $callId]],
        ];

        try {
            // Resolve and validate the final same-origin endpoint before
            // constructing the authenticated POST request. Discovery itself
            // is authenticated, but a malicious advertised/override URL must
            // never be paired with a credential-bearing request object.
            $url = $this->managementApiUrl($correlationId);
            $request = $this->request($correlationId);
            if ($retryRead) {
                $request = $request->retry(3, 100, throw: false);
            }
            $response = $request->post($url, $payload);
        } catch (Throwable $exception) {
            throw $this->safeException($exception, 'provider_unavailable');
        }

        if (! $response->successful()) {
            throw $this->httpFailure($response);
        }

        $methodResponse = data_get($response->json(), 'methodResponses.0');
        if (! is_array($methodResponse)
            || ($methodResponse[2] ?? null) !== $callId
            || ! is_array($methodResponse[1] ?? null)) {
            throw new StalwartManagementException('invalid_provider_response');
        }

        if (($methodResponse[0] ?? null) === 'error') {
            $type = (string) ($methodResponse[1]['type'] ?? 'provider_error');
            throw new StalwartManagementException('provider_'.$this->safeCode($type));
        }

        if (($methodResponse[0] ?? null) !== $method) {
            throw new StalwartManagementException('invalid_provider_response');
        }

        return $methodResponse[1];
    }

    private function request(?string $correlationId): PendingRequest
    {
        $baseUrl = $this->instance['base_url'];
        $apiKey = $this->instance['admin_api_key'];
        $adminUsername = $this->instance['admin_username'];
        $adminPassword = $this->instance['admin_password'];
        if ($baseUrl === '' || ($apiKey === '' && ($adminUsername === '' || $adminPassword === ''))) {
            throw new StalwartManagementException('provisioning_unavailable', 'Managed mail provisioning is not configured.');
        }

        $this->assertBaseUrl($baseUrl);

        if (! $this->instance['verify_tls'] && app()->isProduction()) {
            throw new StalwartManagementException('unsafe_tls_configuration', 'Managed mail TLS verification cannot be disabled in production.');
        }
        if ($apiKey === '' && app()->isProduction()) {
            throw new StalwartManagementException('unsafe_bootstrap_configuration', 'Managed mail bootstrap credentials cannot be used in production.');
        }

        $request = Http::baseUrl($baseUrl)
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'User-Agent' => 'Outboss-Managed-Mail/1.0',
                'X-Correlation-ID' => $correlationId ?: (string) Str::uuid(),
            ])
            ->connectTimeout(min(5, $this->instance['timeout_seconds']))
            ->timeout($this->instance['timeout_seconds']);

        $request = $apiKey !== ''
            ? $request->withToken($apiKey)
            : $request->withBasicAuth($adminUsername, $adminPassword);

        return $this->instance['verify_tls'] ? $request : $request->withoutVerifying();
    }

    private function managementApiUrl(?string $correlationId): string
    {
        if ($this->discoveredApiUrl !== null) {
            return $this->discoveredApiUrl;
        }

        $configured = trim($this->instance['management_api_url']);
        // A configured endpoint is only an override for the endpoint in an
        // authenticated session document. Validate it before creating the
        // authenticated discovery request, but never let it skip discovery.
        $override = $configured !== '' ? $this->sameOriginUrl($configured) : null;

        try {
            $response = $this->request($correlationId)
                ->retry(3, 100, throw: false)
                ->get('/.well-known/jmap');
        } catch (Throwable $exception) {
            throw $this->safeException($exception, 'provider_unavailable');
        }

        if (! $response->successful()) {
            throw $this->httpFailure($response);
        }

        $apiUrl = $response->json('apiUrl');
        if (! is_string($apiUrl) || trim($apiUrl) === '') {
            throw new StalwartManagementException('invalid_provider_response');
        }

        $discovered = $this->sameOriginUrl($apiUrl);

        return $this->discoveredApiUrl = $override ?? $discovered;
    }

    private function sameOriginUrl(string $url): string
    {
        $baseUrl = $this->instance['base_url'];
        $url = trim($url);

        if (Str::startsWith($url, '/') && ! Str::startsWith($url, '//')) {
            $url = $baseUrl.$url;
        }

        if (Str::startsWith($url, '//')) {
            throw new StalwartManagementException('invalid_provider_response');
        }

        $base = parse_url($baseUrl);
        $candidate = parse_url($url);

        if (! is_array($base)
            || ! is_array($candidate)
            || ! isset($base['scheme'], $base['host'], $candidate['scheme'], $candidate['host'])) {
            throw new StalwartManagementException('invalid_provider_response');
        }

        $normalisedBaseHost = $this->normaliseHost($base['host']);
        $normalisedCandidateHost = $this->normaliseHost($candidate['host']);

        if (isset($base['user']) || isset($base['pass'])
            || isset($candidate['user']) || isset($candidate['pass'])
            || isset($base['fragment']) || isset($candidate['fragment'])
            || ! in_array(strtolower((string) $base['scheme']), ['http', 'https'], true)
            || strtolower((string) $base['scheme']) !== strtolower((string) $candidate['scheme'])
            || $normalisedBaseHost === null
            || $normalisedCandidateHost === null
            || $normalisedBaseHost !== $normalisedCandidateHost
            || $this->effectivePort($base) !== $this->effectivePort($candidate)) {
            throw new StalwartManagementException('invalid_provider_response');
        }

        return $url;
    }

    /** Ensure configuration is a credential-safe absolute HTTP(S) origin. */
    private function assertBaseUrl(string $baseUrl): void
    {
        $parts = parse_url($baseUrl);

        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new StalwartManagementException('invalid_provider_configuration', 'Managed mail provisioning is not configured safely.');
        }

        if ($this->normaliseHost($parts['host']) === null) {
            throw new StalwartManagementException('invalid_provider_configuration', 'Managed mail provisioning is not configured safely.');
        }

        if (app()->isProduction() && strtolower((string) $parts['scheme']) !== 'https') {
            throw new StalwartManagementException(
                'unsafe_tls_configuration',
                'Managed mail provisioning requires HTTPS in production.',
            );
        }
    }

    /** @param array<string, mixed> $parts */
    private function effectivePort(array $parts): int
    {
        $port = $parts['port'] ?? null;
        if (is_int($port) || (is_string($port) && ctype_digit($port))) {
            return (int) $port;
        }

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https' ? 443 : 80;
    }

    private function normaliseHost(mixed $host): ?string
    {
        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        $host = strtolower(trim($host));
        $host = trim($host, '[]');
        $host = rtrim($host, '.');

        if ($host === '') {
            return null;
        }

        if (function_exists('idn_to_ascii') && ! filter_var($host, FILTER_VALIDATE_IP)) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT);
            if (is_string($ascii) && $ascii !== '') {
                $host = strtolower(rtrim($ascii, '.'));
            }
        }

        return $host;
    }

    /** @param array<string, mixed> $result */
    private function assertUpdated(array $result, string $id, string $fallbackCode): void
    {
        $updated = data_get($result, 'updated', []);
        if (is_array($updated) && array_key_exists($id, $updated)) {
            return;
        }

        $this->throwSetFailure($result, $id, $fallbackCode, 'notUpdated');
    }

    /** @param array<string, mixed> $result */
    private function throwSetFailure(
        array $result,
        string $key,
        string $fallbackCode,
        string $failureBucket = 'notCreated',
    ): never {
        $type = data_get($result, "{$failureBucket}.{$key}.type");
        $code = is_string($type) && $type !== '' ? 'provider_'.$this->safeCode($type) : $fallbackCode;

        throw new StalwartManagementException($code);
    }

    private function httpFailure(Response $response): StalwartManagementException
    {
        $code = match ($response->status()) {
            401, 403 => 'provider_authentication_failed',
            404 => 'provider_endpoint_not_found',
            408, 429 => 'provider_temporarily_unavailable',
            default => $response->serverError() ? 'provider_unavailable' : 'provider_request_rejected',
        };

        return new StalwartManagementException($code);
    }

    private function safeException(Throwable $exception, string $fallbackCode): StalwartManagementException
    {
        if ($exception instanceof StalwartManagementException) {
            return $exception;
        }

        $code = $exception instanceof ConnectionException ? 'provider_unavailable' : $fallbackCode;

        return new StalwartManagementException($code, previous: $exception);
    }

    private function safeCode(string $code): string
    {
        $safe = Str::snake(preg_replace('/[^a-zA-Z0-9]+/', '_', $code) ?: 'error');

        return trim($safe, '_') ?: 'error';
    }

    /** @return array<string, mixed>|null */
    private function recoverDomain(string $domain, ?string $correlationId): ?array
    {
        try {
            return $this->findDomainByName($domain, $correlationId);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function recoverAccount(string $remoteDomainId, string $localPart, ?string $correlationId): ?array
    {
        try {
            return $this->findAccount($remoteDomainId, $localPart, $correlationId);
        } catch (Throwable) {
            return null;
        }
    }

    private function accountIsAbsent(string $remoteAccountId, ?string $correlationId): bool
    {
        try {
            $this->inspectAccount($remoteAccountId, $correlationId);

            return false;
        } catch (StalwartManagementException $exception) {
            return $exception->errorCode === 'account_not_found';
        } catch (Throwable) {
            return false;
        }
    }
}
