<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\Contracts\DnsResolver;
use App\Domain\Mail\Contracts\DomainAuthChecker;
use App\Domain\Mail\Contracts\DomainAuthEvidenceChecker;
use App\Domain\Mail\Contracts\ManagedMailboxProvisioner;
use App\Domain\Mail\Contracts\ManagedMailEndpointChecker;
use App\Domain\Mail\DTOs\ProvisionedDomain;
use App\Domain\Mail\Enums\ManagedMailDomainStatus;
use App\Domain\Mail\Exceptions\ManagedMailProvisioningException;
use App\Models\ManagedMailDomain;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Refresh and verify the public readiness evidence for a managed domain.
 *
 * The generic mailbox-auth endpoint remains provider-neutral, but managed
 * verification supplies the provisioner's expected DKIM selector/key and
 * mail-host configuration to live evidence checkers. Production checkers
 * perform bounded DNS/PTR/TLS observations; provider status fields alone are
 * never treated as a readiness pass.
 */
final class VerifyManagedMailDomain
{
    public function __construct(
        private readonly ManagedMailboxProvisioner $provisioner,
        private readonly DomainAuthChecker $authChecker,
        private readonly DnsResolver $mxResolver,
        private readonly ManagedMailCapability $capability,
        private readonly ManagedMailEventRecorder $events,
        private readonly ?ManagedMailEndpointChecker $endpointChecker = null,
    ) {}

    public function handle(
        Workspace $workspace,
        User $actor,
        ManagedMailDomain|int $domain,
    ): ManagedMailDomain {
        $this->authorize($workspace, $actor);
        $record = $this->resolveDomain($workspace, $domain);

        $remoteId = trim((string) $record->remote_domain_id);
        if ($remoteId === '') {
            throw new ManagedMailProvisioningException('domain_missing_remote_id');
        }

        try {
            $remote = $this->provisioner->inspectDomain($remoteId, $record->managed_instance_key);
            $existingRecords = $record->getAttribute('dns_records');
            $existingRecords = is_array($existingRecords) ? $existingRecords : [];
            $records = $this->mergePublicRecords($existingRecords, $remote);
            $checks = $this->checks($record, $records);
            $records['verification'] = $checks;

            if (! $this->ready($checks)) {
                $this->recordPendingEvidence($record, $records, $checks);

                throw ValidationException::withMessages([
                    'domain' => $this->notReadyMessage($checks),
                ]);
            }

            return DB::transaction(function () use ($record, $workspace, $actor, $records, $checks): ManagedMailDomain {
                $locked = ManagedMailDomain::query()
                    ->whereKey($record->id)
                    ->where('workspace_id', $workspace->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $fromState = $this->status($locked)->value;

                // A recoverable failed/provisioning row can be verified after
                // the remote domain is found again. The explicit transitions
                // still prevent an unverified record becoming active.
                $status = $this->status($locked);
                if ($status === ManagedMailDomainStatus::Draft) {
                    $locked->transitionTo(ManagedMailDomainStatus::Provisioning, persist: false);
                    $status = ManagedMailDomainStatus::Provisioning;
                } elseif ($status === ManagedMailDomainStatus::Failed) {
                    $locked->transitionTo(ManagedMailDomainStatus::Provisioning, persist: false);
                    $status = ManagedMailDomainStatus::Provisioning;
                }

                if ($status === ManagedMailDomainStatus::Provisioning) {
                    $locked->transitionTo(ManagedMailDomainStatus::PendingDns, persist: false);
                    $status = ManagedMailDomainStatus::PendingDns;
                }

                $locked->forceFill([
                    'dns_records' => $records,
                    'dns_activated_at' => $locked->dns_activated_at ?? now(),
                    'last_verified_at' => now(),
                    'last_error_code' => null,
                    'last_error_message' => null,
                ]);

                if ($status === ManagedMailDomainStatus::PendingDns
                    || $status === ManagedMailDomainStatus::Suspended) {
                    $locked->transitionTo(ManagedMailDomainStatus::Verified, persist: false);
                }

                if ($this->status($locked) === ManagedMailDomainStatus::Verified) {
                    $locked->transitionTo(ManagedMailDomainStatus::Active, persist: false);
                }

                $locked->save();

                $this->events->record(
                    $workspace,
                    'domain_verified',
                    $actor,
                    $locked,
                    fromState: $fromState,
                    toState: $this->status($locked)->value,
                    metadata: [
                        'check_statuses' => $this->safeMetadata($checks),
                    ],
                );

                return $locked->fresh(['mailboxAccounts']);
            }, 3);
        } catch (ValidationException|ManagedMailProvisioningException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // Remote inspection failures are retryable and should not turn a
            // DNS record into a terminal local failure.
            throw new ManagedMailProvisioningException('domain_verification_failed', previous: $exception);
        }
    }

    public function verify(
        Workspace $workspace,
        User $actor,
        ManagedMailDomain|int $domain,
    ): ManagedMailDomain {
        return $this->handle($workspace, $actor, $domain);
    }

    private function authorize(Workspace $workspace, User $actor): void
    {
        if (! $this->capability->allows($actor, $workspace)) {
            throw new ManagedMailProvisioningException(
                'managed_mail_forbidden',
                'Managed mail is not available for this workspace.',
            );
        }
    }

    private function resolveDomain(Workspace $workspace, ManagedMailDomain|int $domain): ManagedMailDomain
    {
        $record = $domain instanceof ManagedMailDomain
            ? $domain
            : ManagedMailDomain::query()->whereKey($domain)->first();

        if ($record === null || (int) $record->workspace_id !== (int) $workspace->id) {
            throw new ManagedMailProvisioningException('managed_mail_workspace_mismatch');
        }

        return $record->fresh(['mailboxAccounts']);
    }

    /**
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    private function mergePublicRecords(array $existing, ProvisionedDomain $remote): array
    {
        return $this->safeMetadata([
            ...$existing,
            ...$remote->dnsRecords,
        ]);
    }

    /**
     * @param  array<string, mixed>  $records
     * @return array{mx: string, spf: string, dkim: string, dmarc: string, forward_dns: string, ptr: string, tls: string}
     */
    private function checks(ManagedMailDomain $domain, array $records): array
    {
        $expectedRecords = [
            ...$records,
            // Auth and endpoint evidence must use the same managed instance
            // configuration. This prevents an instance's outbound IP from
            // being silently borrowed by another domain verification.
            'managed_instance_key' => $domain->managed_instance_key,
        ];
        $auth = $this->authChecker instanceof DomainAuthEvidenceChecker
            ? $this->authChecker->checkWithExpectedRecords($domain->domain, $expectedRecords)
            : $this->genericAuthEvidence($domain->domain);
        $mxHosts = $this->mxResolver->resolveMx($domain->domain);
        $configuredHosts = $this->configuredMailHosts($domain);
        $expectedHosts = $this->mailHostsFromRecords($records, $domain->domain);
        $intendedHost = $this->intendedMailHost($mxHosts, $configuredHosts, $expectedHosts);
        $endpoint = ($this->endpointChecker ?? app(ManagedMailEndpointChecker::class))->check(
            $domain->domain,
            $expectedRecords,
            $intendedHost === null ? [] : [$intendedHost],
        );

        return [
            'mx' => $this->mxStatus($mxHosts, $intendedHost),
            'spf' => $this->statusValue($auth['spf']),
            'dkim' => $this->statusValue($auth['dkim']),
            'dmarc' => $this->statusValue($auth['dmarc']),
            'forward_dns' => $this->statusValue($endpoint['forward_dns']),
            'ptr' => $this->statusValue($endpoint['ptr']),
            'tls' => $this->statusValue($endpoint['tls']),
        ];
    }

    /** @return array{spf: 'unknown', dmarc: 'pass'|'missing'|'unknown', dkim: 'unknown'} */
    private function genericAuthEvidence(string $domain): array
    {
        // A provider-neutral checker has no provisioner expectation
        // contract. None of its generic SPF/DKIM statuses are readiness
        // evidence for a managed domain; retaining only DMARC keeps the
        // generic checker useful for operator context.
        $auth = $this->authChecker->check($domain);

        return [
            'spf' => 'unknown',
            'dmarc' => $auth['dmarc'],
            'dkim' => 'unknown',
        ];
    }

    /** @param array{mx: string, spf: string, dkim: string, dmarc: string, forward_dns: string, ptr: string, tls: string} $checks */
    private function ready(array $checks): bool
    {
        // Every plan-blocking signal must be positively observed. In
        // particular, unknown is not an implicit pass: the current resolver
        // cannot observe PTR, TLS, forward DNS, or DKIM, so production
        // verification safely remains pending until an evidence-producing
        // resolver is installed.
        foreach ($checks as $status) {
            if ($status !== 'pass') {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, string> $checks */
    private function notReadyMessage(array $checks): string
    {
        $failed = [];
        foreach ($checks as $key => $status) {
            if ($status !== 'pass') {
                $failed[] = $key.'='.$status;
            }
        }

        return 'Managed domain DNS is not ready. Review: '.implode(', ', $failed).'.';
    }

    /**
     * @param  array<string, mixed>  $records
     * @param  array{mx: string, spf: string, dkim: string, dmarc: string, forward_dns: string, ptr: string, tls: string}  $checks
     */
    private function recordPendingEvidence(ManagedMailDomain $record, array $records, array $checks): void
    {
        DB::transaction(function () use ($record, $records, $checks): void {
            $locked = ManagedMailDomain::query()->whereKey($record->id)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }

            if ($this->status($locked) === ManagedMailDomainStatus::Active) {
                $locked->transitionTo(ManagedMailDomainStatus::PendingDns, persist: false);
            }

            $locked->forceFill([
                'dns_records' => $records,
                'last_verified_at' => now(),
                'last_error_code' => 'dns_not_ready',
                'last_error_message' => $this->notReadyMessage($checks),
            ])->save();
        }, 3);
    }

    /**
     * @param  list<string>  $mxHosts
     */
    private function mxStatus(array $mxHosts, ?string $intendedHost): string
    {
        if ($mxHosts === []) {
            return 'unknown';
        }

        if ($intendedHost === null) {
            return 'missing';
        }

        $observed = array_values(array_unique(array_filter(array_map(
            fn (mixed $host): ?string => $this->normaliseHost($host),
            $mxHosts,
        ))));

        return in_array($intendedHost, $observed, true) ? 'pass' : 'missing';
    }

    /**
     * @return list<string>
     */
    private function configuredMailHosts(ManagedMailDomain $domain): array
    {
        $instance = trim((string) $domain->managed_instance_key);
        $configuredValues = [];

        foreach ([
            'outboss.managed_mail.instances.'.$instance.'.mail_host',
            'outboss.managed_mail.instances.'.$instance.'.mx_host',
            'stalwart.instances.'.$instance.'.mail_host',
            'services.stalwart.instances.'.$instance.'.mail_host',
        ] as $path) {
            $value = config($path);
            if (is_string($value) && trim($value) !== '') {
                $configuredValues[] = $value;
            } elseif (is_array($value)) {
                $configuredValues = [...$configuredValues, ...$value];
            }
        }

        if ($configuredValues === []) {
            $base = config('outboss.managed_mail.instances.'.$instance.'.base_url');
            if (is_string($base)) {
                $parts = parse_url(trim($base));
                if (is_array($parts) && is_string($parts['host'] ?? null)) {
                    $configuredValues[] = $parts['host'];
                }
            }
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $host): ?string => $this->normaliseHost($host),
            $configuredValues,
        ))));
    }

    /**
     * Select exactly one host shared by the configured EHLO target, the
     * provisioner's expected MX target, and the observed MX set. A list of
     * candidate hosts is intentionally not passed to the endpoint checker;
     * otherwise A/AAAA, PTR, and TLS evidence could be mixed across hosts.
     *
     * @param  list<string>  $mxHosts
     * @param  list<string>  $configuredHosts
     * @param  list<string>  $expectedHosts
     */
    private function intendedMailHost(array $mxHosts, array $configuredHosts, array $expectedHosts): ?string
    {
        $observed = array_values(array_unique(array_filter(array_map(
            fn (mixed $host): ?string => $this->normaliseHost($host),
            $mxHosts,
        ))));
        if ($observed === []) {
            return null;
        }

        $configured = array_values(array_unique(array_filter(array_map(
            fn (mixed $host): ?string => $this->normaliseHost($host),
            $configuredHosts,
        ))));
        $expected = array_values(array_unique(array_filter(array_map(
            fn (mixed $host): ?string => $this->normaliseHost($host),
            $expectedHosts,
        ))));

        // Configuration is the explicit EHLO intent and may disambiguate a
        // provider zone that advertises failover MX targets. It must itself
        // contain exactly one host. Without that explicit intent, a provider
        // expectation is usable only when it names one host.
        if (count($configured) > 1 || ($configured === [] && count($expected) !== 1)) {
            return null;
        }

        $intended = $configured[0] ?? $expected[0] ?? null;
        if ($intended === null || ($expected !== [] && ! in_array($intended, $expected, true))) {
            return null;
        }

        return in_array($intended, $observed, true) ? $intended : null;
    }

    /**
     * @param  array<string, mixed>  $records
     * @return list<string>
     */
    private function mailHostsFromRecords(array $records, string $domain): array
    {
        $hosts = [];
        $mx = $records['mx'] ?? null;
        $collect = function (mixed $value, string $key = '', bool $mxContext = false) use (&$collect, &$hosts): void {
            $mxContext = $mxContext || str_contains(strtolower($key), 'mx');
            if (is_array($value)) {
                if ($mxContext) {
                    foreach (['value', 'target', 'mail_host'] as $candidateKey) {
                        $candidate = $value[$candidateKey] ?? null;
                        if (is_string($candidate) && trim($candidate) !== '') {
                            $hosts[] = $candidate;
                        }
                    }
                }

                foreach ($value as $childKey => $childValue) {
                    $collect($childValue, (string) $childKey, $mxContext);
                }

                return;
            }

            if ($mxContext && is_string($value)
                && (in_array(strtolower($key), ['value', 'target', 'mail_host'], true)
                    || str_contains(strtolower($key), 'mx')
                    || ctype_digit($key))
                && trim($value) !== '') {
                $hosts[] = $value;
            }
        };
        $collect($mx, 'mx');

        $zoneFile = $records['zone_file'] ?? null;
        if (is_string($zoneFile)) {
            foreach (preg_split('/\R/', $zoneFile) ?: [] as $line) {
                if (preg_match('/^\s*\S+\s+(?:(?:\d+)\s+)?IN\s+MX\s+\d+\s+(\S+)\s*$/i', trim($line), $matches) === 1) {
                    $hosts[] = $matches[1];
                }
            }
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $host): ?string => $this->normaliseMxHost($host),
            $hosts,
        ))));
    }

    private function normaliseMxHost(mixed $host): ?string
    {
        if (is_string($host)
            && preg_match('/^\s*\d+\s+(\S+)\s*$/', $host, $matches) === 1) {
            $host = $matches[1];
        }

        return $this->normaliseHost($host);
    }

    private function normaliseHost(mixed $host): ?string
    {
        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        $host = strtolower(trim($host));
        $host = trim($host, '[]');
        $host = rtrim($host, '.');

        return $host !== '' && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            ? $host
            : null;
    }

    private function statusValue(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, ['pass', 'missing', 'unknown'], true) ? $value : 'unknown';
    }

    private function status(ManagedMailDomain $domain): ManagedMailDomainStatus
    {
        $status = $domain->getAttribute('status');

        return $status instanceof ManagedMailDomainStatus
            ? $status
            : ManagedMailDomainStatus::from((string) $status);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function safeMetadata(array $values, int $depth = 0): array
    {
        if ($depth > 2) {
            return [];
        }

        $safe = [];
        foreach ($values as $key => $value) {
            $key = substr((string) $key, 0, 64);
            if ($key === '' || preg_match('/(?:pass(word)?|secret|token|credential|api[_-]?key|authorization|body|payload)/i', $key) === 1) {
                continue;
            }

            if (is_array($value)) {
                $nested = $this->safeMetadata($value, $depth + 1);
                if ($nested !== []) {
                    $safe[$key] = $nested;
                }
            } elseif (is_scalar($value) || $value === null) {
                $safe[$key] = is_string($value)
                    ? substr(str_replace(["\r", "\n"], ' ', trim($value)), 0, 1000)
                    : $value;
            }
        }

        return $safe;
    }
}
