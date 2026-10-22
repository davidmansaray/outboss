<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\Contracts\ManagedMailboxProvisioner;
use App\Domain\Mail\DTOs\ManagedDomainRequest;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\ManagedMailDomainStatus;
use App\Domain\Mail\Exceptions\InvalidManagedMailStateTransition;
use App\Domain\Mail\Exceptions\ManagedMailProvisioningException;
use App\Domain\Mail\Exceptions\StalwartManagementException;
use App\Domain\Mail\Support\ManagedDomainNormalizer;
use App\Models\ManagedMailDomain;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Orchestrate local managed-domain state around the provider boundary.
 *
 * The remote call intentionally happens after the local provisioning row is
 * committed. A timeout or local write failure therefore leaves a durable row
 * which a later retry can reconcile through the provisioner's idempotent
 * create operation.
 */
final class ProvisionManagedDomain
{
    public function __construct(
        private readonly ManagedMailboxProvisioner $provisioner,
        private readonly ManagedMailCapability $capability,
        private readonly ManagedMailEventRecorder $events,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function handle(
        Workspace $workspace,
        User $actor,
        string $domain,
        ?string $managedInstanceKey = null,
        array $metadata = [],
    ): ManagedMailDomain {
        $this->authorize($workspace, $actor);

        $normalized = ManagedDomainNormalizer::normalize($domain);
        if ($normalized === null) {
            throw ValidationException::withMessages([
                'domain' => 'Enter a valid public DNS domain for the managed mailbox.',
            ]);
        }

        $organisationalDomain = ManagedDomainNormalizer::organisationalDomain($normalized);
        if ($organisationalDomain === null) {
            throw ValidationException::withMessages([
                'domain' => 'Enter a registrable public DNS domain for the managed mailbox.',
            ]);
        }

        $instanceKey = $this->instanceKey($managedInstanceKey);

        // Give the caller a friendly error in the normal case. The database
        // unique key remains authoritative when two workspaces race between
        // this check and the insert.
        if (ManagedMailDomain::query()
            ->where('managed_instance_key', $instanceKey)
            ->where('domain', $normalized)
            ->where('workspace_id', '!=', $workspace->id)
            ->exists()) {
            throw ValidationException::withMessages([
                'domain' => 'This domain is already managed by another workspace.',
            ]);
        }

        $correlationId = $this->correlationId($metadata);
        try {
            $local = DB::transaction(function () use (
                $workspace,
                $actor,
                $normalized,
                $organisationalDomain,
                $instanceKey,
            ): ManagedMailDomain {
                $domainRecord = ManagedMailDomain::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('managed_instance_key', $instanceKey)
                    ->where('domain', $normalized)
                    ->lockForUpdate()
                    ->first();

                if ($domainRecord === null) {
                    $domainRecord = ManagedMailDomain::query()->create([
                        'workspace_id' => $workspace->id,
                        'created_by_user_id' => $actor->id,
                        'provider' => MailProviderName::Stalwart,
                        'managed_instance_key' => $instanceKey,
                        'domain' => $normalized,
                        'organisational_domain' => $organisationalDomain,
                        'domain_kind' => ManagedDomainNormalizer::kind($normalized),
                        'status' => ManagedMailDomainStatus::Draft,
                        'dns_records' => null,
                    ]);
                }

                $status = $this->domainStatus($domainRecord);
                if (in_array($status, [
                    ManagedMailDomainStatus::PendingDns,
                    ManagedMailDomainStatus::Verified,
                    ManagedMailDomainStatus::Active,
                ], true) && filled($domainRecord->remote_domain_id)) {
                    return $domainRecord;
                }

                if ($status === ManagedMailDomainStatus::Suspended) {
                    throw InvalidManagedMailStateTransition::domain(
                        $status,
                        ManagedMailDomainStatus::Provisioning,
                    );
                }

                if ($status !== ManagedMailDomainStatus::Provisioning) {
                    $domainRecord->transitionTo(ManagedMailDomainStatus::Provisioning, persist: false);
                }

                $domainRecord->forceFill([
                    'last_error_code' => null,
                    'last_error_message' => null,
                ])->save();

                return $domainRecord;
            }, 3);
        } catch (QueryException $exception) {
            if (! $this->isInstanceDomainConflict($exception)) {
                throw $exception;
            }

            // A same-workspace duplicate is an idempotent retry. A different
            // workspace winning the unique-key race is a validation error.
            $local = ManagedMailDomain::query()
                ->where('workspace_id', $workspace->id)
                ->where('managed_instance_key', $instanceKey)
                ->where('domain', $normalized)
                ->first();

            if ($local === null) {
                throw ValidationException::withMessages([
                    'domain' => 'This domain is already managed by another workspace on this mail instance.',
                ]);
            }
        }

        $status = $this->domainStatus($local);
        if (in_array($status, [
            ManagedMailDomainStatus::PendingDns,
            ManagedMailDomainStatus::Verified,
            ManagedMailDomainStatus::Active,
        ], true) && filled($local->remote_domain_id)) {
            return $local->fresh();
        }

        try {
            $remote = $this->provisioner->createDomain(new ManagedDomainRequest(
                domain: $normalized,
                managedInstanceKey: $instanceKey,
                workspaceId: $workspace->id,
                metadata: ['correlation_id' => $correlationId],
            ));

            if (trim($remote->remoteDomainId) === '') {
                throw new ManagedMailProvisioningException('domain_missing_remote_id');
            }

            $updated = DB::transaction(function () use ($local, $remote, $actor): ManagedMailDomain {
                $locked = ManagedMailDomain::query()->whereKey($local->id)->lockForUpdate()->firstOrFail();
                $fromState = $this->domainStatus($locked)->value;

                $locked->forceFill([
                    'remote_domain_id' => trim($remote->remoteDomainId),
                    'dns_records' => $this->publicDnsRecords($remote->dnsRecords),
                    'last_error_code' => null,
                    'last_error_message' => null,
                ]);

                if ($this->domainStatus($locked) !== ManagedMailDomainStatus::PendingDns) {
                    $locked->transitionTo(ManagedMailDomainStatus::PendingDns, persist: false);
                }

                $locked->save();

                $this->events->record(
                    $locked->workspace,
                    'domain_provisioned',
                    $actor,
                    $locked,
                    fromState: $fromState,
                    toState: ManagedMailDomainStatus::PendingDns->value,
                    metadata: [
                        'remote_domain_id' => $locked->remote_domain_id,
                        'dns_record_keys' => is_array($dnsRecords = $locked->getAttribute('dns_records'))
                            ? array_keys($dnsRecords)
                            : [],
                    ],
                );

                return $locked;
            }, 3);

            return $updated->fresh();
        } catch (Throwable $exception) {
            $this->markFailed($local, $exception);

            throw $this->safeException($exception, 'domain_provision_failed');
        }
    }

    /**
     * Alias for callers that prefer an explicit provisioning verb.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function provision(
        Workspace $workspace,
        User $actor,
        string $domain,
        ?string $managedInstanceKey = null,
        array $metadata = [],
    ): ManagedMailDomain {
        return $this->handle($workspace, $actor, $domain, $managedInstanceKey, $metadata);
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

    private function instanceKey(?string $instanceKey): string
    {
        $instanceKey = trim((string) ($instanceKey ?: config('outboss.managed_mail.default_instance', 'internal')));

        if ($instanceKey === '' || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,79}\z/', $instanceKey) !== 1) {
            throw ValidationException::withMessages([
                'managed_instance_key' => 'Choose a configured managed mail instance.',
            ]);
        }

        if (! is_array(config("outboss.managed_mail.instances.{$instanceKey}", []))) {
            throw ValidationException::withMessages([
                'managed_instance_key' => 'Choose a configured managed mail instance.',
            ]);
        }

        return $instanceKey;
    }

    /** @param array<string, mixed> $metadata */
    private function correlationId(array $metadata): string
    {
        $value = $metadata['correlation_id'] ?? null;

        return is_string($value) && preg_match('/\A[a-zA-Z0-9_.:-]{1,120}\z/', $value) === 1
            ? $value
            : (string) Str::uuid();
    }

    private function domainStatus(ManagedMailDomain $domain): ManagedMailDomainStatus
    {
        $status = $domain->getAttribute('status');

        return $status instanceof ManagedMailDomainStatus
            ? $status
            : ManagedMailDomainStatus::from((string) $status);
    }

    private function isInstanceDomainConflict(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'managed_mail_domains_instance_domain_unique')
            || (str_contains($message, 'managed_mail_domains.managed_instance_key')
                && str_contains($message, 'managed_mail_domains.domain'));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, bool|float|int|string|null|array<string, mixed>>
     */
    private function publicDnsRecords(array $values, int $depth = 0): array
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
                $nested = $this->publicDnsRecords($value, $depth + 1);
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

    private function markFailed(ManagedMailDomain $domain, Throwable $exception): void
    {
        $code = $this->errorCode($exception, 'domain_provision_failed');
        $message = $this->errorMessage($exception, 'Managed domain provisioning failed. Retry or reconcile the managed domain.');

        try {
            $locked = ManagedMailDomain::query()->whereKey($domain->id)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }

            $fromState = $this->domainStatus($locked)->value;
            if ($this->domainStatus($locked) !== ManagedMailDomainStatus::Failed) {
                $locked->transitionTo(ManagedMailDomainStatus::Failed, persist: false);
            }
            $locked->forceFill([
                'last_error_code' => $code,
                'last_error_message' => $message,
            ])->save();

            $this->events->record(
                $locked->workspace,
                'domain_provision_failed',
                $locked->createdBy,
                $locked,
                fromState: $fromState,
                toState: ManagedMailDomainStatus::Failed->value,
                metadata: ['error_code' => $code],
            );
        } catch (Throwable) {
            // Preserve the original provider failure. Reconciliation can
            // inspect the row if the best-effort failure write itself failed.
        }
    }

    private function errorCode(Throwable $exception, string $fallback): string
    {
        $code = $exception instanceof StalwartManagementException || $exception instanceof ManagedMailProvisioningException
            ? $exception->errorCode
            : $fallback;

        $code = strtolower(substr($code, 0, 120));

        return preg_replace('/[^a-z0-9_.-]+/', '_', $code) ?: $fallback;
    }

    private function errorMessage(Throwable $exception, string $fallback): string
    {
        if (! ($exception instanceof StalwartManagementException || $exception instanceof ManagedMailProvisioningException)) {
            return $fallback;
        }

        $message = trim(str_replace(["\r", "\n"], ' ', $exception->getMessage()));

        return $message === '' ? $fallback : substr($message, 0, 500);
    }

    private function safeException(Throwable $exception, string $fallback): Throwable
    {
        if ($exception instanceof StalwartManagementException || $exception instanceof ManagedMailProvisioningException) {
            return $exception;
        }

        return new ManagedMailProvisioningException($fallback, previous: $exception);
    }
}
